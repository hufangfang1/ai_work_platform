<?php

namespace app\service\AiDev;

use think\facade\Db;

class TaskService
{
    const DEPENDENCY_READY_STATUSES = ['committed', 'retrospected'];

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
        $task['retrospective'] = Db::name('ai_dev_retrospectives')->where('task_id', $id)->order('created_at', 'desc')->find();
        $task['has_multi_project_breakdown'] = $this->hasMultiProjectBreakdown($task);
        $withDependencies = $this->attachDependenciesToTasks([$task], (int) $task['requirement_id']);
        $task = $withDependencies[0];
        return $task;
    }

    private function hasMultiProjectBreakdown(array $task)
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
