<?php

namespace app\service\AiDev;

use think\facade\Db;

class CommitService
{
    public function generateMessage($taskId)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        return [
            'commit_message' => "feat({$task['repo_name']}): {$task['title']}\n\n- 完成 AI 开发工单代码改动\n- 保存 Review 与验证结果\n- 保留提交依据与复盘记录",
        ];
    }

    public function commit($taskId, $message)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || $task['status'] !== 'ready_to_commit') {
            throw new \RuntimeException('当前状态不能提交');
        }
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        $worktree = dirname(rtrim($project['local_path'], '/')) . '/wt-task-' . $task['id'];
        exec('git -C ' . escapeshellarg($worktree) . ' add -A');
        exec('git -C ' . escapeshellarg($worktree) . ' commit -m ' . escapeshellarg($message) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException('git commit 失败：' . implode("\n", $output));
        }
        exec('git -C ' . escapeshellarg($worktree) . ' rev-parse HEAD', $hashOutput, $hashCode);
        $hash = $hashCode === 0 && !empty($hashOutput[0]) ? $hashOutput[0] : '';
        Db::name('ai_dev_tasks')->where('id', $taskId)->update([
            'status' => 'committed',
            'commit_message' => $message,
            'commit_hash' => $hash,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::name('ai_dev_tasks')->where('id', $taskId)->find();
    }

    public function push($taskId)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        if (!$project['allow_auto_push']) {
            throw new \RuntimeException('项目配置禁止自动 push');
        }
        $worktree = dirname(rtrim($project['local_path'], '/')) . '/wt-task-' . $task['id'];
        exec('git -C ' . escapeshellarg($worktree) . ' push origin ' . escapeshellarg($task['final_branch_name']) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException('git push 失败：' . implode("\n", $output));
        }
        Db::name('ai_dev_tasks')->where('id', $taskId)->update([
            'is_pushed' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::name('ai_dev_tasks')->where('id', $taskId)->find();
    }
}
