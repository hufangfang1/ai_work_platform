<?php

namespace app\service\AiDev;

use think\facade\Db;

class PlanService
{
    public function generate($taskId, $model = '')
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        if (!$project || $project['local_path'] === '' || !is_dir($project['local_path'])) {
            throw new \RuntimeException('项目本地目录不存在,无法读取代码生成计划');
        }
        $doc = Db::name('ai_dev_requirement_docs')->where('id', $task['doc_version_id'])->find();
        if (!$doc || trim((string) $doc['content']) === '') {
            throw new \RuntimeException('需求文档快照缺失');
        }

        $this->assertNoRunningPlan($taskId);
        return (new RunService())->enqueueGeneration((int) $taskId, 'task_plan', [
            'operation' => 'task_plan',
            'task_id' => (int) $taskId,
            'prompt' => $this->buildPrompt((new TaskService())->projectContext($task), $task['scope_summary']),
            'options' => [
                'cwd' => $project['local_path'],
                'allowed_tools' => 'Read,Glob,Grep',
                'max_turns' => 25,
                'timeout' => 600,
            ],
        ], 'task:' . (int) $taskId, $model);
    }

    public function finishRun(array $run, array $data)
    {
        $taskId = (int) $run['task_id'];
        $content = isset($data['plan_markdown']) ? trim((string) $data['plan_markdown']) : '';
        if ($content === '') {
            throw new \RuntimeException('claude 未返回 plan_markdown');
        }
        return $this->saveVersion($taskId, $content, 'ai', $run['model_name']);
    }

    private function assertNoRunningPlan($taskId)
    {
        $runs = (new RunService())->listByTask($taskId);
        foreach ($runs as $run) {
            if ($run['run_type'] === 'task_plan' && in_array($run['status'], ['queued', 'running'], true)) {
                throw new \RuntimeException('已有计划生成任务正在运行');
            }
        }
    }

    public function saveHumanVersion($taskId, $content)
    {
        return $this->saveVersion($taskId, $content, 'human', '');
    }

    public function saveVersion($taskId, $content, $source, $modelName)
    {
        $version = (int) Db::name('ai_dev_plans')->where('task_id', $taskId)->max('version') + 1;
        $id = Db::name('ai_dev_plans')->insertGetId([
            'task_id' => $taskId,
            'version' => $version,
            'plan_content' => $content,
            'source' => $source,
            'model_name' => $modelName,
            'confirmed_by' => 0,
            'confirmed_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Db::name('ai_dev_tasks')->where('id', $taskId)->update([
            'status' => 'plan_generated',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::name('ai_dev_plans')->where('id', $id)->find();
    }

    public function confirmLatest($taskId)
    {
        $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->order('version', 'desc')->find();
        if (!$plan) {
            throw new \RuntimeException('没有可确认的开发计划');
        }
        Db::name('ai_dev_plans')->where('id', $plan['id'])->update([
            'confirmed_by' => 0,
            'confirmed_at' => date('Y-m-d H:i:s'),
        ]);
        Db::name('ai_dev_tasks')->where('id', $taskId)->update([
            'status' => 'plan_confirmed',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::name('ai_dev_plans')->where('id', $plan['id'])->find();
    }

    private function buildPrompt($projectContext, $scopeSummary)
    {
        return "你在该项目代码库根目录,可用 Read/Glob/Grep 阅读代码(禁止修改任何文件)。"
            . "为以下需求产出本项目的开发计划。只返回 JSON,结构:{\"plan_markdown\":\"...\"},不要 JSON 以外的内容。\n"
            . "计划必须引用真实存在的文件路径;结构固定为:\n"
            . "## 需求理解 / ## 涉及模块与文件 / ## 实施步骤 / ## 配置变更 / ## SQL 变更 / ## 验证计划 / ## 风险点\n\n"
            . $projectContext . "\n\n"
            . "# 本项目职责(来自需求拆解)\n" . ($scopeSummary !== '' ? $scopeSummary : '整个需求都在本项目内实现') . "\n";
    }
}
