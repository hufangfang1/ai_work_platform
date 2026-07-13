<?php

namespace app\service\AiDev;

use think\facade\Db;

class TaskService
{
    /** 上游依赖满足后即可开始本工单 AI 编码(自动 Review 通过即可,不要求已提交)。 */
    const DEPENDENCY_READY_STATUSES = [
        'review_passed',
        'ready_to_commit',
        'committing',
        'committed',
        'retrospected',
    ];

    public function query(array $filters)
    {
        $query = Db::name('ai_dev_tasks')->alias('t')
            ->leftJoin('ai_dev_projects p', 'p.id = t.project_id')
            ->leftJoin('ai_dev_requirements r', 'r.id = t.requirement_id')
            ->field('t.*, p.name as project_name, p.default_base_branch, p.default_branch_prefix, r.title as requirement_title');

        if (!empty($filters['status'])) {
            $query->where('t.status', $filters['status']);
        }
        if (!empty($filters['project_id'])) {
            $query->where('t.project_id', (int) $filters['project_id']);
        }
        if (!empty($filters['requirement_id'])) {
            $query->where('t.requirement_id', (int) $filters['requirement_id']);
        }
        if (isset($filters['submitted']) && $filters['submitted'] !== '') {
            $filters['submitted'] === '1' || $filters['submitted'] === true || $filters['submitted'] === 'true'
                ? $query->where('t.commit_hash', '<>', '')
                : $query->where('t.commit_hash', '');
        }

        return $query->order('t.updated_at', 'desc')->select()->toArray();
    }

    /**
     * 生成计划/编码/Review 时喂给 AI 的"本项目上下文"。
     * 多项目:本项目子文档 + 已确认拆解(含共享接口契约);单项目:原始需求文档全文。
     */
    public function projectContext(array $task)
    {
        $spec = isset($task['spec_markdown']) ? trim((string) $task['spec_markdown']) : '';
        if ($spec !== '') {
            $breakdown = Db::name('ai_dev_breakdowns')
                ->where('requirement_id', (int) $task['requirement_id'])
                ->order('version', 'desc')->find();
            $contract = $breakdown ? $this->extractSharedContract((string) $breakdown['content']) : '';
            return "# 本项目需求文档(按本项目职责拆解)\n" . $spec . "\n\n"
                . "# 共享接口契约\n" . $contract . "\n";
        }
        $doc = Db::name('ai_dev_requirement_docs')->where('id', (int) $task['doc_version_id'])->find();
        return "# 需求文档(已脱敏)\n" . ($doc ? (string) $doc['content'] : '') . "\n";
    }

    private function extractSharedContract($content)
    {
        $content = trim((string) $content);
        if ($content === '') {
            return '';
        }
        if (preg_match('/^##\s*跨项目接口(?:契约|约定)\s*\R([\s\S]*?)(?=^##\s|\z)/m', $content, $m)) {
            return "## 跨项目接口契约\n\n" . trim($m[1]) . "\n";
        }
        return $content;
    }

    /**
     * 生成计划时注入依赖项目的开发计划(优先已确认版本),供前端/下游消费上游接口契约。
     */
    public function dependencyPlansContext(array $task)
    {
        $withDependencies = $this->attachDependenciesToTasks([$task], (int) $task['requirement_id']);
        $task = $withDependencies ? $withDependencies[0] : $task;
        $dependencies = isset($task['dependencies']) && is_array($task['dependencies']) ? $task['dependencies'] : [];
        if (!$dependencies) {
            return '';
        }

        $sections = ["# 依赖项目开发计划(供接口消费参考)\n"];
        if (!empty($task['dependency_reason'])) {
            $sections[] = '本项目依赖说明: ' . trim((string) $task['dependency_reason']) . "\n";
        }
        $hasPlan = false;
        foreach ($dependencies as $dependency) {
            $dependencyTaskId = (int) (isset($dependency['task_id']) ? $dependency['task_id'] : 0);
            $projectName = isset($dependency['project_name']) ? trim((string) $dependency['project_name']) : '';
            if ($dependencyTaskId <= 0) {
                $sections[] = "## {$projectName}\n"
                    . "状态: 未找到对应工单,暂无可引用的开发计划。请先在需求下为该项目生成工单与计划。\n";
                continue;
            }

            $confirmedPlan = Db::name('ai_dev_plans')
                ->where('task_id', $dependencyTaskId)
                ->whereNotNull('confirmed_at')
                ->order('version', 'desc')
                ->find();
            $latestPlan = Db::name('ai_dev_plans')
                ->where('task_id', $dependencyTaskId)
                ->order('version', 'desc')
                ->find();
            $plan = $confirmedPlan ?: $latestPlan;
            if (!$plan || trim((string) $plan['plan_content']) === '') {
                $status = isset($dependency['status']) ? (string) $dependency['status'] : 'unknown';
                $sections[] = "## {$projectName}\n"
                    . "状态: {$status},尚未生成开发计划。前端接口消费只能暂依共享接口契约,待该项目计划确认后应重新生成本项目计划。\n";
                continue;
            }

            $hasPlan = true;
            $planLabel = $confirmedPlan
                ? '已确认开发计划 v' . (int) $confirmedPlan['version']
                : '最新计划草案 v' . (int) $plan['version'] . '(尚未确认,仅供参考)';
            $planContent = trim((string) $plan['plan_content']);
            $apiContract = $this->extractPlanSection($planContent, '接口契约');
            $sections[] = "## {$projectName} · {$planLabel}\n"
                . ($apiContract !== ''
                    ? "### 接口契约(摘自依赖项目开发计划)\n" . $apiContract . "\n"
                    : "### 开发计划全文(依赖项目未单独拆分接口契约章节)\n" . $planContent . "\n");
        }

        if (!$hasPlan) {
            $sections[] = "\n> 提示: 当前依赖项目都还没有可引用的开发计划。若本项目需要消费上游 API,请优先生成并确认依赖项目的开发计划,再重新生成本项目计划。\n";
        }
        return implode("\n", $sections) . "\n";
    }

    private function extractPlanSection($content, $heading)
    {
        $content = trim((string) $content);
        $heading = trim((string) $heading);
        if ($content === '' || $heading === '') {
            return '';
        }
        $pattern = '/^##\s*' . preg_quote($heading, '/') . '\s*\R([\s\S]*?)(?=^##\s|\z)/m';
        if (!preg_match($pattern, $content, $matches)) {
            return '';
        }
        return trim((string) $matches[1]);
    }

    /**
     * 从本项目需求文档中提取页面布局章节,供前端开发计划生成时强制保留。
     */
    public function specLayoutContext(array $task)
    {
        $spec = isset($task['spec_markdown']) ? trim((string) $task['spec_markdown']) : '';
        if ($spec === '') {
            return '';
        }
        $sections = ['页面布局与区域规格', 'PC 端布局', 'H5 端布局', '筛选、列表与详情交互'];
        $parts = ["# 本项目需求文档·页面布局(生成计划时必须原样展开为独立章节,不得省略)\n"];
        $hasSection = false;
        foreach ($sections as $heading) {
            $body = $this->extractPlanSection($spec, $heading);
            if ($body !== '') {
                $hasSection = true;
                $parts[] = "## {$heading}\n" . $body . "\n";
            }
        }
        return $hasSection ? implode("\n", $parts) . "\n" : '';
    }

    /**
     * 手动建单(需求详情页内追加工单),必须挂在需求下。
     */
    public function create(array $input)
    {
        $requirementId = isset($input['requirement_id']) ? (int) $input['requirement_id'] : 0;
        $requirement = Db::name('ai_dev_requirements')->where('id', $requirementId)->find();
        if (!$requirement) {
            throw new \RuntimeException('工单必须关联一个已存在的需求');
        }
        $project = Db::name('ai_dev_projects')->where('id', isset($input['project_id']) ? (int) $input['project_id'] : 0)->find();
        if (!$project) {
            throw new \RuntimeException('项目不存在');
        }
        $doc = (new RequirementService())->latestDoc($requirementId);
        if (!$doc) {
            throw new \RuntimeException('请先在需求下录入文档快照');
        }
        return $this->createFromBreakdown($requirement, $doc, [
            'project_id' => (int) $project['id'],
            'scope_summary' => isset($input['scope_summary']) ? $input['scope_summary'] : '',
        ]);
    }

    /**
     * 由需求拆解生成工单(BreakdownService::confirm 内调用)。
     */
    public function createFromBreakdown(array $requirement, array $doc, array $item)
    {
        $project = Db::name('ai_dev_projects')->where('id', (int) $item['project_id'])->find();
        if (!$project) {
            throw new \RuntimeException('项目不存在: id=' . $item['project_id']);
        }
        $requirementBranchName = isset($requirement['branch_name']) ? (string) $requirement['branch_name'] : '';
        $requirementFinalBranchName = isset($requirement['final_branch_name']) ? (string) $requirement['final_branch_name'] : '';
        $id = Db::name('ai_dev_tasks')->insertGetId([
            'requirement_id' => (int) $requirement['id'],
            'doc_version_id' => (int) $doc['id'],
            'scope_summary' => isset($item['scope_summary']) ? $item['scope_summary'] : '',
            'spec_markdown' => isset($item['spec_markdown']) ? trim((string) $item['spec_markdown']) : '',
            'title' => $requirement['title'] . ' - ' . $project['name'],
            'project_id' => (int) $project['id'],
            'repo_name' => $project['name'],
            'base_branch' => $project['default_base_branch'],
            'branch_prefix' => $project['default_branch_prefix'],
            'branch_name' => $requirementBranchName,
            'final_branch_name' => $requirementFinalBranchName,
            'status' => $requirementFinalBranchName !== '' ? 'branch_generated' : 'created',
            'created_by' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->detail($id);
    }

    public function detail($id)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $id)->find();
        if (!$task) {
            return null;
        }
        $task['project'] = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        $task['worktree'] = $task['project'] ? (new WorktreeService())->status($task['project'], $task) : null;
        $requirement = Db::name('ai_dev_requirements')->where('id', $task['requirement_id'])->find();
        $task['requirement'] = $requirement ? ['id' => $requirement['id'], 'title' => $requirement['title'], 'doc_url' => $requirement['doc_url']] : null;
        $doc = Db::name('ai_dev_requirement_docs')->where('id', $task['doc_version_id'])->find();
        $task['doc_content'] = $doc ? $doc['content'] : '';
        $task['doc_version'] = $doc ? (int) $doc['version'] : 0;
        $task['plans'] = Db::name('ai_dev_plans')->where('task_id', $id)->order('version', 'asc')->select()->toArray();
        $task['runs'] = Db::name('ai_dev_runs')->where('task_id', $id)->order('created_at', 'desc')->select()->toArray();
        $task['changes'] = Db::name('ai_dev_changes')->where('task_id', $id)->order('created_at', 'desc')->select()->toArray();
        $task['reviews'] = Db::name('ai_dev_reviews')->where('task_id', $id)->order('created_at', 'desc')->select()->toArray();
        $task['reviews'] = $this->enrichReviewResults((int) $id, $task['reviews']);
        $task['retrospective'] = Db::name('ai_dev_retrospectives')->where('task_id', $id)->order('created_at', 'desc')->find();
        $task['has_multi_project_breakdown'] = $this->hasMultiProjectBreakdown($task);
        $withDependencies = $this->attachDependenciesToTasks([$task], (int) $task['requirement_id']);
        $task = $withDependencies[0];
        return $task;
    }

    private function enrichReviewResults($taskId, array $reviews)
    {
        if (!$reviews) {
            return $reviews;
        }
        $reviewService = new ReviewService();
        $salvaged = null;
        foreach ($reviews as $index => $review) {
            $parsed = json_decode((string) $review['review_result'], true);
            if (!is_array($parsed)) {
                continue;
            }
            $normalized = [
                'source' => $parsed['source'] ?? 'ai_review',
                'status' => $parsed['status'] ?? $review['status'],
                'risk_level' => $parsed['risk_level'] ?? $review['risk_level'],
                'summary' => $parsed['summary'] ?? '',
                'blocking_issues' => isset($parsed['blocking_issues']) && is_array($parsed['blocking_issues']) ? $parsed['blocking_issues'] : [],
                'warnings' => isset($parsed['warnings']) && is_array($parsed['warnings']) ? $parsed['warnings'] : [],
                'suggestions' => isset($parsed['suggestions']) && is_array($parsed['suggestions']) ? $parsed['suggestions'] : [],
            ];
            $hasContent = trim((string) $normalized['summary']) !== ''
                || $normalized['blocking_issues']
                || $normalized['warnings']
                || $normalized['suggestions'];
            // 空壳 human_reject 合并上一次 AI/自动 Review,并回写库内记录
            if ($review['status'] === 'human_reject' && $index === 0) {
                $effective = $reviewService->latestEffectiveReviewResult($taskId);
                if ($effective) {
                    $json = json_encode($effective, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                    if ($json !== (string) $review['review_result']) {
                        $reviews[$index]['review_result'] = $json;
                        Db::name('ai_dev_reviews')->where('id', $review['id'])->update([
                            'review_result' => $json,
                            'risk_level' => $effective['risk_level'] ?? $review['risk_level'],
                        ]);
                    }
                    continue;
                }
            }
            if ($hasContent) {
                continue;
            }
            if ($salvaged === null) {
                $salvaged = $reviewService->latestEffectiveReviewResult($taskId);
            }
            if (!$salvaged) {
                continue;
            }
            $json = json_encode($salvaged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $reviews[$index]['review_result'] = $json;
            if ($index === 0) {
                Db::name('ai_dev_reviews')->where('id', $review['id'])->update([
                    'review_result' => $json,
                    'risk_level' => $salvaged['risk_level'],
                ]);
            }
        }
        return $reviews;
    }

    public function hasMultiProjectBreakdown(array $task)
    {
        $breakdown = Db::name('ai_dev_breakdowns')
            ->where('requirement_id', (int) $task['requirement_id'])
            ->whereNotNull('confirmed_at')
            ->order('version', 'desc')->find();
        if (!$breakdown) {
            return false;
        }
        $items = json_decode((string) $breakdown['projects_json'], true);
        return is_array($items) && count($items) > 1;
    }

    public function attachDependenciesToTasks(array $tasks, $requirementId)
    {
        if (!$tasks) {
            return [];
        }
        $dependencyMap = $this->dependencyMapForRequirement((int) $requirementId);
        $allTasks = Db::name('ai_dev_tasks')->alias('t')
            ->leftJoin('ai_dev_projects p', 'p.id = t.project_id')
            ->where('t.requirement_id', (int) $requirementId)
            ->where('t.status', '<>', 'terminated')
            ->field('t.id, t.project_id, t.status, p.name as project_name')
            ->select()->toArray();
        $taskByProjectId = [];
        foreach ($allTasks as $row) {
            $taskByProjectId[(int) $row['project_id']] = $row;
        }

        $dependentMap = [];
        foreach ($dependencyMap as $projectId => $meta) {
            foreach ($meta['depends_on_project_ids'] as $dependencyProjectId) {
                $dependentMap[$dependencyProjectId][] = (int) $projectId;
            }
        }

        foreach ($tasks as &$task) {
            $projectId = (int) (isset($task['project_id']) ? $task['project_id'] : 0);
            $meta = isset($dependencyMap[$projectId]) ? $dependencyMap[$projectId] : [
                'depends_on_project_ids' => [],
                'dependency_reason' => '',
                'dependency_stage' => 'none',
            ];
            $dependencies = [];
            foreach ($meta['depends_on_project_ids'] as $dependencyProjectId) {
                $dependencyTask = isset($taskByProjectId[$dependencyProjectId]) ? $taskByProjectId[$dependencyProjectId] : null;
                $dependencies[] = [
                    'project_id' => (int) $dependencyProjectId,
                    'project_name' => $dependencyTask ? $dependencyTask['project_name'] : '',
                    'task_id' => $dependencyTask ? (int) $dependencyTask['id'] : 0,
                    'status' => $dependencyTask ? $dependencyTask['status'] : 'missing',
                    'ready' => $dependencyTask ? in_array($dependencyTask['status'], self::DEPENDENCY_READY_STATUSES, true) : false,
                ];
            }
            $dependents = [];
            foreach (isset($dependentMap[$projectId]) ? $dependentMap[$projectId] : [] as $dependentProjectId) {
                $dependentTask = isset($taskByProjectId[$dependentProjectId]) ? $taskByProjectId[$dependentProjectId] : null;
                $dependents[] = [
                    'project_id' => (int) $dependentProjectId,
                    'project_name' => $dependentTask ? $dependentTask['project_name'] : '',
                    'task_id' => $dependentTask ? (int) $dependentTask['id'] : 0,
                    'status' => $dependentTask ? $dependentTask['status'] : 'missing',
                ];
            }
            $blocked = false;
            foreach ($dependencies as $dependency) {
                if (!$dependency['ready']) {
                    $blocked = true;
                    break;
                }
            }
            $task['dependencies'] = $dependencies;
            $task['dependents'] = $dependents;
            $task['dependency_reason'] = $meta['dependency_reason'];
            $task['dependency_stage'] = $meta['dependency_stage'];
            $task['dependency_blocked'] = $meta['dependency_stage'] === 'before_coding' && $blocked;
        }
        unset($task);
        return $tasks;
    }

    public function blockingDependencies(array $task)
    {
        $withDependencies = $this->attachDependenciesToTasks([$task], (int) $task['requirement_id']);
        $task = $withDependencies ? $withDependencies[0] : $task;
        $blocked = [];
        foreach (isset($task['dependencies']) ? $task['dependencies'] : [] as $dependency) {
            if (empty($dependency['ready'])) {
                $blocked[] = $dependency;
            }
        }
        return $blocked;
    }

    private function dependencyMapForRequirement($requirementId)
    {
        $breakdown = Db::name('ai_dev_breakdowns')
            ->where('requirement_id', (int) $requirementId)
            ->order('version', 'desc')->find();
        if (!$breakdown) {
            return [];
        }
        $items = json_decode((string) $breakdown['projects_json'], true);
        if (!is_array($items)) {
            return [];
        }
        $nameToId = [];
        $backendIds = [];
        foreach ($items as $item) {
            $projectId = (int) (isset($item['project_id']) ? $item['project_id'] : 0);
            $projectName = isset($item['project_name']) ? trim((string) $item['project_name']) : '';
            if ($projectId > 0 && $projectName !== '') {
                $nameToId[$projectName] = $projectId;
                if (mb_strpos((string) (isset($item['role']) ? $item['role'] : ''), '后端') !== false) {
                    $backendIds[] = $projectId;
                }
            }
        }

        $map = [];
        foreach ($items as $item) {
            $projectId = (int) (isset($item['project_id']) ? $item['project_id'] : 0);
            if ($projectId <= 0) {
                continue;
            }
            $ids = [];
            foreach (isset($item['depends_on_project_ids']) && is_array($item['depends_on_project_ids']) ? $item['depends_on_project_ids'] : [] as $id) {
                $id = (int) $id;
                if ($id > 0 && $id !== $projectId && !in_array($id, $ids, true)) {
                    $ids[] = $id;
                }
            }
            if (!$ids && !empty($item['depends_on_projects']) && is_array($item['depends_on_projects'])) {
                foreach ($item['depends_on_projects'] as $name) {
                    $name = trim((string) $name);
                    if (isset($nameToId[$name]) && $nameToId[$name] !== $projectId && !in_array($nameToId[$name], $ids, true)) {
                        $ids[] = $nameToId[$name];
                    }
                }
            }
            if (!$ids && mb_strpos((string) (isset($item['role']) ? $item['role'] : ''), '前端') !== false) {
                foreach ($backendIds as $backendId) {
                    if ($backendId !== $projectId && !in_array($backendId, $ids, true)) {
                        $ids[] = $backendId;
                    }
                }
            }
            $map[$projectId] = [
                'depends_on_project_ids' => $ids,
                'dependency_reason' => isset($item['dependency_reason']) && trim((string) $item['dependency_reason']) !== ''
                    ? (string) $item['dependency_reason']
                    : ($ids ? '依赖上游项目完成接口契约与实现后再进入编码/联调' : ''),
                'dependency_stage' => isset($item['dependency_stage']) && $item['dependency_stage'] !== '' ? (string) $item['dependency_stage'] : ($ids ? 'before_coding' : 'none'),
            ];
        }
        return $map;
    }

    public function update($id, array $input)
    {
        $allowed = [];
        foreach (['title', 'base_branch', 'branch_prefix', 'branch_name', 'final_branch_name', 'scope_summary', 'status'] as $field) {
            if (array_key_exists($field, $input)) {
                $allowed[$field] = $input[$field];
            }
        }
        if ($allowed) {
            $allowed['updated_at'] = date('Y-m-d H:i:s');
            Db::name('ai_dev_tasks')->where('id', $id)->update($allowed);
        }
        return $this->detail($id);
    }

    public function updateStatus($id, $status)
    {
        Db::name('ai_dev_tasks')->where('id', $id)->update([
            'status' => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function terminate($id)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $id)->find();
        if ($task) {
            $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
            if ($project) {
                try {
                    (new WorktreeService())->remove($project, $task, true);
                } catch (\Throwable $e) {
                    // 终止工单以状态关闭为主，worktree 清理失败时不阻断关闭流程。
                }
            }
        }
        $this->updateStatus($id, 'terminated');
        return $this->detail($id);
    }

    public function cleanupWorktree($id)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $id)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        if (!$project) {
            throw new \RuntimeException('项目不存在');
        }
        $removed = (new WorktreeService())->remove($project, $task, true);
        return [
            'removed' => $removed,
            'worktree' => (new WorktreeService())->status($project, $task),
        ];
    }
}
