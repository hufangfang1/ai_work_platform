<?php

namespace app\service\AiDev;

use think\facade\Db;

class GenerationExecutorService
{
    private $lastResultText = null;
    /** @var string[] stream 里历次 result 文本,避免最后一次只是说明性短句而丢掉真正的 JSON */
    private $resultTexts = [];
    private $modelKey = '';
    /** @var string Claude 流事件中的 session，用于达到轮次上限后的无工具收尾 */
    private $resumeSessionId = '';
    /** @var bool 最近一次结果是否因达到 max turns 而结束 */
    private $maxTurnsExceeded = false;

    public function execute($runId)
    {
        $this->lastResultText = null;
        $this->resultTexts = [];
        $this->resumeSessionId = '';
        $this->maxTurnsExceeded = false;
        $runService = new RunService();
        $run = $runService->detail($runId);
        if (!$run) {
            throw new \RuntimeException('执行记录不存在');
        }
        $payload = json_decode((string) $run['input'], true);
        if (!is_array($payload)) {
            throw new \RuntimeException('run input 不是合法 JSON');
        }
        $prompt = isset($payload['prompt']) ? (string) $payload['prompt'] : '';
        if (trim($prompt) === '') {
            throw new \RuntimeException('AI 生成任务缺少 prompt');
        }
        $options = isset($payload['options']) && is_array($payload['options']) ? $payload['options'] : [];
        $options['model_profile'] = isset($run['model_name']) ? (string) $run['model_name'] : '';
        if (!empty($options['finalize_only'])) {
            $prompt = '前一次计划生成已经完成代码研究。禁止调用任何工具、禁止继续搜索、禁止解释过程。'
                . '立即只输出原任务要求的最终 JSON 对象，必须包含 plan_markdown，且 plan_markdown 必须是完整可执行的开发计划。';
            $options['max_turns'] = 4;
            // 恢复会话的收尾不应再占用完整的计划生成时限；没有首个输出通常表示
            // 上游会话已悬挂，尽快失败让用户重试或改用新会话。
            $options['timeout'] = min((int) config('ai_dev.agent.plan_finalize_timeout', 90), 90);
            $options['allowed_tools'] = '';
            $options['disallowed_tools'] = $this->finalizationDisallowedTools();
        }
        $result = $this->runClaude($runId, $prompt, $options, $runService);
        $runService->assertNotCancelled($runId);
        $data = $this->extractJsonObject($result);
        $applied = $this->applyResult($run, $data);
        $output = is_array($applied) ? $applied : $data;
        $runService->assertNotCancelled($runId);
        $runService->finish($runId, 'succeeded', json_encode($output, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), '');
    }

    private function runClaude($runId, $prompt, array $options, RunService $runService)
    {
        $cwd = isset($options['cwd']) && $options['cwd'] !== '' ? $options['cwd'] : runtime_path();
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 300;
        $maxTurns = isset($options['max_turns']) ? (int) $options['max_turns'] : 8;
        @set_time_limit($timeout + 60);

        $modelProfile = new ModelProfileService();
        $modelKey = isset($options['model_profile']) ? (string) $options['model_profile'] : '';
        $this->modelKey = $modelKey;

        if (!$modelProfile->isHttp($modelKey)) {
            throw new \RuntimeException('生成任务必须使用 HTTP 模型档案: ' . $modelKey);
        }

        $runService->markRunning($runId, 0);
        $run = $runService->detail($runId);
        $runType = $run ? (string) $run['run_type'] : 'generic';
        $streamProfile = in_array($runType, ['ai_review', 'task_plan', 'coding'], true) ? $runType : 'generic';
        $stepMessage = $runType === 'ai_review'
            ? '正在流式请求模型进行代码审查…'
            : ($runType === 'task_plan' ? '正在流式请求模型生成开发计划…' : '正在流式请求模型…');
        $runService->appendLog($runId, 'stdout', 'HTTP 直调档案: ' . $modelKey);
        $runService->appendLog($runId, 'step', $stepMessage);
        $content = trim((new HttpChatService())->complete(
            $modelProfile->profile($modelKey),
            $prompt,
            [
                'timeout' => $timeout,
                'stream' => true,
                'on_log' => function ($type, $content) use ($runService, $runId) {
                    $runService->appendLog($runId, $type, $content);
                },
                'on_stream' => (new HttpStreamLogService())->tracker($runService, $runId, $streamProfile),
                'should_cancel' => $runService->cancelChecker($runId),
            ]
        ));
        if ($content !== '') {
            $runService->appendLog($runId, 'step', '模型响应完成，共 ' . mb_strlen($content) . ' 字符');
        }
        return $content;

        $tempService = new ProcessTempService();
        $tempDir = $tempService->create($cwd, 'generation', $runId);
        try {
            $promptFile = $tempService->writeFile($tempDir, 'prompt.md', $prompt);
            // 生成类任务只读仓库产出 JSON,不改代码。
            $cmd = $modelProfile->buildCommand($modelKey, $promptFile, [
                'max_turns' => $maxTurns,
                'allowed_tools' => isset($options['allowed_tools']) ? (string) $options['allowed_tools'] : '',
                'disallowed_tools' => isset($options['disallowed_tools']) ? (string) $options['disallowed_tools'] : '',
                'resume_session' => isset($options['resume_session']) ? (string) $options['resume_session'] : '',
                'edit' => false,
            ]);

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($cmd, $descriptors, $pipes, $cwd, $modelProfile->processEnv($modelKey, $tempDir));
            if (!is_resource($process)) {
                throw new \RuntimeException('claude 子进程启动失败');
            }
            $status = proc_get_status($process);
            $runService->markRunning($runId, (int) $status['pid']);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $startedAt = time();
            $output = '';
            $error = '';
            $exitCode = -1;
            $termSignal = 0;
            $salvaged = false;
            $finalizationRetries = 0;
            $maxFinalizationRetries = 1;
            $finalizationTurns = 4;
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
                    // 进程退出时最后一条 result 可能仍在管道缓冲区，先读取再判断原因。
                    $this->drainPipe($pipes[1], $stdoutBuf, $onStdout);
                    $this->drainPipe($pipes[2], $stderrBuf, $onStderr);
                    if ($stdoutBuf !== '') {
                        $onStdout($stdoutBuf);
                        $stdoutBuf = '';
                    }
                    if ($stderrBuf !== '') {
                        $onStderr($stderrBuf);
                        $stderrBuf = '';
                    }
                    if ($this->maxTurnsExceeded
                        && $finalizationRetries < $maxFinalizationRetries
                        && $this->resumeSessionId !== ''
                        && $modelProfile->agentType($modelKey) === 'claude'
                        && time() - $startedAt < $timeout
                    ) {
                        $finalizationRetries++;
                        fclose($pipes[1]);
                        fclose($pipes[2]);
                        proc_close($process);
                        $runService->appendLog(
                            $runId,
                            'retry',
                            '达到研究轮次上限，复用原会话进入无工具 JSON 收尾阶段 ' . $finalizationRetries . '/' . $maxFinalizationRetries
                        );
                        $finalPrompt = "你已经完成前序代码阅读。现在禁止调用任何工具、禁止继续搜索、禁止解释过程。"
                            . "只基于当前会话中已有证据，立即输出原任务要求的最终 JSON 对象；必须包含原约定的业务字段。";
                        $finalFile = $tempService->writeFile($tempDir, 'finalize-' . $finalizationRetries . '.md', $finalPrompt);
                        $finalOptions = [
                            'max_turns' => $finalizationTurns,
                            'allowed_tools' => '',
                            'disallowed_tools' => $this->finalizationDisallowedTools(),
                            'resume_session' => $this->resumeSessionId,
                            'edit' => false,
                        ];
                        $cmd = $modelProfile->buildCommand($modelKey, $finalFile, $finalOptions);
                        $process = proc_open($cmd, $descriptors, $pipes, $cwd, $modelProfile->processEnv($modelKey, $tempDir));
                        if (!is_resource($process)) {
                            throw new \RuntimeException('计划生成收尾子进程启动失败');
                        }
                        $restartStatus = proc_get_status($process);
                        Db::name('ai_dev_runs')->where('id', $runId)->update(['pid' => (int) $restartStatus['pid']]);
                        fclose($pipes[0]);
                        stream_set_blocking($pipes[1], false);
                        stream_set_blocking($pipes[2], false);
                        $stdoutBuf = '';
                        $stderrBuf = '';
                        $exitCode = -1;
                        $termSignal = 0;
                        $this->maxTurnsExceeded = false;
                        continue;
                    }
                    break;
                }
                if (time() - $startedAt > $timeout) {
                    proc_terminate($process);
                    $this->drainPipe($pipes[1], $stdoutBuf, $onStdout);
                    $this->drainPipe($pipes[2], $stderrBuf, $onStderr);
                    if ($stdoutBuf !== '') {
                        $onStdout($stdoutBuf);
                    }
                    if ($stderrBuf !== '') {
                        $onStderr($stderrBuf);
                    }
                    $candidate = $this->pickResultText($output);
                    if ($candidate !== '') {
                        try {
                            $this->extractJsonObject($candidate);
                            $runService->appendLog($runId, 'stdout', '已超过 ' . $timeout . 's 且进程未自行结束,但已取得完整结果,采用该结果结束本次运行。');
                            $salvaged = true;
                            break;
                        } catch (\RuntimeException $e) {
                            /* 尚无可用 JSON,继续走下方兜底 */
                        }
                    }
                    if ($this->lastResultText !== null && trim((string) $this->lastResultText) !== '') {
                        $runService->appendLog($runId, 'stdout', '已超过 ' . $timeout . 's 且进程未自行结束,但已取得完整结果,采用该结果结束本次运行。');
                        $salvaged = true;
                        break;
                    }
                    throw new \RuntimeException('执行超时');
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
        } finally {
            $tempService->cleanup($tempDir);
        }

        if (!$salvaged && $exitCode !== 0) {
            $message = $this->buildFailureMessage($exitCode, $termSignal, $error);
            throw new \RuntimeException($message);
        }
        return $this->pickResultText($output);
    }

    /**
     * Claude Code 在恢复会话时会暴露独立的 Task/Cron 工具；只禁用泛称 Task 无法阻止它们。
     */
    private function finalizationDisallowedTools()
    {
        return 'Read,Glob,Grep,Bash,WebFetch,WebSearch,Write,Edit,NotebookEdit,Task,TaskCreate,TaskGet,TaskList,TaskOutput,TaskStop,TaskUpdate,CronCreate,CronDelete,CronList,ScheduleWakeup,SendMessage,ReportFindings,EnterWorktree,ExitWorktree,DesignSync,Skill,Workflow';
    }

    private function applyResult(array $run, array $data)
    {
        if ($run['run_type'] === 'requirement_breakdown') {
            return (new BreakdownService())->finishRun($run, $data);
        }
        if ($run['run_type'] === 'task_plan') {
            return (new PlanService())->finishRun($run, $data);
        }
        if ($run['run_type'] === 'task_spec') {
            return (new SpecService())->finishRun($run, $data);
        }
        if ($run['run_type'] === 'project_description') {
            return (new ProjectService())->finishDescribeRun($run, $data);
        }
        if ($run['run_type'] === 'ai_review') {
            return (new ReviewService())->finishAiReviewRun($run, $data);
        }
        if ($run['run_type'] === 'commit_message') {
            return (new CommitService())->finishMessageRun($run, $data);
        }
        if ($run['run_type'] === 'branch_name') {
            return (new BranchService())->finishRun($run, $data);
        }
        throw new \RuntimeException('未知 AI 生成任务类型: ' . $run['run_type']);
    }

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

    private function handleStreamLine(RunService $runService, $runId, $line)
    {
        if ($line === '') {
            return;
        }
        $event = json_decode($line, true);
        if (is_array($event)) {
            $type = isset($event['type']) ? $event['type'] : 'json';
            if (!empty($event['session_id'])) {
                $this->resumeSessionId = (string) $event['session_id'];
            }
            if ($type === 'result' && isset($event['subtype']) && $event['subtype'] === 'error_max_turns') {
                $this->maxTurnsExceeded = true;
            }
            $resultText = (new ModelProfileService())->streamResultText($this->modelKey, $event);
            if ($resultText !== null) {
                $text = trim((string) $resultText);
                $this->lastResultText = $text;
                if ($text !== '') {
                    $this->resultTexts[] = $text;
                }
            }
            if ($type === 'system' && isset($event['subtype']) && $event['subtype'] === 'thinking_tokens') {
                return;
            }
            $runService->appendStreamEvent($runId, $type, $event);
            return;
        }
        $runService->appendLog($runId, 'stdout', $line);
    }

    private function pickResultText($output)
    {
        $candidates = $this->resultTexts;
        $output = trim((string) $output);
        if ($output !== '') {
            $candidates[] = $output;
        }
        $candidates = array_values(array_unique(array_filter($candidates, function ($text) {
            return trim((string) $text) !== '';
        })));
        if (!$candidates) {
            return '';
        }
        // 生成类任务的最终业务字段必须全部在此，否则会因工具事件 JSON 更长而误选日志。
        $markers = ['plan_markdown', 'spec_markdown', 'breakdown_markdown', 'description', 'commit_message', 'branch_name', 'blocking_issues'];
        foreach ($candidates as $text) {
            foreach ($markers as $marker) {
                if (strpos($text, '"' . $marker . '"') !== false) {
                    return $text;
                }
            }
        }
        $withJson = array_values(array_filter($candidates, function ($text) {
            return strpos($text, '{') !== false;
        }));
        if ($withJson) {
            usort($withJson, function ($a, $b) {
                return strlen($b) - strlen($a);
            });
            return $withJson[0];
        }
        return $candidates[count($candidates) - 1];
    }

    /** 从 ai_review run 日志打捞 Review JSON(解析落库失败时的兜底)。 */
    public function parseReviewResultFromRunLogs($runId)
    {
        $this->resultTexts = [];
        $this->lastResultText = null;
        $logs = (new RunService())->logs((int) $runId, 0);
        for ($i = count($logs) - 1; $i >= 0; $i--) {
            $content = isset($logs[$i]['content']) ? (string) $logs[$i]['content'] : '';
            $event = json_decode($content, true);
            if (!is_array($event)) {
                continue;
            }
            $toolResult = $this->parseReportFindingsEvent($event);
            if ($toolResult !== null) {
                return $toolResult;
            }
            if (empty($event['result'])) {
                continue;
            }
            try {
                $data = $this->extractJsonObject((string) $event['result']);
                if (is_array($data) && (!empty($data['blocking_issues']) || ($data['status'] ?? '') === 'pass')) {
                    return $data;
                }
            } catch (\RuntimeException $e) {
                continue;
            }
        }
        return null;
    }

    /** 兼容旧版 Review 会话调用 ReportFindings 后没有输出最终 JSON 的情况。 */
    private function parseReportFindingsEvent(array $event)
    {
        $blocks = [];
        foreach (['content', 'message'] as $key) {
            if (isset($event[$key]) && is_array($event[$key])) {
                $blocks = array_merge($blocks, isset($event[$key]['content']) && is_array($event[$key]['content'])
                    ? $event[$key]['content']
                    : $event[$key]);
            }
        }
        foreach ($blocks as $block) {
            if (!is_array($block) || ($block['name'] ?? '') !== 'ReportFindings') {
                continue;
            }
            $input = isset($block['input']) && is_array($block['input']) ? $block['input'] : [];
            $findings = isset($input['findings']) && is_array($input['findings']) ? $input['findings'] : [];
            $blocking = [];
            foreach ($findings as $finding) {
                if (is_string($finding) && trim($finding) !== '') {
                    $blocking[] = trim($finding);
                } elseif (is_array($finding)) {
                    $blocking[] = trim((string) ($finding['description'] ?? $finding['message'] ?? json_encode($finding, JSON_UNESCAPED_UNICODE)));
                }
            }
            return [
                'status' => $blocking ? 'fail' : 'pass',
                'risk_level' => $blocking ? 'high' : 'low',
                'summary' => $blocking ? 'AI Review 发现待处理问题。' : 'AI Review 未发现阻塞问题。',
                'blocking_issues' => $blocking,
                'warnings' => [],
                'suggestions' => [],
            ];
        }
        return null;
    }

    private function extractJsonObject($raw)
    {
        $fallback = null;
        foreach ($this->resultTextCandidates($raw) as $candidate) {
            $data = $this->tryParseJsonCandidate($candidate);
            if (!is_array($data)) {
                continue;
            }
            // 拆解、规格、计划、分支名等生成任务没有 status 字段。此前这里把
            // AI Review 的择优规则错误地用于所有任务，导致合法 JSON 被丢弃。
            if (!isset($data['status'])) {
                return $data;
            }
            if (!empty($data['blocking_issues'])) {
                return $data;
            }
            if (($data['status'] ?? '') === 'pass') {
                return $data;
            }
            if ($fallback === null && isset($data['status']) && in_array($data['status'], ['pass', 'fail'], true)) {
                $fallback = $data;
            }
        }
        if ($fallback !== null) {
            foreach ($this->resultTextCandidates($raw) as $candidate) {
                $recovered = $this->recoverReviewResult($candidate);
                if (is_array($recovered) && !empty($recovered['blocking_issues'])) {
                    return $recovered;
                }
            }
            return $fallback;
        }
        throw new \RuntimeException(
            'AI 返回结果不是可识别的 JSON(也可能因输出过长被截断): '
            . mb_substr((string) $raw, 0, 200)
        );
    }

    private function resultTextCandidates($raw)
    {
        $candidates = $this->resultTexts;
        $raw = trim((string) $raw);
        if ($raw !== '') {
            $candidates[] = $raw;
        }
        $seen = [];
        $ordered = [];
        foreach ($candidates as $text) {
            $text = trim((string) $text);
            if ($text === '' || isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;
            $ordered[] = $text;
        }
        usort($ordered, function ($a, $b) {
            return strlen($b) - strlen($a);
        });
        return $ordered;
    }

    private function tryParseJsonCandidate($raw)
    {
        $cleaned = preg_replace('/^```(json)?\s*$|^```\s*$/m', '', trim((string) $raw));
        if ($cleaned === '') {
            return null;
        }
        $json = $this->sliceBalancedJson($cleaned);
        if ($json !== null) {
            $data = json_decode($json, true);
            if (is_array($data)) {
                if (!empty($data['blocking_issues']) || ($data['status'] ?? '') === 'pass') {
                    return $data;
                }
                $recovered = $this->recoverReviewResult($cleaned);
                if (is_array($recovered) && !empty($recovered['blocking_issues'])) {
                    return $recovered;
                }
                return $data;
            }
        }
        // 项目描述偶尔会在 JSON 字符串内写未转义的双引号，如
        // {"description":"项目"橙啦"是..."}。外壳与唯一字段仍明确时可安全恢复。
        $description = $this->recoverDescriptionResult($cleaned);
        if (is_array($description)) {
            return $description;
        }
        $start = strpos($cleaned, '{');
        if ($start !== false) {
            $fromStart = substr($cleaned, $start);
            $data = $this->recoverMarkdownField($fromStart) ?: $this->recoverMarkdownField($cleaned);
            if (is_array($data)) {
                return $data;
            }
        }
        $data = $this->recoverMarkdownField($cleaned);
        if (is_array($data)) {
            return $data;
        }
        return $this->recoverReviewResult($cleaned);
    }

    private function recoverDescriptionResult($raw)
    {
        $text = trim((string) $raw);
        if (!preg_match('/^\{\s*"description"\s*:\s*"([\s\S]*)"\s*\}\s*$/u', $text, $matches)) {
            return null;
        }
        $description = str_replace(
            ['\\n', '\\r', '\\t', '\\/','\\"', '\\\\'],
            ["\n", "\r", "\t", '/', '"', '\\'],
            (string) $matches[1]
        );
        $description = trim($description);
        return $description === '' ? null : ['description' => $description];
    }

    /** AI Review 偶发输出 summary 键名损坏的 JSON,回退用正则提取各列表字段。 */
    private function recoverReviewResult($text)
    {
        if (strpos((string) $text, 'blocking_issues') === false) {
            return null;
        }
        $status = preg_match('/"status"\s*:\s*"(pass|fail)"/', $text, $m) ? $m[1] : 'fail';
        $risk = preg_match('/"risk_level"\s*:\s*"(low|medium|high)"/', $text, $m) ? $m[1] : ($status === 'pass' ? 'low' : 'high');
        $summary = '';
        if (preg_match('/"summary[^"]*"\s*:\s*"([^"]+)"/u', $text, $m)) {
            $summary = $m[1];
        } elseif (preg_match('/"summary([^",]+)"/u', $text, $m)) {
            $summary = ltrim($m[1], '": ');
        }
        $blocking = $this->extractReviewStringArray($text, 'blocking_issues');
        if (!$blocking) {
            return null;
        }
        return [
            'status' => $status,
            'risk_level' => $risk,
            'summary' => $summary,
            'blocking_issues' => $blocking,
            'warnings' => $this->extractReviewStringArray($text, 'warnings'),
            'suggestions' => $this->extractReviewStringArray($text, 'suggestions'),
        ];
    }

    private function extractReviewStringArray($text, $field)
    {
        if (!preg_match('/"' . preg_quote($field, '/') . '"\s*:\s*\[/', $text, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }
        $start = $m[0][1] + strlen($m[0][0]) - 1;
        $slice = $this->sliceBalancedBracket(substr($text, $start));
        if ($slice === null) {
            return [];
        }
        $data = json_decode($slice, true);
        return is_array($data) ? $data : [];
    }

    private function sliceBalancedBracket($text)
    {
        $text = (string) $text;
        $start = strpos($text, '[');
        if ($start === false) {
            return null;
        }
        $len = strlen($text);
        $depth = 0;
        $inString = false;
        $escape = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($inString) {
                if ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '[') {
                $depth++;
            } elseif ($ch === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }

    private function sliceBalancedJson($text)
    {
        $text = (string) $text;
        $start = strpos($text, '{');
        if ($start === false) {
            return null;
        }
        $len = strlen($text);
        $depth = 0;
        $inString = false;
        $escape = false;
        for ($i = $start; $i < $len; $i++) {
            $ch = $text[$i];
            if ($escape) {
                $escape = false;
                continue;
            }
            if ($inString) {
                if ($ch === '\\') {
                    $escape = true;
                } elseif ($ch === '"') {
                    $inString = false;
                }
                continue;
            }
            if ($ch === '"') {
                $inString = true;
                continue;
            }
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($text, $start, $i - $start + 1);
                }
            }
        }
        return null;
    }

    private function recoverMarkdownField($json)
    {
        $json = trim((string) $json);
        foreach (['spec_markdown', 'plan_markdown', 'breakdown_markdown'] as $field) {
            $decoded = $this->extractJsonStringField($json, $field);
            if ($decoded !== null && $decoded !== '') {
                return [$field => $decoded];
            }
            // 完整:字段值以 "} 正常闭合。
            $pattern = '/"' . preg_quote($field, '/') . '"\s*:\s*"([\s\S]*)"\s*}\s*$/';
            if (preg_match($pattern, $json, $matches)) {
                $decoded = $this->decodeJsonStringBody($matches[1]);
                if ($decoded !== '') {
                    return [$field => $decoded];
                }
            }
            // 截断:字段开头在,但没有正常闭合。抢救已生成部分,并明确标注不完整。
            $truncPattern = '/"' . preg_quote($field, '/') . '"\s*:\s*"([\s\S]+)$/';
            if (preg_match($truncPattern, $json, $matches)) {
                $decoded = $this->decodeTruncatedJsonStringBody($matches[1]);
                if (mb_strlen($decoded) >= 50) {
                    return [$field => $decoded . "\n\n> ⚠️ 模型输出超出最大长度被截断,以上内容不完整。请调大 ai_dev.max_output_tokens 或改用更大输出上限的模型后重新生成。"];
                }
            }
        }
        return null;
    }

    private function extractJsonStringField($raw, $field)
    {
        $raw = (string) $raw;
        $endAnchored = '/"' . preg_quote($field, '/') . '"\s*:\s*"(.*)"\s*}\s*$/s';
        if (preg_match($endAnchored, $raw, $matches)) {
            $decoded = $this->decodeJsonStringBody($matches[1]);
            if ($decoded !== '') {
                return $decoded;
            }
            $fallback = trim(stripcslashes($matches[1]));
            if ($fallback !== '') {
                return $fallback;
            }
        }

        // 输出超长被截断时没有闭合的 `"}`:抢救从字段开头到全文末尾的内容。
        $truncated = '/"' . preg_quote($field, '/') . '"\s*:\s*"(.*)$/s';
        if (preg_match($truncated, $raw, $matches)) {
            $body = preg_replace('/"\s*}\s*$/', '', $matches[1]);
            $decoded = $this->decodeTruncatedJsonStringBody($body);
            if (mb_strlen($decoded) < 200) {
                $decoded = trim(str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], $body));
            }
            if (mb_strlen($decoded) >= 200) {
                $suffix = preg_match($endAnchored, $raw)
                    ? ''
                    : "\n\n> ⚠️ 模型输出超出最大长度被截断,以上内容可能不完整。请调大 ai_dev.max_output_tokens 或改用更大输出上限的模型后重新生成。";
                return $decoded . $suffix;
            }
        }

        return null;
    }

    /** 把一段 JSON 字符串字面量的内容(不含外层引号)解码为纯文本。 */
    private function decodeJsonStringBody($body)
    {
        $decoded = json_decode('"' . $body . '"', true);
        if (!is_string($decoded)) {
            $decoded = stripcslashes((string) $body);
        }
        return trim((string) $decoded);
    }

    /** 解码被截断的 JSON 字符串体:先去掉尾部残缺的转义,再逐字回退直到能解析。 */
    private function decodeTruncatedJsonStringBody($body)
    {
        $body = (string) $body;
        // 截断抢救时,尾部常会带上 `"}` 或残缺的 JSON 闭合符。
        $body = preg_replace('/"\s*}\s*$/', '', $body);
        $body = preg_replace('/"\s*$/', '', $body);
        // 去掉结尾残缺的 \uXXXX 与落单的反斜杠,避免整体解析失败。
        $body = preg_replace('/\\\\u[0-9a-fA-F]{0,3}$/', '', $body);
        $body = preg_replace('/\\\\+$/', '', $body);
        for ($i = 0; $i < 8 && $body !== ''; $i++) {
            $decoded = json_decode('"' . $body . '"', true);
            if (is_string($decoded)) {
                return trim($decoded);
            }
            $body = substr($body, 0, -1);
        }
        return trim(stripcslashes($body));
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
            $parts[] = $label . ' 被信号 ' . $termSignal . ' 终止';
        } else {
            $parts[] = $label . ' 退出码 ' . $exitCode;
        }
        return implode("\n", $parts);
    }
}
