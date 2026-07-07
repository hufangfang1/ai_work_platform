<?php

namespace app\service\AiDev;

use think\facade\Db;

class BreakdownService
{
    public function generate($requirementId, array $projectIds = [])
    {
        $requirement = Db::name('ai_dev_requirements')->where('id', $requirementId)->find();
        if (!$requirement) {
            throw new \RuntimeException('需求不存在');
        }
        $doc = (new RequirementService())->latestDoc($requirementId);
        if (!$doc || trim((string) $doc['content']) === '') {
            throw new \RuntimeException('请先录入需求文档快照');
        }
        $projects = Db::name('ai_dev_projects')->where('status', 1)->select()->toArray();
        if (!$projects) {
            throw new \RuntimeException('请先在项目页添加至少一个项目');
        }

        // 人工指定涉及项目时,只在所选范围内拆解;留空则由 AI 从全部候选中判断。
        $manual = !empty($projectIds);
        $candidates = $projects;
        if ($manual) {
            $wanted = array_map('intval', $projectIds);
            $candidates = array_values(array_filter($projects, function ($p) use ($wanted) {
                return in_array((int) $p['id'], $wanted, true);
            }));
            if (!$candidates) {
                throw new \RuntimeException('所选项目无效,请重新选择');
            }
        }

        $config = (new ConfigService())->model();
        $modelName = $config ? $config['model_name'] : '';
        // AI 调用可能耗时数分钟,先释放 MySQL 连接,避免 idle 后写回报 Packets out of order。
        Db::connect()->close();
        $result = (new ClaudeCliService())->runJson($this->buildPrompt($doc['content'], $candidates, $manual), [
            'timeout' => 300,
            'max_turns' => 3,
        ]);

        $markdown = isset($result['breakdown_markdown']) ? $result['breakdown_markdown'] : '';
        $items = isset($result['projects']) && is_array($result['projects']) ? $result['projects'] : [];
        if ($markdown === '' || !$items) {
            throw new \RuntimeException('拆解结果不完整,请重试');
        }
        $nameMap = [];
        foreach ($projects as $project) {
            $nameMap[$project['name']] = (int) $project['id'];
        }
        $normalized = [];
        foreach ($items as $item) {
            $projectName = isset($item['project_name']) ? trim($item['project_name']) : '';
            $normalized[] = [
                'project_id' => isset($nameMap[$projectName]) ? $nameMap[$projectName] : 0,
                'project_name' => $projectName,
                'scope_summary' => isset($item['scope_summary']) ? $item['scope_summary'] : '',
                'interfaces' => isset($item['interfaces']) ? $item['interfaces'] : '',
                'unmatched' => !isset($nameMap[$projectName]),
            ];
        }
        return $this->saveVersion($requirementId, $markdown, $normalized, 'ai', $modelName);
    }

    public function saveHuman($requirementId, $content, $projectsJson)
    {
        $items = is_array($projectsJson) ? $projectsJson : json_decode((string) $projectsJson, true);
        if (!is_array($items)) {
            throw new \RuntimeException('projects_json 格式不合法');
        }
        return $this->saveVersion($requirementId, $content, $items, 'human', '');
    }

    public function confirm($requirementId)
    {
        $breakdown = Db::name('ai_dev_breakdowns')
            ->where('requirement_id', $requirementId)->order('version', 'desc')->find();
        if (!$breakdown) {
            throw new \RuntimeException('没有可确认的拆解');
        }
        if ($breakdown['confirmed_at']) {
            throw new \RuntimeException('该拆解版本已确认');
        }
        $items = json_decode((string) $breakdown['projects_json'], true);
        if (!is_array($items) || !$items) {
            throw new \RuntimeException('拆解中没有项目条目');
        }
        foreach ($items as $item) {
            if (empty($item['project_id'])) {
                throw new \RuntimeException('存在未匹配到已配置项目的条目,请先编辑修正: ' . (isset($item['project_name']) ? $item['project_name'] : '未知'));
            }
        }
        $requirement = Db::name('ai_dev_requirements')->where('id', $requirementId)->find();
        $doc = (new RequirementService())->latestDoc($requirementId);
        if (!$doc) {
            throw new \RuntimeException('需求文档快照缺失');
        }

        $taskService = new TaskService();
        $created = [];
        Db::startTrans();
        try {
            Db::name('ai_dev_breakdowns')->where('id', $breakdown['id'])->update([
                'confirmed_by' => 0,
                'confirmed_at' => date('Y-m-d H:i:s'),
            ]);
            foreach ($items as $item) {
                $exists = Db::name('ai_dev_tasks')
                    ->where('requirement_id', $requirementId)
                    ->where('project_id', (int) $item['project_id'])
                    ->where('status', '<>', 'terminated')
                    ->find();
                if ($exists) {
                    $created[] = ['task_id' => $exists['id'], 'project_id' => (int) $item['project_id'], 'skipped' => true];
                    continue;
                }
                $task = $taskService->createFromBreakdown($requirement, $doc, $item);
                $created[] = ['task_id' => $task['id'], 'project_id' => (int) $item['project_id'], 'skipped' => false];
            }
            Db::name('ai_dev_requirements')->where('id', $requirementId)->update([
                'status' => 'active',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }
        return $created;
    }

    private function saveVersion($requirementId, $content, array $items, $source, $modelName)
    {
        $version = (int) Db::name('ai_dev_breakdowns')->where('requirement_id', $requirementId)->max('version') + 1;
        $id = Db::name('ai_dev_breakdowns')->insertGetId([
            'requirement_id' => $requirementId,
            'version' => $version,
            'content' => $content,
            'projects_json' => json_encode($items, JSON_UNESCAPED_UNICODE),
            'source' => $source,
            'model_name' => $modelName,
            'confirmed_by' => 0,
            'confirmed_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Db::name('ai_dev_requirements')->where('id', $requirementId)->update([
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::name('ai_dev_breakdowns')->where('id', $id)->find();
    }

    private function buildPrompt($docContent, array $projects, $manual = false)
    {
        $lines = [];
        foreach ($projects as $project) {
            $lines[] = '- name: ' . $project['name']
                . ' | description: ' . ($project['description'] !== '' ? $project['description'] : '无')
                . ' | repo: ' . $project['repo_url'];
        }
        if ($manual) {
            $task = "你是研发负责人。阅读需求文档,下方项目已由人工确认为本需求涉及的项目。\n"
                . "只返回 JSON,结构:{\"breakdown_markdown\":\"...\",\"projects\":[{\"project_name\":\"...\",\"scope_summary\":\"...\",\"interfaces\":\"...\"}]}\n"
                . "breakdown_markdown 用中文 Markdown,包含:## 需求理解 / ## 涉及项目与分工 / ## 跨项目接口约定 / ## 风险点。\n"
                . "projects 必须为下方**每一个**项目各输出一条,不得新增或遗漏;project_name 必须从列表原样取;"
                . "scope_summary 说明该项目在本需求中要做什么;interfaces 说明与其他项目的接口约定,没有则留空。\n\n"
                . "# 本需求涉及的项目(人工确认)\n";
        } else {
            $task = "你是研发负责人。阅读需求文档,从下方候选项目中判断本需求涉及哪些项目,并给出拆解。\n"
                . "只返回 JSON,结构:{\"breakdown_markdown\":\"...\",\"projects\":[{\"project_name\":\"...\",\"scope_summary\":\"...\",\"interfaces\":\"...\"}]}\n"
                . "breakdown_markdown 用中文 Markdown,包含:## 需求理解 / ## 涉及项目与分工 / ## 跨项目接口约定 / ## 风险点。\n"
                . "projects 只列确实需要改动的项目;project_name 必须从候选列表原样取;scope_summary 说明该项目要做什么;interfaces 说明与其他项目的接口约定,没有则留空。\n\n"
                . "# 候选项目\n";
        }
        return $task . implode("\n", $lines) . "\n\n"
            . "# 需求文档(已脱敏)\n" . $docContent . "\n";
    }
}
