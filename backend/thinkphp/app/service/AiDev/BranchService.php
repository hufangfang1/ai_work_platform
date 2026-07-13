<?php

namespace app\service\AiDev;

use think\facade\Db;

class BranchService
{
    /**
     * 入队一次「AI 生成分支名」任务(异步,和拆解/计划同一套执行器,模型可按步骤单独选)。
     * $model 为本次指定的模型 key,留空走 step_models 默认。
     */
    public function enqueue($taskId, $model = '', $draft = false)
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        return $this->enqueueForRequirement((int) $task['requirement_id'], $model, $draft);
    }

    public function enqueueForRequirement($requirementId, $model = '', $draft = false)
    {
        $requirement = Db::name('ai_dev_requirements')->where('id', $requirementId)->find();
        if (!$requirement) {
            throw new \RuntimeException('需求不存在');
        }
        $this->assertRequirementHasTasks((int) $requirementId);
        $doc = (new RequirementService())->latestDoc($requirementId);
        $docHead = $doc ? mb_substr((string) $doc['content'], 0, 500) : '';
        $payload = [
            'operation' => 'requirement_branch_name',
            'requirement_id' => (int) $requirementId,
            'branch_prefix' => $this->defaultBranchPrefix((int) $requirementId),
            'prompt' => $this->buildRequirementPrompt($requirement, $docHead),
            'options' => ['timeout' => 120, 'max_turns' => 2],
        ];
        return (new RunService())->enqueueGeneration(0, 'branch_name', $payload, 'requirement:' . (int) $requirementId, $model, $draft);
    }

    /** 生成任务完成后回填:模型给的名字过一遍 slug 清洗,不合法则回退到本地规则生成。 */
    public function finishRun(array $run, array $data)
    {
        $payload = json_decode((string) $run['input'], true);
        if (is_array($payload) && !empty($payload['requirement_id'])) {
            return $this->finishRequirementRun($run, $data, $payload);
        }

        $task = Db::name('ai_dev_tasks')->where('id', $run['task_id'])->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        $branchName = $this->sanitizeBranchSlug(isset($data['branch_name']) ? (string) $data['branch_name'] : '');
        if ($branchName === '') {
            // 模型没给出合法分支名,回退到纯本地 slug,保证一定有可用结果
            $doc = Db::name('ai_dev_requirement_docs')->where('id', $task['doc_version_id'])->find();
            $docHead = $doc ? mb_substr((string) $doc['content'], 0, 500) : '';
            $branchName = $this->makeSlug($task['title'] . "\n" . $task['scope_summary'] . "\n" . $docHead);
        }
        $finalBranchName = $task['branch_prefix'] . $branchName;
        Db::name('ai_dev_tasks')->where('id', $run['task_id'])->update([
            'branch_name' => $branchName,
            'final_branch_name' => $finalBranchName,
            'status' => 'branch_generated',
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'branch_name' => $branchName,
            'final_branch_name' => $finalBranchName,
            'reason' => isset($data['reason']) && trim((string) $data['reason']) !== ''
                ? trim((string) $data['reason'])
                : 'AI 根据需求语义生成',
        ];
    }

    private function finishRequirementRun(array $run, array $data, array $payload)
    {
        $requirementId = (int) $payload['requirement_id'];
        $branchName = $this->sanitizeBranchSlug(isset($data['branch_name']) ? (string) $data['branch_name'] : '');
        if ($branchName === '') {
            $requirement = Db::name('ai_dev_requirements')->where('id', $requirementId)->find();
            $doc = (new RequirementService())->latestDoc($requirementId);
            $docHead = $doc ? mb_substr((string) $doc['content'], 0, 500) : '';
            $branchName = $this->makeSlug(($requirement ? $requirement['title'] : '') . "\n" . $docHead);
        }
        $prefix = isset($payload['branch_prefix']) ? (string) $payload['branch_prefix'] : '';
        $finalBranchName = $prefix . $branchName;
        $this->saveForRequirement($requirementId, $finalBranchName);
        return [
            'branch_name' => $branchName,
            'final_branch_name' => $finalBranchName,
            'reason' => isset($data['reason']) && trim((string) $data['reason']) !== ''
                ? trim((string) $data['reason'])
                : 'AI 根据需求语义生成',
        ];
    }

    public function saveForRequirement($requirementId, $finalBranchName)
    {
        $requirement = Db::name('ai_dev_requirements')->where('id', $requirementId)->find();
        if (!$requirement) {
            throw new \RuntimeException('需求不存在');
        }
        $this->assertRequirementHasTasks((int) $requirementId);
        $finalBranchName = $this->normalizeFinalBranchName($finalBranchName);
        if (!$this->validFinalBranchName($finalBranchName)) {
            throw new \RuntimeException('分支名格式不合法');
        }
        $branchName = $this->sanitizeBranchSlug(basename(str_replace('\\', '/', $finalBranchName)));
        Db::name('ai_dev_requirements')->where('id', $requirementId)->update([
            'branch_name' => $branchName,
            'final_branch_name' => $finalBranchName,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        $tasks = Db::name('ai_dev_tasks')
            ->where('requirement_id', (int) $requirementId)
            ->where('status', '<>', 'terminated')
            ->select()->toArray();
        foreach ($tasks as $task) {
            $update = [
                'branch_name' => $branchName,
                'final_branch_name' => $finalBranchName,
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($task['status'] === 'created') {
                $update['status'] = 'branch_generated';
            }
            Db::name('ai_dev_tasks')->where('id', $task['id'])->update($update);
        }
        return Db::name('ai_dev_requirements')->where('id', $requirementId)->find();
    }

    private function buildPrompt(array $task, $docHead)
    {
        return "你是资深工程师。根据下面的工单信息,生成一个简洁的 git 分支名。\n"
            . "要求:kebab-case、全小写、只用 ASCII 字母数字和连字符、不超过 50 字符、体现需求核心语义,不要带任何前缀。\n"
            . "只返回 JSON,不要多余文字,结构:{\"branch_name\":\"...\",\"reason\":\"简要中文说明\"}\n\n"
            . "# 工单标题\n" . (string) $task['title'] . "\n\n"
            . "# 范围说明\n" . (string) $task['scope_summary'] . "\n\n"
            . "# 需求文档(节选)\n" . (string) $docHead . "\n";
    }

    private function buildRequirementPrompt(array $requirement, $docHead)
    {
        return "你是资深工程师。根据下面的需求信息,生成一个给该需求下所有项目复用的 git 分支名。\n"
            . "要求:kebab-case、全小写、只用 ASCII 字母数字和连字符、不超过 50 字符、体现需求核心语义,不要带任何前缀。\n"
            . "只返回 JSON,不要多余文字,结构:{\"branch_name\":\"...\",\"reason\":\"简要中文说明\"}\n\n"
            . "# 需求标题\n" . (string) $requirement['title'] . "\n\n"
            . "# 需求文档(节选)\n" . (string) $docHead . "\n";
    }

    /** 把模型给的分支名清洗成合法 git slug;清洗后为空返回 ''(交给调用方回退)。 */
    public function sanitizeBranchSlug($text)
    {
        $slug = strtolower(trim((string) $text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim(substr(trim($slug, '-'), 0, 50), '-');
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

    public function checkForRequirement($requirementId, $finalBranchName)
    {
        $this->assertRequirementHasTasks((int) $requirementId);
        $finalBranchName = $this->normalizeFinalBranchName($finalBranchName);
        $tasks = Db::name('ai_dev_tasks')->alias('t')
            ->leftJoin('ai_dev_projects p', 'p.id = t.project_id')
            ->where('t.requirement_id', (int) $requirementId)
            ->where('t.status', '<>', 'terminated')
            ->field('p.*, t.id as task_id')
            ->select()->toArray();
        if (!$tasks) {
            $valid = $this->validFinalBranchName($finalBranchName);
            return [
                'valid' => $valid,
                'valid_format' => $valid,
                'exists' => false,
                'message' => $valid ? '分支名格式可用;确认拆解生成工单后会同步到各项目' : '分支名格式不合法',
                'projects' => [],
            ];
        }

        $items = [];
        $valid = true;
        $exists = false;
        foreach ($tasks as $project) {
            $check = $this->checkRemote($project, $finalBranchName);
            $valid = $valid && $check['valid'];
            $exists = $exists || !empty($check['exists']);
            $items[] = [
                'project_name' => $project['name'],
                'valid' => $check['valid'],
                'exists' => $check['exists'],
                'message' => $check['message'],
            ];
        }
        return [
            'valid' => $valid,
            'valid_format' => $this->validFinalBranchName($finalBranchName),
            'exists' => $exists,
            'message' => $valid ? '所有项目分支均可用' : '至少一个项目的分支不可用',
            'projects' => $items,
        ];
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
        $validFormat = $this->validFinalBranchName($finalBranchName);
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

    private function defaultBranchPrefix($requirementId)
    {
        $task = Db::name('ai_dev_tasks')
            ->where('requirement_id', (int) $requirementId)
            ->where('status', '<>', 'terminated')
            ->order('id', 'asc')->find();
        return $task && isset($task['branch_prefix']) ? (string) $task['branch_prefix'] : 'future/';
    }

    private function assertRequirementHasTasks($requirementId)
    {
        $count = Db::name('ai_dev_tasks')
            ->where('requirement_id', (int) $requirementId)
            ->where('status', '<>', 'terminated')
            ->count();
        if ((int) $count === 0) {
            throw new \RuntimeException('请先确认拆解生成工单，再生成需求分支');
        }
    }

    private function normalizeFinalBranchName($name)
    {
        $name = strtolower(trim((string) $name));
        $name = preg_replace('/\s+/', '-', $name);
        $name = preg_replace('/[^a-z0-9._\/-]+/', '-', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = preg_replace('/\/+/', '/', $name);
        return trim($name, '/-');
    }

    private function validFinalBranchName($finalBranchName)
    {
        $basicValid = (bool) preg_match('/^(?!\/)(?!.*\/\/)[a-z0-9._\/-]+$/', (string) $finalBranchName)
            && strpos((string) $finalBranchName, ' ') === false
            && substr((string) $finalBranchName, -1) !== '/'
            && strlen((string) $finalBranchName) <= 120;
        if (!$basicValid) {
            return false;
        }
        $output = [];
        exec('git check-ref-format --branch ' . escapeshellarg((string) $finalBranchName) . ' 2>/dev/null', $output, $code);
        return $code === 0;
    }
}
