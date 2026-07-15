<?php

namespace app\service\AiDev;

use think\facade\Db;
use think\facade\Queue;

class RunService
{
    const GENERATION_RUN_TYPES = ['requirement_breakdown', 'task_spec', 'task_plan', 'project_description', 'ai_review', 'commit_message', 'branch_name'];

    public function enqueueCoding($taskId, $model = '', $draft = false)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || !in_array($task['status'], ['plan_confirmed', 'failed'], true)) {
            throw new \RuntimeException('只有已确认计划或执行失败的工单才能开始 AI 修改');
        }
        $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->whereNotNull('confirmed_at')->order('version', 'desc')->find();
        if (!$plan) {
            throw new \RuntimeException('没有已确认的开发计划');
        }
        $project = Db::name('ai_dev_projects')->where('id', (int) $task['project_id'])->find();
        if (!$project) {
            throw new \RuntimeException('项目不存在');
        }
        $blockingDependencies = (new TaskService())->blockingDependencies($task);
        if ($blockingDependencies) {
            $names = [];
            foreach ($blockingDependencies as $dependency) {
                $names[] = ($dependency['project_name'] !== '' ? $dependency['project_name'] : ('project#' . $dependency['project_id']))
                    . '(' . $dependency['status'] . ')';
            }
            throw new \RuntimeException('依赖工单 AI Review 未通过,暂不能开始 AI 修改: ' . implode('、', $names));
        }
        $modelKey = (new ModelProfileService())->resolveKey('coding', $model);
        $run = $this->createRun($taskId, 'coding', $this->buildPrompt($task, $plan, $project, ''), 'plan:' . (int) $plan['id'], $modelKey);
        return $this->dispatchOrDraft($run, 'app\job\AiDevCodeJob', $taskId, 'coding', $draft);
    }

    public function enqueueFix($taskId, $feedback, $model = '', $draft = false)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || !in_array($task['status'], ['review_failed', 'code_changed', 'review_passed'], true)) {
            throw new \RuntimeException('只有代码已修改、Review 待确认或未通过的工单才能继续修改');
        }
        $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->whereNotNull('confirmed_at')->order('version', 'desc')->find();
        if (!$plan) {
            throw new \RuntimeException('没有已确认的开发计划，不能继续修改');
        }
        $project = Db::name('ai_dev_projects')->where('id', (int) $task['project_id'])->find();
        if (!$project) {
            throw new \RuntimeException('项目不存在');
        }
        $modelKey = (new ModelProfileService())->resolveKey('fix', $model);
        $latestChange = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('id', 'desc')->find();
        $run = $this->createRun(
            $taskId,
            'fix',
            $this->buildPrompt($task, $plan, $project, (new ReviewService())->effectiveReviewFeedbackForFix($taskId, $feedback)),
            $latestChange ? 'change:' . (int) $latestChange['id'] : '',
            $modelKey
        );
        return $this->dispatchOrDraft($run, 'app\job\AiDevCodeJob', $taskId, 'fixing', $draft);
    }

    /** $model 为用户本次指定的模型 key,留空走 step_models 配置默认 */
    public function enqueueGeneration($taskId, $runType, array $payload, $targetKey = '', $model = '', $draft = false)
    {
        if (!in_array($runType, self::GENERATION_RUN_TYPES, true)) {
            throw new \RuntimeException('未知 AI 生成任务类型: ' . $runType);
        }
        $modelKey = (new ModelProfileService())->resolveKey($runType, $model);
        $run = $this->createRun($taskId, $runType, json_encode($payload, JSON_UNESCAPED_UNICODE), $targetKey, $modelKey);
        $job = $runType === 'commit_message' ? 'app\job\AiDevCommitMessageJob' : 'app\job\AiDevGenerationJob';
        // 生成类入队时不改工单状态(ai_review 的 reviewing 由其 service 负责),draft 也无需状态副作用。
        return $this->dispatchOrDraft($run, $job, 0, '', $draft);
    }

    /**
     * 统一收口:draft=true 时只把 run 置为草稿(不入队、不改工单状态),保存提示语后再由页面主按钮 executeDraft;
     * draft=false 时按原逻辑推队列并同步工单状态。
     */
    private function dispatchOrDraft(array $run, $job, $taskId, $taskStatus, $draft)
    {
        if ($draft) {
            Db::name('ai_dev_runs')->where('id', $run['id'])->update(['status' => 'draft']);
            return $this->detail($run['id']);
        }
        Queue::push($job, ['run_id' => $run['id']], 'ai_dev_code');
        if ((int) $taskId > 0 && $taskStatus !== '') {
            (new TaskService())->updateStatus((int) $taskId, $taskStatus);
        }
        return $run;
    }

    /** 草稿 run 的提示语改写:保留 {prompt,options} 外壳(生成类),编码/继续修改则是纯文本。 */
    public function updateDraftPrompt($runId, $prompt)
    {
        $run = $this->detail($runId);
        if (!$run) {
            throw new \RuntimeException('执行记录不存在');
        }
        if ($run['status'] !== 'draft') {
            throw new \RuntimeException('只有草稿状态的提示语可以编辑');
        }
        $decoded = json_decode((string) $run['input'], true);
        if (is_array($decoded) && array_key_exists('prompt', $decoded)) {
            $decoded['prompt'] = (string) $prompt;
            $newInput = json_encode($decoded, JSON_UNESCAPED_UNICODE);
        } else {
            $newInput = (string) $prompt;
        }
        Db::name('ai_dev_runs')->where('id', $runId)->update(['input' => $newInput]);
        return $this->detail($runId);
    }

    /** 把草稿 run 正式推上队列执行,并补上原本在入队时该做的工单状态更新。 */
    public function executeDraft($runId)
    {
        $run = $this->detail($runId);
        if (!$run) {
            throw new \RuntimeException('执行记录不存在');
        }
        if ($run['status'] !== 'draft') {
            throw new \RuntimeException('只有草稿状态的执行才能触发');
        }
        $runType = $run['run_type'];
        if (in_array($runType, ['coding', 'fix'], true)) {
            $job = 'app\job\AiDevCodeJob';
        } elseif ($runType === 'commit_message') {
            $job = 'app\job\AiDevCommitMessageJob';
        } elseif (in_array($runType, self::GENERATION_RUN_TYPES, true)) {
            $job = 'app\job\AiDevGenerationJob';
        } else {
            throw new \RuntimeException('该执行类型暂不支持: ' . $runType);
        }
        $this->assertDraftStillApplicable($run);
        Db::name('ai_dev_runs')->where('id', $runId)->update(['status' => 'queued']);
        Queue::push($job, ['run_id' => $runId], 'ai_dev_code');
        $taskId = (int) $run['task_id'];
        if ($taskId > 0) {
            $statusMap = ['coding' => 'coding', 'fix' => 'fixing', 'ai_review' => 'reviewing'];
            if (isset($statusMap[$runType])) {
                (new TaskService())->updateStatus($taskId, $statusMap[$runType]);
            }
        }
        return $this->detail($runId);
    }

    private function assertDraftStillApplicable(array $run)
    {
        $runType = (string) $run['run_type'];
        $payload = json_decode((string) $run['input'], true);

        if ($runType === 'requirement_breakdown' && is_array($payload)) {
            $latestDoc = (new RequirementService())->latestDoc((int) (isset($payload['requirement_id']) ? $payload['requirement_id'] : 0));
            if (!$latestDoc || (int) $latestDoc['id'] !== (int) (isset($payload['doc_version_id']) ? $payload['doc_version_id'] : 0)) {
                throw new \RuntimeException('需求文档已更新，当前拆解草稿上下文已过期，请重新生成草稿');
            }
            return;
        }

        $taskId = (int) $run['task_id'];
        if ($taskId <= 0) {
            return;
        }
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }

        if ($runType === 'coding') {
            if (!in_array($task['status'], ['plan_confirmed', 'failed'], true)) {
                throw new \RuntimeException('工单状态已变化，编码草稿不能再执行');
            }
            $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->whereNotNull('confirmed_at')->order('version', 'desc')->find();
            if (!$plan || $run['agent_session_id'] !== 'plan:' . (int) $plan['id']) {
                throw new \RuntimeException('已确认计划已变化，编码草稿上下文已过期');
            }
            if ((new TaskService())->blockingDependencies($task)) {
                throw new \RuntimeException('上游依赖尚未满足，编码草稿不能执行');
            }
            return;
        }
        if ($runType === 'fix') {
            if (!in_array($task['status'], ['review_failed', 'code_changed', 'review_passed'], true)) {
                throw new \RuntimeException('工单状态已变化，Fix 草稿不能再执行');
            }
            $change = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('id', 'desc')->find();
            if (!$change || $run['agent_session_id'] !== 'change:' . (int) $change['id']) {
                throw new \RuntimeException('代码或 Review 轮次已变化，Fix 草稿上下文已过期');
            }
            return;
        }
        if ($runType === 'task_plan' && is_array($payload)) {
            if (!in_array($task['status'], ['branch_generated', 'plan_generated'], true)) {
                throw new \RuntimeException('工单已进入后续阶段，计划草稿不能再执行');
            }
            $breakdown = Db::name('ai_dev_breakdowns')->where('requirement_id', (int) $task['requirement_id'])
                ->whereNotNull('confirmed_at')->order('version', 'desc')->find();
            $matches = (int) $task['doc_version_id'] === (int) (isset($payload['doc_version_id']) ? $payload['doc_version_id'] : 0)
                && sha1((string) $task['spec_markdown']) === (string) (isset($payload['spec_hash']) ? $payload['spec_hash'] : '')
                && sha1((string) $task['scope_summary']) === (string) (isset($payload['scope_hash']) ? $payload['scope_hash'] : '')
                && ($breakdown ? (int) $breakdown['id'] : 0) === (int) (isset($payload['breakdown_id']) ? $payload['breakdown_id'] : 0);
            if (!$matches) {
                throw new \RuntimeException('需求规格或拆解已变化，计划草稿上下文已过期，请重新生成');
            }
            return;
        }
        if ($runType === 'ai_review' && is_array($payload)) {
            if (!in_array($task['status'], ['code_changed', 'review_passed', 'review_failed', 'ready_to_commit'], true)) {
                throw new \RuntimeException('工单状态已变化，Review 草稿不能再执行');
            }
            $change = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('id', 'desc')->find();
            if (!$change || (int) $change['id'] !== (int) (isset($payload['change_id']) ? $payload['change_id'] : 0)) {
                throw new \RuntimeException('代码变更已更新，Review 草稿上下文已过期');
            }
        }
    }

    /**
     * 放弃草稿:直接置为 cancelled。草稿从未改动过工单状态,因此不像 cancel() 那样回滚工单状态。
     */
    public function discardDraft($runId)
    {
        $run = $this->detail($runId);
        if (!$run) {
            throw new \RuntimeException('执行记录不存在');
        }
        if ($run['status'] !== 'draft') {
            throw new \RuntimeException('只有草稿状态可以放弃');
        }
        $this->finish($runId, 'cancelled', '', '草稿已放弃');
        return $this->detail($runId);
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

    public function isCancelled($runId)
    {
        $run = $this->detail($runId);
        return $run && $run['status'] === 'cancelled';
    }

    public function assertNotCancelled($runId)
    {
        if ($this->isCancelled($runId)) {
            throw new \RuntimeException('人工取消执行');
        }
    }

    /** @return callable(): bool */
    public function cancelChecker($runId)
    {
        $self = $this;
        return function () use ($self, $runId) {
            return $self->isCancelled($runId);
        };
    }

    public function finish($runId, $status, $output = '', $error = '')
    {
        if ($this->isCancelled($runId)) {
            return;
        }
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
        $input = (string) $run['input'];
        if ($run['run_type'] === 'project_description') {
            $decoded = json_decode($input, true);
            $path = is_array($decoded) && isset($decoded['path']) ? (string) $decoded['path'] : '';
            if ($path !== '' && is_dir($path)) {
                $input = json_encode((new ProjectService())->describePayload($path), JSON_UNESCAPED_UNICODE);
            }
            // 旧 run 未显式选模型时，重试应使用当前步骤默认，不再落回 CLI 全局模型。
            if ($modelOverride === null && trim((string) $modelName) === '') {
                $modelName = (new ModelProfileService())->resolveKey($run['run_type']);
            }
        } elseif ($run['run_type'] === 'ai_review') {
            $decoded = json_decode($input, true);
            if (is_array($decoded)) {
                if (!isset($decoded['options']) || !is_array($decoded['options'])) {
                    $decoded['options'] = [];
                }
                $decoded['options']['timeout'] = (int) config('ai_dev.agent.review_timeout', 900);
                $decoded['options']['max_turns'] = 16;
                $input = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
        }
        if ($run['run_type'] === 'task_plan') {
            $decoded = json_decode($input, true);
            if (is_array($decoded)) {
                if (!isset($decoded['options']) || !is_array($decoded['options'])) {
                    $decoded['options'] = [];
                }
                $sessionId = $this->findRunSessionId((int) $run['id']);
                if ($sessionId !== '') {
                    // 旧 run 已完成研究但因 max turns/超时未输出 JSON 时，直接原会话收尾。
                    $decoded['options']['resume_session'] = $sessionId;
                    $decoded['options']['finalize_only'] = true;
                } else {
                    $decoded['options']['max_turns'] = 18;
                    $decoded['options']['timeout'] = (int) config('ai_dev.agent.plan_timeout', 1200);
                }
                $input = json_encode($decoded, JSON_UNESCAPED_UNICODE);
            }
        }
        if (in_array($run['run_type'], ['coding', 'fix'], true)) {
            // 旧 run 可能未保存模型选择；重试必须采用当前步骤默认，不能再次静默回退全局重模型。
            if ($modelOverride === null && trim((string) $modelName) === '') {
                $modelName = (new ModelProfileService())->resolveKey($run['run_type']);
            }
            // worktree 会被复用，先给接续约束，避免新会话无视已有 diff 后重新探索/重做。
            $input = "# 失败运行接续 source_run_id=" . (int) $run['id'] . "\n这是同一任务的续跑，worktree 中可能已有部分修改。保留正确改动，先查看已有变更文件；"
                . "最多补充 3 次必要读取后直接完成剩余计划，禁止重新规划、创建 Task 或重复大范围探索。\n\n"
                . $input;
        }
        $newRun = $this->createRun(
            (int) $run['task_id'],
            $run['run_type'],
            $input,
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

    /** 从失败生成 run 的流事件恢复 Claude session，用于无工具 JSON 收尾。 */
    private function findRunSessionId($runId)
    {
        $logs = Db::name('ai_dev_run_logs')
            ->where('run_id', (int) $runId)
            ->order('seq', 'desc')
            ->limit(200)
            ->select()
            ->toArray();
        foreach ($logs as $log) {
            $event = json_decode((string) $log['content'], true);
            if (is_array($event) && !empty($event['session_id'])) {
                return (string) $event['session_id'];
            }
        }
        return '';
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

    private function buildPrompt(array $task, array $plan, array $project, $feedback)
    {
        if (trim((string) $feedback) !== '') {
            return $this->buildFixPrompt($task, $plan, $project, $feedback);
        }
        return $this->buildCodingPrompt($task, $plan, $project);
    }

    private function buildCodingPrompt(array $task, array $plan, array $project)
    {
        $prompt = "# 任务\n进入执行模式：严格按以下已确认开发计划完成代码修改，并用可复现检查验证结果。禁止重新规划。\n\n";
        $prompt .= (new TaskService())->projectContext($task) . "\n\n";
        if (!empty($task['scope_summary'])) {
            $prompt .= "# 本项目职责（来自需求拆解）\n" . $task['scope_summary'] . "\n\n";
        }
        $prompt .= "# 已确认的开发计划\n" . $plan['plan_content'] . "\n\n";
        $prompt .= "# 执行要求\n"
            . $this->codingExecutionRules()
            . $this->projectChecksPrompt($project) . "\n";
        $prompt .= $this->codingOutputConstraints();
        return $prompt;
    }

    /** 编码阶段是计划的执行器，不再允许模型建立第二套任务/计划系统。 */
    private function codingExecutionRules()
    {
        return "1. 已确认计划就是唯一执行清单，禁止重新拆解、重新规划或创建/维护 Task；直接按计划顺序实施。\n"
            . "2. 首轮探索最多 8 次 Read/Glob/Grep，只读取计划明确涉及的文件及其直接调用方/同类实现；不存在代码事实冲突时不得扩大搜索范围，随后必须开始编辑。\n"
            . "3. 修改前确认必要的真实代码约定；计划与代码冲突时以代码事实为准，并在最终 unresolved_risks 说明，不得静默改变需求。\n"
            . "4. 逐条落实计划中的需求/验收编号；只改本项目职责和计划覆盖的文件，不顺手重构，不新增需求未要求的功能。\n"
            . "5. 优先复用已有服务、类型、错误处理和测试模式；新增接口或字段必须与已确认契约一致。\n"
            . "6. 只运行下方明确配置并已授权的检查命令；未配置时不要尝试其他 Bash 命令，改做静态核对并记录风险。\n"
            . "7. 工具调用和说明保持精简：独立的读取/搜索应在同一轮并行发出；大文件按所需区间读取，每次通常不超过 200 行；不要重复读取未变化文件或输出中间总结。\n"
            . "8. 禁止执行 git commit、git push，不要修改需求和计划文档。\n\n";
    }

    private function buildFixPrompt(array $task, array $plan, array $project, $feedback)
    {
        $change = Db::name('ai_dev_changes')->where('task_id', (int) $task['id'])->order('id', 'desc')->find();
        $changedFiles = $change ? json_decode((string) $change['changed_files'], true) : [];
        $prompt = "# 任务\n修复下方 Review 的 blocking 问题，保持已正确行为不回退，并重新执行验证。\n\n"
            . (new TaskService())->projectContext($task) . "\n\n"
            . "# 本项目职责\n" . trim((string) $task['scope_summary']) . "\n\n"
            . "# 已确认开发计划（修复不得越界）\n" . $plan['plan_content'] . "\n\n"
            . "# 当前变更文件\n- " . implode("\n- ", is_array($changedFiles) && $changedFiles ? $changedFiles : ['暂无记录，请从反馈定位']) . "\n\n"
            . "# 修复规则\n"
            . "1. 逐条处理 blocking_issues；先读取问题位置及调用上下文，确认根因后再改，不要只压制报错或硬编码绕过。\n"
            . "2. warnings 仅在不扩大改动范围时处理；suggestions 默认非阻塞，不得借此重构无关模块。\n"
            . "3. 检查修复是否影响同一调用链、接口契约、空值/异常分支和已有测试。\n"
            . "4. 为回归问题补测试，并运行项目检查；禁止 git commit、git push。\n\n"
            . $this->projectChecksPrompt($project) . "\n"
            . "# Review 反馈\n" . trim((string) $feedback) . "\n\n"
            . $this->codingOutputConstraints();

        return $prompt;
    }

    private function projectChecksPrompt(array $project)
    {
        $lines = ["# 项目检查命令"];
        $configured = false;
        foreach (['lint_command' => 'Lint', 'test_command' => 'Test', 'build_command' => 'Build'] as $field => $label) {
            $command = trim((string) (isset($project[$field]) ? $project[$field] : ''));
            if ($command === '') {
                continue;
            }
            $configured = true;
            $lines[] = "- {$label}: `{$command}`";
        }
        if (!$configured) {
            $lines[] = '- 未配置，跳过自动检查；不要搜索 vendor 中的测试框架或尝试 Bash。';
        }
        return implode("\n", $lines) . "\n";
    }

    private function codingOutputConstraints()
    {
        return "- 完成后只输出 JSON,不要 Markdown,不要代码块,结构固定为:"
            . "{\"summary_subject\":\"一句话说明这次代码改了什么\",\"change_summary\":[\"改动点\"],"
            . "\"changed_files\":[\"文件路径\"],\"completed_requirements\":[\"已完成的需求/验收编号\"],"
            . "\"verification_results\":[\"实际执行的命令及结果\"],\"unresolved_risks\":[\"未解决问题；没有则为空数组\"]}\n";
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
            $hasHumanPass = Db::name('ai_dev_reviews')
                ->where('task_id', $taskId)
                ->where('status', 'human_pass')
                ->count() > 0;
            $status = $hasHumanPass ? 'ready_to_commit' : 'code_changed';
        }
        if ($status !== '') {
            (new TaskService())->updateStatus($taskId, $status);
        }
    }

    /** coding/fix 执行失败时按 run 类型恢复工单状态,避免 fix 失败误落到步骤 2 的 failed。 */
    public function restoreStatusAfterCodeRunFailure(array $run)
    {
        $taskId = (int) $run['task_id'];
        if ($taskId <= 0) {
            return;
        }
        $status = '';
        if ($run['run_type'] === 'fix') {
            $status = 'review_failed';
        } elseif ($run['run_type'] === 'coding') {
            $status = 'failed';
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
