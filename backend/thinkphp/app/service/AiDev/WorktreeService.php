<?php

namespace app\service\AiDev;

class WorktreeService
{
    public function path(array $project, array $task)
    {
        return dirname(rtrim($project['local_path'], '/')) . '/wt-task-' . $task['id'];
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
