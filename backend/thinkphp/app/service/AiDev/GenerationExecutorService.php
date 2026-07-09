<?php

namespace app\service\AiDev;

class GenerationExecutorService
{
    private $lastResultText = null;
    private $modelKey = '';

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
        $this->modelKey = $modelKey;

        $promptFile = sys_get_temp_dir() . '/ai-dev-generation-prompt-' . $runId . '.md';
        file_put_contents($promptFile, $prompt);
        // 生成类任务只读仓库产出 JSON,不改代码。
        $cmd = $modelProfile->buildCommand($modelKey, $promptFile, [
            'max_turns' => $maxTurns,
            'allowed_tools' => isset($options['allowed_tools']) ? (string) $options['allowed_tools'] : '',
            'edit' => false,
        ]);

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
        if ($this->lastResultText !== null) {
            return trim((string) $this->lastResultText);
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
                $this->lastResultText = $resultText;
            }
            if ($type === 'system' && isset($event['subtype']) && $event['subtype'] === 'thinking_tokens') {
                return;
            }
            $runService->appendStreamEvent($runId, $type, $event);
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
