<?php

namespace app\service\AiDev;

use think\facade\Db;

class BreakdownService
{
    public function generate($requirementId, array $projectIds = [], $model = '', $draft = false)
    {
        $requirement = Db::name('ai_dev_requirements')->where('id', $requirementId)->find();
        if (!$requirement) {
            throw new \RuntimeException('需求不存在');
        }
        $doc = (new RequirementService())->latestDoc($requirementId);
        if (!$doc || trim((string) $doc['content']) === '') {
            throw new \RuntimeException('请先录入需求文档快照');
        }
        $projects = Db::name('ai_dev_projects')->where('status', 1)->select()->toArray();
        if (!$projects) {
            throw new \RuntimeException('请先在项目页添加至少一个项目');
        }

        // 人工指定涉及项目时,只在所选范围内拆解;留空则由 AI 从全部候选中判断。
        $manual = !empty($projectIds);
        $candidates = $projects;
        if ($manual) {
            $wanted = array_map('intval', $projectIds);
            $candidates = array_values(array_filter($projects, function ($p) use ($wanted) {
                return in_array((int) $p['id'], $wanted, true);
            }));
            if (!$candidates) {
                throw new \RuntimeException('所选项目无效,请重新选择');
            }
        }

        $targetKey = 'requirement:' . (int) $requirementId;
        $this->assertNoRunningBreakdown($targetKey);
        return (new RunService())->enqueueGeneration(0, 'requirement_breakdown', [
            'operation' => 'requirement_breakdown',
            'requirement_id' => (int) $requirementId,
            'project_ids' => array_map('intval', $projectIds),
            'prompt' => $this->buildPrompt($doc['content'], $candidates, $manual),
            'options' => [
                'timeout' => 300,
                'max_turns' => 3,
            ],
        ], $targetKey, $model, $draft);
    }

    public function finishRun(array $run, array $result)
    {
        $payload = json_decode((string) $run['input'], true);
        if (!is_array($payload) || empty($payload['requirement_id'])) {
            throw new \RuntimeException('需求拆解 run 缺少 requirement_id');
        }
        $requirementId = (int) $payload['requirement_id'];
        $projects = Db::name('ai_dev_projects')->where('status', 1)->select()->toArray();
        $markdown = isset($result['breakdown_markdown']) ? $result['breakdown_markdown'] : '';
        $items = isset($result['projects']) && is_array($result['projects']) ? $result['projects'] : [];
        if ($markdown === '' || !$items) {
            throw new \RuntimeException('拆解结果不完整,请重试');
        }
        $nameMap = [];
        foreach ($projects as $project) {
            $nameMap[$project['name']] = (int) $project['id'];
        }
        $backendNames = [];
        foreach ($items as $item) {
            $role = isset($item['role']) ? (string) $item['role'] : '';
            $projectName = isset($item['project_name']) ? trim((string) $item['project_name']) : '';
            if ($projectName !== '' && mb_strpos($role, '后端') !== false) {
                $backendNames[] = $projectName;
            }
        }

        $normalized = [];
        foreach ($items as $item) {
            $projectName = isset($item['project_name']) ? trim($item['project_name']) : '';
            $role = isset($item['role']) ? trim($item['role']) : '其他';
            $specMarkdown = isset($item['spec_markdown']) ? trim((string) $item['spec_markdown']) : '';
            if ($specMarkdown === '') {
                throw new \RuntimeException('拆解结果缺少本项目需求文档: ' . ($projectName !== '' ? $projectName : '未知项目'));
            }
            $dependencyNames = $this->normalizeDependencyNames($item, $projectName, $role, $backendNames);
            $dependencyIds = [];
            foreach ($dependencyNames as $name) {
                if (isset($nameMap[$name])) {
                    $dependencyIds[] = $nameMap[$name];
                }
            }
            $normalized[] = [
                'project_id' => isset($nameMap[$projectName]) ? $nameMap[$projectName] : 0,
                'project_name' => $projectName,
                'role' => $role,
                'scope_summary' => isset($item['scope_summary']) ? $item['scope_summary'] : '',
                'spec_markdown' => $specMarkdown,
                'interfaces' => isset($item['interfaces']) ? $item['interfaces'] : '',
                'depends_on_projects' => $dependencyNames,
                'depends_on_project_ids' => $dependencyIds,
                'dependency_reason' => isset($item['dependency_reason']) && trim((string) $item['dependency_reason']) !== ''
                    ? trim((string) $item['dependency_reason'])
                    : ($dependencyNames ? '依赖上游项目完成接口契约与实现后再进入编码/联调' : ''),
                'dependency_stage' => isset($item['dependency_stage']) && trim((string) $item['dependency_stage']) !== ''
                    ? trim((string) $item['dependency_stage'])
                    : ($dependencyNames ? 'before_coding' : 'none'),
                'unmatched' => !isset($nameMap[$projectName]),
            ];
        }
        $breakdown = $this->saveVersion($requirementId, $markdown, $normalized, 'ai', $run['model_name']);
        $this->syncExistingTasksFromBreakdown($requirementId, $normalized);
        return $breakdown;
    }

    private function assertNoRunningBreakdown($targetKey)
    {
        $runs = (new RunService())->listByTarget($targetKey, ['requirement_breakdown']);
        foreach ($runs as $run) {
            if (in_array($run['status'], ['queued', 'running'], true)) {
                throw new \RuntimeException('已有需求拆解任务正在运行');
            }
        }
    }

    public function saveHuman($requirementId, $content, $projectsJson)
    {
        $items = is_array($projectsJson) ? $projectsJson : json_decode((string) $projectsJson, true);
        if (!is_array($items)) {
            throw new \RuntimeException('projects_json 格式不合法');
        }
        return $this->saveVersion($requirementId, $content, $items, 'human', '');
    }

    public function confirm($requirementId)
    {
        $breakdown = Db::name('ai_dev_breakdowns')
            ->where('requirement_id', $requirementId)->order('version', 'desc')->find();
        if (!$breakdown) {
            throw new \RuntimeException('没有可确认的拆解');
        }
        if ($breakdown['confirmed_at']) {
            throw new \RuntimeException('该拆解版本已确认');
        }
        $items = json_decode((string) $breakdown['projects_json'], true);
        if (!is_array($items) || !$items) {
            throw new \RuntimeException('拆解中没有项目条目');
        }
        foreach ($items as $item) {
            if (empty($item['project_id'])) {
                throw new \RuntimeException('存在未匹配到已配置项目的条目,请先编辑修正: ' . (isset($item['project_name']) ? $item['project_name'] : '未知'));
            }
            if (trim((string) (isset($item['spec_markdown']) ? $item['spec_markdown'] : '')) === '') {
                throw new \RuntimeException('拆解版本缺少本项目需求文档,请重新拆解需求后再确认: ' . (isset($item['project_name']) ? $item['project_name'] : '未知'));
            }
        }
        $requirement = Db::name('ai_dev_requirements')->where('id', $requirementId)->find();
        $doc = (new RequirementService())->latestDoc($requirementId);
        if (!$doc) {
            throw new \RuntimeException('需求文档快照缺失');
        }

        $taskService = new TaskService();
        $created = [];
        Db::startTrans();
        try {
            Db::name('ai_dev_breakdowns')->where('id', $breakdown['id'])->update([
                'confirmed_by' => 0,
                'confirmed_at' => date('Y-m-d H:i:s'),
            ]);
            foreach ($items as $item) {
                $exists = Db::name('ai_dev_tasks')
                    ->where('requirement_id', $requirementId)
                    ->where('project_id', (int) $item['project_id'])
                    ->where('status', '<>', 'terminated')
                    ->find();
                if ($exists) {
                    $taskUpdate = [
                        'doc_version_id' => (int) $doc['id'],
                        'scope_summary' => isset($item['scope_summary']) ? $item['scope_summary'] : '',
                        'spec_markdown' => isset($item['spec_markdown']) ? trim((string) $item['spec_markdown']) : '',
                        'updated_at' => date('Y-m-d H:i:s'),
                    ];
                    if (!empty($requirement['final_branch_name'])) {
                        $taskUpdate['branch_name'] = isset($requirement['branch_name']) ? (string) $requirement['branch_name'] : '';
                        $taskUpdate['final_branch_name'] = (string) $requirement['final_branch_name'];
                        if ($exists['status'] === 'created') {
                            $taskUpdate['status'] = 'branch_generated';
                        }
                    }
                    Db::name('ai_dev_tasks')->where('id', $exists['id'])->update($taskUpdate);
                    $created[] = ['task_id' => $exists['id'], 'project_id' => (int) $item['project_id'], 'skipped' => true];
                    continue;
                }
                $task = $taskService->createFromBreakdown($requirement, $doc, $item);
                $created[] = ['task_id' => $task['id'], 'project_id' => (int) $item['project_id'], 'skipped' => false];
            }
            Db::name('ai_dev_requirements')->where('id', $requirementId)->update([
                'status' => 'active',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return $created;
    }

    private function saveVersion($requirementId, $content, array $items, $source, $modelName)
    {
        $version = (int) Db::name('ai_dev_breakdowns')->where('requirement_id', $requirementId)->max('version') + 1;
        $id = Db::name('ai_dev_breakdowns')->insertGetId([
            'requirement_id' => $requirementId,
            'version' => $version,
            'content' => $content,
            'projects_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'source' => $source,
            'model_name' => $modelName,
            'confirmed_by' => 0,
            'confirmed_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Db::name('ai_dev_requirements')->where('id', $requirementId)->update([
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::name('ai_dev_breakdowns')->where('id', $id)->find();
    }

    /**
     * 重新拆解已有需求时,拆解完成即把每项目需求文档同步到下方工单卡片。
     * 确认拆解仍负责创建缺失工单和锁定版本。
     */
    private function syncExistingTasksFromBreakdown($requirementId, array $items)
    {
        $doc = (new RequirementService())->latestDoc($requirementId);
        if (!$doc) {
            return;
        }
        foreach ($items as $item) {
            $projectId = isset($item['project_id']) ? (int) $item['project_id'] : 0;
            if ($projectId <= 0) {
                continue;
            }
            $task = Db::name('ai_dev_tasks')
                ->where('requirement_id', (int) $requirementId)
                ->where('project_id', $projectId)
                ->where('status', '<>', 'terminated')
                ->find();
            if (!$task) {
                continue;
            }
            Db::name('ai_dev_tasks')->where('id', (int) $task['id'])->update([
                'doc_version_id' => (int) $doc['id'],
                'scope_summary' => isset($item['scope_summary']) ? (string) $item['scope_summary'] : '',
                'spec_markdown' => isset($item['spec_markdown']) ? trim((string) $item['spec_markdown']) : '',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
    }

    private function normalizeDependencyNames(array $item, $projectName, $role, array $backendNames)
    {
        $names = [];
        foreach (['depends_on_projects', 'depends_on_project_names', 'dependencies'] as $field) {
            if (empty($item[$field]) || !is_array($item[$field])) {
                continue;
            }
            foreach ($item[$field] as $dependency) {
                if (is_array($dependency)) {
                    $name = isset($dependency['project_name']) ? $dependency['project_name'] : (isset($dependency['name']) ? $dependency['name'] : '');
                } else {
                    $name = $dependency;
                }
                $name = trim((string) $name);
                if ($name !== '') {
                    $names[] = $name;
                }
            }
        }
        if (!$names && mb_strpos((string) $role, '前端') !== false) {
            $names = $backendNames;
        }
        $normalized = [];
        foreach ($names as $name) {
            if ($name === '' || $name === $projectName || in_array($name, $normalized, true)) {
                continue;
            }
            $normalized[] = $name;
        }
        return $normalized;
    }

    private function buildPrompt($docContent, array $projects, $manual = false)
    {
        $lines = [];
        foreach ($projects as $project) {
            $lines[] = '- name: ' . $project['name']
                . ' | description: ' . ($project['description'] !== '' ? $project['description'] : '无')
                . ' | repo: ' . $project['repo_url'];
        }
        if ($manual) {
            $task = "你是研发负责人。阅读需求文档,下方项目已由人工确认为本需求涉及的项目。\n"
                . "只返回 JSON,结构:{\"breakdown_markdown\":\"...\",\"projects\":[{\"project_name\":\"...\",\"role\":\"前端|后端|其他\",\"scope_summary\":\"...\",\"spec_markdown\":\"...\",\"interfaces\":\"...\",\"depends_on_projects\":[\"...\"],\"dependency_stage\":\"before_coding|none\",\"dependency_reason\":\"...\"}]}\n"
                . "所有 Markdown 字段必须是合法 JSON 字符串,换行使用 \\n 转义,不要输出 JSON 代码块或 JSON 外的文字。\n"
                . "breakdown_markdown 用中文 Markdown,包含:## 需求理解 / ## 涉及项目与分工 / ## 跨项目接口契约 / ## 风险点。\n"
                . "其中 ## 跨项目接口契约 必须独立且完整:写清接口路径、入参出参、字段口径、用户标识来源、谁读谁写;它是后续各项目子文档共享的唯一事实源。\n"
                . "## 跨项目接口契约 必须逐接口展开,每个接口至少包含:方法、路径、用途、调用方、提供方、鉴权要求、请求参数表(参数名/位置/类型/必填/默认值/说明)、响应字段表(字段路径/类型/可空/说明/来源口径)、空数据与失败状态。禁止只写接口名称或一句话摘要。\n"
                . "判定分工时,必须依据每个项目下方的 description(项目简介)界定该项目在本需求中的职责边界,只把属于它的部分划给它,不要展开任何一个项目的代码级实现细节。\n"
                . "role 依据项目简介判定:以页面/H5/PC 展示为主填『前端』,以接口/代理/数据/日志为主填『后端』,无法归类填『其他』。\n"
                . "必须显式输出项目依赖关系:前端项目若需要调用后端接口,depends_on_projects 必须填提供接口的后端 project_name,dependency_stage 填 before_coding,dependency_reason 写清依赖的接口/数据契约;后端接口提供方通常不依赖前端,填空数组和 none。依赖项目名必须从项目列表原样取。\n"
                . "projects 必须为下方**每一个**项目各输出一条,不得新增或遗漏;project_name 必须从列表原样取;"
                . "scope_summary 用 1 段话说明该项目在本需求中要做什么;spec_markdown 是该项目可直接落地的本项目需求文档;interfaces 说明与其他项目的接口约定,没有则留空。\n"
                . "不同 role 的 spec_markdown 必须使用不同章节骨架,不要让前端和后端文档看起来像同一份模板。\n"
                . "后端项目 spec_markdown 第一行必须是 `# {project_name} 后端需求文档`,章节必须围绕:## 后端交付目标 / ## API 接口范围 / ## 数据来源与聚合口径 / ## 汇总缓存与刷新策略 / ## 权限、脱敏与审计 / ## 异常状态与降级 / ## 后端验收标准。\n"
                . "前端项目 spec_markdown 第一行必须是 `# {project_name} 前端需求文档`,章节必须围绕:## 页面与入口 / ## PC 信息架构 / ## H5 降级体验 / ## 筛选、列表与详情交互 / ## 接口消费与字段展示 / ## 空态、错误态与脱敏 / ## 前端验收标准。\n"
                . "前端项目只写页面/模块范围、PC/H5 信息层级、交互流程、展示字段、脱敏和验收;禁止定义 SQL、表结构、缓存刷新或后端实现。后端项目只写接口、数据口径、日志快照、缓存策略、权限安全和验收;禁止展开页面布局、组件状态或前端交互实现。\n"
                . "spec_markdown 不要复制原文或共享契约全文,跨项目 API 完整 schema 统一引用『见共享接口契约』。\n\n"
                . "# 本需求涉及的项目(人工确认)\n";
        } else {
            $task = "你是研发负责人。阅读需求文档,从下方候选项目中判断本需求涉及哪些项目,并给出拆解。\n"
                . "只返回 JSON,结构:{\"breakdown_markdown\":\"...\",\"projects\":[{\"project_name\":\"...\",\"role\":\"前端|后端|其他\",\"scope_summary\":\"...\",\"spec_markdown\":\"...\",\"interfaces\":\"...\",\"depends_on_projects\":[\"...\"],\"dependency_stage\":\"before_coding|none\",\"dependency_reason\":\"...\"}]}\n"
                . "所有 Markdown 字段必须是合法 JSON 字符串,换行使用 \\n 转义,不要输出 JSON 代码块或 JSON 外的文字。\n"
                . "breakdown_markdown 用中文 Markdown,包含:## 需求理解 / ## 涉及项目与分工 / ## 跨项目接口契约 / ## 风险点。\n"
                . "其中 ## 跨项目接口契约 必须独立且完整:写清接口路径、入参出参、字段口径、用户标识来源、谁读谁写;它是后续各项目子文档共享的唯一事实源。\n"
                . "## 跨项目接口契约 必须逐接口展开,每个接口至少包含:方法、路径、用途、调用方、提供方、鉴权要求、请求参数表(参数名/位置/类型/必填/默认值/说明)、响应字段表(字段路径/类型/可空/说明/来源口径)、空数据与失败状态。禁止只写接口名称或一句话摘要。\n"
                . "判定分工时,必须依据每个项目下方的 description(项目简介)界定该项目在本需求中的职责边界,只把属于它的部分划给它,不要展开任何一个项目的代码级实现细节。\n"
                . "role 依据项目简介判定:以页面/H5/PC 展示为主填『前端』,以接口/代理/数据/日志为主填『后端』,无法归类填『其他』。\n"
                . "必须显式输出项目依赖关系:前端项目若需要调用后端接口,depends_on_projects 必须填提供接口的后端 project_name,dependency_stage 填 before_coding,dependency_reason 写清依赖的接口/数据契约;后端接口提供方通常不依赖前端,填空数组和 none。依赖项目名必须从项目列表原样取。\n"
                . "projects 只列确实需要改动的项目;project_name 必须从候选列表原样取;scope_summary 用 1 段话说明该项目要做什么;spec_markdown 是该项目可直接落地的本项目需求文档;interfaces 说明与其他项目的接口约定,没有则留空。\n"
                . "不同 role 的 spec_markdown 必须使用不同章节骨架,不要让前端和后端文档看起来像同一份模板。\n"
                . "后端项目 spec_markdown 第一行必须是 `# {project_name} 后端需求文档`,章节必须围绕:## 后端交付目标 / ## API 接口范围 / ## 数据来源与聚合口径 / ## 汇总缓存与刷新策略 / ## 权限、脱敏与审计 / ## 异常状态与降级 / ## 后端验收标准。\n"
                . "前端项目 spec_markdown 第一行必须是 `# {project_name} 前端需求文档`,章节必须围绕:## 页面与入口 / ## PC 信息架构 / ## H5 降级体验 / ## 筛选、列表与详情交互 / ## 接口消费与字段展示 / ## 空态、错误态与脱敏 / ## 前端验收标准。\n"
                . "前端项目只写页面/模块范围、PC/H5 信息层级、交互流程、展示字段、脱敏和验收;禁止定义 SQL、表结构、缓存刷新或后端实现。后端项目只写接口、数据口径、日志快照、缓存策略、权限安全和验收;禁止展开页面布局、组件状态或前端交互实现。\n"
                . "spec_markdown 不要复制原文或共享契约全文,跨项目 API 完整 schema 统一引用『见共享接口契约』。\n\n"
                . "# 候选项目\n";
        }
        return $task . implode("\n", $lines) . "\n\n"
            . "# 需求文档(已脱敏)\n" . $docContent . "\n";
    }
}
