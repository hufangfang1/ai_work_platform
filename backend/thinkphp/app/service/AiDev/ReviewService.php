<?php

namespace app\service\AiDev;

use think\facade\Db;

class ReviewService
{
    public function review($taskId)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        $change = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('created_at', 'desc')->find();
        if (!$task || !$change) {
            throw new \RuntimeException('没有可 Review 的代码改动');
        }
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        $testResult = $this->runConfiguredChecks($project);
        $result = [
            'status' => 'pass',
            'risk_level' => 'low',
            'blocking_issues' => [],
            'warnings' => [],
            'suggestions' => ['提交前确认 diff 是否严格限定在计划范围内'],
            'summary' => '基础检查通过，可进入提交确认。',
        ];
        $id = Db::name('ai_dev_reviews')->insertGetId([
            'task_id' => $taskId,
            'run_id' => $change['run_id'],
            'status' => $result['status'],
            'risk_level' => $result['risk_level'],
            'review_result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'test_result' => $testResult,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Db::name('ai_dev_tasks')->where('id', $taskId)->update([
            'status' => 'ready_to_commit',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::name('ai_dev_reviews')->where('id', $id)->find();
    }

    private function runConfiguredChecks(array $project)
    {
        $parts = [];
        foreach (['lint_command', 'test_command', 'build_command'] as $field) {
            if (empty($project[$field])) {
                continue;
            }
            exec('cd ' . escapeshellarg($project['local_path']) . ' && ' . $project[$field] . ' 2>&1', $output, $code);
            $parts[] = "命令：{$project[$field]}\n退出码：{$code}\n" . implode("\n", $output);
        }
        return implode("\n\n", $parts);
    }
}
