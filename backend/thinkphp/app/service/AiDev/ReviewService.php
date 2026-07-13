<?php

namespace app\service\AiDev;

use think\facade\Db;

class ReviewService
{
    /**
     * 自动 Review：在 worktree 里跑项目配置的 lint/test/build。
     * 通过 → review_passed（等待人工确认），失败 → review_failed。
     * 无论结果如何，都不会直接进入 ready_to_commit，提交必须先经人工通过。
     */
    public function review($taskId)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        $change = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('created_at', 'desc')->find();
        if (!$task || !$change) {
            throw new \RuntimeException('没有可 Review 的代码改动');
        }
        if (!in_array($task['status'], ['code_changed', 'review_passed', 'review_failed'], true)) {
            throw new \RuntimeException('当前状态不能发起 Review');
        }
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        $worktree = (new WorktreeService())->path($project, $task);
        if (!is_dir($worktree)) {
            throw new \RuntimeException('工作副本不存在，无法执行检查');
        }
        $change = $this->refreshChangeSnapshot($change, $worktree);

        (new CommandSafetyService())->assertProjectChecks($project);
        list($testResult, $failedCommands, $checkedCommands) = $this->runConfiguredChecks($project, $worktree);
        $hasChecks = count($checkedCommands) > 0;
        $pass = $hasChecks && count($failedCommands) === 0;
        $result = [
            'status' => $pass ? 'pass' : 'fail',
            'risk_level' => $pass ? 'low' : 'high',
            'blocking_issues' => !$hasChecks ? ['项目未配置 lint/test/build 检查命令，无法完成自动 Review'] : array_map(function ($cmd) {
                return "检查命令执行失败：{$cmd}";
            }, $failedCommands),
            'warnings' => [],
            'suggestions' => ['提交前确认 diff 是否严格限定在计划范围内'],
            'summary' => $pass
                ? '自动检查通过，请人工 Review diff 后确认通过或驳回。'
                : ($hasChecks
                    ? '自动检查未通过，请查看检查输出并让 AI 继续修改。'
                    : '未配置自动检查命令，请先在项目配置中补充 lint/test/build 命令，或人工核对后再处理。'),
        ];
        $id = Db::name('ai_dev_reviews')->insertGetId([
            'task_id' => $taskId,
            'run_id' => $change['run_id'],
            'status' => $result['status'],
            'risk_level' => $result['risk_level'],
            'review_result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'test_result' => $testResult,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        (new TaskService())->updateStatus($taskId, $pass ? 'review_passed' : 'review_failed');
        return Db::name('ai_dev_reviews')->where('id', $id)->find();
    }

    /**
     * 只读 AI Review：让 Claude 读取计划、diff 与代码上下文，输出结构化风险结论。
     * 只允许 Read/Glob/Grep，不允许 Edit/Write/Bash。
     */
    public function aiReview($taskId, $model = '', $draft = false)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        $change = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('created_at', 'desc')->find();
        if (!$task || !$change) {
            throw new \RuntimeException('没有可 Review 的代码改动');
        }
        if (!in_array($task['status'], ['code_changed', 'review_passed', 'review_failed'], true)) {
            throw new \RuntimeException('当前状态不能发起 AI Review');
        }
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        $worktree = (new WorktreeService())->path($project, $task);
        if (!is_dir($worktree)) {
            throw new \RuntimeException('工作副本不存在，无法执行 AI Review');
        }
        $change = $this->refreshChangeSnapshot($change, $worktree);
        $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->whereNotNull('confirmed_at')->order('version', 'desc')->find();
        if (!$plan) {
            $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->order('version', 'desc')->find();
        }
        $this->assertNoRunningAiReview($taskId);
        $run = (new RunService())->enqueueGeneration((int) $taskId, 'ai_review', [
            'operation' => 'ai_review',
            'task_id' => (int) $taskId,
            'change_id' => (int) $change['id'],
            'change_run_id' => (int) $change['run_id'],
            'prompt' => $this->buildAiReviewPrompt($task, $plan, $change),
            'options' => [
                'cwd' => $worktree,
                'timeout' => (int) config('ai_dev.agent.review_timeout', 900),
                'max_turns' => 16,
                'allowed_tools' => 'Read,Glob,Grep,Bash(git diff:*),Bash(git status:*),Bash(git show:*)',
            ],
        ], 'task:' . (int) $taskId, $model, $draft);
        // 草稿不改工单状态,reviewing 推迟到 executeDraft 时再设。
        if (!$draft) {
            (new TaskService())->updateStatus($taskId, 'reviewing');
        }
        return $run;
    }

    public function finishAiReviewRun(array $run, array $raw)
    {
        $taskId = (int) $run['task_id'];
        $payload = json_decode((string) $run['input'], true);
        $result = $this->normalizeAiReviewResult($raw);
        if ($result['status'] === 'fail' && !$result['blocking_issues']) {
            $salvaged = (new GenerationExecutorService())->parseReviewResultFromRunLogs((int) $run['id']);
            if ($salvaged) {
                $result = $this->normalizeAiReviewResult($salvaged);
            }
        }
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        $project = $task ? Db::name('ai_dev_projects')->where('id', (int) $task['project_id'])->find() : null;
        if (!$task || !$project) {
            throw new \RuntimeException('AI Review 对应的工单或项目不存在');
        }
        $worktree = (new WorktreeService())->path($project, $task);
        if (!is_dir($worktree)) {
            throw new \RuntimeException('工作副本不存在，无法完成 Review 检查');
        }
        (new CommandSafetyService())->assertProjectChecks($project);
        list($testResult, $failedCommands, $checkedCommands) = $this->runConfiguredChecks($project, $worktree);
        if (!$checkedCommands) {
            $result['blocking_issues'][] = '项目未配置 lint/test/build 检查命令，AI 代码判断不能替代可执行验证';
        }
        foreach ($failedCommands as $command) {
            $result['blocking_issues'][] = '检查命令执行失败: ' . $command;
        }
        $result['blocking_issues'] = array_values(array_unique($result['blocking_issues']));
        if ($result['blocking_issues']) {
            $result['status'] = 'fail';
            $result['risk_level'] = 'high';
        }
        $pass = $result['status'] === 'pass' && (bool) $checkedCommands && !$failedCommands;

        $id = Db::name('ai_dev_reviews')->insertGetId([
            'task_id' => $taskId,
            'run_id' => isset($payload['change_run_id']) ? (int) $payload['change_run_id'] : 0,
            'status' => $result['status'],
            'risk_level' => $result['risk_level'],
            'review_result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'test_result' => "AI 只读 Review：已要求基于完整 git diff HEAD 审查，未授权写操作。\n\n" . $testResult,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        (new TaskService())->updateStatus($taskId, $pass ? 'review_passed' : 'review_failed');
        return Db::name('ai_dev_reviews')->where('id', $id)->find();
    }

    /** fix 轮次取最新有效 Review 反馈;用户输入为空或仅有空壳 JSON 时从 ai_review 日志兜底。 */
    public function effectiveReviewFeedbackForFix($taskId, $userFeedback = '')
    {
        $userFeedback = trim((string) $userFeedback);
        $effective = $this->latestEffectiveReviewResult((int) $taskId);
        if ($userFeedback === '') {
            return $effective ? json_encode($effective, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) : '';
        }
        $parsed = json_decode($userFeedback, true);
        if (!is_array($parsed)) {
            if ($effective) {
                return json_encode($this->appendSupplementFeedback($effective, $userFeedback), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            }
            return $userFeedback;
        }
        $normalized = $this->normalizeAiReviewResult($parsed);
        if ($this->resultHasContent($normalized) && !$this->isShellHumanReject($normalized)) {
            return $userFeedback;
        }
        if ($effective) {
            return json_encode($effective, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        }
        return $userFeedback;
    }

    /** 返回 fix 轮次应使用的 Review 结论(跳过 human_pass,空壳 human_reject 会合并上一次 AI/自动 Review)。 */
    public function latestEffectiveReviewResult($taskId)
    {
        $reviews = Db::name('ai_dev_reviews')->where('task_id', $taskId)->order('id', 'desc')->select()->toArray();
        foreach ($reviews as $review) {
            if ($review['status'] === 'human_pass') {
                continue;
            }
            $parsed = json_decode((string) $review['review_result'], true);
            if (!is_array($parsed)) {
                continue;
            }
            $normalized = $this->normalizeAiReviewResult($parsed);
            if ($review['status'] === 'human_reject') {
                $normalized['status'] = 'human_reject';
            }
            if (!$this->resultHasContent($normalized)) {
                continue;
            }
            if ($review['status'] === 'human_reject' && $this->isShellHumanReject($normalized)) {
                $actionable = $this->latestActionableReviewResult($taskId);
                if ($actionable) {
                    return $this->mergeRejectFeedback($actionable, $normalized);
                }
            }
            return $normalized;
        }
        $run = Db::name('ai_dev_runs')
            ->where('task_id', $taskId)
            ->where('run_type', 'ai_review')
            ->where('status', 'succeeded')
            ->order('id', 'desc')
            ->find();
        if (!$run) {
            return null;
        }
        $salvaged = (new GenerationExecutorService())->parseReviewResultFromRunLogs((int) $run['id']);
        return $salvaged ? $this->normalizeAiReviewResult($salvaged) : null;
    }

    /** 最近一次 AI/自动 Review(不含人工通过/驳回记录)。 */
    public function latestActionableReviewResult($taskId)
    {
        $reviews = Db::name('ai_dev_reviews')->where('task_id', $taskId)->order('id', 'desc')->select()->toArray();
        foreach ($reviews as $review) {
            if ($this->isHumanReviewStatus($review['status'])) {
                continue;
            }
            $parsed = json_decode((string) $review['review_result'], true);
            if (!is_array($parsed)) {
                continue;
            }
            $normalized = $this->normalizeAiReviewResult($parsed);
            if ($this->resultHasContent($normalized)) {
                return $normalized;
            }
        }
        $run = Db::name('ai_dev_runs')
            ->where('task_id', $taskId)
            ->where('run_type', 'ai_review')
            ->where('status', 'succeeded')
            ->order('id', 'desc')
            ->find();
        if (!$run) {
            return null;
        }
        $salvaged = (new GenerationExecutorService())->parseReviewResultFromRunLogs((int) $run['id']);
        return $salvaged ? $this->normalizeAiReviewResult($salvaged) : null;
    }

    private function isHumanReviewStatus($status)
    {
        return in_array($status, ['human_pass', 'human_reject'], true);
    }

    private function isShellHumanReject(array $result)
    {
        if (($result['status'] ?? '') !== 'human_reject') {
            return false;
        }
        if (!empty($result['warnings']) || !empty($result['suggestions'])) {
            return false;
        }
        $blocking = $result['blocking_issues'] ?? [];
        if (count($blocking) > 1) {
            return false;
        }
        if (count($blocking) === 1 && mb_strlen((string) $blocking[0]) >= 30) {
            return false;
        }
        return trim((string) ($result['summary'] ?? '')) === '人工 Review 驳回。' || count($blocking) <= 1;
    }

    private function mergeRejectFeedback(array $previous, array $humanReject, $humanFeedback = '')
    {
        $merged = $previous;
        $merged['status'] = 'human_reject';
        $merged['risk_level'] = 'high';
        $humanNote = trim((string) $humanFeedback);
        if ($humanNote === '') {
            $humanNote = trim((string) ($humanReject['human_feedback'] ?? ''));
        }
        if ($humanNote === '' && !empty($humanReject['blocking_issues'])) {
            $humanNote = trim((string) $humanReject['blocking_issues'][0]);
        }
        $merged['summary'] = '人工 Review 驳回。';
        if (trim((string) ($previous['summary'] ?? '')) !== '') {
            $merged['summary'] .= ' 原 Review：' . $previous['summary'];
        }
        if ($humanNote !== '') {
            $blocking = $merged['blocking_issues'] ?? [];
            array_unshift($blocking, '【人工意见】' . $humanNote);
            $merged['blocking_issues'] = array_values(array_unique($blocking));
            $merged['human_feedback'] = $humanNote;
        }
        return $merged;
    }

    private function appendSupplementFeedback(array $result, $supplement)
    {
        $supplement = trim((string) $supplement);
        if ($supplement === '') {
            return $result;
        }
        $note = '【补充意见】' . $supplement;
        $blocking = $result['blocking_issues'] ?? [];
        if (!in_array($note, $blocking, true)) {
            array_unshift($blocking, $note);
        }
        $result['blocking_issues'] = $blocking;
        return $result;
    }

    private function resultHasContent(array $result)
    {
        return trim((string) ($result['summary'] ?? '')) !== ''
            || !empty($result['blocking_issues'])
            || !empty($result['warnings'])
            || !empty($result['suggestions']);
    }

    private function assertNoRunningAiReview($taskId)
    {
        $runs = (new RunService())->listByTask($taskId);
        foreach ($runs as $run) {
            if ($run['run_type'] === 'ai_review' && in_array($run['status'], ['queued', 'running'], true)) {
                throw new \RuntimeException('已有 AI Review 正在运行');
            }
        }
    }

    /**
     * 人工 Review 通过，工单才允许进入提交阶段。
     */
    public function approve($taskId)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || $task['status'] !== 'review_passed') {
            throw new \RuntimeException('只有自动 Review 通过的工单才能人工确认');
        }
        $change = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('created_at', 'desc')->find();
        Db::name('ai_dev_reviews')->insert([
            'task_id' => $taskId,
            'run_id' => $change ? $change['run_id'] : 0,
            'status' => 'human_pass',
            'risk_level' => 'low',
            'review_result' => json_encode([
                'status' => 'human_pass',
                'summary' => '人工 Review 通过，允许提交。',
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'test_result' => '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        (new TaskService())->updateStatus($taskId, 'ready_to_commit');
        return (new TaskService())->detail($taskId);
    }

    /**
     * 人工 Review 驳回：记录驳回意见，工单回到 review_failed 走 fix 轮次。
     * 提交前（ready_to_commit）也允许反悔驳回。
     */
    public function reject($taskId, $feedback)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || !in_array($task['status'], ['review_passed', 'ready_to_commit'], true)) {
            throw new \RuntimeException('当前状态不能驳回');
        }
        if (trim($feedback) === '') {
            throw new \RuntimeException('驳回必须填写意见');
        }
        $change = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('created_at', 'desc')->find();
        $previous = $this->latestActionableReviewResult($taskId);
        $merged = $previous
            ? $this->mergeRejectFeedback($previous, [], trim($feedback))
            : [
                'status' => 'human_reject',
                'risk_level' => 'high',
                'summary' => '人工 Review 驳回。',
                'blocking_issues' => [trim($feedback)],
                'warnings' => [],
                'suggestions' => [],
                'human_feedback' => trim($feedback),
            ];
        Db::name('ai_dev_reviews')->insert([
            'task_id' => $taskId,
            'run_id' => $change ? $change['run_id'] : 0,
            'status' => 'human_reject',
            'risk_level' => 'high',
            'review_result' => json_encode($merged, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'test_result' => '',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        (new TaskService())->updateStatus($taskId, 'review_failed');
        return (new TaskService())->detail($taskId);
    }

    /**
     * @return array [合并输出文本, 失败命令列表, 已执行命令列表]
     */
    private function runConfiguredChecks(array $project, $worktree)
    {
        $parts = [];
        $failed = [];
        $checked = [];
        foreach (['lint_command', 'test_command', 'build_command'] as $field) {
            if (empty($project[$field])) {
                continue;
            }
            $checked[] = $project[$field];
            $output = [];
            (new ProcessTempService())->exec($worktree, $project[$field], $output, $code, 'review-check');
            $parts[] = "命令：{$project[$field]}\n退出码：{$code}\n" . implode("\n", $output);
            if ($code !== 0) {
                $failed[] = $project[$field];
            }
        }
        if (!$checked) {
            $parts[] = '未配置 lint/test/build 检查命令，未执行任何自动检查。';
        }
        return [implode("\n\n", $parts), $failed, $checked];
    }

    private function refreshChangeSnapshot(array $change, $worktree)
    {
        exec('git -C ' . escapeshellarg($worktree) . ' add -A -N');
        $files = [];
        $diffLines = [];
        exec('git -C ' . escapeshellarg($worktree) . ' diff HEAD --name-only', $files, $filesCode);
        exec('git -C ' . escapeshellarg($worktree) . ' diff HEAD', $diffLines, $diffCode);
        if ($filesCode !== 0 || $diffCode !== 0) {
            throw new \RuntimeException('读取 Review 代码快照失败');
        }
        if (!$files) {
            throw new \RuntimeException('当前 worktree 没有可 Review 的代码改动');
        }
        $snapshot = implode("\n", $diffLines);
        Db::name('ai_dev_changes')->where('id', (int) $change['id'])->update([
            'changed_files' => json_encode($files, JSON_UNESCAPED_UNICODE),
            'git_diff_snapshot' => $snapshot,
        ]);
        $change['changed_files'] = json_encode($files, JSON_UNESCAPED_UNICODE);
        $change['git_diff_snapshot'] = $snapshot;
        return $change;
    }

    private function buildAiReviewPrompt(array $task, $plan, array $change)
    {
        $files = json_decode((string) $change['changed_files'], true);
        $files = is_array($files) ? $files : [];
        return "你是资深代码审查员。禁止修改任何文件。除 git diff/status/show 这三个只读命令外禁止执行其他命令。\n"
            . "必须先执行 `git status --short --untracked-files=no` 和 `git diff HEAD` 阅读当前 worktree 相对基准提交的完整实际改动（包括已暂存内容），不能只抽查文件；再读取相关调用方、类型定义和测试代码核对上下文。\n"
            . "逐条对照需求/验收编号、项目职责和已确认计划，检查遗漏实现、错误业务逻辑、空值与异常分支、接口契约偏差、兼容性、越界修改和测试缺口。\n"
            . "status=pass 仅表示没有必须修改的问题；任何会导致需求不满足、运行错误、数据错误、接口不兼容或无法验证的事项都必须放入 blocking_issues 并返回 fail。\n"
            . "blocking_issues 每项必须写成『[文件:行号或符号] 问题 | 影响 | 建议修复』，不得只写笼统结论；无法确认时写明缺少的证据。\n"
            . "只返回 JSON，不要 Markdown，不要解释 JSON 以外的内容。结构固定为：\n"
            . "{\"status\":\"pass|fail\",\"risk_level\":\"low|medium|high\",\"summary\":\"一句话结论\","
            . "\"blocking_issues\":[\"必须修复的问题\"],\"warnings\":[\"风险提示\"],\"suggestions\":[\"非阻塞建议\"]}\n\n"
            . (new TaskService())->projectContext($task) . "\n\n"
            . "# 本项目职责\n" . ($task['scope_summary'] !== '' ? $task['scope_summary'] : '未填写') . "\n\n"
            . "# 已确认计划\n" . ($plan ? $plan['plan_content'] : '未找到计划') . "\n\n"
            . "# 系统记录的变更文件（仍须以实际 git diff 为准）\n- "
            . implode("\n- ", $files ?: ['暂无记录']) . "\n";
    }

    private function normalizeAiReviewResult(array $raw)
    {
        $status = isset($raw['status']) && $raw['status'] === 'pass' ? 'pass' : 'fail';
        $risk = isset($raw['risk_level']) && in_array($raw['risk_level'], ['low', 'medium', 'high'], true)
            ? $raw['risk_level']
            : ($status === 'pass' ? 'low' : 'high');
        return [
            'source' => 'ai_review',
            'status' => $status,
            'risk_level' => $risk,
            'summary' => isset($raw['summary']) ? trim((string) $raw['summary']) : '',
            'blocking_issues' => $this->normalizeList(isset($raw['blocking_issues']) ? $raw['blocking_issues'] : []),
            'warnings' => $this->normalizeList(isset($raw['warnings']) ? $raw['warnings'] : []),
            'suggestions' => $this->normalizeList(isset($raw['suggestions']) ? $raw['suggestions'] : []),
        ];
    }

    private function normalizeList($value)
    {
        if (!is_array($value)) {
            $value = trim((string) $value) !== '' ? [$value] : [];
        }
        return array_values(array_filter(array_map(function ($item) {
            return trim((string) $item);
        }, $value)));
    }
}
