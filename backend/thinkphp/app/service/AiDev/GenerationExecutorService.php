<?php

namespace app\service\AiDev;

class GenerationExecutorService
{
    private $lastResultText = null;
    /** @var string[] stream 里历次 result 文本,避免最后一次只是说明性短句而丢掉真正的 JSON */
    private $resultTexts = [];
    private $modelKey = '';

    public function execute($runId)
    {
        $this->lastResultText = null;
        $this->resultTexts = [];
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
        $result = $this->runClaude($runId, $prompt, $options, $runService);
        $data = $this->extractJsonObject($result);
        $applied = $this->applyResult($run, $data);
        $output = is_array($applied) ? $applied : $data;
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

        // HTTP 直调档案不起子进程,直接发 /chat/completions。请求/响应全过程由 on_log 落库。
        if ($modelProfile->isHttp($modelKey)) {
            $runService->markRunning($runId, 0);
            $runService->appendLog($runId, 'stdout', 'HTTP 直调档案: ' . $modelKey);
            $text = (new HttpChatService())->complete(
                $modelProfile->profile($modelKey),
                $prompt,
                [
                    'timeout' => $timeout,
                    'on_log' => function ($type, $content) use ($runService, $runId) {
                        $runService->appendLog($runId, $type, $content);
                    },
                ]
            );
            return trim($text);
        }

        $tempService = new ProcessTempService();
        $tempDir = $tempService->create($cwd, 'generation', $runId);
        try {
            $promptFile = $tempService->writeFile($tempDir, 'prompt.md', $prompt);
            // 生成类任务只读仓库产出 JSON,不改代码。
            $cmd = $modelProfile->buildCommand($modelKey, $promptFile, [
                'max_turns' => $maxTurns,
                'allowed_tools' => isset($options['allowed_tools']) ? (string) $options['allowed_tools'] : '',
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
            $runService->appendLog($runId, 'error', $message);
            throw new \RuntimeException($message);
        }
        return $this->pickResultText($output);
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
        $markers = ['plan_markdown', 'spec_markdown', 'breakdown_markdown', 'commit_message', 'branch_name', 'blocking_issues'];
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
            if (!is_array($event) || empty($event['result'])) {
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
