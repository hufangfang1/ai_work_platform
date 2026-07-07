<?php

namespace app\service\AiDev;

use think\facade\Db;

class AgentExecutorService
{
    /** @var array|null stream-json 的最终 result 事件，失败时用于还原真实报错 */
    private $lastResultEvent = null;

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
        // prompt 文件放系统临时目录，避免混进 worktree 的 diff 和 git add -A 提交
        $promptFile = sys_get_temp_dir() . '/ai-dev-prompt-' . $runId . '.md';
        file_put_contents($promptFile, $run['input']);

        $allowedTools = $this->buildAllowedTools($project);
        $claudeCommand = function_exists('config') ? config('ai_dev.agent.command', 'claude') : 'claude';
        $modelProfile = new ModelProfileService();
        $modelKey = isset($run['model_name']) ? (string) $run['model_name'] : '';
        // claude 在 --print 模式下用 stream-json 必须同时带 --verbose,否则直接报错退出。
        $cmd = sprintf(
            '%s -p "$(cat %s)" --output-format stream-json --verbose --permission-mode acceptEdits --allowedTools %s --max-turns 50',
            escapeshellcmd($claudeCommand),
            escapeshellarg($promptFile),
            escapeshellarg($allowedTools)
        ) . $modelProfile->commandArg($modelKey);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $worktree, $modelProfile->processEnv($modelKey));
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
                $runService->finish($runId, 'failed', $output, '执行超时');
                (new TaskService())->updateStatus((int) $run['task_id'], 'failed');
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

        if ($exitCode !== 0) {
            $message = $this->buildFailureMessage($exitCode, $termSignal, $error);
            $runService->appendLog($runId, 'error', $message);
            $runService->finish($runId, 'failed', $output, $message);
            (new TaskService())->updateStatus((int) $run['task_id'], 'failed');
            return;
        }

        $this->collectChange($runService, $run, $task, $project, $worktree);
        @unlink($promptFile);
        $runService->finish($runId, 'succeeded', $output, $error);
        (new TaskService())->updateStatus((int) $run['task_id'], 'code_changed');
    }

    private function prepareWorktree(array $task, array $project, RunService $runService, $runId)
    {
        $repoPath = rtrim($project['local_path'], '/');
        if (!is_dir($repoPath . '/.git')) {
            throw new \RuntimeException('项目本地目录不是 git 仓库');
        }
        exec('git -C ' . escapeshellarg($repoPath) . ' status --porcelain', $statusLines);
        if (count($statusLines) > 0) {
            throw new \RuntimeException('主工作目录存在未提交改动，已阻断执行');
        }

        $worktree = dirname($repoPath) . '/wt-task-' . $task['id'];
        if (!is_dir($worktree)) {
            $cmd = sprintf(
                'git -C %s worktree add %s -b %s origin/%s',
                escapeshellarg($repoPath),
                escapeshellarg($worktree),
                escapeshellarg($task['final_branch_name']),
                escapeshellarg($task['base_branch'])
            );
            exec($cmd, $output, $code);
            $runService->appendLog($runId, 'git', implode("\n", $output));
            if ($code !== 0) {
                throw new \RuntimeException('创建 git worktree 失败');
            }
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
        if (is_array($this->lastResultEvent)) {
            $detail = isset($this->lastResultEvent['result']) ? trim((string) $this->lastResultEvent['result']) : '';
            $subtype = isset($this->lastResultEvent['subtype']) ? $this->lastResultEvent['subtype'] : '';
            if ($detail !== '' || $subtype !== '') {
                $parts[] = 'result[' . $subtype . ']: ' . mb_substr($detail, 0, 2000);
            }
        }
        if ($termSignal > 0) {
            $parts[] = 'Claude Code 被信号 ' . $termSignal . ' 终止（可能是外部 kill / 队列 worker 超时 / 系统资源限制）';
        } else {
            $parts[] = 'Claude Code 退出码 ' . $exitCode;
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
            if ($type === 'result') {
                $this->lastResultEvent = $event;
            }
            // thinking_tokens 心跳事件量极大且无信息量，不落库
            if ($type === 'system' && isset($event['subtype']) && $event['subtype'] === 'thinking_tokens') {
                return;
            }
            $content = json_encode($event, JSON_UNESCAPED_UNICODE);
            $runService->appendLog($runId, $type, $content);
            return;
        }
        $runService->appendLog($runId, 'stdout', $line);
    }

    private function collectChange(RunService $runService, array $run, array $task, array $project, $worktree)
    {
        // intent-to-add 让新增文件也出现在 diff / 变更文件列表里，与最终 git add -A 提交的范围一致
        exec('git -C ' . escapeshellarg($worktree) . ' add -A -N');
        exec('git -C ' . escapeshellarg($worktree) . ' diff --name-only', $files);
        exec('git -C ' . escapeshellarg($worktree) . ' diff', $diffLines);

        $summary = 'Claude Code 已完成代码修改，请以 diff 为准进行 Review。';
        if (is_array($this->lastResultEvent) && !empty($this->lastResultEvent['result'])) {
            $summary = $this->normalizeChangeSummary((string) $this->lastResultEvent['result']);
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

        $testResult = $this->runProjectCommand($worktree, $project['test_command']);
        $runService->appendLog($run['id'], 'test', $testResult);
    }

    private function runProjectCommand($worktree, $command)
    {
        if ($command === '') {
            return '未配置测试命令';
        }
        exec('cd ' . escapeshellarg($worktree) . ' && ' . $command . ' 2>&1', $output, $code);
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
