<?php

namespace app\service\AiDev;

use think\facade\Db;

class PlanService
{
    public function generate($taskId, $model = '', $draft = false)
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
        ], 'task:' . (int) $taskId, $model, $draft);
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
            . "## 需求理解 / ## 涉及模块与文件 / ## 接口契约 / ## 实施步骤 / ## 配置变更 / ## SQL 变更 / ## 验证计划 / ## 风险点\n"
            . "如果本项目需要提供或消费 HTTP/API 接口,`## 接口契约` 必须逐接口写清楚,不能只写接口名称。\n"
            . "每个接口必须包含:方法、完整路径、用途、鉴权/权限要求、请求参数表(参数名/位置 query|path|body|header/类型/必填/默认值/说明/校验规则)、响应字段表(字段路径/类型/可空/说明/来源或计算口径)、空数据响应、错误码或失败状态、分页/排序规则、示例请求和示例响应。\n"
            . "后端计划若新增接口,还必须说明每个响应字段来自哪张表、哪段缓存或哪个上游接口;未知/未汇总字段如何返回 null、unknown、requires_summary 或 failed。\n"
            . "前端计划若消费接口,必须说明每个页面/组件读取哪些接口字段、字段为空或失败时怎么展示;禁止自行发明后端未声明字段。\n"
            . "如果某个接口的入参/出参仍无法从需求或代码确认,必须在 `## 风险点` 标为阻塞项,不要用模糊占位替代。\n\n"
            . $projectContext . "\n\n"
            . "# 本项目职责(来自需求拆解)\n" . ($scopeSummary !== '' ? $scopeSummary : '整个需求都在本项目内实现') . "\n";
    }
}
