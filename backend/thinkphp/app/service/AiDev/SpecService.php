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
        $project = Db::name('ai_dev_projects')->where('id', (int) $task['project_id'])->find();
        if (!$project) {
            throw new \RuntimeException('项目不存在');
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
        $entry = ['role' => '其他', 'scope_summary' => '', 'interfaces' => ''];
        foreach ($items as $it) {
            if ((int) (isset($it['project_id']) ? $it['project_id'] : 0) === (int) $task['project_id']) {
                $entry = [
                    'role' => isset($it['role']) ? $it['role'] : '其他',
                    'scope_summary' => isset($it['scope_summary']) ? $it['scope_summary'] : '',
                    'interfaces' => isset($it['interfaces']) ? $it['interfaces'] : '',
                ];
                break;
            }
        }
        $this->assertNoRunning($taskId);
        return (new RunService())->enqueueGeneration((int) $taskId, 'task_spec', [
            'operation' => 'task_spec',
            'task_id' => (int) $taskId,
            'prompt' => $this->buildPrompt($doc['content'], $breakdown['content'], $project, $entry['role'], $entry['scope_summary'], $entry['interfaces']),
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

    private function buildPrompt($docContent, $breakdownContent, array $project, $role, $scopeSummary, $interfaces)
    {
        $projectName = isset($project['name']) ? (string) $project['name'] : '';
        $description = isset($project['description']) ? (string) $project['description'] : '';
        if (mb_strpos((string) $role, '前端') !== false) {
            $tpl = "本项目是前端。子文档聚焦:页面/模块范围、页面布局与区域规格、PC/H5 布局、筛选与交互流程、展示字段与来源状态、接口消费关系、敏感字段脱敏、空态/异常态、前端验收标准。可以列出需要调用的后端接口和消费字段;禁止定义 SQL、表结构、汇总任务或后端实现。";
            $structureHint = "- spec_markdown 第一行必须是 `# {$projectName} 前端需求文档`。\n"
                . "- 输出结构必须包含:## 前端交付目标 / ## 页面与入口 / ## 页面布局与区域规格 / ## PC 端布局 / ## H5 端布局 / ## 筛选、列表与详情交互 / ## 接口消费与字段展示 / ## 空态、错误态与脱敏 / ## 前端验收标准。\n"
                . "- 如果原始需求文档包含『前端布局/页面布局/PC 端布局/H5 端布局/整体结构/区域规格/抽屉/弹层/表格/卡片/Tabs』等内容,必须提炼并保留为可落地页面需求,不得只写一句摘要或只写信息架构。\n"
                . "- `## 页面布局与区域规格` 至少包含:页面整体区域划分、PC 与 H5 的区域顺序、宽高/单列多列关系、各区域展示内容和交互说明、列表/卡片/详情的字段展示优先级。\n";
        } elseif (mb_strpos((string) $role, '后端') !== false) {
            $tpl = "本项目是后端/代理。子文档聚焦:只读接口清单、入参/出参/字段口径、数据来源分层、SQL 聚合口径、日志与快照字段、汇总缓存策略、权限与安全、性能限制、后端验收标准。可以简述前端消费场景;禁止展开页面布局、组件、H5 卡片或前端交互实现。";
            $structureHint = "- spec_markdown 第一行必须是 `# {$projectName} 后端需求文档`。\n"
                . "- 输出结构必须包含:## 后端交付目标 / ## API 接口范围 / ## 数据来源与聚合口径 / ## 汇总缓存与刷新策略 / ## 权限、脱敏与审计 / ## 异常状态与降级 / ## 后端验收标准。\n";
        } else {
            $tpl = "子文档只写属于本项目职责范围内的内容,并明确与其他项目的依赖边界。";
            $structureHint = "- 输出结构建议包含:## 目标 / ## 本项目范围 / ## 本项目不负责 / ## 功能需求 / ## 数据与接口依赖 / ## 状态与异常 / ## 安全与脱敏 / ## 验收标准。\n";
        }
        return "你是 {$projectName} 项目的需求负责人。" . $tpl . "\n"
            . "只返回 JSON,结构:{\"spec_markdown\":\"...\"},不要 JSON 以外的内容。\n"
            . "拆分原则:\n"
            . "- 按交付责任重组需求,不要按原文章节机械裁剪。\n"
            . "- 子文档要能单独给本项目研发阅读,但跨项目 API 的完整 schema 以下方『跨项目接口契约』为唯一事实源。\n"
            . "- 凡涉及另一个项目负责的实现,只说明依赖/消费关系和验收边界,不要替对方写实现。\n"
            . "- 必须写清『本项目不负责』的内容,避免计划和编码阶段越界。\n"
            . "- 不要复制原始需求文档、需求拆解或共享接口契约全文;只提炼本项目需要落地的内容。\n"
            . "- 正文控制在 4000 到 7000 个中文字符内;不要输出 JSON 代码块,接口只写摘要表,完整 schema 写『见共享接口契约』。\n"
            . $structureHint . "\n"
            . "# 当前项目\n"
            . "- name: " . $projectName . "\n"
            . "- role: " . (string) $role . "\n"
            . "- description: " . ($description !== '' ? $description : '无') . "\n\n"
            . "# 本项目职责(来自拆解)\n" . ($scopeSummary !== '' ? $scopeSummary : '(未提供,请从原文中判定属于本项目的部分)') . "\n\n"
            . "# 本项目接口摘要(来自拆解)\n" . ($interfaces !== '' ? $interfaces : '(无)') . "\n\n"
            . "# 需求拆解(含共享接口契约,唯一事实源)\n" . $breakdownContent . "\n\n"
            . "# 原始需求文档(已脱敏,供补全细节)\n" . $docContent . "\n";
    }
}
