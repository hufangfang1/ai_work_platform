<?php

namespace app\service\AiDev;

use think\facade\Db;

class CommitService
{
    public function generateMessage($taskId)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        $change = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('created_at', 'desc')->find();

        return [
            'commit_message' => $this->buildMessage($task, $change),
        ];
    }

    private function buildMessage(array $task, $change)
    {
        $scope = $this->normalizeScope($task['repo_name']);
        $subject = $this->buildSubject($task, $change);
        $type = $this->inferType($subject);
        return "{$type}({$scope}): {$subject}";
    }

    private function normalizeScope($repoName)
    {
        $scope = preg_replace('/[^A-Za-z0-9_-]+/', '-', strtolower(trim((string) $repoName)));
        return trim($scope, '-') !== '' ? trim($scope, '-') : 'app';
    }

    private function buildSubject(array $task, $change)
    {
        $candidates = [];
        if (!empty($task['scope_summary'])) {
            $candidates[] = $this->firstUsefulSentence($task['scope_summary']);
        }
        if ($change) {
            $candidates = array_merge($candidates, $this->summaryCandidates((string) $change['diff_summary']));
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

    private function inferType($subject)
    {
        if (preg_match('/修复|bug|错误|异常|失败|问题|fix/i', $subject)) {
            return 'fix';
        }
        if (preg_match('/文档|说明|docs?/i', $subject)) {
            return 'docs';
        }
        if (preg_match('/测试|test|spec/i', $subject)) {
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
        if (preg_match('/(无需修改|与计划一致|文件|类型|说明|Review|验证|检查|diff|提交依据|复盘)/iu', $line)) {
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
            || preg_match('/^(测试|test|需求|开发|改动|任务)$/iu', $subject)
            || preg_match('/^```[A-Za-z0-9_-]*$/u', $subject)
            || $this->isMetaSummaryLine($subject)
        ) {
            return '';
        }

        $subject = preg_replace('/^[-*#\s]+/u', '', $subject);
        $subject = preg_replace('/^(本次|此次)?(代码)?(主要)?(改动|变更|实现内容)[：:，,\s]*/u', '', $subject);
        $subject = preg_replace('/^在.+?(框架|项目|系统|模块)(内|中)?/u', '', $subject);
        $subject = preg_replace('/\s+/u', ' ', $subject);
        $subject = trim($subject, " \t\n\r\0\x0B。；;，,");
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
        return trim($subject, " \t\n\r\0\x0B。；;，,");
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
