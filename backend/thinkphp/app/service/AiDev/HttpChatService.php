<?php

namespace app\service\AiDev;

/**
 * OpenAI 兼容 /chat/completions 的非流式 HTTP 直调。
 * 供生成类执行器在 agent=http 档案下替代 CLI 子进程调用。
 */
class HttpChatService
{
    public function complete(array $profile, $prompt, array $options = [])
    {
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

        $body = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);

        // 档案自带 timeout_seconds 优先,否则用执行器传入的 timeout,再兜底 300。
        $timeout = isset($profile['timeout_seconds']) && (int) $profile['timeout_seconds'] > 0
            ? (int) $profile['timeout_seconds']
            : (isset($options['timeout']) ? (int) $options['timeout'] : 300);

        $headers = ['Content-Type: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $raw = $this->post($url, $headers, $body, $timeout);
        return $this->parseContent($raw);
    }

    /** 发 POST 并返回响应体;传输失败或 HTTP>=400 抛异常。protected 便于测试替换。 */
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
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP 直调请求失败: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new \RuntimeException('HTTP 直调返回状态 ' . $code . ': ' . mb_substr((string) $resp, 0, 300));
        }
        return (string) $resp;
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
