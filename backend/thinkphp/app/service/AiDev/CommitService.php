<?php

namespace app\service\AiDev;

use think\facade\Db;

class CommitService
{
    public function generateMessage($taskId, $model = '', $draft = false)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        if ($task['status'] !== 'ready_to_commit') {
            throw new \RuntimeException('只有待提交状态才能生成 commit message');
        }
        $change = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('created_at', 'desc')->find();
        if (!$change) {
            throw new \RuntimeException('没有可生成 commit message 的代码改动');
        }
        $this->assertNoRunningMessageRun($taskId);
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();

        return (new RunService())->enqueueGeneration((int) $taskId, 'commit_message', [
            'operation' => 'commit_message',
            'task_id' => (int) $taskId,
            'fallback_message' => $this->buildMessage($task, $change),
            'prompt' => $this->buildCommitMessagePrompt($task, $project, $change),
            'options' => [
                'timeout' => 180,
                'max_turns' => 3,
            ],
        ], 'task:' . (int) $taskId, $model, $draft);
    }

    public function finishMessageRun(array $run, array $data)
    {
        $payload = json_decode((string) $run['input'], true);
        $message = isset($data['commit_message']) ? trim((string) $data['commit_message']) : '';
        if ($message === '' && is_array($payload) && !empty($payload['fallback_message'])) {
            $message = trim((string) $payload['fallback_message']);
        }
        $task = Db::name('ai_dev_tasks')->where('id', (int) $run['task_id'])->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        $message = $this->normalizeGeneratedMessage($message, $task);
        if ($message === '') {
            throw new \RuntimeException('AI 未返回可用 commit_message');
        }
        Db::name('ai_dev_tasks')->where('id', (int) $run['task_id'])->update([
            'commit_message' => $message,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return ['commit_message' => $message];
    }

    private function assertNoRunningMessageRun($taskId)
    {
        $runs = (new RunService())->listByTask($taskId);
        foreach ($runs as $run) {
            if ($run['run_type'] === 'commit_message' && in_array($run['status'], ['queued', 'running'], true)) {
                throw new \RuntimeException('已有 commit message 生成任务正在运行');
            }
        }
    }

    private function buildMessage(array $task, $change)
    {
        $scope = $this->normalizeScope($task['repo_name']);
        $subject = $this->buildSubject($task, $change);
        $type = $this->inferType($subject, $change);
        return "{$type}({$scope}): {$subject}";
    }

    private function normalizeGeneratedMessage($message, array $task)
    {
        $message = trim((string) $message);
        $message = preg_replace('/^```[A-Za-z0-9_-]*\R?/u', '', $message);
        $message = preg_replace('/\R?```$/u', '', $message);
        $message = trim($message, " \t\n\r\0\x0B\"'`");
        if ($message === '') {
            return '';
        }
        $message = preg_replace('/\s+/u', ' ', $message);
        $scope = $this->normalizeScope($task['repo_name']);
        if (preg_match('/^(feat|fix|docs|style|refactor|test|chore)(?:\([^)]+\))?!?:\s*(.+)$/iu', $message, $match)) {
            $type = strtolower($match[1]);
            $subject = $this->cleanModelSubject($match[2]);
            return $subject !== '' ? "{$type}({$scope}): " . $this->trimLine($subject, 60) : '';
        }
        $subject = $this->cleanModelSubject($message);
        if ($subject === '') {
            return '';
        }
        $change = Db::name('ai_dev_changes')->where('task_id', (int) $task['id'])->order('created_at', 'desc')->find();
        $type = $this->inferType($subject, $change);
        return "{$type}({$scope}): " . $this->trimLine($subject, 60);
    }

    private function cleanModelSubject($text)
    {
        $subject = trim(strip_tags((string) $text));
        $subject = preg_replace('/^[-*#\s]+/u', '', $subject);
        $subject = preg_replace('/\s+/u', ' ', $subject);
        $subject = $this->trimSubjectEdges($subject);
        if (
            $subject === ''
            || preg_match('/^(测试|test|需求|开发|改动|任务)\s*[#\d一二三四五六七八九十._-]*$/iu', $subject)
            || preg_match('/^(更新|修改|调整)?代码(改动|变更)?$/u', $subject)
            || preg_match('/^[#\d一二三四五六七八九十._-]+$/u', $subject)
            || preg_match('/^```[A-Za-z0-9_-]*$/u', $subject)
            || $this->isMetaSummaryLine($subject)
        ) {
            return '';
        }
        return $subject;
    }

    private function buildCommitMessagePrompt(array $task, $project, array $change)
    {
        $scope = $this->normalizeScope($task['repo_name']);
        $files = $this->changedFiles($change);
        $diff = mb_substr((string) $change['git_diff_snapshot'], 0, 60000);
        $summary = trim((string) $change['diff_summary']);
        $projectName = $project && !empty($project['name']) ? $project['name'] : $task['repo_name'];

        return "你是资深工程师，请根据本次代码 diff 生成一个准确的 Conventional Commit message。\n"
            . "只返回 JSON，不要 Markdown，不要代码块，结构固定为:{\"commit_message\":\"type(scope): subject\"}\n"
            . "要求:\n"
            . "- type 只能是 feat/fix/docs/style/refactor/test/chore 之一\n"
            . "- scope 必须使用: {$scope}\n"
            . "- subject 使用中文，简洁描述真实代码改动，不要使用需求标题、工单标题或占位词\n"
            . "- 不要输出“测试 1”“需求 1”“更新代码”这类泛化描述\n"
            . "- 如果只是测试文件变化，type 用 test；否则不要因为验证步骤里有测试就用 test\n\n"
            . "# 项目\n" . $projectName . "\n\n"
            . "# 工单标题\n" . $task['title'] . "\n\n"
            . "# 本项目职责\n" . ($task['scope_summary'] !== '' ? $task['scope_summary'] : '未填写') . "\n\n"
            . "# AI 改动摘要\n" . ($summary !== '' ? $summary : '无') . "\n\n"
            . "# 变更文件\n- " . implode("\n- ", $files ?: ['无']) . "\n\n"
            . "# git diff\n" . $diff . "\n";
    }

    private function normalizeScope($repoName)
    {
        $scope = preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower(trim((string) $repoName)));
        return trim($scope, '-') !== '' ? trim($scope, '-') : 'app';
    }

    private function buildSubject(array $task, $change)
    {
        $candidates = [];
        if ($change) {
            $candidates = array_merge($candidates, $this->summaryCandidates((string) $change['diff_summary']));
            $fileSubject = $this->subjectFromChangedFiles($change);
            if ($fileSubject !== '') {
                $candidates[] = $fileSubject;
            }
        }
        if (!empty($task['scope_summary'])) {
            $candidates[] = $this->firstUsefulSentence($task['scope_summary']);
        }
        $candidates[] = $this->normalizeTitle($task['title'], $task['repo_name']);

        foreach ($candidates as $candidate) {
            $subject = $this->cleanSubject($candidate);
            if ($subject !== '') {
                return $this->trimLine($subject, 42);
            }
        }
        return '更新代码';
    }

    private function normalizeTitle($title, $repoName)
    {
        $subject = trim((string) $title);
        $repoName = trim((string) $repoName);
        if ($repoName !== '') {
            $subject = preg_replace('/\s*[-–—]\s*' . preg_quote($repoName, '/') . '$/u', '', $subject);
        }
        return preg_replace('/\s+/u', ' ', $subject);
    }

    private function inferType($subject, $change = null)
    {
        if (preg_match('/修复|bug|错误|异常|失败|问题|fix/i', $subject)) {
            return 'fix';
        }
        if (preg_match('/文档|说明|docs?/i', $subject)) {
            return 'docs';
        }
        if ($this->isTestOnlyChange($change) || preg_match('/测试|test|spec/i', $subject)) {
            return 'test';
        }
        if (preg_match('/重构|refactor/i', $subject)) {
            return 'refactor';
        }
        return 'feat';
    }

    private function summaryCandidates($summary)
    {
        $summary = trim($summary);
        if ($summary === '' || $summary === 'Claude Code 已完成代码修改，请以 diff 为准进行 Review。') {
            return [];
        }

        $candidates = [];
        foreach (preg_split('/\R/u', $summary) as $line) {
            $line = trim($line);
            if ($line === '' || $this->isMetaSummaryLine($line)) {
                continue;
            }
            $line = preg_replace('/^[-*]\s+/u', '', $line);
            $line = preg_replace('/^\d+[.)]\s+/u', '', $line);
            if ($line === '' || $this->isMetaSummaryLine($line)) {
                continue;
            }
            $candidates[] = $line;
            if (count($candidates) >= 4) {
                break;
            }
        }

        return $candidates;
    }

    private function subjectFromChangedFiles($change)
    {
        $files = $this->changedFiles($change);
        if (!$files) {
            return '';
        }
        $joined = implode("\n", $files);
        if (preg_match('/GenerationExecutorService|AiDevGenerationJob|enqueueGeneration|AiRunPanel|RequirementDetail|TaskDetail|ProjectConfig/u', $joined)) {
            return '异步化 AI 生成任务流程';
        }
        if (preg_match('/CommitService\.php$/m', $joined)) {
            return '优化 commit message 生成逻辑';
        }

        $groups = [
            '前端页面' => '/^src\/views\//',
            '前端组件' => '/^src\/components\//',
            '后端服务' => '/backend\/thinkphp\/app\/service\//',
            '后端任务' => '/backend\/thinkphp\/app\/job\//',
            '接口路由' => '/backend\/thinkphp\/route\//',
        ];
        foreach ($groups as $label => $pattern) {
            $matched = array_values(array_filter($files, function ($file) use ($pattern) {
                return preg_match($pattern, $file);
            }));
            if (count($matched) >= max(2, (int) ceil(count($files) / 2))) {
                return '更新' . $label . '逻辑';
            }
        }

        $name = basename($files[0]);
        return count($files) === 1 ? '更新 ' . $name : '更新代码改动';
    }

    private function isMetaSummaryLine($line)
    {
        if (preg_match('/^```[A-Za-z0-9_-]*$/u', $line) || $line === '```') {
            return true;
        }
        if (preg_match('/\\\\$/u', $line)) {
            return true;
        }
        if (preg_match('/^#{1,6}\s*/u', $line) || preg_match('/^-{3,}$/u', $line)) {
            return true;
        }
        if (preg_match('/^\$?\s*(php|composer|npm|yarn|pnpm|git|cd|ls|cat|grep|rg|curl)\b/i', $line)) {
            return true;
        }
        if (preg_match('/^\s*-{1,2}[A-Za-z]\b/u', $line)) {
            return true;
        }
        if (preg_match('/^(H\s+)?["\']?(Content-Type|Accept|Authorization|User-Agent)\s*:/iu', $line)) {
            return true;
        }
        if (preg_match('/\b(application\/json|multipart\/form-data|Bearer\s+[A-Za-z0-9._-]+)/iu', $line)) {
            return true;
        }
        if (strpos($line, '|') !== false) {
            return true;
        }
        if (preg_match('/(无需修改|与计划一致|文件|类型|Review|验证|检查|diff|提交依据|复盘)/iu', $line)) {
            return true;
        }
        if (preg_match('/(响应体|响应参数|返回参数|返回结果|请求参数|接口示例|调用示例|字段|顶层|errno|success|msg|data)/iu', $line)) {
            return true;
        }
        if (preg_match('/\b[\w\/.-]+\.(php|js|vue|css|sql|md|json|yml|yaml|ts|tsx)\b/i', $line)) {
            return true;
        }
        return false;
    }

    private function cleanSubject($text)
    {
        $subject = trim(strip_tags((string) $text));
        if (
            $subject === ''
            || preg_match('/^(测试|test|需求|开发|改动|任务)\s*[#\d一二三四五六七八九十._-]*$/iu', $subject)
            || preg_match('/^(更新|修改|调整)?代码(改动|变更)?$/u', $subject)
            || preg_match('/^[#\d一二三四五六七八九十._-]+$/u', $subject)
            || preg_match('/^```[A-Za-z0-9_-]*$/u', $subject)
            || $this->isMetaSummaryLine($subject)
        ) {
            return '';
        }

        $subject = preg_replace('/^[-*#\s]+/u', '', $subject);
        $subject = preg_replace('/^(本次|此次)?(代码)?(主要)?(改动|变更|实现内容)[：:，,\s]*/u', '', $subject);
        $subject = preg_replace('/^在.+?(框架|项目|系统|模块)(内|中)?/u', '', $subject);
        $subject = preg_replace('/\s+/u', ' ', $subject);
        $subject = $this->trimSubjectEdges($subject);
        if ($subject === '' || $this->isMetaSummaryLine($subject)) {
            return '';
        }

        $specific = $this->specificSubject($subject);
        if ($specific !== '') {
            return $specific;
        }

        if (!preg_match('/[\x{4e00}-\x{9fff}]|^(add|update|change|modify|fix|remove|implement)\b/iu', $subject)) {
            return '';
        }
        if (preg_match('/(新增|新建|添加|实现|修改|调整|优化|修复|删除|移除).{2,42}/u', $subject, $match)) {
            $subject = $match[0];
        }
        $subject = preg_replace('/^(新建|添加|实现)/u', '新增', $subject);
        return $this->trimSubjectEdges($subject);
    }

    private function trimSubjectEdges($text)
    {
        return preg_replace('/^[\s。；;，,]+|[\s。；;，,]+$/u', '', (string) $text);
    }

    private function changedFiles($change)
    {
        if (!$change || empty($change['changed_files'])) {
            return [];
        }
        $files = json_decode((string) $change['changed_files'], true);
        if (!is_array($files)) {
            return [];
        }
        return array_values(array_filter(array_map(function ($file) {
            return trim((string) $file);
        }, $files)));
    }

    private function isTestOnlyChange($change)
    {
        $files = $this->changedFiles($change);
        if (!$files) {
            return false;
        }
        foreach ($files as $file) {
            if (!preg_match('/(^|\/)(__tests__|tests?|spec)\/|(\.|-)(test|spec)\.[A-Za-z0-9]+$/i', $file)) {
                return false;
            }
        }
        return true;
    }

    private function specificSubject($subject)
    {
        $isFix = preg_match('/(修复|错误|异常|失败|问题|bug|fix)/iu', $subject);
        $isChange = preg_match('/(修改|调整|优化|更新|重构)/u', $subject);
        $action = $isFix ? '修复' : ($isChange ? '修改' : '新增');

        if (preg_match('/(冒泡排序|冒泡算法).*?(接口|API)|接口.*?(冒泡排序|冒泡算法)/iu', $subject)) {
            return $action . '冒泡排序算法接口';
        }
        if (preg_match('/用户分层.*?逻辑|逻辑.*?用户分层/u', $subject)) {
            return $action . '用户分层逻辑';
        }
        return '';
    }

    private function firstUsefulSentence($text)
    {
        $text = trim(strip_tags((string) $text));
        if ($text === '') {
            return '';
        }
        $text = preg_replace('/^[-*#\s]+/u', '', $text);
        $parts = preg_split('/[。；;\n\r]+/u', $text);
        foreach ($parts as $part) {
            $part = trim(preg_replace('/\s+/u', ' ', $part));
            if ($part !== '') {
                return $part;
            }
        }
        return $text;
    }

    private function trimLine($text, $limit)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) $text));
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit - 1) . '…' : $text;
        }
        return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
    }

    public function commit($taskId, $message)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task || $task['status'] !== 'ready_to_commit') {
            throw new \RuntimeException('当前状态不能提交');
        }
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        $worktreeService = new WorktreeService();
        $worktree = $worktreeService->path($project, $task);
        if (!is_dir($worktree)) {
            throw new \RuntimeException('工作副本不存在，无法提交');
        }
        $reviewedChange = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('id', 'desc')->find();
        if (!$reviewedChange) {
            throw new \RuntimeException('没有已 Review 的代码快照');
        }
        $currentDiff = [];
        exec('git -C ' . escapeshellarg($worktree) . ' diff HEAD', $currentDiff, $diffCode);
        if ($diffCode !== 0) {
            throw new \RuntimeException('读取待提交 diff 失败');
        }
        $normalizeDiff = function ($diff) {
            return rtrim(str_replace("\r\n", "\n", (string) $diff));
        };
        if ($normalizeDiff(implode("\n", $currentDiff)) !== $normalizeDiff((string) $reviewedChange['git_diff_snapshot'])) {
            throw new \RuntimeException('Review 后代码又发生了变化，请重新运行 AI 执行结果采集与 Review 后再提交');
        }
        exec('git -C ' . escapeshellarg($worktree) . ' add -A');
        exec('git -C ' . escapeshellarg($worktree) . ' commit -m ' . escapeshellarg($message) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException('git commit 失败：' . implode("\n", $output));
        }
        exec('git -C ' . escapeshellarg($worktree) . ' rev-parse HEAD', $hashOutput, $hashCode);
        $hash = $hashCode === 0 && !empty($hashOutput[0]) ? $hashOutput[0] : '';
        Db::name('ai_dev_tasks')->where('id', $taskId)->update([
            'status' => 'committed',
            'commit_message' => $message,
            'commit_hash' => $hash,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $result = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        try {
            $result['worktree_removed'] = $worktreeService->remove($project, $task, true);
        } catch (\Throwable $e) {
            $result['worktree_cleanup_error'] = $e->getMessage();
        }
        return $result;
    }

    public function push($taskId)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        if (!$project['allow_auto_push']) {
            throw new \RuntimeException('项目配置禁止自动 push');
        }
        $repoPath = rtrim($project['local_path'], '/');
        exec('git -C ' . escapeshellarg($repoPath) . ' push origin ' . escapeshellarg($task['final_branch_name']) . ' 2>&1', $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException('git push 失败：' . implode("\n", $output));
        }
        Db::name('ai_dev_tasks')->where('id', $taskId)->update([
            'is_pushed' => 1,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::name('ai_dev_tasks')->where('id', $taskId)->find();
    }
}
