<?php

namespace app\service\AiDev;

use think\facade\Db;

class TaskService
{
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
        $id = Db::name('ai_dev_tasks')->insertGetId([
            'requirement_id' => (int) $requirement['id'],
            'doc_version_id' => (int) $doc['id'],
            'scope_summary' => isset($item['scope_summary']) ? $item['scope_summary'] : '',
            'title' => $requirement['title'] . ' - ' . $project['name'],
            'project_id' => (int) $project['id'],
            'repo_name' => $project['name'],
            'base_branch' => $project['default_base_branch'],
            'branch_prefix' => $project['default_branch_prefix'],
            'branch_name' => '',
            'final_branch_name' => '',
            'status' => 'created',
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
        return $task;
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
        $this->updateStatus($id, 'terminated');
        return $this->detail($id);
    }
}
