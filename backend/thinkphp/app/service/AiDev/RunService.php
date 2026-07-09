<?php

namespace app\service\AiDev;

use think\facade\Db;
use think\facade\Queue;

class RunService
{
    const GENERATION_RUN_TYPES = ['requirement_breakdown', 'task_plan', 'project_description', 'ai_review', 'commit_message', 'branch_name'];

    public function enqueueCoding($taskId, $model = '')
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || !in_array($task['status'], ['plan_confirmed', 'failed'], true)) {
            throw new \RuntimeException('只有已确认计划或执行失败的工单才能开始 AI 修改');
        }
        $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->whereNotNull('confirmed_at')->order('version', 'desc')->find();
        if (!$plan) {
            throw new \RuntimeException('没有已确认的开发计划');
        }
        $modelKey = (new ModelProfileService())->resolveKey('coding', $model);
        if ((new ModelProfileService())->isHttp($modelKey)) {
            throw new \RuntimeException('编码步骤不支持 HTTP 直调档案,请选择 CLI 档案(claude/codex/cursor)');
        }
        $run = $this->createRun($taskId, 'coding', $this->buildPrompt($task, $plan, ''), '', $modelKey);
        Queue::push('app\job\AiDevCodeJob', ['run_id' => $run['id']], 'ai_dev_code');
        (new TaskService())->updateStatus($taskId, 'coding');
        return $run;
    }

    public function enqueueFix($taskId, $feedback, $model = '')
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || !in_array($task['status'], ['review_failed', 'code_changed'])) {
            throw new \RuntimeException('只有代码已修改或 Review 未通过的工单才能继续修改');
        }
        $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->whereNotNull('confirmed_at')->order('version', 'desc')->find();
        $modelKey = (new ModelProfileService())->resolveKey('fix', $model);
        if ((new ModelProfileService())->isHttp($modelKey)) {
            throw new \RuntimeException('编码步骤不支持 HTTP 直调档案,请选择 CLI 档案(claude/codex/cursor)');
        }
        $run = $this->createRun($taskId, 'fix', $this->buildPrompt($task, $plan, $feedback), '', $modelKey);
        Queue::push('app\job\AiDevCodeJob', ['run_id' => $run['id']], 'ai_dev_code');
        (new TaskService())->updateStatus($taskId, 'fixing');
        return $run;
    }

    /** $model 为用户本次指定的模型 key,留空走 step_models 配置默认 */
    public function enqueueGeneration($taskId, $runType, array $payload, $targetKey = '', $model = '')
    {
        if (!in_array($runType, self::GENERATION_RUN_TYPES, true)) {
            throw new \RuntimeException('未知 AI 生成任务类型: ' . $runType);
        }
        $modelKey = (new ModelProfileService())->resolveKey($runType, $model);
        $run = $this->createRun($taskId, $runType, json_encode($payload, JSON_UNESCAPED_UNICODE), $targetKey, $modelKey);
        $job = $runType === 'commit_message' ? 'app\job\AiDevCommitMessageJob' : 'app\job\AiDevGenerationJob';
        Queue::push($job, ['run_id' => $run['id']], 'ai_dev_code');
        return $run;
    }

    public function createRun($taskId, $runType, $input, $targetKey = '', $modelName = '')
    {
        $id = Db::name('ai_dev_runs')->insertGetId([
            'task_id' => $taskId,
            'run_type' => $runType,
            'status' => 'queued',
            'model_name' => $modelName,
            'agent_session_id' => $targetKey,
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

    /**
     * 落库一条模型流式事件:先把过大的字符串字段(如 codex command_execution 的 aggregated_output,
     * 常内联整段压缩 JS,单条可达数百 KB)就地截断,再编码入库。
     * 否则整条 JSON 会超过 sanitizeLogContent 的字节上限、被从字符串中间切断成非法 JSON,
     * 前端无法解析,只能吐出一大段原始串(旧 run 里 seq 56 就是这样)。
     */
    public function appendStreamEvent($runId, $eventType, array $event)
    {
        $this->truncateLargeStrings($event);
        $this->appendLog($runId, $eventType, json_encode($event, JSON_UNESCAPED_UNICODE));
    }

    private function truncateLargeStrings(&$value, $maxLen = 20000)
    {
        if (is_array($value)) {
            foreach ($value as &$item) {
                $this->truncateLargeStrings($item, $maxLen);
            }
            unset($item);
            return;
        }
        if (is_string($value) && mb_strlen($value) > $maxLen) {
            $value = mb_substr($value, 0, $maxLen) . '…(truncated)';
        }
    }

    public function appendLog($runId, $eventType, $content)
    {
        $content = $this->sanitizeLogContent($content);
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

    public function listByTarget($targetKey, array $types = [])
    {
        $query = Db::name('ai_dev_runs')->where('agent_session_id', $targetKey);
        if ($types) {
            $query->whereIn('run_type', $types);
        }
        return $query->order('created_at', 'desc')->select()->toArray();
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
        $this->appendLog($runId, 'cancel', '人工取消执行');
        $this->finish($runId, 'cancelled', '', '人工取消执行');
        $this->restoreStatusAfterCancel($run);
        return $this->detail($runId);
    }

    public function retry($runId, $modelOverride = null)
    {
        $run = $this->detail($runId);
        if (!$run) {
            throw new \RuntimeException('执行记录不存在');
        }
        if (in_array($run['status'], ['queued', 'running'], true)) {
            throw new \RuntimeException('运行中的任务不能重试');
        }
        // 显式传了模型就换模型重试(解析非法 key 会抛错),否则沿用原 run 的模型。
        $modelName = $run['model_name'];
        if ($modelOverride !== null) {
            $modelName = (new ModelProfileService())->resolveKey($run['run_type'], (string) $modelOverride);
        }
        if ($run['run_type'] === 'commit_message' && $this->recoverCommitMessageRun($run)) {
            return $this->detail($runId);
        }
        $newRun = $this->createRun(
            (int) $run['task_id'],
            $run['run_type'],
            $run['input'],
            $run['agent_session_id'],
            $modelName
        );
        if (in_array($run['run_type'], ['coding', 'fix'], true)) {
            Queue::push('app\job\AiDevCodeJob', ['run_id' => $newRun['id']], 'ai_dev_code');
            (new TaskService())->updateStatus((int) $run['task_id'], $run['run_type'] === 'fix' ? 'fixing' : 'coding');
            return $newRun;
        }
        if (in_array($run['run_type'], self::GENERATION_RUN_TYPES, true)) {
            $job = $run['run_type'] === 'commit_message' ? 'app\job\AiDevCommitMessageJob' : 'app\job\AiDevGenerationJob';
            Queue::push($job, ['run_id' => $newRun['id']], 'ai_dev_code');
            if ($run['run_type'] === 'ai_review' && (int) $run['task_id'] > 0) {
                (new TaskService())->updateStatus((int) $run['task_id'], 'reviewing');
            }
            return $newRun;
        }
        throw new \RuntimeException('该执行类型暂不支持重试');
    }

    private function recoverCommitMessageRun(array $run)
    {
        $data = $this->extractGenerationDataFromLogs((int) $run['id']);
        if (!$data || empty($data['commit_message'])) {
            return false;
        }
        $result = (new CommitService())->finishMessageRun($run, $data);
        $this->appendLog((int) $run['id'], 'recover', '已从历史模型输出恢复 commit message');
        $this->finish((int) $run['id'], 'succeeded', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), '');
        return true;
    }

    private function extractGenerationDataFromLogs($runId)
    {
        $logs = $this->logs($runId, 0);
        for ($i = count($logs) - 1; $i >= 0; $i--) {
            $content = isset($logs[$i]['content']) ? (string) $logs[$i]['content'] : '';
            $event = json_decode($content, true);
            if (is_array($event)) {
                if (isset($event['result'])) {
                    $data = $this->extractJsonObject((string) $event['result']);
                    if ($data) {
                        return $data;
                    }
                }
                if (isset($event['message']['content']) && is_array($event['message']['content'])) {
                    foreach ($event['message']['content'] as $part) {
                        if (isset($part['text'])) {
                            $data = $this->extractJsonObject((string) $part['text']);
                            if ($data) {
                                return $data;
                            }
                        }
                    }
                }
            }
            $data = $this->extractJsonObject($content);
            if ($data) {
                return $data;
            }
        }
        return null;
    }

    private function extractJsonObject($text)
    {
        $cleaned = preg_replace('/^```(json)?\s*$|^```\s*$/m', '', trim((string) $text));
        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($cleaned, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }

    private function buildPrompt(array $task, array $plan, $feedback)
    {
        $prompt = "# 任务\n按以下已确认的开发计划修改代码，不要偏离计划范围。\n\n";
        $prompt .= (new TaskService())->projectContext($task) . "\n\n";
        if (!empty($task['scope_summary'])) {
            $prompt .= "# 本项目职责（来自需求拆解）\n" . $task['scope_summary'] . "\n\n";
        }
        $prompt .= "# 已确认的开发计划\n" . $plan['plan_content'] . "\n\n";
        $prompt .= "# 约束\n- 只修改计划中涉及的模块和文件\n- 不要执行 git commit / git push\n- 不要修改与本需求无关的文件\n"
            . "- 完成后只输出 JSON,不要 Markdown,不要代码块,结构固定为:"
            . "{\"summary_subject\":\"一句话说明这次代码改了什么\",\"change_summary\":[\"改动点\"],"
            . "\"changed_files\":[\"文件路径\"],\"verification_steps\":[\"建议验证步骤\"]}\n";
        if ($feedback !== '') {
            $prompt .= "\n# Review 反馈\n" . $feedback . "\n";
        }
        return $prompt;
    }

    private function restoreStatusAfterCancel(array $run)
    {
        $taskId = (int) $run['task_id'];
        if ($taskId <= 0) {
            return;
        }
        $status = '';
        if ($run['run_type'] === 'coding' || $run['run_type'] === 'fix') {
            $status = $run['run_type'] === 'fix' ? 'review_failed' : 'plan_confirmed';
        } elseif ($run['run_type'] === 'ai_review') {
            $status = 'code_changed';
        }
        if ($status !== '') {
            (new TaskService())->updateStatus($taskId, $status);
        }
    }

    private function sanitizeLogContent($content)
    {
        $content = (string) $content;
        if ($content === '') {
            return '';
        }
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $content);
            if ($converted !== false) {
                $content = $converted;
            }
        }
        if (strlen($content) > 500000) {
            $content = substr($content, 0, 500000) . "\n...(log truncated)";
        }
        return $content;
    }
}
