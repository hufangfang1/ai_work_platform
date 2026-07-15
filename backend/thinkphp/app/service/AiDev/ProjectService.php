<?php

namespace app\service\AiDev;

use think\facade\Db;

class ProjectService
{
    const FIELDS = [
        'name', 'description', 'repo_url', 'local_path',
        'default_base_branch', 'default_branch_prefix',
        'test_command', 'lint_command', 'build_command',
        'allow_auto_commit', 'allow_auto_push',
    ];

    public function query()
    {
        return Db::name('ai_dev_projects')->where('status', 1)->order('id', 'desc')->select()->toArray();
    }

    public function create(array $input)
    {
        $data = $this->filter($input);
        (new CommandSafetyService())->assertProjectChecks($data);
        if (empty($data['name']) || empty($data['local_path'])) {
            throw new \RuntimeException('项目名称与本地目录不能为空');
        }
        $exists = Db::name('ai_dev_projects')->where('local_path', $data['local_path'])->where('status', 1)->find();
        if ($exists) {
            throw new \RuntimeException('该目录已添加为项目: ' . $exists['name']);
        }
        $data['status'] = 1;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $id = Db::name('ai_dev_projects')->insertGetId($data);
        return Db::name('ai_dev_projects')->where('id', $id)->find();
    }

    /**
     * 让 AI 读取仓库,生成一句话项目描述(供需求拆解判断用)。
     */
    public function describe($path, $model = '', $draft = false)
    {
        $path = rtrim((string) $path, '/');
        if ($path === '' || !is_dir($path)) {
            throw new \RuntimeException('项目目录不存在: ' . $path);
        }
        return (new RunService())->enqueueGeneration(
            0,
            'project_description',
            $this->describePayload($path),
            'project_path:' . sha1($path),
            $model,
            $draft
        );
    }

    /** 集中构造轻量描述任务，新建与失败重试共用，避免重试复制旧执行预算。 */
    public function describePayload($path)
    {
        $snapshot = (new RepositorySnapshotService())->build($path);
        $prompt = "根据下方由系统预先采集的有界仓库快照，直接生成项目描述。不得调用任何工具，不得继续探索仓库。"
            . "用 120 到 300 字中文说明:项目用途、技术栈、主要交付边界、对外提供或消费的能力,以及它明确不负责什么。"
            . "描述将用于研发负责人判断需求应分配给哪个项目,不要只罗列框架名称,不要根据快照中不存在的内容猜测。"
            . "只返回 JSON,结构:{\"description\":\"...\"},不要 JSON 以外的内容。description 内不得使用中英文双引号，项目名用书名号。\n\n# 仓库快照\n" . $snapshot;
        return [
            'operation' => 'project_description',
            'path' => $path,
            'prompt' => $prompt,
            'options' => [
                'cwd' => $path,
                'timeout' => 180,
                'max_turns' => 2,
                // 快照已包含所需证据，模型只做单轮总结，彻底杜绝自由遍历。
                'disallowed_tools' => 'Read,Glob,Grep,Bash,WebFetch,WebSearch,Write,Edit,NotebookEdit,Skill,Workflow',
            ],
        ];
    }

    public function finishDescribeRun(array $run, array $data)
    {
        $line = isset($data['description']) ? trim((string) $data['description']) : '';
        $line = trim($line, "\"'` 　");
        if (mb_strlen($line) < 40) {
            throw new \RuntimeException('AI 生成的项目描述过短，无法支持可靠的项目职责判断，请重试或手动填写');
        }
        return ['description' => mb_substr($line, 0, 500)];
    }

    public function update($id, array $input)
    {
        $data = $this->filter($input);
        if ($data) {
            (new CommandSafetyService())->assertProjectChecks($data);
            $data['updated_at'] = date('Y-m-d H:i:s');
            Db::name('ai_dev_projects')->where('id', $id)->update($data);
        }
        return Db::name('ai_dev_projects')->where('id', $id)->find();
    }

    public function delete($id)
    {
        Db::name('ai_dev_projects')->where('id', $id)->update([
            'status' => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function filter(array $input)
    {
        $data = [];
        foreach (self::FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = in_array($field, ['allow_auto_commit', 'allow_auto_push'])
                    ? (int) !empty($input[$field])
                    : $input[$field];
            }
        }
        return $data;
    }
}
