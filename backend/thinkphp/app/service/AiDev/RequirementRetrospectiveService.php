<?php

namespace app\service\AiDev;

use think\facade\Db;

class RequirementRetrospectiveService
{
    const DONE_STATUSES = ['committed', 'retrospected'];

    public function get($requirementId)
    {
        $row = Db::name('ai_dev_retrospectives')
            ->where('requirement_id', (int) $requirementId)
            ->where('task_id', 0)
            ->order('id', 'desc')
            ->find();
        if ($row) {
            $row['project_summaries'] = $this->decodeJson($row['project_summaries_json'] ?? '');
        }
        return $row;
    }

    public function generate($requirementId)
    {
        $requirement = Db::name('ai_dev_requirements')->where('id', (int) $requirementId)->find();
        if (!$requirement) {
            throw new \RuntimeException('需求不存在');
        }
        $tasks = $this->tasks((int) $requirementId);
        if (!$tasks) {
            throw new \RuntimeException('需求还没有项目工单，无法复盘');
        }

        $unfinished = [];
        $completed = 0;
        foreach ($tasks as $task) {
            if ($task['status'] === 'terminated') {
                continue;
            }
            if (!in_array($task['status'], self::DONE_STATUSES, true)) {
                $unfinished[] = ($task['project_name'] ?: ('project#' . $task['project_id'])) . '（' . $task['status'] . '）';
            } else {
                $completed++;
            }
        }
        if ($unfinished) {
            throw new \RuntimeException('以下项目尚未完成提交，不能生成最终复盘：' . implode('、', $unfinished));
        }
        if ($completed === 0) {
            throw new \RuntimeException('需求没有已提交的项目，无法生成最终复盘');
        }

        $summaries = [];
        foreach ($tasks as $task) {
            $summaries[] = $this->summarizeProject($task);
        }
        return [
            'content' => $this->render($requirement, $summaries),
            'project_summaries' => $summaries,
        ];
    }

    public function save($requirementId, $content, array $projectSummaries = [])
    {
        if (!Db::name('ai_dev_requirements')->where('id', (int) $requirementId)->find()) {
            throw new \RuntimeException('需求不存在');
        }
        if (trim((string) $content) === '') {
            throw new \RuntimeException('复盘内容不能为空');
        }
        $now = date('Y-m-d H:i:s');
        $data = [
            'content' => (string) $content,
            'project_summaries_json' => json_encode($projectSummaries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => $now,
        ];
        $existing = $this->get((int) $requirementId);
        if ($existing) {
            Db::name('ai_dev_retrospectives')->where('id', $existing['id'])->update($data);
            $id = $existing['id'];
        } else {
            $data['task_id'] = 0;
            $data['requirement_id'] = (int) $requirementId;
            $data['created_by'] = 0;
            $data['created_at'] = $now;
            $id = Db::name('ai_dev_retrospectives')->insertGetId($data);
        }
        return Db::name('ai_dev_retrospectives')->where('id', $id)->find();
    }

    private function tasks($requirementId)
    {
        return Db::name('ai_dev_tasks')->alias('t')
            ->leftJoin('ai_dev_projects p', 'p.id = t.project_id')
            ->where('t.requirement_id', $requirementId)
            ->field('t.*, p.name as project_name, p.test_command, p.lint_command, p.build_command')
            ->order('t.id', 'asc')->select()->toArray();
    }

    private function summarizeProject(array $task)
    {
        $taskId = (int) $task['id'];
        $runs = Db::name('ai_dev_runs')->where('task_id', $taskId)->order('id', 'asc')->select()->toArray();
        $changes = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('id', 'asc')->select()->toArray();
        $reviews = Db::name('ai_dev_reviews')->where('task_id', $taskId)->order('id', 'asc')->select()->toArray();

        $files = [];
        foreach ($changes as $change) {
            foreach ($this->decodeJson($change['changed_files'] ?? '') as $file) {
                if (is_string($file) && $file !== '' && !in_array($file, $files, true)) {
                    $files[] = $file;
                }
            }
        }

        $issues = [];
        foreach ($runs as $run) {
            if (!in_array($run['status'], ['failed', 'cancelled'], true)) {
                continue;
            }
            $detail = trim((string) ($run['error'] ?: $run['output']));
            $issues[] = $run['run_type'] . ' ' . $run['status'] . ($detail !== '' ? '：' . $this->shorten($detail, 500) : '');
        }
        $reviewSuggestions = [];
        foreach ($reviews as $review) {
            $result = $this->decodeJson($review['review_result'] ?? '');
            foreach (['blocking_issues', 'warnings', 'suggestions'] as $field) {
                foreach (($result[$field] ?? []) as $item) {
                    $text = is_array($item) ? json_encode($item, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $item;
                    if ($text !== '' && !in_array($text, $reviewSuggestions, true)) {
                        $reviewSuggestions[] = $text;
                    }
                }
            }
        }
        $issues = array_values(array_unique(array_merge($issues, $reviewSuggestions)));

        $codingRuns = array_filter($runs, function ($run) {
            return in_array($run['run_type'], ['coding', 'fix'], true);
        });
        $fixCount = count(array_filter($runs, function ($run) {
            return $run['run_type'] === 'fix';
        }));
        $failedCount = count(array_filter($runs, function ($run) {
            return in_array($run['status'], ['failed', 'cancelled'], true);
        }));
        $optimizations = $reviewSuggestions;
        if ($fixCount > 0) {
            $optimizations[] = "本次经历 {$fixCount} 轮 Fix；应把触发返工的问题补充到该项目的需求说明、计划检查项或自动检查中。";
        }
        if ($failedCount > 0) {
            $optimizations[] = "本次有 {$failedCount} 次失败/取消执行；应根据上方失败原因补齐前置校验，避免进入编码后才失败。";
        }
        $latestReview = $reviews ? end($reviews) : null;
        return [
            'task_id' => $taskId,
            'project_id' => (int) $task['project_id'],
            'project_name' => $task['project_name'] ?: ('project#' . $task['project_id']),
            'status' => $task['status'],
            'scope_summary' => (string) ($task['scope_summary'] ?? ''),
            'commit_hash' => (string) ($task['commit_hash'] ?? ''),
            'changed_files' => $files,
            'coding_run_count' => count($codingRuns),
            'fix_count' => $fixCount,
            'review_count' => count($reviews),
            'issues' => array_slice($issues, 0, 30),
            'verification' => $latestReview ? $this->shorten(trim((string) $latestReview['test_result']), 3000) : '',
            'optimizations' => array_values(array_unique(array_slice($optimizations, 0, 30))),
        ];
    }

    private function render(array $requirement, array $summaries)
    {
        $completed = count(array_filter($summaries, function ($item) {
            return in_array($item['status'], self::DONE_STATUSES, true);
        }));
        $content = '# ' . $requirement['title'] . " 项目复盘\n\n";
        $content .= "## 总体结论\n\n本需求涉及 " . count($summaries) . " 个项目，已提交 {$completed} 个项目。以下内容按项目汇总本次实际执行记录，可继续人工补充结论。\n\n";
        foreach ($summaries as $item) {
            $content .= '## 项目：' . $item['project_name'] . "\n\n";
            $content .= "### 本次交付\n\n" . ($item['scope_summary'] ?: '未记录项目职责') . "\n\n";
            $content .= '- 提交：' . ($item['commit_hash'] ?: '未记录') . "\n";
            $content .= '- 编码/Fix 执行：' . $item['coding_run_count'] . ' 次（Fix ' . $item['fix_count'] . " 次）\n";
            $content .= '- Review：' . $item['review_count'] . " 次\n\n";
            $content .= "### 涉及文件\n\n" . $this->renderList($item['changed_files'], '未记录改动文件') . "\n\n";
            $content .= "### 出现的问题\n\n" . $this->renderList($item['issues'], '执行记录中未发现失败或 Review 问题；请人工确认是否有未被系统记录的问题') . "\n\n";
            $content .= "### 验证结果\n\n" . ($item['verification'] ?: '未记录可执行的验证结果') . "\n\n";
            $content .= "### 后续优化\n\n" . $this->renderList($item['optimizations'], '暂无由执行记录直接推导出的优化项；请结合实际协作过程补充') . "\n\n";
        }
        $content .= "## 跨项目协作问题\n\n- 请根据接口约定、依赖等待和联调过程补充；系统不会在缺少证据时自动编造。\n";
        return $content;
    }

    private function renderList(array $items, $empty)
    {
        if (!$items) {
            return '- ' . $empty;
        }
        return '- ' . implode("\n- ", array_map(function ($item) {
            return str_replace(["\r", "\n"], [' ', ' '], (string) $item);
        }, $items));
    }

    private function decodeJson($value)
    {
        $decoded = json_decode((string) $value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function shorten($value, $max)
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }
        return mb_substr($value, 0, $max) . '…';
    }
}
