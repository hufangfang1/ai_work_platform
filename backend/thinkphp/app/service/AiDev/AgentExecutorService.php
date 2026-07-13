<?php

namespace app\service\AiDev;

use think\facade\Db;

class AgentExecutorService
{
    /** @var string|null agent 输出的最终结果文本，失败时用于还原真实报错 */
    private $lastResultText = null;
    /** @var string 本次运行使用的模型档案 key，决定命令与输出解析走 claude 还是 codex */
    private $modelKey = '';

    public function execute($runId)
    {
        $runService = new RunService();
        $run = $runService->detail($runId);
        if (!$run) {
            throw new \RuntimeException('执行记录不存在');
        }

        $task = Db::name('ai_dev_tasks')->where('id', $run['task_id'])->find();
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        $worktree = $this->prepareWorktree($task, $project, $runService, $runId);
        $tempService = new ProcessTempService();
        $tempDir = $tempService->create($worktree, 'agent', $runId);
        try {
            $promptFile = $tempService->writeFile($tempDir, 'prompt.md', $run['input']);

            $allowedTools = $this->buildAllowedTools($project);
            $modelProfile = new ModelProfileService();
            $modelKey = isset($run['model_name']) ? (string) $run['model_name'] : '';
            $this->modelKey = $modelKey;
            // 编码步骤要在 worktree 里真正改代码,全程无人值守。
            $cmd = $modelProfile->buildCommand($modelKey, $promptFile, [
                'permission_mode' => 'acceptEdits',
                'allowed_tools' => $allowedTools,
                'max_turns' => 50,
                'edit' => true,
            ]);

            $descriptors = [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($cmd, $descriptors, $pipes, $worktree, $modelProfile->processEnv($modelKey, $tempDir));
            if (!is_resource($process)) {
                throw new \RuntimeException('Claude Code 子进程启动失败');
            }

            $status = proc_get_status($process);
            $runService->markRunning($runId, (int) $status['pid']);
            // prompt 已通过命令行参数传入,关闭 stdin 避免 claude 空等 stdin。
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $startedAt = time();
            $timeout = (int) (function_exists('config') ? config('ai_dev.agent.timeout', 1800) : 1800);
            $output = '';
            $error = '';
            $exitCode = -1;
            $termSignal = 0;
            $stdoutBuf = '';
            $stderrBuf = '';
            $onStdout = function ($line) use ($runService, $runId, &$output) {
                $output .= $line . "\n";
                $this->handleStreamLine($runService, $runId, trim($line));
            };
            $onStderr = function ($line) use ($runService, $runId, &$error) {
                $error .= $line . "\n";
                if (trim($line) !== '') {
                    $runService->appendLog($runId, 'stderr', trim($line));
                }
            };
            while (true) {
                $this->drainPipe($pipes[1], $stdoutBuf, $onStdout);
                $this->drainPipe($pipes[2], $stderrBuf, $onStderr);

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    if (!empty($status['signaled'])) {
                        $termSignal = (int) $status['termsig'];
                    }
                    break;
                }
                if (time() - $startedAt > $timeout) {
                    proc_terminate($process);
                    $tempService->cleanup($tempDir);
                    $tempDir = '';
                    $runService->finish($runId, 'failed', $output, '执行超时');
                    $runService->restoreStatusAfterCodeRunFailure($run);
                    return;
                }
                usleep(100000);
            }

            $this->drainPipe($pipes[1], $stdoutBuf, $onStdout);
            $this->drainPipe($pipes[2], $stderrBuf, $onStderr);
            if ($stdoutBuf !== '') {
                $onStdout($stdoutBuf);
            }
            if ($stderrBuf !== '') {
                $onStderr($stderrBuf);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            $tempService->cleanup($tempDir);
            $tempDir = '';

            if ($exitCode !== 0) {
                $message = $this->buildFailureMessage($exitCode, $termSignal, $error);
                $runService->appendLog($runId, 'error', $message);
                $runService->finish($runId, 'failed', $output, $message);
                $runService->restoreStatusAfterCodeRunFailure($run);
                return;
            }

            $this->collectChange($runService, $run, $task, $project, $worktree);
            $runService->finish($runId, 'succeeded', $output, $error);
            (new TaskService())->updateStatus((int) $run['task_id'], 'code_changed');
        } finally {
            if ($tempDir !== '') {
                $tempService->cleanup($tempDir);
            }
        }
    }

    private function prepareWorktree(array $task, array $project, RunService $runService, $runId)
    {
        $repoPath = rtrim($project['local_path'], '/');
        if (!is_dir($repoPath . '/.git')) {
            throw new \RuntimeException('项目本地目录不是 git 仓库');
        }

        $worktree = (new WorktreeService())->path($project, $task);
        if (is_dir($worktree)) {
            $branchLines = [];
            exec('git -C ' . escapeshellarg($worktree) . ' rev-parse --abbrev-ref HEAD 2>/dev/null', $branchLines, $branchCode);
            $actualBranch = $branchCode === 0 && isset($branchLines[0]) ? trim((string) $branchLines[0]) : '';
            if ($actualBranch !== (string) $task['final_branch_name']) {
                throw new \RuntimeException('现有 worktree 分支与工单不一致: ' . $actualBranch);
            }
            return $worktree;
        }

        if (trim((string) $task['final_branch_name']) === '' || trim((string) $task['base_branch']) === '') {
            throw new \RuntimeException('工单分支名或基准分支为空');
        }

        if ((bool) config('ai_dev.safety.require_clean_repo', false)) {
            $statusLines = [];
            exec('git -C ' . escapeshellarg($repoPath) . ' status --porcelain', $statusLines);
            if (count($statusLines) > 0) {
                throw new \RuntimeException('主工作目录存在未提交改动，已阻断执行');
            }
        }

        $fetchOutput = [];
        $fetchRefspec = '+refs/heads/' . $task['base_branch'] . ':refs/remotes/origin/' . $task['base_branch'];
        $fetchCmd = 'git -C ' . escapeshellarg($repoPath) . ' fetch origin ' . escapeshellarg($fetchRefspec) . ' 2>&1';
        exec($fetchCmd, $fetchOutput, $fetchCode);
        $runService->appendLog($runId, 'git', "更新基准分支:\n" . implode("\n", $fetchOutput));
        if ($fetchCode !== 0) {
            throw new \RuntimeException('更新远程基准分支失败，请检查 origin 和凭据');
        }

        $localBranchOutput = [];
        exec(
            'git -C ' . escapeshellarg($repoPath) . ' show-ref --verify --quiet refs/heads/'
                . escapeshellarg($task['final_branch_name']),
            $localBranchOutput,
            $localBranchCode
        );
        if ($localBranchCode === 0) {
            $cmd = sprintf(
                'git -C %s worktree add %s %s 2>&1',
                escapeshellarg($repoPath),
                escapeshellarg($worktree),
                escapeshellarg($task['final_branch_name'])
            );
        } else {
            $cmd = sprintf(
                'git -C %s worktree add %s -b %s origin/%s 2>&1',
                escapeshellarg($repoPath),
                escapeshellarg($worktree),
                escapeshellarg($task['final_branch_name']),
                escapeshellarg($task['base_branch'])
            );
        }
        $output = [];
        exec($cmd, $output, $code);
        $runService->appendLog($runId, 'git', implode("\n", $output));
        if ($code !== 0) {
            throw new \RuntimeException('创建 git worktree 失败: ' . implode("\n", $output));
        }
        return $worktree;
    }

    private function buildAllowedTools(array $project)
    {
        $tools = ['Read', 'Edit', 'Write', 'Glob', 'Grep'];
        foreach (['test_command', 'lint_command', 'build_command'] as $field) {
            if (!empty($project[$field])) {
                $tools[] = 'Bash(' . $project[$field] . ':*)';
            }
        }
        return implode(',', $tools);
    }

    /**
     * 非阻塞读尽管道当前可读的数据，按整行回调，半行留在 $buf 等下一次。
     */
    private function drainPipe($pipe, &$buf, callable $onLine)
    {
        if (!is_resource($pipe)) {
            return;
        }
        while (($chunk = fread($pipe, 65536)) !== false && $chunk !== '') {
            $buf .= $chunk;
        }
        while (($pos = strpos($buf, "\n")) !== false) {
            $line = substr($buf, 0, $pos);
            $buf = (string) substr($buf, $pos + 1);
            $onLine($line);
        }
    }

    private function buildFailureMessage($exitCode, $termSignal, $error)
    {
        $parts = [];
        if (trim($error) !== '') {
            $parts[] = trim($error);
        }
        if ($this->lastResultText !== null && trim((string) $this->lastResultText) !== '') {
            $parts[] = 'result: ' . mb_substr(trim((string) $this->lastResultText), 0, 2000);
        }
        $label = (new ModelProfileService())->agentLabel($this->modelKey);
        if ($termSignal > 0) {
            $parts[] = $label . ' 被信号 ' . $termSignal . ' 终止（可能是外部 kill / 队列 worker 超时 / 系统资源限制）';
        } else {
            $parts[] = $label . ' 退出码 ' . $exitCode;
        }
        return implode("\n", $parts);
    }

    private function handleStreamLine(RunService $runService, $runId, $line)
    {
        if ($line === '') {
            return;
        }
        $event = json_decode($line, true);
        if (is_array($event)) {
            $type = isset($event['type']) ? $event['type'] : 'json';
            $resultText = (new ModelProfileService())->streamResultText($this->modelKey, $event);
            if ($resultText !== null) {
                $this->lastResultText = $resultText;
            }
            // thinking_tokens 心跳事件量极大且无信息量，不落库
            if ($type === 'system' && isset($event['subtype']) && $event['subtype'] === 'thinking_tokens') {
                return;
            }
            $runService->appendStreamEvent($runId, $type, $event);
            return;
        }
        $runService->appendLog($runId, 'stdout', $line);
    }

    private function collectChange(RunService $runService, array $run, array $task, array $project, $worktree)
    {
        // intent-to-add 让新增文件也出现在 diff / 变更文件列表里，与最终 git add -A 提交的范围一致
        exec('git -C ' . escapeshellarg($worktree) . ' add -A -N');
        exec('git -C ' . escapeshellarg($worktree) . ' diff HEAD --name-only', $files);
        exec('git -C ' . escapeshellarg($worktree) . ' diff HEAD', $diffLines);

        $summary = 'AI 已完成代码修改，请以 diff 为准进行 Review。';
        if ($this->lastResultText !== null && trim((string) $this->lastResultText) !== '') {
            $summary = $this->normalizeChangeSummary((string) $this->lastResultText);
        }

        Db::name('ai_dev_changes')->insert([
            'task_id' => $task['id'],
            'run_id' => $run['id'],
            'diff_summary' => $summary,
            'changed_files' => json_encode($files, JSON_UNESCAPED_UNICODE),
            'git_diff_snapshot' => implode("\n", $diffLines),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Db::name('ai_dev_reviews')->where('task_id', $task['id'])->delete();

        foreach (['lint_command' => 'lint', 'test_command' => 'test', 'build_command' => 'build'] as $field => $eventType) {
            $command = trim((string) (isset($project[$field]) ? $project[$field] : ''));
            if ($command === '') {
                continue;
            }
            $runService->appendLog($run['id'], $eventType, $this->runProjectCommand($worktree, $command));
        }
    }

    private function runProjectCommand($worktree, $command)
    {
        if ($command === '') {
            return '未配置测试命令';
        }
        (new ProcessTempService())->exec($worktree, $command, $output, $code, 'project-command');
        return "命令：{$command}\n退出码：{$code}\n" . implode("\n", $output);
    }

    private function normalizeChangeSummary($result)
    {
        $result = trim($result);
        $data = $this->extractJsonObject($result);
        if (!$data) {
            return $result;
        }

        $lines = [];
        if (!empty($data['summary_subject'])) {
            $lines[] = trim((string) $data['summary_subject']);
        }
        if (!empty($data['change_summary']) && is_array($data['change_summary'])) {
            foreach ($data['change_summary'] as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $lines[] = '- ' . $item;
                }
            }
        }
        if (!empty($data['verification_steps']) && is_array($data['verification_steps'])) {
            $lines[] = '';
            $lines[] = '验证建议:';
            foreach ($data['verification_steps'] as $item) {
                $item = trim((string) $item);
                if ($item !== '') {
                    $lines[] = '- ' . $item;
                }
            }
        }
        return $lines ? implode("\n", $lines) : $result;
    }

    private function extractJsonObject($text)
    {
        $cleaned = preg_replace('/^```(json)?\s*$|^```\s*$/m', '', trim($text));
        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');
        if ($start === false || $end === false || $end <= $start) {
            return null;
        }
        $data = json_decode(substr($cleaned, $start, $end - $start + 1), true);
        return is_array($data) ? $data : null;
    }
}
