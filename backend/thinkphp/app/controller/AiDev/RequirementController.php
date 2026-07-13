<?php

namespace app\controller\AiDev;

use app\service\AiDev\BreakdownService;
use app\service\AiDev\BranchService;
use app\service\AiDev\RequirementService;
use app\service\AiDev\RequirementRetrospectiveService;
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
            $this->request->post('model/s', ''),
            (bool) $this->request->post('draft/d', 0)
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

    public function generateBranch($id, BranchService $service)
    {
        return $this->ok($service->enqueueForRequirement((int) $id, $this->request->post('model/s', ''), (bool) $this->request->post('draft/d', 0)), 'queued');
    }

    public function saveBranch($id, BranchService $service)
    {
        return $this->ok($service->saveForRequirement((int) $id, $this->request->put('final_branch_name/s', '')));
    }

    public function checkBranch($id, BranchService $service)
    {
        return $this->ok($service->checkForRequirement((int) $id, $this->request->post('final_branch_name/s', '')));
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

    public function retrospect($id, RequirementRetrospectiveService $service)
    {
        return $this->ok($service->generate((int) $id));
    }

    public function getRetrospective($id, RequirementRetrospectiveService $service)
    {
        return $this->ok($service->get((int) $id));
    }

    public function saveRetrospective($id, RequirementRetrospectiveService $service)
    {
        $summaries = $this->request->put('project_summaries', []);
        return $this->ok($service->save(
            (int) $id,
            $this->request->put('content/s', ''),
            is_array($summaries) ? $summaries : []
        ));
    }
}
