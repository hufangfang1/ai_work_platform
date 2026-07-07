<?php

namespace app\service\AiDev;

use think\facade\Db;
use think\facade\Queue;

class RunService
{
    public function enqueueCoding($taskId)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || $task['status'] !== 'plan_confirmed') {
            throw new \RuntimeException('只有已确认计划的工单才能执行');
        }
        $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->whereNotNull('confirmed_at')->order('version', 'desc')->find();
        if (!$plan) {
            throw new \RuntimeException('没有已确认的开发计划');
        }
        $run = $this->createRun($taskId, 'coding', $this->buildPrompt($task, $plan, ''));
        Queue::push('app\job\AiDevCodeJob', ['run_id' => $run['id']], 'ai_dev_code');
        (new TaskService())->updateStatus($taskId, 'coding');
        return $run;
    }

    public function enqueueFix($taskId, $feedback)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || !in_array($task['status'], ['review_failed', 'code_changed'])) {
            throw new \RuntimeException('只有代码已修改或 Review 未通过的工单才能继续修改');
        }
        $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->whereNotNull('confirmed_at')->order('version', 'desc')->find();
        $run = $this->createRun($taskId, 'fix', $this->buildPrompt($task, $plan, $feedback));
        Queue::push('app\job\AiDevCodeJob', ['run_id' => $run['id']], 'ai_dev_code');
        (new TaskService())->updateStatus($taskId, 'fixing');
        return $run;
    }

    public function createRun($taskId, $runType, $input)
    {
        $id = Db::name('ai_dev_runs')->insertGetId([
            'task_id' => $taskId,
            'run_type' => $runType,
            'status' => 'queued',
            'model_name' => '',
            'agent_session_id' => '',
            'pid' => 0,
            'input' => $input,
            'output' => '',
            'error' => '',
            'started_at' => null,
            'finished_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->detail($id);
    }

    public function markRunning($runId, $pid)
    {
        Db::name('ai_dev_runs')->where('id', $runId)->update([
            'status' => 'running',
            'pid' => $pid,
            'started_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function finish($runId, $status, $output = '', $error = '')
    {
        Db::name('ai_dev_runs')->where('id', $runId)->update([
            'status' => $status,
            'output' => $output,
            'error' => $error,
            'finished_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function appendLog($runId, $eventType, $content)
    {
        $seq = (int) Db::name('ai_dev_run_logs')->where('run_id', $runId)->max('seq') + 1;
        Db::name('ai_dev_run_logs')->insert([
            'run_id' => $runId,
            'seq' => $seq,
            'event_type' => $eventType,
            'content' => $content,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function listByTask($taskId)
    {
        return Db::name('ai_dev_runs')->where('task_id', $taskId)->order('created_at', 'desc')->select()->toArray();
    }

    public function detail($runId)
    {
        return Db::name('ai_dev_runs')->where('id', $runId)->find();
    }

    public function logs($runId, $afterSeq)
    {
        return Db::name('ai_dev_run_logs')
            ->where('run_id', $runId)
            ->where('seq', '>', $afterSeq)
            ->order('seq', 'asc')
            ->select()
            ->toArray();
    }

    public function cancel($runId)
    {
        $run = $this->detail($runId);
        if (!$run) {
            throw new \RuntimeException('执行记录不存在');
        }
        if ((int) $run['pid'] > 0) {
            exec('kill ' . (int) $run['pid']);
        }
        $this->finish($runId, 'cancelled', '', '人工取消执行');
        (new TaskService())->updateStatus((int) $run['task_id'], 'plan_confirmed');
        return $this->detail($runId);
    }

    private function buildPrompt(array $task, array $plan, $feedback)
    {
        $doc = Db::name('ai_dev_requirement_docs')->where('id', $task['doc_version_id'])->find();
        $prompt = "# 任务\n按以下已确认的开发计划修改代码，不要偏离计划范围。\n\n";
        $prompt .= "# 需求文档（已脱敏快照）\n" . ($doc ? $doc['content'] : '') . "\n\n";
        if (!empty($task['scope_summary'])) {
            $prompt .= "# 本项目职责（来自需求拆解）\n" . $task['scope_summary'] . "\n\n";
        }
        $prompt .= "# 已确认的开发计划\n" . $plan['plan_content'] . "\n\n";
        $prompt .= "# 约束\n- 只修改计划中涉及的模块和文件\n- 不要执行 git commit / git push\n- 不要修改与本需求无关的文件\n- 完成后输出：改动文件列表、改动摘要、建议的验证步骤\n";
        if ($feedback !== '') {
            $prompt .= "\n# Review 反馈\n" . $feedback . "\n";
        }
        return $prompt;
    }
}
