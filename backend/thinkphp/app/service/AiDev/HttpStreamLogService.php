<?php

namespace app\service\AiDev;

/** HTTP 流式响应的增量日志:节流进度 + 按任务类型识别 JSON 字段里程碑。 */
class HttpStreamLogService
{
    public function tracker(RunService $runService, $runId, $profile = 'generic')
    {
        $lastLogAt = 0;
        $lastThinkingLogAt = 0;
        $seenMarkers = [];
        $seenFiles = [];
        $buffer = '';
        $thinkingBuf = '';
        $markers = $this->markersForProfile($profile);

        $flushThinking = function ($force = false) use ($runService, $runId, &$thinkingBuf, &$lastThinkingLogAt) {
            if ($runService->isCancelled($runId)) {
                return;
            }
            $text = trim($thinkingBuf);
            if ($text === '') {
                return;
            }
            if (!$force && mb_strlen($text) < 80) {
                return;
            }
            $runService->appendLog($runId, 'http_thinking', $text);
            $thinkingBuf = '';
            $lastThinkingLogAt = microtime(true);
        };

        return function ($delta, $totalChars, array $meta = []) use (
            $runService,
            $runId,
            $profile,
            $markers,
            $flushThinking,
            &$lastLogAt,
            &$lastThinkingLogAt,
            &$seenMarkers,
            &$seenFiles,
            &$buffer,
            &$thinkingBuf
        ) {
            if ($runService->isCancelled($runId)) {
                return;
            }
            $kind = isset($meta['kind']) ? (string) $meta['kind'] : 'content';
            $now = microtime(true);

            if ($kind === 'wait') {
                $elapsed = (int) ($meta['elapsed'] ?? 0);
                if ($elapsed === 0 && !empty($meta['message'])) {
                    $runService->appendLog($runId, 'http_stream', (string) $meta['message']);
                } else {
                    $runService->appendLog($runId, 'http_stream', '等待模型响应…已等待 ' . $elapsed . ' 秒');
                }
                return;
            }
            if ($kind === 'first_byte') {
                $runService->appendLog($runId, 'http_stream', (string) ($meta['message'] ?? '已收到模型首个响应片段'));
                return;
            }
            if ($kind === 'thinking') {
                $piece = (string) ($meta['delta'] ?? '');
                if ($piece !== '') {
                    $thinkingBuf .= $piece;
                }
                if ($now - $lastThinkingLogAt >= 1.5 || mb_strlen($thinkingBuf) >= 400) {
                    $flushThinking(true);
                }
                return;
            }
            if ($kind === 'thinking_flush') {
                $flushThinking(true);
                return;
            }

            if ($thinkingBuf !== '') {
                $flushThinking(true);
            }

            $buffer .= $delta;

            foreach ($markers as $key => $message) {
                if (!isset($seenMarkers[$key]) && strpos($buffer, '"' . $key . '"') !== false) {
                    $seenMarkers[$key] = true;
                    $runService->appendLog($runId, 'http_stream', $message);
                }
            }

            if ($profile === 'coding') {
                foreach ($this->detectNewPatchFiles($buffer, $seenFiles) as $file) {
                    $seenFiles[$file] = true;
                    $runService->appendLog($runId, 'http_stream', '生成补丁文件: ' . $file);
                }
            }

            if ($delta !== '' && $now - $lastLogAt >= 2.0) {
                $lastLogAt = $now;
                $runService->appendLog($runId, 'http_stream', '正式输出中…' . mb_substr($delta, 0, 120));
            } elseif ($now - $lastLogAt >= 2.0) {
                $lastLogAt = $now;
                $runService->appendLog($runId, 'http_stream', '模型输出中…已生成 ' . $totalChars . ' 字符');
            }
        };
    }

    private function markersForProfile($profile)
    {
        $map = [
            'coding' => [
                'summary_subject' => '正在生成改动摘要…',
            ],
            'ai_review' => [
                'status' => '正在确定审查结果…',
                'blocking_issues' => '正在整理阻塞问题…',
                'risk_level' => '正在评估风险等级…',
                'summary' => '正在生成审查结论…',
            ],
            'task_plan' => [
                'plan_markdown' => '正在生成开发计划…',
            ],
            'generic' => [],
        ];
        return isset($map[$profile]) ? $map[$profile] : $map['generic'];
    }

    private function detectNewPatchFiles($buffer, array $seenFiles)
    {
        $found = [];
        $patterns = [
            '/diff --git a[\/\\\\]+([^\s"\\\\]+)/',
            '/\+\+\+ b[\/\\\\]+([^\s"\\\\\r\n]+)/',
            '/"([A-Za-z0-9_.\/\\\\-]+\.(?:php|json|xml|js|ts|vue))"/',
        ];
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern, $buffer, $matches)) {
                continue;
            }
            foreach ($matches[1] as $file) {
                $file = str_replace('\\/', '/', (string) $file);
                $file = trim($file, '/');
                if ($file === '' || $file === 'dev/null' || isset($seenFiles[$file])) {
                    continue;
                }
                $found[] = $file;
            }
        }
        return $found;
    }
}
