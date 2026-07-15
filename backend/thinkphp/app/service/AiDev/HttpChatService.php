<?php

namespace app\service\AiDev;

/**
 * OpenAI 兼容 /chat/completions 的 HTTP 直调。
 * 支持非流式与 SSE 流式;流式时通过 on_stream 回调增量落库。
 */
class HttpChatService
{
    /**
     * @param array $options 支持:
     *   - timeout int 执行器兜底超时
     *   - stream bool 是否 SSE 流式(默认 false)
     *   - on_log callable($eventType, $content) 请求/响应边界日志
     *   - on_stream callable($delta, $totalChars, array $meta) 流式增量回调
     *   - should_cancel callable(): bool 返回 true 时中断流式请求
     */
    public function complete(array $profile, $prompt, array $options = [])
    {
        $onLog = isset($options['on_log']) && is_callable($options['on_log']) ? $options['on_log'] : null;
        $log = function ($type, $content) use ($onLog) {
            if ($onLog) {
                $onLog($type, $content);
            }
        };

        $apiBase = isset($profile['api_base']) ? rtrim(trim((string) $profile['api_base']), '/') : '';
        if ($apiBase === '') {
            throw new \RuntimeException('HTTP 直调档案缺少 api_base');
        }
        $model = isset($profile['model']) ? trim((string) $profile['model']) : '';
        if ($model === '') {
            throw new \RuntimeException('HTTP 直调档案缺少 model');
        }
        $url = $apiBase . '/chat/completions';
        $apiKey = (new ModelProfileService())->resolveApiKey(
            isset($profile['api_key_ref']) ? (string) $profile['api_key_ref'] : ''
        );

        $messages = [];
        $lang = trim((string) config('ai_dev.agent.language_prompt', ''));
        if ($lang !== '') {
            $messages[] = ['role' => 'system', 'content' => $lang];
        }
        $messages[] = ['role' => 'user', 'content' => (string) $prompt];

        $stream = !empty($options['stream']);
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => $stream,
        ];
        $maxTokens = isset($profile['max_output_tokens']) && (int) $profile['max_output_tokens'] > 0
            ? (int) $profile['max_output_tokens']
            : (int) config('ai_dev.agent.max_output_tokens', 0);
        if ($maxTokens > 0) {
            $payload['max_tokens'] = $maxTokens;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        $timeout = isset($profile['timeout_seconds']) && (int) $profile['timeout_seconds'] > 0
            ? (int) $profile['timeout_seconds']
            : (isset($options['timeout']) ? (int) $options['timeout'] : 300);

        $headers = ['Content-Type: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $log('http_request', $this->formatRequestLog($url, $model, $timeout, $messages, $stream));

        if ($stream) {
            $resp = $this->postStream($url, $headers, $body, $timeout, $options);
        } else {
            $resp = $this->post($url, $headers, $body, $timeout);
        }

        if (!empty($options['should_cancel']) && is_callable($options['should_cancel']) && $options['should_cancel']()) {
            throw new \RuntimeException('人工取消执行');
        }

        $log('http_response', $this->formatResponseLog($resp));
        if ($resp['code'] >= 400) {
            throw new \RuntimeException('HTTP 直调返回状态 ' . $resp['code'] . ': ' . mb_substr($resp['body'], 0, 300));
        }
        return $this->parseContent($resp['body']);
    }

    /**
     * 发 POST,返回 ['body'=>响应体, 'code'=>HTTP状态码, 'elapsed_ms'=>耗时]。
     */
    protected function post($url, array $headers, $body, $timeout)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $startedAt = microtime(true);
        $resp = curl_exec($ch);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP 直调请求失败: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['body' => (string) $resp, 'code' => $code, 'elapsed_ms' => $elapsedMs];
    }

    /** SSE 流式 POST:边收边拼 content,通过 on_stream 回调增量通知。 */
    protected function postStream($url, array $headers, $body, $timeout, array $options = [])
    {
        $onStream = isset($options['on_stream']) && is_callable($options['on_stream']) ? $options['on_stream'] : null;
        $shouldCancel = isset($options['should_cancel']) && is_callable($options['should_cancel'])
            ? $options['should_cancel']
            : null;
        $cancelled = false;
        $checkCancel = function () use ($shouldCancel, &$cancelled) {
            if ($cancelled) {
                return true;
            }
            if ($shouldCancel && $shouldCancel()) {
                $cancelled = true;
                return true;
            }
            return false;
        };
        $content = '';
        $reasoningChars = 0;
        $sseBuf = '';
        $usage = [];
        $finishReason = '';
        $startedAt = microtime(true);
        $lastWaitLogAt = 0;
        $gotFirstByte = false;

        $emit = function ($kind, array $meta = []) use ($onStream, &$content, &$reasoningChars, $checkCancel) {
            if ($checkCancel()) {
                return;
            }
            if (!$onStream) {
                return;
            }
            $meta['kind'] = $kind;
            if ($kind === 'content') {
                $onStream($meta['delta'] ?? '', mb_strlen($content), $meta);
            } else {
                $onStream('', mb_strlen($content), $meta);
            }
        };

        $writeFn = function ($ch, $chunk) use (&$sseBuf, &$content, &$reasoningChars, &$usage, &$finishReason, &$gotFirstByte, $emit, $checkCancel) {
            if ($checkCancel()) {
                return 0;
            }
            if (!$gotFirstByte && $chunk !== '') {
                $gotFirstByte = true;
                $emit('first_byte', ['message' => '已收到模型首个响应片段']);
            }
            $sseBuf .= str_replace("\r\n", "\n", $chunk);
            while (($pos = strpos($sseBuf, "\n\n")) !== false) {
                $event = substr($sseBuf, 0, $pos);
                $sseBuf = (string) substr($sseBuf, $pos + 2);
                foreach (explode("\n", $event) as $line) {
                    $line = trim($line);
                    if ($line === '' || strpos($line, 'data:') !== 0) {
                        continue;
                    }
                    $data = trim(substr($line, 5));
                    if ($data === '' || $data === '[DONE]') {
                        continue;
                    }
                    $json = json_decode($data, true);
                    if (!is_array($json)) {
                        continue;
                    }
                    if (isset($json['usage']) && is_array($json['usage'])) {
                        $usage = $json['usage'];
                    }
                    $choice = $json['choices'][0] ?? [];
                    if (isset($choice['finish_reason']) && $choice['finish_reason'] !== null) {
                        $finishReason = (string) $choice['finish_reason'];
                    }
                    $delta = isset($choice['delta']['content']) ? (string) $choice['delta']['content'] : '';
                    if ($delta === '') {
                        $reasoning = '';
                        if (isset($choice['delta']['reasoning_content'])) {
                            $reasoning = (string) $choice['delta']['reasoning_content'];
                        } elseif (isset($choice['delta']['thinking'])) {
                            $reasoning = (string) $choice['delta']['thinking'];
                        }
                        if ($reasoning !== '') {
                            $reasoningChars += mb_strlen($reasoning);
                            $emit('thinking', ['delta' => $reasoning, 'reasoning_chars' => $reasoningChars]);
                        }
                        continue;
                    }
                    if ($delta === '') {
                        continue;
                    }
                    $content .= $delta;
                    $emit('content', ['delta' => $delta]);
                }
            }
            return strlen($chunk);
        };

        $progressFn = function () use (&$lastWaitLogAt, $startedAt, $emit, &$gotFirstByte, $checkCancel) {
            if ($checkCancel()) {
                return 1;
            }
            if ($gotFirstByte) {
                return 0;
            }
            $now = time();
            if ($now - $lastWaitLogAt >= 3) {
                $lastWaitLogAt = $now;
                $emit('wait', ['elapsed' => $now - (int) $startedAt]);
            }
            return 0;
        };

        $emit('wait', ['elapsed' => 0, 'message' => '流式连接已建立，等待模型响应…']);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => $writeFn,
            CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function ($resource, $downloadTotal, $downloaded) use ($progressFn) {
                return $progressFn();
            },
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $ok = curl_exec($ch);
        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);
        $curlErr = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($checkCancel()) {
            throw new \RuntimeException('人工取消执行');
        }
        if ($ok === false) {
            throw new \RuntimeException('HTTP 流式请求失败: ' . $curlErr);
        }

        $emit('thinking_flush', []);

        $wrapped = json_encode([
            'choices' => [['message' => ['content' => $content], 'finish_reason' => $finishReason ?: 'stop']],
            'usage' => $usage,
        ], JSON_UNESCAPED_UNICODE);

        return ['body' => $wrapped !== false ? $wrapped : '{"choices":[{"message":{"content":""}}]}', 'code' => $code, 'elapsed_ms' => $elapsedMs];
    }

    private function formatRequestLog($url, $model, $timeout, array $messages, $stream = false)
    {
        $lines = [];
        $lines[] = 'POST ' . $url;
        $lines[] = 'model: ' . $model;
        $lines[] = 'stream: ' . ($stream ? 'true' : 'false') . ' · timeout: ' . $timeout . 's';
        $lines[] = '--- messages ---';
        foreach ($messages as $message) {
            $lines[] = '[' . $message['role'] . '] ' . (string) $message['content'];
        }
        return implode("\n", $lines);
    }

    private function formatResponseLog(array $resp)
    {
        $lines = [];
        $lines[] = 'HTTP ' . $resp['code'] . ' · ' . $resp['elapsed_ms'] . 'ms';
        $data = json_decode((string) $resp['body'], true);
        if (is_array($data)) {
            if (isset($data['usage']) && is_array($data['usage']) && $data['usage']) {
                $usage = $data['usage'];
                $lines[] = 'usage: prompt=' . ($usage['prompt_tokens'] ?? '?')
                    . ' completion=' . ($usage['completion_tokens'] ?? '?')
                    . ' total=' . ($usage['total_tokens'] ?? '?');
            }
            if (isset($data['choices'][0]['finish_reason'])) {
                $lines[] = 'finish_reason: ' . (string) $data['choices'][0]['finish_reason'];
            }
            if (isset($data['choices'][0]['message']['content'])) {
                $lines[] = '--- content ---';
                $lines[] = (string) $data['choices'][0]['message']['content'];
            } else {
                $lines[] = '--- raw ---';
                $lines[] = mb_substr((string) $resp['body'], 0, 20000);
            }
        } else {
            $lines[] = '--- raw ---';
            $lines[] = mb_substr((string) $resp['body'], 0, 20000);
        }
        return implode("\n", $lines);
    }

    private function parseContent($raw)
    {
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('HTTP 直调响应非 JSON: ' . mb_substr((string) $raw, 0, 200));
        }
        $content = isset($data['choices'][0]['message']['content'])
            ? (string) $data['choices'][0]['message']['content']
            : '';
        if (trim($content) === '') {
            throw new \RuntimeException('HTTP 直调响应缺少 choices[0].message.content: ' . mb_substr((string) $raw, 0, 300));
        }
        return $content;
    }
}
