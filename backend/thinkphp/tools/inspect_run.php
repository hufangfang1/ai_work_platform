<?php

require dirname(__DIR__) . '/vendor/autoload.php';

$app = new think\App();
$app->initialize();

$runId = (int) ($argv[1] ?? 0);
if ($runId <= 0) {
    fwrite(STDERR, "usage: php tools/inspect_run.php <run_id>\n");
    exit(1);
}

$run = think\facade\Db::name('ai_dev_runs')->where('id', $runId)->find();
echo "RUN:\n" . json_encode($run, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n\n";

$logs = think\facade\Db::name('ai_dev_run_logs')->where('run_id', $runId)->order('seq', 'asc')->select()->toArray();
echo "LOGS (" . count($logs) . "):\n";
foreach ($logs as $log) {
    $content = (string) $log['content'];
    if (strlen($content) > 300) {
        $content = substr($content, 0, 300) . '...';
    }
    echo sprintf("#%s [%s] %s\n", $log['seq'], $log['event_type'], str_replace("\n", ' ', $content));
}
