<?php

namespace app\service\AiDev;

/**
 * OpenAI 兼容 /chat/completions 的非流式 HTTP 直调。
 * 供生成类执行器在 agent=http 档案下替代 CLI 子进程调用。
 */
class HttpChatService
{
    /**
     * @param array $options 支持:
     *   - timeout int 执行器兜底超时
     *   - on_log  callable($eventType, $content) 落库每次交互的详细日志(请求/响应全过程)
     */
    public function complete(array $profile, $prompt, array $options = [])
    {
        // on_log 不传则静默,便于非执行器场景(如 smoke)直接调用。
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

        $payload = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ];
        // 放宽单次输出上限,避免长计划/规格/拆解的 JSON 被截断。档案可用 max_output_tokens 覆盖。
        $maxTokens = isset($profile['max_output_tokens']) && (int) $profile['max_output_tokens'] > 0
            ? (int) $profile['max_output_tokens']
            : (int) config('ai_dev.agent.max_output_tokens', 0);
        if ($maxTokens > 0) {
            $payload['max_tokens'] = $maxTokens;
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // 档案自带 timeout_seconds 优先,否则用执行器传入的 timeout,再兜底 300。
        $timeout = isset($profile['timeout_seconds']) && (int) $profile['timeout_seconds'] > 0
            ? (int) $profile['timeout_seconds']
            : (isset($options['timeout']) ? (int) $options['timeout'] : 300);

        $headers = ['Content-Type: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        // 请求前先落一条:发出去的完整内容(端点、模型、system+user 全部消息),Authorization 不入库。
        $log('http_request', $this->formatRequestLog($url, $model, $timeout, $messages));

        $resp = $this->post($url, $headers, $body, $timeout);

        // 响应先落库(含错误响应体、token 用量、耗时),再做状态码 / 解析校验,保证失败也留痕。
        $log('http_response', $this->formatResponseLog($resp));
        if ($resp['code'] >= 400) {
            throw new \RuntimeException('HTTP 直调返回状态 ' . $resp['code'] . ': ' . mb_substr($resp['body'], 0, 300));
        }
        return $this->parseContent($resp['body']);
    }

    /**
     * 发 POST,返回 ['body'=>响应体, 'code'=>HTTP状态码, 'elapsed_ms'=>耗时]。
     * 传输失败抛异常;HTTP>=400 不在此处抛,交由调用方先落库再决定。protected 便于测试替换。
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

    /** 请求日志:端点、模型、超时,以及逐条消息(角色 + 完整内容)。 */
    private function formatRequestLog($url, $model, $timeout, array $messages)
    {
        $lines = [];
        $lines[] = 'POST ' . $url;
        $lines[] = 'model: ' . $model;
        $lines[] = 'stream: false · timeout: ' . $timeout . 's';
        $lines[] = '--- messages ---';
        foreach ($messages as $message) {
            $lines[] = '[' . $message['role'] . '] ' . (string) $message['content'];
        }
        return implode("\n", $lines);
    }

    /** 响应日志:状态码、耗时、token 用量、finish_reason,以及回复正文(解析不出就落原始体)。 */
    private function formatResponseLog(array $resp)
    {
        $lines = [];
        $lines[] = 'HTTP ' . $resp['code'] . ' · ' . $resp['elapsed_ms'] . 'ms';
        $data = json_decode((string) $resp['body'], true);
        if (is_array($data)) {
            if (isset($data['usage']) && is_array($data['usage'])) {
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
