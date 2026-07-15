<?php

namespace app\service\AiDev;

class WorktreeService
{
    public function path(array $project, array $task)
    {
        $prefix = (string) config('ai_dev.worktree.prefix', 'wt-task-');
        $prefix = preg_replace('/[^A-Za-z0-9._-]+/', '-', $prefix);
        if ($prefix === '') {
            $prefix = 'wt-task-';
        }
        return dirname(rtrim($project['local_path'], '/')) . '/' . $prefix . $task['id'];
    }

    /** 编码前确保 worktree 存在且位于工单分支；分支不一致时强制重建。 */
    public function ensure(array $project, array $task, RunService $runService = null, $runId = 0)
    {
        $repoPath = rtrim($project['local_path'], '/');
        if (!is_dir($repoPath . '/.git')) {
            throw new \RuntimeException('项目本地目录不是 git 仓库');
        }

        $expectedBranch = trim((string) $task['final_branch_name']);
        if ($expectedBranch === '' || trim((string) $task['base_branch']) === '') {
            throw new \RuntimeException('工单分支名或基准分支为空');
        }

        $worktree = $this->path($project, $task);
        if (is_dir($worktree)) {
            $branchLines = [];
            exec('git -C ' . escapeshellarg($worktree) . ' rev-parse --abbrev-ref HEAD 2>/dev/null', $branchLines, $branchCode);
            $actualBranch = $branchCode === 0 && isset($branchLines[0]) ? trim((string) $branchLines[0]) : '';
            if ($actualBranch === $expectedBranch) {
                return $worktree;
            }
            $message = "worktree 分支 {$actualBranch} 与工单 {$expectedBranch} 不一致，将重建 worktree";
            if ($runService && $runId > 0) {
                $runService->appendLog($runId, 'git', $message);
            }
            $this->remove($project, $task, true);
        }

        if ((bool) config('ai_dev.safety.require_clean_repo', false)) {
            $statusLines = [];
            exec('git -C ' . escapeshellarg($repoPath) . ' status --porcelain', $statusLines);
            if (count($statusLines) > 0) {
                throw new \RuntimeException('主工作目录存在未提交改动，已阻断执行');
            }
        }

        $baseBranch = trim((string) $task['base_branch']);
        $fetchOutput = [];
        $remoteRef = 'refs/remotes/origin/' . $baseBranch;
        $remoteRefOutput = [];
        exec(
            'git -C ' . escapeshellarg($repoPath) . ' show-ref --verify --quiet ' . escapeshellarg($remoteRef),
            $remoteRefOutput,
            $remoteRefCode
        );
        if ($remoteRefCode !== 0) {
            $fetchRefspec = '+refs/heads/' . $baseBranch . ':refs/remotes/origin/' . $baseBranch;
            $fetchCmd = 'git -C ' . escapeshellarg($repoPath) . ' fetch origin ' . escapeshellarg($fetchRefspec) . ' 2>&1';
            exec($fetchCmd, $fetchOutput, $fetchCode);
            if ($runService && $runId > 0) {
                $runService->appendLog($runId, 'git', "更新基准分支:\n" . implode("\n", $fetchOutput));
            }
            if ($fetchCode !== 0) {
                throw new \RuntimeException('更新远程基准分支失败，请检查 origin 和凭据');
            }
        } elseif ($runService && $runId > 0) {
            $runService->appendLog($runId, 'git', "基准分支 origin/{$baseBranch} 已存在，跳过 fetch");
        }

        $localBranchOutput = [];
        exec(
            'git -C ' . escapeshellarg($repoPath) . ' show-ref --verify --quiet refs/heads/'
                . escapeshellarg($expectedBranch),
            $localBranchOutput,
            $localBranchCode
        );
        if ($localBranchCode === 0) {
            $cmd = sprintf(
                'git -C %s worktree add %s %s 2>&1',
                escapeshellarg($repoPath),
                escapeshellarg($worktree),
                escapeshellarg($expectedBranch)
            );
        } else {
            $cmd = sprintf(
                'git -C %s worktree add %s -b %s origin/%s 2>&1',
                escapeshellarg($repoPath),
                escapeshellarg($worktree),
                escapeshellarg($expectedBranch),
                escapeshellarg($task['base_branch'])
            );
        }
        $output = [];
        exec($cmd, $output, $code);
        if ($runService && $runId > 0) {
            $runService->appendLog($runId, 'git', implode("\n", $output));
        }
        if ($code !== 0) {
            throw new \RuntimeException('创建 git worktree 失败: ' . implode("\n", $output));
        }
        return $worktree;
    }

    public function remove(array $project, array $task, $force = false)
    {
        $repoPath = rtrim($project['local_path'], '/');
        $worktree = $this->path($project, $task);
        if (!is_dir($worktree)) {
            return '';
        }

        $cmd = 'git -C ' . escapeshellarg($repoPath) . ' worktree remove '
            . ($force ? '--force ' : '')
            . escapeshellarg($worktree) . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0) {
            throw new \RuntimeException('清理 git worktree 失败：' . implode("\n", $output));
        }
        $this->prune($repoPath);
        return $worktree;
    }

    public function status(array $project, array $task)
    {
        $repoPath = rtrim($project['local_path'], '/');
        $worktree = $this->path($project, $task);
        $exists = is_dir($worktree);
        $registered = false;

        if (is_dir($repoPath . '/.git')) {
            exec('git -C ' . escapeshellarg($repoPath) . ' worktree list --porcelain', $lines);
            foreach ($lines as $line) {
                if (strpos($line, 'worktree ') === 0 && trim(substr($line, 9)) === $worktree) {
                    $registered = true;
                    break;
                }
            }
        }

        $branch = '';
        $head = '';
        $dirty = false;
        if ($exists) {
            exec('git -C ' . escapeshellarg($worktree) . ' rev-parse --abbrev-ref HEAD 2>/dev/null', $branchLines, $branchCode);
            exec('git -C ' . escapeshellarg($worktree) . ' rev-parse --short HEAD 2>/dev/null', $headLines, $headCode);
            exec('git -C ' . escapeshellarg($worktree) . ' status --porcelain 2>/dev/null', $statusLines);
            $branch = $branchCode === 0 && !empty($branchLines[0]) ? $branchLines[0] : '';
            $head = $headCode === 0 && !empty($headLines[0]) ? $headLines[0] : '';
            $dirty = count($statusLines) > 0;
        }

        return [
            'path' => $worktree,
            'exists' => $exists,
            'registered' => $registered,
            'branch' => $branch,
            'head' => $head,
            'dirty' => $dirty,
        ];
    }

    private function prune($repoPath)
    {
        exec('git -C ' . escapeshellarg($repoPath) . ' worktree prune 2>&1');
    }
}
