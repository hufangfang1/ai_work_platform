<?php

namespace app\service\AiDev;

use think\facade\Db;

class RequirementService
{
    const DONE_STATUSES = ['committed', 'retrospected'];

    public function create(array $input)
    {
        $title = isset($input['title']) ? trim($input['title']) : '';
        if ($title === '') {
            throw new \RuntimeException('需求标题不能为空');
        }
        $id = Db::name('ai_dev_requirements')->insertGetId([
            'title' => $title,
            'doc_url' => isset($input['doc_url']) ? $input['doc_url'] : '',
            'status' => 'draft',
            'created_by' => isset($input['created_by']) ? (int) $input['created_by'] : 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->detail($id);
    }

    public function query()
    {
        $requirements = Db::name('ai_dev_requirements')->order('updated_at', 'desc')->select()->toArray();
        if (!$requirements) {
            return [];
        }
        $ids = array_column($requirements, 'id');
        $tasks = Db::name('ai_dev_tasks')->alias('t')
            ->leftJoin('ai_dev_projects p', 'p.id = t.project_id')
            ->whereIn('t.requirement_id', $ids)
            ->field('t.id, t.requirement_id, t.status, p.name as project_name')
            ->select()->toArray();
        $docVersions = Db::name('ai_dev_requirement_docs')
            ->whereIn('requirement_id', $ids)
            ->field('requirement_id, MAX(version) as latest_version')
            ->group('requirement_id')
            ->select()->toArray();
        $versionMap = array_column($docVersions, 'latest_version', 'requirement_id');

        $grouped = [];
        foreach ($tasks as $task) {
            $grouped[$task['requirement_id']][] = $task;
        }
        foreach ($requirements as &$requirement) {
            $own = isset($grouped[$requirement['id']]) ? $grouped[$requirement['id']] : [];
            $committed = 0;
            $projectNames = [];
            foreach ($own as $task) {
                if (in_array($task['status'], self::DONE_STATUSES)) {
                    $committed++;
                }
                if ($task['project_name'] !== null && !in_array($task['project_name'], $projectNames)) {
                    $projectNames[] = $task['project_name'];
                }
            }
            $requirement['task_total'] = count($own);
            $requirement['task_committed'] = $committed;
            $requirement['project_names'] = implode(',', $projectNames);
            $requirement['latest_doc_version'] = isset($versionMap[$requirement['id']]) ? (int) $versionMap[$requirement['id']] : 0;
        }
        unset($requirement);
        return $requirements;
    }

    public function detail($id)
    {
        $requirement = Db::name('ai_dev_requirements')->where('id', $id)->find();
        if (!$requirement) {
            return null;
        }
        $requirement['docs'] = Db::name('ai_dev_requirement_docs')
            ->where('requirement_id', $id)->order('version', 'desc')->select()->toArray();
        $requirement['breakdowns'] = Db::name('ai_dev_breakdowns')
            ->where('requirement_id', $id)->order('version', 'desc')->select()->toArray();
        $requirement['runs'] = (new RunService())->listByTarget('requirement:' . (int) $id, ['requirement_breakdown']);
        $requirement['tasks'] = Db::name('ai_dev_tasks')->alias('t')
            ->leftJoin('ai_dev_projects p', 'p.id = t.project_id')
            ->where('t.requirement_id', $id)
            ->field('t.id, t.title, t.status, t.final_branch_name, t.scope_summary, t.updated_at, p.name as project_name')
            ->order('t.id', 'asc')
            ->select()->toArray();
        return $requirement;
    }

    public function update($id, array $input)
    {
        $allowed = [];
        foreach (['title', 'doc_url'] as $field) {
            if (array_key_exists($field, $input)) {
                $allowed[$field] = $input[$field];
            }
        }
        if ($allowed) {
            $allowed['updated_at'] = date('Y-m-d H:i:s');
            Db::name('ai_dev_requirements')->where('id', $id)->update($allowed);
        }
        return $this->detail($id);
    }

    public function close($id)
    {
        Db::name('ai_dev_requirements')->where('id', $id)->update([
            'status' => 'closed',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->detail($id);
    }

    public function loadDoc($id, array $input)
    {
        $requirement = Db::name('ai_dev_requirements')->where('id', $id)->find();
        if (!$requirement) {
            throw new \RuntimeException('需求不存在');
        }
        $content = isset($input['content']) ? $input['content'] : '';
        if (trim($content) === '') {
            throw new \RuntimeException('需求文档内容不能为空');
        }
        $masked = (new DocService())->mask($content);
        $version = (int) Db::name('ai_dev_requirement_docs')->where('requirement_id', $id)->max('version') + 1;
        Db::name('ai_dev_requirement_docs')->insert([
            'requirement_id' => $id,
            'version' => $version,
            'content' => $masked,
            'source' => isset($input['source']) ? $input['source'] : 'manual',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        $update = [
            'status' => $requirement['status'] === 'closed' ? 'closed' : 'active',
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($input['doc_url'])) {
            $update['doc_url'] = $input['doc_url'];
        }
        Db::name('ai_dev_requirements')->where('id', $id)->update($update);
        return ['version' => $version, 'content' => $masked];
    }

    public function latestDoc($id)
    {
        return Db::name('ai_dev_requirement_docs')
            ->where('requirement_id', $id)->order('version', 'desc')->find();
    }
}
