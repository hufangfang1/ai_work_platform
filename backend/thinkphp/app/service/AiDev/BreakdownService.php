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
        $missingDescriptions = [];
        foreach ($candidates as $candidate) {
            if (mb_strlen(trim((string) $candidate['description'])) < 40) {
                $missingDescriptions[] = (string) $candidate['name'];
            }
        }
        if ($missingDescriptions) {
            $preview = array_slice($missingDescriptions, 0, 8);
            $suffix = count($missingDescriptions) > count($preview)
                ? ' 等 ' . count($missingDescriptions) . ' 个项目'
                : '';
            throw new \RuntimeException(
                '以下候选项目缺少可用于职责判断的项目描述，请先在项目页生成或补充描述: '
                . implode('、', $preview) . $suffix
            );
        }

        $targetKey = 'requirement:' . (int) $requirementId;
        $this->assertNoRunningBreakdown($targetKey);
        return (new RunService())->enqueueGeneration(0, 'requirement_breakdown', [
            'operation' => 'requirement_breakdown',
            'requirement_id' => (int) $requirementId,
            'doc_version_id' => (int) $doc['id'],
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
        $expectedProjectIds = isset($payload['project_ids']) && is_array($payload['project_ids'])
            ? array_map('intval', $payload['project_ids'])
            : [];
        $this->assertValidBreakdown($markdown, $normalized, $expectedProjectIds);
        $breakdown = $this->saveVersion($requirementId, $markdown, $normalized, 'ai', $run['model_name']);
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
        }
        $this->assertValidBreakdown((string) $breakdown['content'], $items);

        $progressed = Db::name('ai_dev_tasks')
            ->where('requirement_id', (int) $requirementId)
            ->where('status', '<>', 'terminated')
            ->whereNotIn('status', ['created', 'branch_generated'])
            ->select()->toArray();
        if ($progressed) {
            $ids = array_map(function ($task) {
                return '#' . (int) $task['id'] . '(' . $task['status'] . ')';
            }, $progressed);
            throw new \RuntimeException(
                '已有工单进入计划或编码阶段，不能再确认新的拆解版本，以免下游上下文漂移: ' . implode('、', $ids)
            );
        }
        $requirement = Db::name('ai_dev_requirements')->where('id', $requirementId)->find();
        $doc = (new RequirementService())->latestDoc($requirementId);
        if (!$doc) {
            throw new \RuntimeException('需求文档快照缺失');
        }

        $taskService = new TaskService();
        $created = [];
        $specTaskIds = [];
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
                        'updated_at' => date('Y-m-d H:i:s'),
                    ];
                    $itemSpec = isset($item['spec_markdown']) ? trim((string) $item['spec_markdown']) : '';
                    if ($itemSpec !== '') {
                        $taskUpdate['spec_markdown'] = $itemSpec;
                    } elseif (count($items) > 1) {
                        // 新拆解版本改变了职责/契约，旧子文档不能继续作为下游输入。
                        $taskUpdate['spec_markdown'] = '';
                    }
                    if (!empty($requirement['final_branch_name'])) {
                        $taskUpdate['branch_name'] = isset($requirement['branch_name']) ? (string) $requirement['branch_name'] : '';
                        $taskUpdate['final_branch_name'] = (string) $requirement['final_branch_name'];
                        if ($exists['status'] === 'created') {
                            $taskUpdate['status'] = 'branch_generated';
                        }
                    }
                    Db::name('ai_dev_tasks')->where('id', $exists['id'])->update($taskUpdate);
                    $created[] = ['task_id' => $exists['id'], 'project_id' => (int) $item['project_id'], 'skipped' => true];
                    if (count($items) > 1 && $itemSpec === '') {
                        $specTaskIds[] = (int) $exists['id'];
                    }
                    continue;
                }
                $task = $taskService->createFromBreakdown($requirement, $doc, $item);
                $created[] = ['task_id' => $task['id'], 'project_id' => (int) $item['project_id'], 'skipped' => false];
                if (count($items) > 1 && trim((string) (isset($item['spec_markdown']) ? $item['spec_markdown'] : '')) === '') {
                    $specTaskIds[] = (int) $task['id'];
                }
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
        if ($specTaskIds) {
            $specService = new SpecService();
            foreach ($specTaskIds as $taskId) {
                try {
                    $run = $specService->generate($taskId);
                    foreach ($created as &$item) {
                        if ((int) $item['task_id'] === (int) $taskId) {
                            $item['spec_run_id'] = (int) $run['id'];
                            break;
                        }
                    }
                    unset($item);
                } catch (\Throwable $e) {
                    foreach ($created as &$item) {
                        if ((int) $item['task_id'] === (int) $taskId) {
                            $item['spec_error'] = $e->getMessage();
                            break;
                        }
                    }
                    unset($item);
                }
            }
        }
        return $created;
    }

    private function assertValidBreakdown($markdown, array $items, array $expectedProjectIds = [])
    {
        if (mb_strlen(trim((string) $markdown)) < 300) {
            throw new \RuntimeException('需求拆解内容过短，尚不足以支持项目分工');
        }
        foreach (['需求理解', '涉及项目与分工', '跨项目接口契约', '风险点'] as $section) {
            if (!preg_match('/^##\s*' . preg_quote($section, '/') . '\s*$/m', (string) $markdown)) {
                throw new \RuntimeException('需求拆解缺少必需章节: ## ' . $section);
            }
        }
        if (!$items) {
            throw new \RuntimeException('需求拆解没有项目条目');
        }

        $seen = [];
        $actualIds = [];
        $dependencyGraph = [];
        foreach ($items as $item) {
            $projectId = (int) (isset($item['project_id']) ? $item['project_id'] : 0);
            $projectName = trim((string) (isset($item['project_name']) ? $item['project_name'] : ''));
            if ($projectId <= 0 || $projectName === '' || !empty($item['unmatched'])) {
                throw new \RuntimeException('拆解项目未匹配到已配置项目: ' . ($projectName !== '' ? $projectName : '未知'));
            }
            if (isset($seen[$projectId])) {
                throw new \RuntimeException('拆解中项目重复: ' . $projectName);
            }
            $seen[$projectId] = true;
            $actualIds[] = $projectId;
            $role = trim((string) (isset($item['role']) ? $item['role'] : ''));
            if (!in_array($role, ['前端', '后端', '其他'], true)) {
                throw new \RuntimeException('项目角色必须是前端、后端或其他: ' . $projectName);
            }
            if (mb_strlen(trim((string) (isset($item['scope_summary']) ? $item['scope_summary'] : ''))) < 20) {
                throw new \RuntimeException('项目职责说明过短: ' . $projectName);
            }
            $stage = trim((string) (isset($item['dependency_stage']) ? $item['dependency_stage'] : 'none'));
            if (!in_array($stage, ['before_coding', 'none'], true)) {
                throw new \RuntimeException('项目依赖阶段不合法: ' . $projectName);
            }
            $dependencyNames = isset($item['depends_on_projects']) && is_array($item['depends_on_projects'])
                ? array_values(array_filter(array_map('strval', $item['depends_on_projects'])))
                : [];
            $dependencyIds = isset($item['depends_on_project_ids']) && is_array($item['depends_on_project_ids'])
                ? array_values(array_filter(array_map('intval', $item['depends_on_project_ids'])))
                : [];
            if (count($dependencyNames) !== count($dependencyIds)) {
                throw new \RuntimeException('项目依赖未匹配到已配置项目: ' . $projectName);
            }
            if ($dependencyIds && $stage !== 'before_coding') {
                throw new \RuntimeException('存在依赖的项目必须使用 before_coding: ' . $projectName);
            }
            $dependencyGraph[$projectId] = array_values(array_unique($dependencyIds));
        }
        foreach ($dependencyGraph as $projectId => $dependencyIds) {
            foreach ($dependencyIds as $dependencyId) {
                if (!isset($seen[$dependencyId])) {
                    throw new \RuntimeException('项目依赖未包含在本次拆解范围内: project#' . $projectId . ' -> project#' . $dependencyId);
                }
            }
        }
        $visiting = [];
        $visited = [];
        $visit = function ($projectId) use (&$visit, &$visiting, &$visited, $dependencyGraph) {
            if (isset($visited[$projectId])) {
                return;
            }
            if (isset($visiting[$projectId])) {
                throw new \RuntimeException('项目依赖存在循环，所有相关工单都会互相阻塞: project#' . $projectId);
            }
            $visiting[$projectId] = true;
            foreach (isset($dependencyGraph[$projectId]) ? $dependencyGraph[$projectId] : [] as $dependencyId) {
                $visit($dependencyId);
            }
            unset($visiting[$projectId]);
            $visited[$projectId] = true;
        };
        foreach (array_keys($dependencyGraph) as $projectId) {
            $visit($projectId);
        }

        if ($expectedProjectIds) {
            $expectedProjectIds = array_values(array_unique(array_filter(array_map('intval', $expectedProjectIds))));
            sort($expectedProjectIds);
            sort($actualIds);
            if ($actualIds !== $expectedProjectIds) {
                throw new \RuntimeException('AI 拆解结果与人工选择的项目范围不一致，请重试');
            }
        }
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
                . "只返回 JSON,结构:{\"breakdown_markdown\":\"...\",\"projects\":[{\"project_name\":\"...\",\"role\":\"前端|后端|其他\",\"scope_summary\":\"...\",\"interfaces\":\"...\",\"depends_on_projects\":[\"...\"],\"dependency_stage\":\"before_coding|none\",\"dependency_reason\":\"...\"}]}\n"
                . "所有 Markdown 字段必须是合法 JSON 字符串,换行使用 \\n 转义,不要输出 JSON 代码块或 JSON 外的文字。\n"
                . "breakdown_markdown 用中文 Markdown,包含:## 需求理解 / ## 涉及项目与分工 / ## 跨项目接口契约 / ## 风险点。\n"
                . "其中 ## 跨项目接口契约 是需求阶段的候选契约:写清接口路径、入参出参、字段口径、用户标识来源、谁读谁写;后续必须由提供方结合代码核验并确认。\n"
                . "## 跨项目接口契约 必须逐接口展开,每个接口至少包含:方法、路径、用途、调用方、提供方、鉴权要求、请求参数表(参数名/位置/类型/必填/默认值/说明)、响应字段表(字段路径/类型/可空/说明/来源口径)、空数据与失败状态。禁止只写接口名称或一句话摘要。\n"
                . "需求原文未明确且无法从当前输入确认的路径、字段和规则必须标注『待提供方代码核验』,禁止把猜测写成已确认事实;风险点要逐项列出歧义、缺失信息和确认责任人。\n"
                . "判定分工时,必须依据每个项目下方的 description(项目简介)界定该项目在本需求中的职责边界,只把属于它的部分划给它,不要展开任何一个项目的代码级实现细节。\n"
                . "role 依据项目简介判定:以页面/H5/PC 展示为主填『前端』,以接口/代理/数据/日志为主填『后端』,无法归类填『其他』。\n"
                . "必须显式输出项目依赖关系:前端项目若需要调用后端接口,depends_on_projects 必须填提供接口的后端 project_name,dependency_stage 填 before_coding,dependency_reason 写清依赖的接口/数据契约;后端接口提供方通常不依赖前端,填空数组和 none。依赖项目名必须从项目列表原样取。\n"
                . "projects 必须为下方**每一个**项目各输出一条,不得新增或遗漏;project_name 必须从列表原样取;"
                . "scope_summary 必须同时写清交付范围、不负责范围和可验证完成条件;interfaces 只写该项目提供或消费的接口摘要,没有则留空。\n"
                . "本阶段只做分工和共享契约,不要输出每项目完整需求子文档,不要写 spec_markdown;确认拆解后系统会按项目逐一生成子文档。\n\n"
                . "# 本需求涉及的项目(人工确认)\n";
        } else {
            $task = "你是研发负责人。阅读需求文档,从下方候选项目中判断本需求涉及哪些项目,并给出拆解。\n"
                . "只返回 JSON,结构:{\"breakdown_markdown\":\"...\",\"projects\":[{\"project_name\":\"...\",\"role\":\"前端|后端|其他\",\"scope_summary\":\"...\",\"interfaces\":\"...\",\"depends_on_projects\":[\"...\"],\"dependency_stage\":\"before_coding|none\",\"dependency_reason\":\"...\"}]}\n"
                . "所有 Markdown 字段必须是合法 JSON 字符串,换行使用 \\n 转义,不要输出 JSON 代码块或 JSON 外的文字。\n"
                . "breakdown_markdown 用中文 Markdown,包含:## 需求理解 / ## 涉及项目与分工 / ## 跨项目接口契约 / ## 风险点。\n"
                . "其中 ## 跨项目接口契约 是需求阶段的候选契约:写清接口路径、入参出参、字段口径、用户标识来源、谁读谁写;后续必须由提供方结合代码核验并确认。\n"
                . "## 跨项目接口契约 必须逐接口展开,每个接口至少包含:方法、路径、用途、调用方、提供方、鉴权要求、请求参数表(参数名/位置/类型/必填/默认值/说明)、响应字段表(字段路径/类型/可空/说明/来源口径)、空数据与失败状态。禁止只写接口名称或一句话摘要。\n"
                . "需求原文未明确且无法从当前输入确认的路径、字段和规则必须标注『待提供方代码核验』,禁止把猜测写成已确认事实;风险点要逐项列出歧义、缺失信息和确认责任人。\n"
                . "判定分工时,必须依据每个项目下方的 description(项目简介)界定该项目在本需求中的职责边界,只把属于它的部分划给它,不要展开任何一个项目的代码级实现细节。\n"
                . "role 依据项目简介判定:以页面/H5/PC 展示为主填『前端』,以接口/代理/数据/日志为主填『后端』,无法归类填『其他』。\n"
                . "必须显式输出项目依赖关系:前端项目若需要调用后端接口,depends_on_projects 必须填提供接口的后端 project_name,dependency_stage 填 before_coding,dependency_reason 写清依赖的接口/数据契约;后端接口提供方通常不依赖前端,填空数组和 none。依赖项目名必须从项目列表原样取。\n"
                . "projects 只列确实需要改动的项目;project_name 必须从候选列表原样取;scope_summary 必须同时写清交付范围、不负责范围和可验证完成条件;interfaces 只写该项目提供或消费的接口摘要,没有则留空。\n"
                . "本阶段只做分工和共享契约,不要输出每项目完整需求子文档,不要写 spec_markdown;确认拆解后系统会按项目逐一生成子文档。\n\n"
                . "# 候选项目\n";
        }
        return $task . implode("\n", $lines) . "\n\n"
            . "# 需求文档(已脱敏)\n" . $docContent . "\n";
    }
}
