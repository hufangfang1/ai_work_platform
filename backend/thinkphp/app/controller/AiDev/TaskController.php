<?php

namespace app\controller\AiDev;

use app\service\AiDev\BranchService;
use app\service\AiDev\CommitService;
use app\service\AiDev\PlanService;
use app\service\AiDev\RetrospectiveService;
use app\service\AiDev\ReviewService;
use app\service\AiDev\RunService;
use app\service\AiDev\TaskService;

class TaskController extends BaseController
{
    public function index(TaskService $service)
    {
        return $this->ok($service->query($this->request->get()));
    }

    public function save(TaskService $service)
    {
        return $this->ok($service->create($this->request->post()), 'created');
    }

    public function read($id, TaskService $service)
    {
        $task = $service->detail((int) $id);
        return $task ? $this->ok($task) : $this->fail('工单不存在', 404);
    }

    public function update($id, TaskService $service)
    {
        return $this->ok($service->update((int) $id, $this->request->put()));
    }

    public function terminate($id, TaskService $service)
    {
        return $this->ok($service->terminate((int) $id));
    }

    public function cleanupWorktree($id, TaskService $service)
    {
        return $this->ok($service->cleanupWorktree((int) $id));
    }

    public function generateBranch($id, BranchService $service)
    {
        return $this->ok($service->generateForTask((int) $id));
    }

    public function checkBranch($id, BranchService $service)
    {
        return $this->ok($service->checkForTask((int) $id, $this->request->post('final_branch_name/s', '')));
    }

    public function generatePlan($id, PlanService $service)
    {
        return $this->ok($service->generate((int) $id));
    }

    public function savePlan($id, PlanService $service)
    {
        return $this->ok($service->saveHumanVersion((int) $id, $this->request->put('plan_content/s', '')));
    }

    public function confirmPlan($id, PlanService $service)
    {
        return $this->ok($service->confirmLatest((int) $id));
    }

    public function execute($id, RunService $service)
    {
        return $this->ok($service->enqueueCoding((int) $id), 'queued');
    }

    public function review($id, ReviewService $service)
    {
        return $this->ok($service->review((int) $id));
    }

    public function aiReview($id, ReviewService $service)
    {
        return $this->ok($service->aiReview((int) $id));
    }

    public function approveReview($id, ReviewService $service)
    {
        return $this->ok($service->approve((int) $id));
    }

    public function rejectReview($id, ReviewService $service)
    {
        return $this->ok($service->reject((int) $id, $this->request->post('feedback/s', '')));
    }

    public function fix($id, RunService $service)
    {
        return $this->ok($service->enqueueFix((int) $id, $this->request->post('feedback/s', '')), 'queued');
    }

    public function generateCommitMessage($id, CommitService $service)
    {
        return $this->ok($service->generateMessage((int) $id));
    }

    public function commit($id, CommitService $service)
    {
        return $this->ok($service->commit((int) $id, $this->request->post('commit_message/s', '')));
    }

    public function push($id, CommitService $service)
    {
        return $this->ok($service->push((int) $id));
    }

    public function retrospect($id, RetrospectiveService $service)
    {
        return $this->ok($service->generate((int) $id));
    }

    public function getRetrospective($id, RetrospectiveService $service)
    {
        return $this->ok($service->get((int) $id));
    }

    public function saveRetrospective($id, RetrospectiveService $service)
    {
        return $this->ok($service->save((int) $id, $this->request->put('content/s', '')));
    }
}
