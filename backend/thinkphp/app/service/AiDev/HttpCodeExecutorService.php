<?php

namespace app\service\AiDev;

use think\facade\Db;

/** 由后端掌控读写、patch 应用和验证的 HTTP 代码执行器。 */
class HttpCodeExecutorService
{
    public function execute($runId)
    {
        $runService = new RunService();
        $run = $runService->detail($runId);
        if (!$run) {
            throw new \RuntimeException('执行记录不存在');
        }
        $task = Db::name('ai_dev_tasks')->where('id', (int) $run['task_id'])->find();
        $project = $task ? Db::name('ai_dev_projects')->where('id', (int) $task['project_id'])->find() : null;
        if (!$task || !$project) {
            throw new \RuntimeException('代码执行对应的工单或项目不存在');
        }
        $worktree = (new WorktreeService())->ensure($project, $task, $runService, $runId);
        $runService->appendLog($runId, 'step', 'worktree 已就绪: ' . $worktree);
        (new CommandSafetyService())->assertProjectChecks($project);
        $runService->markRunning($runId, 0);
        $profile = (new ModelProfileService())->profile((string) $run['model_name']);
        if (!$profile || !(new ModelProfileService())->isHttp((string) $run['model_name'])) {
            throw new \RuntimeException('代码执行必须使用 HTTP 模型档案');
        }

        $prompt = $this->buildPrompt((string) $run['input'], $worktree);
        $runService->appendLog($runId, 'step', '正在流式请求模型生成代码补丁…');
        $content = (new HttpChatService())->complete($profile, $prompt, [
            'timeout' => (int) config('ai_dev.agent.code_http_timeout', 300),
            'stream' => true,
            'on_log' => function ($type, $message) use ($runService, $runId) {
                $runService->appendLog($runId, $type, $message);
            },
            'on_stream' => (new HttpStreamLogService())->tracker($runService, $runId, 'coding'),
            'should_cancel' => $runService->cancelChecker($runId),
        ]);
        $runService->assertNotCancelled($runId);
        $runService->appendLog($runId, 'step', '模型响应完成，共 ' . mb_strlen($content) . ' 字符');
        $data = $this->extractJson($content);
        $patch = $this->normalizePatch(trim((string) ($data['patch'] ?? '')));
        if ($patch === '') {
            throw new \RuntimeException('HTTP 代码模型未返回 patch，拒绝继续');
        }
        $patch = $this->ensureDiffGitHeaders($patch);
        $patchFiles = $this->listPatchFiles($patch);
        $runService->appendLog(
            $runId,
            'step',
            'patch 已规范化，共 ' . count($patchFiles) . ' 个文件: ' . implode('、', $patchFiles)
        );
        $this->applyPatch($worktree, $patch, $runId, $runService);
        list($checks, $failed) = $this->runChecks($project, $worktree, $runService, $runId);
        $change = $this->collectChange($task, $run, $worktree, $data, $checks);
        $runService->appendLog($runId, 'step', '编码完成，变更已写入 worktree');
        Db::name('ai_dev_reviews')->where('task_id', (int) $task['id'])->delete();
        (new TaskService())->updateStatus((int) $task['id'], 'code_changed');
        $runService->assertNotCancelled($runId);
        $runService->finish($runId, 'succeeded', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), $failed ? '项目检查未通过，请查看检查输出后继续修复' : '');
        return $change;
    }

    private function buildPrompt($instruction, $worktree)
    {
        $files = (new GitWorktreeService())->lines($worktree, ['ls-files']);
        $context = [];
        $total = 0;
        foreach ($this->candidateFiles($instruction, $files) as $file) {
            $full = rtrim($worktree, '/') . '/' . $file;
            if (!is_file($full) || !is_readable($full)) {
                continue;
            }
            $content = (string) file_get_contents($full);
            if (strpos($content, "\0") !== false) {
                continue;
            }
            $content = mb_substr($content, 0, 24000);
            $context[] = "### {$file}\n```\n{$content}\n```";
            $total += mb_strlen($content);
            if ($total >= 180000) {
                break;
            }
        }
        $diff = (new GitWorktreeService())->output($worktree, ['diff', 'HEAD']);
        return "你是代码修改执行器。后端会负责写文件和运行检查，你不能调用工具。\n"
            . "严格按任务和 Review 反馈修改，只返回 JSON，不要 Markdown。\n"
            . "必须返回结构：{\"summary_subject\":\"...\",\"patch\":\"完整 unified diff\",\"changed_files\":[\"相对路径\"],\"unresolved_risks\":[]}。\n"
            . "patch 必须可由 git apply 应用；每个 @@ hunk 头部的行数必须与下方 +/- 行严格一致；只能修改任务范围内文件，不要修改 .git、依赖目录或生成临时文件。没有可修复内容时 patch 不能为空，需在 unresolved_risks 说明并返回最小安全修复。\n\n"
            . "# 原任务\n" . trim($instruction) . "\n\n"
            . "# 当前 git diff\n" . ($diff !== '' ? $diff : '(暂无改动)') . "\n\n"
            . "# 可读取代码上下文\n" . ($context ? implode("\n\n", $context) : '(未找到候选文件)');
    }

    private function candidateFiles($instruction, array $files)
    {
        $selected = [];
        foreach ($files as $file) {
            if (preg_match('/^(src|app|backend|config|route|routes|pages|api)\/.+\.(php|vue|ts|tsx|js|jsx|json|go|py)$/i', $file)) {
                $selected[] = $file;
            }
        }
        $mentioned = [];
        preg_match_all('#(?:src|app|backend|config|route|routes|pages|api)/[A-Za-z0-9_.\-]+\.(?:php|vue|ts|tsx|js|jsx|json|go|py)#i', $instruction, $matches);
        foreach ($matches[0] ?? [] as $file) {
            if (in_array($file, $files, true)) {
                array_unshift($mentioned, $file);
            }
        }
        return array_values(array_unique(array_merge($mentioned, $selected)));
    }

    private function listPatchFiles($patch)
    {
        $files = [];
        if (preg_match_all('/diff --git a\/(.+?) b\/\1/', $patch, $matches)) {
            $files = $matches[1];
        } elseif (preg_match_all('/^\+\+\+ b\/(.+)$/m', $patch, $matches)) {
            foreach ($matches[1] as $file) {
                if ($file !== '/dev/null') {
                    $files[] = $file;
                }
            }
        }
        return array_values(array_unique($files));
    }

    /** 修正模型常见 patch 瑕疵：hunk 行数不符、错误的 No newline 标记等。 */
    private function normalizePatch($patch)
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", rtrim((string) $patch, "\n")));
        $out = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (!preg_match('/^@@ -(\d+)(?:,(\d+))? \+(\d+)(?:,(\d+))? @@(.*)$/', $line, $matches)) {
                if (preg_match('/^\+\\\\ No newline at end of file$/', $line)) {
                    $out[] = '\\ No newline at end of file';
                } else {
                    $out[] = $line;
                }
                continue;
            }

            $oldStart = (int) $matches[1];
            $newStart = (int) $matches[3];
            $suffix = (string) $matches[5];
            $i++;
            $oldCount = 0;
            $newCount = 0;
            $body = [];
            while ($i < $count) {
                $chunk = $lines[$i];
                if (preg_match('/^@@ /', $chunk)
                    || strpos($chunk, 'diff --git ') === 0
                    || preg_match('/^--- /', $chunk)
                    || preg_match('/^\+\+\+ /', $chunk)) {
                    break;
                }
                if ($chunk === '\\ No newline at end of file' || preg_match('/^\+\\\\ No newline at end of file$/', $chunk)) {
                    $body[] = '\\ No newline at end of file';
                    $i++;
                    continue;
                }
                $body[] = $chunk;
                if ($chunk !== '') {
                    $prefix = $chunk[0];
                    if ($prefix === '+') {
                        $newCount++;
                    } elseif ($prefix === '-') {
                        $oldCount++;
                    } elseif ($prefix === ' ') {
                        $oldCount++;
                        $newCount++;
                    }
                }
                $i++;
            }
            $i--;
            $out[] = "@@ -{$oldStart},{$oldCount} +{$newStart},{$newCount} @@{$suffix}";
            foreach ($body as $bodyLine) {
                $out[] = $bodyLine;
            }
        }

        return $out ? implode("\n", $out) . "\n" : '';
    }

    /** 模型常省略 diff --git 头，git apply 需要补全。 */
    private function ensureDiffGitHeaders($patch)
    {
        $lines = explode("\n", rtrim((string) $patch, "\n"));
        $out = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];
            if (preg_match('/^--- (.+)$/', $line, $oldMatch)
                && $i + 1 < $count
                && preg_match('/^\+\+\+ b\/(.+)$/', $lines[$i + 1], $newMatch)
                && ($i === 0 || !preg_match('/^diff --git /', $lines[$i - 1]))) {
                $path = $newMatch[1];
                $isNew = $oldMatch[1] === '/dev/null';
                $out[] = "diff --git a/{$path} b/{$path}";
                if ($isNew) {
                    $out[] = 'new file mode 100644';
                    $out[] = 'index 0000000..1111111';
                }
            }
            $out[] = $line;
        }

        return $out ? implode("\n", $out) . "\n" : '';
    }

    private function applyPatch($worktree, $patch, $runId, RunService $runService = null)
    {
        $temp = new ProcessTempService();
        $dir = $temp->create($worktree, 'http-code', $runId);
        try {
            $file = $temp->writeFile($dir, 'change.patch', $patch);
            $command = 'git apply --check --whitespace=nowarn ' . escapeshellarg($file);
            $temp->exec($worktree, $command, $output, $code, 'http-patch-check', $runId);
            if ($runService) {
                $runService->appendLog($runId, 'git', 'patch 校验' . ($code === 0 ? '通过' : '失败') . "\n" . implode("\n", $output));
            }
            if ($code !== 0) {
                throw new \RuntimeException('模型返回的 patch 无法应用：' . implode("\n", $output));
            }
            $temp->exec($worktree, 'git apply --whitespace=nowarn ' . escapeshellarg($file), $output, $code, 'http-patch-apply', $runId);
            if ($runService) {
                $runService->appendLog($runId, 'git', 'patch 应用' . ($code === 0 ? '成功' : '失败') . "\n" . implode("\n", $output));
            }
            if ($code !== 0) {
                throw new \RuntimeException('应用模型 patch 失败：' . implode("\n", $output));
            }
        } finally {
            $temp->cleanup($dir);
        }
    }

    private function runChecks(array $project, $worktree, RunService $runService, $runId)
    {
        $outputs = [];
        $failed = [];
        foreach (['lint_command', 'test_command', 'build_command'] as $field) {
            $command = trim((string) ($project[$field] ?? ''));
            if ($command === '') {
                continue;
            }
            (new ProcessTempService())->exec($worktree, $command, $output, $code, 'http-' . $field, $runId);
            $text = "命令：{$command}\n退出码：{$code}\n" . implode("\n", $output);
            $outputs[] = $text;
            $runService->appendLog($runId, str_replace('_command', '', $field), $text);
            if ($code !== 0) {
                $failed[] = $command;
            }
        }
        return [implode("\n\n", $outputs), $failed];
    }

    private function collectChange(array $task, array $run, $worktree, array $data, $checks)
    {
        exec('git -C ' . escapeshellarg($worktree) . ' add -A -N');
        $git = new GitWorktreeService();
        $files = $git->lines($worktree, ['diff', 'HEAD', '--name-only']);
        $snapshot = $git->output($worktree, ['diff', 'HEAD']);
        if (!$files) {
            throw new \RuntimeException('模型 patch 未产生代码改动');
        }
        return Db::name('ai_dev_changes')->insertGetId([
            'task_id' => $task['id'],
            'run_id' => $run['id'],
            'diff_summary' => trim((string) ($data['summary_subject'] ?? 'HTTP 模型已完成代码修改')),
            'changed_files' => json_encode($files, JSON_UNESCAPED_UNICODE),
            'git_diff_snapshot' => $snapshot,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function extractJson($content)
    {
        $text = trim((string) $content);
        $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        if ($start === false || $end <= $start) {
            throw new \RuntimeException('HTTP 代码模型返回的不是 JSON');
        }
        $data = json_decode(substr($text, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            throw new \RuntimeException('HTTP 代码模型 JSON 解析失败：' . json_last_error_msg());
        }
        return $data;
    }
}
