<?php

namespace app\service\AiDev;

class GenerationExecutorService
{
    private $lastResultEvent = null;

    public function execute($runId)
    {
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

        $promptFile = sys_get_temp_dir() . '/ai-dev-generation-prompt-' . $runId . '.md';
        file_put_contents($promptFile, $prompt);
        $cmd = sprintf(
            '%s -p "$(cat %s)" --output-format stream-json --verbose --max-turns %d',
            escapeshellcmd(config('ai_dev.agent.command', 'claude')),
            escapeshellarg($promptFile),
            $maxTurns
        ) . $modelProfile->commandArg($modelKey);
        if (!empty($options['allowed_tools'])) {
            $cmd .= ' --allowedTools ' . escapeshellarg($options['allowed_tools']);
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $cwd, $modelProfile->processEnv($modelKey));
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
                @unlink($promptFile);
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
        @unlink($promptFile);

        if ($exitCode !== 0) {
            $message = $this->buildFailureMessage($exitCode, $termSignal, $error);
            $runService->appendLog($runId, 'error', $message);
            throw new \RuntimeException($message);
        }
        if (is_array($this->lastResultEvent) && isset($this->lastResultEvent['result'])) {
            return trim((string) $this->lastResultEvent['result']);
        }
        return trim($output);
    }

    private function applyResult(array $run, array $data)
    {
        if ($run['run_type'] === 'requirement_breakdown') {
            return (new BreakdownService())->finishRun($run, $data);
        }
        if ($run['run_type'] === 'task_plan') {
            return (new PlanService())->finishRun($run, $data);
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
            if ($type === 'result') {
                $this->lastResultEvent = $event;
            }
            if ($type === 'system' && isset($event['subtype']) && $event['subtype'] === 'thinking_tokens') {
                return;
            }
            $runService->appendLog($runId, $type, json_encode($event, JSON_UNESCAPED_UNICODE));
            return;
        }
        $runService->appendLog($runId, 'stdout', $line);
    }

    private function extractJsonObject($raw)
    {
        $cleaned = preg_replace('/^```(json)?\s*$|^```\s*$/m', '', (string) $raw);
        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new \RuntimeException('claude 未返回 JSON: ' . mb_substr((string) $raw, 0, 200));
        }
        $data = json_decode(substr($cleaned, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            throw new \RuntimeException('claude JSON 解析失败: ' . mb_substr((string) $raw, 0, 200));
        }
        return $data;
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
            $parts[] = 'Claude Code 被信号 ' . $termSignal . ' 终止';
        } else {
            $parts[] = 'Claude Code 退出码 ' . $exitCode;
        }
        return implode("\n", $parts);
    }
}
