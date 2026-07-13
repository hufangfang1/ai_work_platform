<?php

require dirname(__DIR__) . '/vendor/autoload.php';

$failures = 0;
$check = function ($condition, $message) use (&$failures) {
    if ($condition) {
        echo "PASS: {$message}\n";
        return;
    }
    $failures++;
    echo "FAIL: {$message}\n";
};

$service = new app\service\AiDev\GenerationExecutorService();
$extract = new ReflectionMethod($service, 'extractJsonObject');
$extract->setAccessible(true);

$cases = [
    '需求拆解 JSON' => [
        'json' => json_encode([
            'breakdown_markdown' => "## 需求理解\n内容",
            'projects' => [['project_name' => 'demo']],
        ], JSON_UNESCAPED_UNICODE),
        'key' => 'breakdown_markdown',
    ],
    '项目规格 JSON' => [
        'json' => json_encode(['spec_markdown' => "## 目标\n内容"], JSON_UNESCAPED_UNICODE),
        'key' => 'spec_markdown',
    ],
    '开发计划 JSON' => [
        'json' => json_encode(['plan_markdown' => "## 需求理解\n内容"], JSON_UNESCAPED_UNICODE),
        'key' => 'plan_markdown',
    ],
    '分支名 JSON' => [
        'json' => json_encode(['branch_name' => 'feature-name'], JSON_UNESCAPED_UNICODE),
        'key' => 'branch_name',
    ],
    'AI Review 通过 JSON' => [
        'json' => json_encode(['status' => 'pass', 'blocking_issues' => []], JSON_UNESCAPED_UNICODE),
        'key' => 'status',
    ],
    'AI Review 失败 JSON' => [
        'json' => json_encode(['status' => 'fail', 'blocking_issues' => ['问题']], JSON_UNESCAPED_UNICODE),
        'key' => 'blocking_issues',
    ],
];

foreach ($cases as $label => $case) {
    try {
        $result = $extract->invoke($service, $case['json']);
        $check(is_array($result) && array_key_exists($case['key'], $result), $label . ' 可解析');
    } catch (Throwable $e) {
        $check(false, $label . ' 可解析: ' . $e->getMessage());
    }
}

try {
    $extract->invoke($service, 'not json');
    $check(false, '非 JSON 输出会被拒绝');
} catch (RuntimeException $e) {
    $check(true, '非 JSON 输出会被拒绝');
}

$retrospective = new app\service\AiDev\RequirementRetrospectiveService();
$render = new ReflectionMethod($retrospective, 'render');
$render->setAccessible(true);
$content = $render->invoke($retrospective, ['title' => '示例需求'], [[
    'project_name' => 'demo-api',
    'status' => 'committed',
    'scope_summary' => '实现示例接口',
    'commit_hash' => 'abc123',
    'changed_files' => ['app/controller/Demo.php'],
    'coding_run_count' => 2,
    'fix_count' => 1,
    'review_count' => 2,
    'issues' => ['缺少参数校验'],
    'verification' => '测试通过',
    'optimizations' => ['补充参数边界测试'],
]]);
$check(strpos($content, '## 项目：demo-api') !== false, '需求复盘按项目分段');
$check(strpos($content, '缺少参数校验') !== false && strpos($content, '补充参数边界测试') !== false, '需求复盘保留实际问题与优化项');
$check(strpos($content, '接入 MR/PR') === false && strpos($content, '飞书通知') === false, '需求复盘不注入固定套话');

exit($failures === 0 ? 0 : 1);
