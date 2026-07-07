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
        $worktree = dirname(rtrim($project['local_path'], '/')) . '/wt-task-' . $task['id'];
        if (!is_dir($worktree)) {
            throw new \RuntimeException('工作副本不存在，无法执行检查');
        }

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
    public function aiReview($taskId)
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
        $worktree = dirname(rtrim($project['local_path'], '/')) . '/wt-task-' . $task['id'];
        if (!is_dir($worktree)) {
            throw new \RuntimeException('工作副本不存在，无法执行 AI Review');
        }
        $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->whereNotNull('confirmed_at')->order('version', 'desc')->find();
        if (!$plan) {
            $plan = Db::name('ai_dev_plans')->where('task_id', $taskId)->order('version', 'desc')->find();
        }
        $doc = Db::name('ai_dev_requirement_docs')->where('id', $task['doc_version_id'])->find();

        $prompt = $this->buildAiReviewPrompt($task, $plan, $doc, $change);
        Db::connect()->close();
        $raw = (new ClaudeCliService())->runJson($prompt, [
            'cwd' => $worktree,
            'timeout' => 300,
            'max_turns' => 12,
            'allowed_tools' => 'Read,Glob,Grep',
        ]);
        $result = $this->normalizeAiReviewResult($raw);
        $pass = $result['status'] === 'pass';

        $id = Db::name('ai_dev_reviews')->insertGetId([
            'task_id' => $taskId,
            'run_id' => $change['run_id'],
            'status' => $result['status'],
            'risk_level' => $result['risk_level'],
            'review_result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
            'test_result' => 'AI 只读 Review：未执行写操作；允许工具：Read,Glob,Grep。',
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        (new TaskService())->updateStatus($taskId, $pass ? 'review_passed' : 'review_failed');
        return Db::name('ai_dev_reviews')->where('id', $id)->find();
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
        Db::name('ai_dev_reviews')->insert([
            'task_id' => $taskId,
            'run_id' => $change ? $change['run_id'] : 0,
            'status' => 'human_reject',
            'risk_level' => 'high',
            'review_result' => json_encode([
                'status' => 'human_reject',
                'summary' => '人工 Review 驳回。',
                'blocking_issues' => [trim($feedback)],
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
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
            exec('cd ' . escapeshellarg($worktree) . ' && ' . $project[$field] . ' 2>&1', $output, $code);
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

    private function buildAiReviewPrompt(array $task, $plan, $doc, array $change)
    {
        $diff = mb_substr((string) $change['git_diff_snapshot'], 0, 50000);
        return "你是资深代码审查员。你只能读取文件和搜索代码，禁止修改任何文件，禁止执行命令。\n"
            . "请对照需求、项目职责、已确认计划和 git diff 做只读 Review，判断改动是否满足计划、是否有明显 bug、越界修改、接口/兼容性/安全风险。\n"
            . "只返回 JSON，不要 Markdown，不要解释 JSON 以外的内容。结构固定为：\n"
            . "{\"status\":\"pass|fail\",\"risk_level\":\"low|medium|high\",\"summary\":\"一句话结论\","
            . "\"blocking_issues\":[\"必须修复的问题\"],\"warnings\":[\"风险提示\"],\"suggestions\":[\"非阻塞建议\"]}\n\n"
            . "# 需求文档\n" . ($doc ? $doc['content'] : '') . "\n\n"
            . "# 本项目职责\n" . ($task['scope_summary'] !== '' ? $task['scope_summary'] : '未填写') . "\n\n"
            . "# 已确认计划\n" . ($plan ? $plan['plan_content'] : '未找到计划') . "\n\n"
            . "# git diff\n" . $diff . "\n";
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
