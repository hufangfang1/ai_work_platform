<?php

namespace app\service\AiDev;

use think\facade\Db;

class AgentExecutorService
{
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
        $promptFile = $worktree . '/.ai-dev-prompt-' . $runId . '.md';
        file_put_contents($promptFile, $run['input']);

        $allowedTools = $this->buildAllowedTools($project);
        $claudeCommand = function_exists('config') ? config('ai_dev.agent.command', 'claude') : 'claude';
        // claude 在 --print 模式下用 stream-json 必须同时带 --verbose,否则直接报错退出。
        $cmd = sprintf(
            '%s -p "$(cat %s)" --output-format stream-json --verbose --permission-mode acceptEdits --allowedTools %s --max-turns 50',
            escapeshellcmd($claudeCommand),
            escapeshellarg($promptFile),
            escapeshellarg($allowedTools)
        );

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($cmd, $descriptors, $pipes, $worktree);
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
        $output = '';
        $error = '';
        while (true) {
            $line = fgets($pipes[1]);
            if ($line !== false) {
                $output .= $line;
                $this->handleStreamLine($runService, $runId, trim($line));
            }
            $err = fgets($pipes[2]);
            if ($err !== false) {
                $error .= $err;
                $runService->appendLog($runId, 'stderr', trim($err));
            }

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() - $startedAt > 1800) {
                proc_terminate($process);
                $runService->finish($runId, 'failed', $output, '执行超时');
                (new TaskService())->updateStatus((int) $run['task_id'], 'failed');
                return;
            }
            usleep(100000);
        }

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            $runService->finish($runId, 'failed', $output, $error);
            (new TaskService())->updateStatus((int) $run['task_id'], 'failed');
            return;
        }

        $this->collectChange($run, $task, $project, $worktree);
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

    private function handleStreamLine(RunService $runService, $runId, $line)
    {
        if ($line === '') {
            return;
        }
        $event = json_decode($line, true);
        if (is_array($event)) {
            $type = isset($event['type']) ? $event['type'] : 'json';
            $content = json_encode($event, JSON_UNESCAPED_UNICODE);
            $runService->appendLog($runId, $type, $content);
            return;
        }
        $runService->appendLog($runId, 'stdout', $line);
    }

    private function collectChange(array $run, array $task, array $project, $worktree)
    {
        exec('git -C ' . escapeshellarg($worktree) . ' diff --name-only', $files);
        exec('git -C ' . escapeshellarg($worktree) . ' diff', $diffLines);
        $testResult = $this->runProjectCommand($worktree, $project['test_command']);
        Db::name('ai_dev_changes')->insert([
            'task_id' => $task['id'],
            'run_id' => $run['id'],
            'diff_summary' => 'Claude Code 已完成代码修改，请以 diff 为准进行 Review。',
            'changed_files' => json_encode($files, JSON_UNESCAPED_UNICODE),
            'git_diff_snapshot' => implode("\n", $diffLines),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Db::name('ai_dev_reviews')->where('task_id', $task['id'])->delete();
        Db::name('ai_dev_runs')->where('id', $run['id'])->update(['output' => $testResult]);
    }

    private function runProjectCommand($worktree, $command)
    {
        if ($command === '') {
            return '未配置测试命令';
        }
        exec('cd ' . escapeshellarg($worktree) . ' && ' . $command . ' 2>&1', $output, $code);
        return "命令：{$command}\n退出码：{$code}\n" . implode("\n", $output);
    }
}
