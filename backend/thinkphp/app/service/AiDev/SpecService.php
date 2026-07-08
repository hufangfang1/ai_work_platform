<?php

namespace app\service\AiDev;

use think\facade\Db;

class SpecService
{
    public function generate($taskId, $model = '')
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        $doc = Db::name('ai_dev_requirement_docs')->where('id', $task['doc_version_id'])->find();
        if (!$doc || trim((string) $doc['content']) === '') {
            throw new \RuntimeException('需求文档快照缺失');
        }
        $breakdown = Db::name('ai_dev_breakdowns')
            ->where('requirement_id', (int) $task['requirement_id'])
            ->whereNotNull('confirmed_at')
            ->order('version', 'desc')->find();
        if (!$breakdown) {
            throw new \RuntimeException('未找到已确认的需求拆解');
        }
        $items = json_decode((string) $breakdown['projects_json'], true) ?: [];
        $entry = ['role' => '其他', 'scope_summary' => ''];
        foreach ($items as $it) {
            if ((int) (isset($it['project_id']) ? $it['project_id'] : 0) === (int) $task['project_id']) {
                $entry = [
                    'role' => isset($it['role']) ? $it['role'] : '其他',
                    'scope_summary' => isset($it['scope_summary']) ? $it['scope_summary'] : '',
                ];
                break;
            }
        }
        $this->assertNoRunning($taskId);
        return (new RunService())->enqueueGeneration((int) $taskId, 'task_spec', [
            'operation' => 'task_spec',
            'task_id' => (int) $taskId,
            'prompt' => $this->buildPrompt($doc['content'], $breakdown['content'], $entry['role'], $entry['scope_summary']),
            'options' => ['timeout' => 300, 'max_turns' => 3],
        ], 'task:' . (int) $taskId, $model);
    }

    public function finishRun(array $run, array $data)
    {
        $taskId = (int) $run['task_id'];
        $content = isset($data['spec_markdown']) ? trim((string) $data['spec_markdown']) : '';
        if ($content === '') {
            throw new \RuntimeException('claude 未返回 spec_markdown');
        }
        Db::name('ai_dev_tasks')->where('id', $taskId)->update([
            'spec_markdown' => $content,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::name('ai_dev_tasks')->where('id', $taskId)->find();
    }

    private function assertNoRunning($taskId)
    {
        foreach ((new RunService())->listByTask($taskId) as $run) {
            if ($run['run_type'] === 'task_spec' && in_array($run['status'], ['queued', 'running'], true)) {
                throw new \RuntimeException('已有本项目需求文档生成任务正在运行');
            }
        }
    }

    private function buildPrompt($docContent, $breakdownContent, $role, $scopeSummary)
    {
        if (mb_strpos((string) $role, '前端') !== false) {
            $tpl = "本项目是前端。产出【本项目视角】的落地需求子文档,聚焦:页面结构与信息层级、交互流程、每个展示字段及其数据来源标注、敏感字段脱敏规则、空态/异常态。禁止写 SQL、表结构、后端实现。";
        } elseif (mb_strpos((string) $role, '后端') !== false) {
            $tpl = "本项目是后端/代理。产出【本项目视角】的落地需求子文档,聚焦:接口清单(路径/入参/出参/口径)、数据来源分层、SQL 聚合口径、日志与快照字段。禁止写页面布局与前端交互细节。";
        } else {
            $tpl = "产出【本项目视角】的落地需求子文档,只写属于本项目职责范围内的内容。";
        }
        return "你是本项目的负责人。" . $tpl . "\n"
            . "只返回 JSON,结构:{\"spec_markdown\":\"...\"},不要 JSON 以外的内容。\n"
            . "凡涉及跨项目交互,一律写『见共享接口契约』并引用下方拆解中的『跨项目接口契约』小节,不要复述其内容。\n\n"
            . "# 本项目职责(来自拆解)\n" . ($scopeSummary !== '' ? $scopeSummary : '(未提供,请从原文中判定属于本项目的部分)') . "\n\n"
            . "# 需求拆解(含共享接口契约,唯一事实源)\n" . $breakdownContent . "\n\n"
            . "# 原始需求文档(已脱敏,供补全细节)\n" . $docContent . "\n";
    }
}
