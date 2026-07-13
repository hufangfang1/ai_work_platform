<?php

namespace app\service\AiDev;

/**
 * 统一封装 claude CLI headless 调用(claude -p),供拆解、计划等生成类场景使用。
 * 编码执行(带写权限的长任务)仍走 AgentExecutorService。
 */
class ClaudeCliService
{
    public function runText($prompt, array $options = [])
    {
        $cwd = isset($options['cwd']) && $options['cwd'] !== '' ? $options['cwd'] : runtime_path();
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 300;
        $maxTurns = isset($options['max_turns']) ? (int) $options['max_turns'] : 8;
        // AI 调用远超 PHP 默认 max_execution_time(30s),否则脚本会在轮询循环中被杀。
        @set_time_limit($timeout + 60);
        $cmd = escapeshellcmd(config('ai_dev.agent.command', 'claude'))
            . ' -p --output-format text --max-turns ' . $maxTurns;
        if (!empty($options['allowed_tools'])) {
            $cmd .= ' --allowedTools ' . escapeshellarg($options['allowed_tools']);
        }

        $tempService = new ProcessTempService();
        $tempDir = $tempService->create($cwd, 'claude-cli');
        try {
            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($cmd, $descriptors, $pipes, $cwd, $tempService->env(null, $tempDir));
            if (!is_resource($process)) {
                throw new \RuntimeException('claude 子进程启动失败');
            }
            fwrite($pipes[0], $prompt);
            fclose($pipes[0]);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $output = '';
            $error = '';
            $exitCode = -1;
            $startedAt = time();
            while (true) {
                $output .= (string) stream_get_contents($pipes[1]);
                $error .= (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    // 退出码必须在此刻从 proc_get_status 读取:一旦进程被 waitpid 回收,
                    // 后续 proc_close() 只会返回 -1,不能用它判断成败。
                    $exitCode = $status['exitcode'];
                    break;
                }
                if (time() - $startedAt > $timeout) {
                    proc_terminate($process);
                    proc_close($process);
                    throw new \RuntimeException('claude 调用超时(' . $timeout . 's)');
                }
                usleep(200000);
            }
            $output .= (string) stream_get_contents($pipes[1]);
            $error .= (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            $tempService->cleanup($tempDir);
        }

        if ($exitCode !== 0 || trim($output) === '') {
            throw new \RuntimeException('claude 调用失败: ' . ($error !== '' ? trim($error) : '空输出(exit ' . $exitCode . ')'));
        }
        return trim($output);
    }

    public function runJson($prompt, array $options = [])
    {
        $raw = $this->runText($prompt, $options);
        $cleaned = preg_replace('/^```(json)?\s*$|^```\s*$/m', '', $raw);
        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new \RuntimeException('claude 未返回 JSON: ' . mb_substr($raw, 0, 200));
        }
        $data = json_decode(substr($cleaned, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            throw new \RuntimeException('claude JSON 解析失败: ' . mb_substr($raw, 0, 200));
        }
        return $data;
    }
}
