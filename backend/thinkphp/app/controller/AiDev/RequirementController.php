<?php

namespace app\controller\AiDev;

use app\service\AiDev\BreakdownService;
use app\service\AiDev\RequirementService;
use think\facade\Db;

class RequirementController extends BaseController
{
    public function index(RequirementService $service)
    {
        return $this->ok($service->query());
    }

    public function save(RequirementService $service)
    {
        return $this->ok($service->create($this->request->post()), 'created');
    }

    public function read($id, RequirementService $service)
    {
        $requirement = $service->detail((int) $id);
        return $requirement ? $this->ok($requirement) : $this->fail('需求不存在', 404);
    }

    public function update($id, RequirementService $service)
    {
        return $this->ok($service->update((int) $id, $this->request->put()));
    }

    public function close($id, RequirementService $service)
    {
        return $this->ok($service->close((int) $id));
    }

    public function loadDoc($id, RequirementService $service)
    {
        return $this->ok($service->loadDoc((int) $id, $this->request->post()));
    }

    public function generateBreakdown($id, BreakdownService $service)
    {
        $projectIds = $this->request->post('project_ids', []);
        return $this->ok($service->generate(
            (int) $id,
            is_array($projectIds) ? $projectIds : [],
            $this->request->post('model/s', '')
        ));
    }

    public function saveBreakdown($id, BreakdownService $service)
    {
        return $this->ok($service->saveHuman(
            (int) $id,
            $this->request->put('content/s', ''),
            $this->request->put('projects_json', [])
        ));
    }

    public function confirmBreakdown($id, BreakdownService $service)
    {
        return $this->ok($service->confirm((int) $id));
    }

    public function tasks($id)
    {
        $tasks = Db::name('ai_dev_tasks')->alias('t')
            ->leftJoin('ai_dev_projects p', 'p.id = t.project_id')
            ->where('t.requirement_id', (int) $id)
            ->field('t.*, p.name as project_name')
            ->order('t.id', 'asc')
            ->select()->toArray();
        return $this->ok($tasks);
    }
}
