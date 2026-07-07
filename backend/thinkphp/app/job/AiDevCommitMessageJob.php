<?php

namespace app\job;

use app\service\AiDev\CommitMessageExecutorService;
use app\service\AiDev\RunService;
use think\queue\Job;

class AiDevCommitMessageJob
{
    public function fire(Job $job, $data)
    {
        $runId = isset($data['run_id']) ? (int) $data['run_id'] : 0;
        $runService = new RunService();

        try {
            $run = $runService->detail($runId);
            if (!$run || $run['status'] === 'cancelled') {
                $job->delete();
                return;
            }
            $runService->appendLog($runId, 'queue', 'Worker 领取 commit message 生成任务');
            (new CommitMessageExecutorService())->execute($runId);
            $job->delete();
        } catch (\Throwable $e) {
            $run = $runService->detail($runId);
            if ($run && $run['status'] === 'cancelled') {
                $job->delete();
                return;
            }
            $runService->appendLog($runId, 'error', $e->getMessage());
            $runService->finish($runId, 'failed', '', $e->getMessage());
            $job->delete();
        }
    }

    public function failed($data)
    {
        $runId = isset($data['run_id']) ? (int) $data['run_id'] : 0;
        if ($runId <= 0) {
            return;
        }
        $runService = new RunService();
        $run = $runService->detail($runId);
        $runService->finish($runId, 'failed', '', $run && $run['error'] ? $run['error'] : '队列任务执行失败');
    }
}
