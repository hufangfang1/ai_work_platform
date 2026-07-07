<?php

namespace app\controller\AiDev;

use app\service\AiDev\RunService;

class RunController extends BaseController
{
    public function index($id, RunService $service)
    {
        return $this->ok($service->listByTask((int) $id));
    }

    public function read($runId, RunService $service)
    {
        return $this->ok($service->detail((int) $runId));
    }

    public function logs($runId, RunService $service)
    {
        return $this->ok($service->logs((int) $runId, (int) $this->request->get('after_seq/d', 0)));
    }

    public function cancel($runId, RunService $service)
    {
        return $this->ok($service->cancel((int) $runId));
    }
}
