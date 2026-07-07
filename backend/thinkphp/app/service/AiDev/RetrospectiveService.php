<?php

namespace app\service\AiDev;

use think\facade\Db;

class RetrospectiveService
{
    public function generate($taskId)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        $change = Db::name('ai_dev_changes')->where('task_id', $taskId)->order('created_at', 'desc')->find();
        $review = Db::name('ai_dev_reviews')->where('task_id', $taskId)->order('created_at', 'desc')->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        $requirement = Db::name('ai_dev_requirements')->where('id', $task['requirement_id'])->find();
        $docSource = $requirement && $requirement['doc_url'] !== '' ? $requirement['doc_url'] : '手动录入需求';
        $requirementTitle = $requirement ? $requirement['title'] : '未关联需求';
        $files = $change ? json_decode($change['changed_files'], true) : [];
        $content = "# {$task['title']} 复盘\n\n"
            . "## 需求背景\n\n所属需求：{$requirementTitle}；需求来源：{$docSource}。\n\n"
            . "## 本项目职责\n\n" . ($task['scope_summary'] !== '' && $task['scope_summary'] !== null ? $task['scope_summary'] : '未填写') . "\n\n"
            . "## 实现内容\n\n" . ($change ? $change['diff_summary'] : '暂无改动摘要') . "\n\n"
            . "## 涉及文件\n\n- " . implode("\n- ", $files ?: ['暂无']) . "\n\n"
            . "## 验证情况\n\n" . ($review ? $review['test_result'] : '暂无测试结果') . "\n\n"
            . "## 风险与遗留项\n\n- 后续需关注 Review 中标记的风险。\n\n"
            . "## 后续建议\n\n- 接入 MR/PR 创建与飞书通知。\n";
        return ['content' => $content];
    }

    public function get($taskId)
    {
        return Db::name('ai_dev_retrospectives')->where('task_id', $taskId)->order('created_at', 'desc')->find();
    }

    public function save($taskId, $content)
    {
        $existing = $this->get($taskId);
        if ($existing) {
            Db::name('ai_dev_retrospectives')->where('id', $existing['id'])->update([
                'content' => $content,
            ]);
            $id = $existing['id'];
        } else {
            $id = Db::name('ai_dev_retrospectives')->insertGetId([
                'task_id' => $taskId,
                'content' => $content,
                'created_by' => 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        Db::name('ai_dev_tasks')->where('id', $taskId)->update([
            'status' => 'retrospected',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::name('ai_dev_retrospectives')->where('id', $id)->find();
    }
}
