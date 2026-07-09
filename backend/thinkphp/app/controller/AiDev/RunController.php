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

    public function retry($runId, RunService $service)
    {
        // 传了 model 字段就按新模型重试(允许空串=走 CLI 默认),没传则沿用原 run 的模型。
        $model = $this->request->has('model', 'post') ? (string) $this->request->post('model') : null;
        return $this->ok($service->retry((int) $runId, $model), 'queued');
    }
}
