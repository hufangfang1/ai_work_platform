<?php

namespace app\service\AiDev;

use think\facade\Db;

class BranchService
{
    public function generateForTask($taskId)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        $doc = Db::name('ai_dev_requirement_docs')->where('id', $task['doc_version_id'])->find();
        $docHead = $doc ? mb_substr((string) $doc['content'], 0, 500) : '';
        $branchName = $this->makeSlug($task['title'] . "\n" . $task['scope_summary'] . "\n" . $docHead);
        $finalBranchName = $task['branch_prefix'] . $branchName;
        Db::name('ai_dev_tasks')->where('id', $taskId)->update([
            'branch_name' => $branchName,
            'final_branch_name' => $finalBranchName,
            'status' => 'branch_generated',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'branch_name' => $branchName,
            'final_branch_name' => $finalBranchName,
            'reason' => '根据需求文档提取核心语义生成',
        ];
    }

    public function checkForTask($taskId, $finalBranchName)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        $project = Db::name('ai_dev_projects')->where('id', $task['project_id'])->find();
        return $this->checkRemote($project, $finalBranchName);
    }

    public function makeSlug($text)
    {
        $lower = strtolower(preg_replace('/https?:\/\/\S+/', ' ', $text));
        if (preg_match('/(spa\s*2(\.0)?|spa2)/i', $lower) && preg_match('/(知识图谱|knowledge[-\s]?graph)/i', $lower)) {
            return 'spa2-knowledge-graph';
        }
        $tokens = [];
        $map = [
            '知识图谱' => 'knowledge-graph',
            'review' => 'review',
            '复盘' => 'retrospective',
            '提交' => 'commit',
            '分支' => 'branch',
        ];
        foreach ($map as $keyword => $slug) {
            if (stripos($lower, $keyword) !== false && !in_array($slug, $tokens)) {
                $tokens[] = $slug;
            }
        }
        if (preg_match_all('/[a-z0-9]+/', $lower, $matches)) {
            foreach ($matches[0] as $word) {
                if (strlen($word) > 2 && !in_array($word, ['the', 'and', 'for', 'with', 'http', 'https'])) {
                    $tokens[] = $word;
                }
            }
        }
        $slug = implode('-', array_unique($tokens ?: ['ai', 'dev', 'task']));
        $slug = preg_replace('/-+/', '-', $slug);
        return trim(substr($slug, 0, 60), '-');
    }

    public function checkRemote(array $project, $finalBranchName)
    {
        $validFormat = (bool) preg_match('/^(?!\/)(?!.*\/\/)[a-z0-9._\/-]+$/', $finalBranchName)
            && strpos($finalBranchName, ' ') === false
            && substr($finalBranchName, -1) !== '/'
            && strlen($finalBranchName) <= 120;
        $exists = false;

        if (!empty($project['local_path']) && is_dir($project['local_path'] . '/.git')) {
            $cmd = sprintf(
                'git -C %s ls-remote --heads origin %s',
                escapeshellarg($project['local_path']),
                escapeshellarg($finalBranchName)
            );
            exec($cmd, $output, $code);
            $exists = $code === 0 && count($output) > 0;
        }

        return [
            'valid' => $validFormat && !$exists,
            'valid_format' => $validFormat,
            'exists' => $exists,
            'message' => !$validFormat ? '分支名格式不合法' : ($exists ? '远程仓库已存在该分支' : '分支可用'),
        ];
    }
}
