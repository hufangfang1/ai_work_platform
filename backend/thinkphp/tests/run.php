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

$projectService = new app\service\AiDev\ProjectService();
$describePayload = $projectService->describePayload('/tmp/example-project');
$describeOptions = isset($describePayload['options']) ? $describePayload['options'] : [];
$check(($describeOptions['max_turns'] ?? 0) === 2, '项目描述限制为 2 轮纯总结');
$check(($describeOptions['timeout'] ?? 0) === 180, '项目描述单轮生成超时为 180 秒');
$check(
    strpos((string) ($describeOptions['disallowed_tools'] ?? ''), 'Read') !== false
        && strpos((string) ($describeOptions['disallowed_tools'] ?? ''), 'Glob') !== false,
    '项目描述禁用所有仓库探索工具'
);
$snapshot = (new app\service\AiDev\RepositorySnapshotService())->build(dirname(__DIR__));
$check(strpos($snapshot, 'composer.json') !== false, '有界仓库快照包含依赖摘要');
$check(strpos($snapshot, 'vendor/') === false, '有界仓库快照排除 vendor 依赖目录');

// 项目描述的工具日志往往比最终 JSON 更长，必须优先选中含 description 的 result。
$descriptionResult = json_encode(['description' => str_repeat('项目职责说明', 12)], JSON_UNESCAPED_UNICODE);
$resultTexts = new ReflectionProperty($service, 'resultTexts');
$resultTexts->setAccessible(true);
$resultTexts->setValue($service, [$descriptionResult]);
$pickResultText = new ReflectionMethod($service, 'pickResultText');
$pickResultText->setAccessible(true);
$longToolEvent = json_encode(['type' => 'tool_result', 'content' => str_repeat('x', 2000)]);
$check(
    $pickResultText->invoke($service, $longToolEvent) === $descriptionResult,
    '项目描述优先选择最终 description JSON，不误选更长的工具日志'
);
$resultTexts->setValue($service, []);

$reportFindings = new ReflectionMethod($service, 'parseReportFindingsEvent');
$reportFindings->setAccessible(true);
$noFindings = $reportFindings->invoke($service, [
    'type' => 'assistant',
    'message' => ['content' => [[
        'type' => 'tool_use',
        'name' => 'ReportFindings',
        'input' => ['findings' => []],
    ]]],
]);
$check(
    is_array($noFindings) && $noFindings['status'] === 'pass' && $noFindings['blocking_issues'] === [],
    'ReportFindings 空结果正确转换为 Review 通过'
);

$runService = new app\service\AiDev\RunService();
$codingRules = new ReflectionMethod($runService, 'codingExecutionRules');
$codingRules->setAccessible(true);
$rules = $codingRules->invoke($runService);
$check(strpos($rules, '最多 8 次') !== false, '编码提示限制首轮探索预算');
$check(strpos($rules, '禁止重新拆解、重新规划') !== false, '编码提示明确进入计划执行模式');

$reviewService = new app\service\AiDev\ReviewService();
$runConfiguredChecks = new ReflectionMethod($reviewService, 'runConfiguredChecks');
$runConfiguredChecks->setAccessible(true);
$emptyChecks = $runConfiguredChecks->invoke($reviewService, [], sys_get_temp_dir());
$check(
    $emptyChecks[0] === '' && $emptyChecks[1] === [] && $emptyChecks[2] === [],
    '未配置检查命令时静默跳过，不生成阻塞问题'
);

$normalizeReview = new ReflectionMethod($reviewService, 'normalizeAiReviewResult');
$normalizeReview->setAccessible(true);
$passWithSuggestion = $normalizeReview->invoke($reviewService, [
    'status' => 'pass',
    'summary' => '检查通过',
    'blocking_issues' => [],
    'suggestions' => ['提交前确认 diff'],
]);
$check(
    empty($passWithSuggestion['blocking_issues']) && !empty($passWithSuggestion['suggestions']),
    '通过结果的建议不会被误判为阻塞问题'
);

$malformedDescription = '{"description":"该项目"橙啦新运营中台"是基于 Vue3 的后台管理系统前端工程，负责管理端页面交互与数据展示。"}';
$recoveredDescription = $extract->invoke($service, $malformedDescription);
$check(
    isset($recoveredDescription['description']) && strpos($recoveredDescription['description'], '橙啦新运营中台') !== false,
    '项目描述 JSON 内部双引号未转义时可恢复'
);

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
