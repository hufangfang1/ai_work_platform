<?php

namespace app\controller\AiDev;

use app\service\AiDev\ProjectService;
use app\service\AiDev\WorkspaceService;

class ProjectController extends BaseController
{
    public function index(ProjectService $service)
    {
        return $this->ok($service->query());
    }

    public function scan(WorkspaceService $service)
    {
        return $this->ok($service->scan());
    }

    public function describe(ProjectService $service)
    {
        return $this->ok($service->describe($this->request->post('path/s', '')));
    }

    public function save(ProjectService $service)
    {
        return $this->ok($service->create($this->request->post()), 'created');
    }

    public function update($id, ProjectService $service)
    {
        return $this->ok($service->update((int) $id, $this->request->put()));
    }

    public function delete($id, ProjectService $service)
    {
        $service->delete((int) $id);
        return $this->ok();
    }
}
