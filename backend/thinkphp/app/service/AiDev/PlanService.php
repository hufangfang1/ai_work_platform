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
        if (!in_array($task['status'], ['branch_generated', 'plan_generated'], true)) {
            throw new \RuntimeException('只有已生成需求分支、且尚未进入编码的工单才能生成开发计划');
        }
        if (trim((string) $task['final_branch_name']) === '') {
            throw new \RuntimeException('请先在需求页生成并确认分支名');
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
        $role = $this->resolveProjectRole($task);
        $taskService = new TaskService();
        $this->assertReadyForPlan($task, $role, $taskService);
        $confirmedBreakdown = Db::name('ai_dev_breakdowns')
            ->where('requirement_id', (int) $task['requirement_id'])
            ->whereNotNull('confirmed_at')->order('version', 'desc')->find();
        return (new RunService())->enqueueGeneration((int) $taskId, 'task_plan', [
            'operation' => 'task_plan',
            'task_id' => (int) $taskId,
            'doc_version_id' => (int) $task['doc_version_id'],
            'breakdown_id' => $confirmedBreakdown ? (int) $confirmedBreakdown['id'] : 0,
            'spec_hash' => sha1((string) $task['spec_markdown']),
            'scope_hash' => sha1((string) $task['scope_summary']),
            'prompt' => $this->buildPrompt(
                $taskService->projectContext($task),
                $taskService->specLayoutContext($task),
                $taskService->dependencyPlansContext($task),
                $task['scope_summary'],
                $role,
                $project
            ),
            'options' => [
                'cwd' => $project['local_path'],
                'allowed_tools' => 'Read,Glob,Grep',
                'max_turns' => 25,
                'timeout' => (int) config('ai_dev.agent.plan_timeout', 1200),
            ],
        ], 'task:' . (int) $taskId, $model, $draft);
    }

    public function finishRun(array $run, array $data)
    {
        $taskId = (int) $run['task_id'];
        $content = isset($data['plan_markdown']) ? trim((string) $data['plan_markdown']) : '';
        if ($content === '') {
            throw new \RuntimeException('AI 未返回 plan_markdown');
        }
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || !in_array($task['status'], ['branch_generated', 'plan_generated'], true)) {
            throw new \RuntimeException('计划生成期间工单状态已变化，本次结果不再适用，请重新生成');
        }
        $this->assertValidPlanContent($content, $this->resolveProjectRole($task));
        return $this->saveVersion($taskId, $content, 'ai', $run['model_name']);
    }

    private function assertValidPlanContent($content, $role)
    {
        if (mb_strlen((string) $content) < 1000) {
            throw new \RuntimeException(
                '开发计划过短(' . mb_strlen($content) . ' 字)，不足以指导可靠编码，请重新生成或补充'
            );
        }
        if (strpos((string) $content, '⚠️ 模型输出超出最大长度被截断') !== false) {
            throw new \RuntimeException('开发计划内容被截断，不能确认执行');
        }

        if (mb_strpos((string) $role, '前端') !== false) {
            $required = ['需求理解', '页面布局与区域规格', 'PC 端布局', 'H5 端布局', '组件与文件映射', '接口消费与字段展示', '实施步骤', '验证计划', '风险点'];
            $forbidden = ['接口契约', 'SQL 变更'];
        } elseif (mb_strpos((string) $role, '后端') !== false) {
            $required = ['需求理解', '涉及模块与文件', '接口契约', '实施步骤', '验证计划', '风险点'];
            $forbidden = [];
        } else {
            $required = ['需求理解', '涉及模块与文件', '实施步骤', '验证计划', '风险点'];
            $forbidden = [];
        }
        foreach ($required as $section) {
            if (!preg_match('/^##\s*' . preg_quote($section, '/') . '\s*$/m', $content)) {
                throw new \RuntimeException('开发计划缺少必需章节: ## ' . $section . '，请补充后再确认');
            }
        }
        foreach ($forbidden as $section) {
            if (preg_match('/^##\s*' . preg_quote($section, '/') . '\s*$/m', $content)) {
                throw new \RuntimeException(
                    '前端开发计划误用了后端章节(## ' . $section . ')。'
                    . '前端应写布局与接口消费映射,接口定义请引用依赖项目开发计划,请重试'
                );
            }
        }
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

    private function assertReadyForPlan(array $task, $role, TaskService $taskService)
    {
        if ($taskService->hasMultiProjectBreakdown($task) && trim((string) (isset($task['spec_markdown']) ? $task['spec_markdown'] : '')) === '') {
            $runs = (new RunService())->listByTask((int) $task['id']);
            foreach ($runs as $run) {
                if ($run['run_type'] === 'task_spec' && in_array($run['status'], ['queued', 'running'], true)) {
                    throw new \RuntimeException('本项目需求文档正在生成,完成后再生成开发计划');
                }
            }
            throw new \RuntimeException('请先生成本项目需求文档,再生成开发计划');
        }

        if (mb_strpos((string) $role, '前端') === false) {
            return;
        }

        $withDependencies = $taskService->attachDependenciesToTasks([$task], (int) $task['requirement_id']);
        $task = $withDependencies ? $withDependencies[0] : $task;
        if ((isset($task['dependency_stage']) ? (string) $task['dependency_stage'] : 'none') !== 'before_coding') {
            return;
        }

        $missing = [];
        foreach (isset($task['dependencies']) && is_array($task['dependencies']) ? $task['dependencies'] : [] as $dependency) {
            $dependencyTaskId = (int) (isset($dependency['task_id']) ? $dependency['task_id'] : 0);
            $projectName = isset($dependency['project_name']) && $dependency['project_name'] !== ''
                ? (string) $dependency['project_name']
                : 'project#' . (int) (isset($dependency['project_id']) ? $dependency['project_id'] : 0);
            if ($dependencyTaskId <= 0 || !$this->hasConfirmedPlan($dependencyTaskId)) {
                $missing[] = $projectName;
            }
        }

        if ($missing) {
            throw new \RuntimeException(
                '前端开发计划需要引用上游接口契约,请先生成并确认依赖工单开发计划: ' . implode('、', $missing)
            );
        }
    }

    private function hasConfirmedPlan($taskId)
    {
        return (bool) Db::name('ai_dev_plans')
            ->where('task_id', (int) $taskId)
            ->whereNotNull('confirmed_at')
            ->find();
    }

    public function saveHumanVersion($taskId, $content)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || !in_array($task['status'], ['branch_generated', 'plan_generated'], true)) {
            throw new \RuntimeException('当前阶段不能修改开发计划');
        }
        if (trim((string) $content) === '') {
            throw new \RuntimeException('开发计划不能为空');
        }
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
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        if ($task['status'] !== 'plan_generated') {
            throw new \RuntimeException('只有待确认的最新计划才能确认');
        }
        $this->assertValidPlanContent((string) $plan['plan_content'], $this->resolveProjectRole($task));
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

    private function resolveProjectRole(array $task)
    {
        $breakdown = Db::name('ai_dev_breakdowns')
            ->where('requirement_id', (int) $task['requirement_id'])
            ->whereNotNull('confirmed_at')
            ->order('version', 'desc')->find();
        if (!$breakdown) {
            return '其他';
        }
        $items = json_decode((string) $breakdown['projects_json'], true) ?: [];
        foreach ($items as $item) {
            if ((int) (isset($item['project_id']) ? $item['project_id'] : 0) === (int) $task['project_id']) {
                return isset($item['role']) && trim((string) $item['role']) !== '' ? trim((string) $item['role']) : '其他';
            }
        }
        return '其他';
    }

    private function buildPrompt($projectContext, $layoutContext, $dependencyContext, $scopeSummary, $role, array $project)
    {
        $projectName = isset($project['name']) ? (string) $project['name'] : '';
        $layoutBlock = trim((string) $layoutContext) !== '' ? $layoutContext . "\n\n" : '';
        $dependencyBlock = trim((string) $dependencyContext) !== '' ? $dependencyContext . "\n\n" : '';
        $common = "你在该项目代码库根目录,可用 Read/Glob/Grep 阅读代码(禁止修改任何文件)。"
            . "为以下需求产出本项目的开发计划。"
            . "最终回复必须且只能是一个 JSON 对象,结构:{\"plan_markdown\":\"...\"};"
            . "禁止在 JSON 前后输出任何说明、总结或过渡句(例如『我已经收集了足够的信息』『现在生成开发计划』)。\n"
            . "JSON 格式要求:plan_markdown 的值必须是合法 JSON 字符串;换行写成 \\n,双引号写成 \\\",反引号无需转义;"
            . "正文里的中文引号请用「」,不要用英文双引号,否则会破坏 JSON。\n"
            . "计划必须先读取仓库再落笔,引用真实存在的文件路径、类/函数/路由/组件等符号;拟新增文件必须明确标注『新增』,不能把猜测写成现状。\n"
            . "每个实施步骤必须写清:对应的需求/验收编号(UI-xx、API-xx、AC-xx,若上游未编号则引用原文标题)、修改或新增的文件、目标符号、具体改动、验收方式。\n"
            . "先说明现有实现与需求之间的差距,再给修改步骤;不要只复述需求,不要只给文件清单,不要给无法执行的泛化建议。\n"
            . "需求或代码无法确认的信息必须列入风险点并说明需要谁确认,禁止自行补造接口、字段或业务规则。\n";

        if (mb_strpos((string) $role, '前端') !== false) {
            return $common
                . "本项目是前端项目({$projectName}),计划结构固定且仅允许以下章节(按顺序输出,不得增删改名):\n"
                . "## 需求理解 / ## 页面布局与区域规格 / ## PC 端布局 / ## H5 端布局 / ## 组件与文件映射 / ## 接口消费与字段展示 / ## 实施步骤 / ## 配置变更 / ## 验证计划 / ## 风险点\n"
                . "严禁出现以下后端章节: ## 涉及模块与文件 / ## 接口契约 / ## SQL 变更。\n"
                . "前端计划的核心是页面怎么摆、区域怎么分、PC/H5 各长什么样,不是后端接口设计。\n"
                . "下方已提供『本项目需求文档·页面布局』,你必须将其展开写入 ## 页面布局与区域规格 / ## PC 端布局 / ## H5 端布局,"
                . "可结合代码库补充组件与文件映射,但不得用文件清单替代布局描述。\n"
                . "`## 组件与文件映射` 把布局章节里的区域映射到具体 Vue 组件和文件路径,说明每个组件负责哪块 UI。\n"
                . "`## 接口消费与字段展示` 必须基于下方『依赖项目开发计划』中摘录的接口契约来写:"
                . "逐区域/组件说明调用哪个接口、读取哪些字段、分页/排序/筛选如何透传、null/unknown/error 如何展示。\n"
                . "接口路径、入参、出参、字段口径必须以依赖项目已确认开发计划为首要事实源;"
                . "若依赖项目只有计划草案则标注为草案并写入风险点;若依赖项目尚无计划则只能暂依共享接口契约,并在风险点标为阻塞项。\n"
                . "禁止自行发明依赖项目未声明的接口、字段或返回结构;也不要在本节重复抄写完整 API 大表,应引用依赖计划并做组件级映射。\n"
                . "禁止在本计划中定义 SQL、表结构、缓存刷新、后端聚合口径或替后端设计新接口。\n"
                . "实施步骤必须围绕页面/组件/路由/状态管理/响应式布局展开,不要写成后端接口开发教程。\n\n"
                . $layoutBlock
                . $projectContext . "\n\n"
                . $dependencyBlock
                . "# 本项目职责(来自需求拆解)\n" . ($scopeSummary !== '' ? $scopeSummary : '整个需求都在本项目内实现') . "\n";
        }

        if (mb_strpos((string) $role, '后端') !== false) {
            return $common
                . "本项目是后端项目({$projectName}),计划结构固定为:\n"
                . "## 需求理解 / ## 涉及模块与文件 / ## 接口契约 / ## 实施步骤 / ## 配置变更 / ## SQL 变更 / ## 验证计划 / ## 风险点\n"
                . "如果本项目需要提供 HTTP/API 接口,`## 接口契约` 必须逐接口写清楚,不能只写接口名称。\n"
                . "每个接口必须包含:方法、完整路径、用途、鉴权/权限要求、请求参数表(参数名/位置 query|path|body|header/类型/必填/默认值/说明/校验规则)、响应字段表(字段路径/类型/可空/说明/来源或计算口径)、空数据响应、错误码或失败状态、分页/排序规则、示例请求和示例响应。\n"
                . "还必须说明每个响应字段来自哪张表、哪段缓存或哪个上游接口;未知/未汇总字段如何返回 null、unknown、requires_summary 或 failed。\n"
                . "禁止展开页面布局、组件状态或前端交互实现。\n"
                . "如果某个接口的入参/出参仍无法从需求或代码确认,必须在 `## 风险点` 标为阻塞项,不要用模糊占位替代。\n\n"
                . $projectContext . "\n\n"
                . $dependencyBlock
                . "# 本项目职责(来自需求拆解)\n" . ($scopeSummary !== '' ? $scopeSummary : '整个需求都在本项目内实现') . "\n";
        }

        return $common
            . "计划结构固定为:\n"
            . "## 需求理解 / ## 涉及模块与文件 / ## 接口契约 / ## 实施步骤 / ## 配置变更 / ## SQL 变更 / ## 验证计划 / ## 风险点\n"
            . "如果本项目需要提供或消费 HTTP/API 接口,`## 接口契约` 必须逐接口写清楚,不能只写接口名称。\n"
            . "每个接口必须包含:方法、完整路径、用途、鉴权/权限要求、请求参数表、响应字段表、空数据响应、错误码或失败状态、分页/排序规则、示例请求和示例响应。\n"
            . "若下方提供了依赖项目开发计划,消费上游接口时必须与其接口契约保持一致。\n"
            . "如果某个接口的入参/出参仍无法从需求或代码确认,必须在 `## 风险点` 标为阻塞项,不要用模糊占位替代。\n\n"
            . $projectContext . "\n\n"
            . $dependencyBlock
            . "# 本项目职责(来自需求拆解)\n" . ($scopeSummary !== '' ? $scopeSummary : '整个需求都在本项目内实现') . "\n";
    }
}
