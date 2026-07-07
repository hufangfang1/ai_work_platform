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
    public function describe($path, $model = '')
    {
        $path = rtrim((string) $path, '/');
        if ($path === '' || !is_dir($path)) {
            throw new \RuntimeException('项目目录不存在: ' . $path);
        }
        $prompt = "阅读当前项目仓库(优先看 README、目录结构、主要源码与依赖清单),"
            . "用一句不超过 40 字的中文概括该项目的用途与技术栈。"
            . "只返回 JSON,结构:{\"description\":\"...\"},不要 JSON 以外的内容。";
        return (new RunService())->enqueueGeneration(0, 'project_description', [
            'operation' => 'project_description',
            'path' => $path,
            'prompt' => $prompt,
            'options' => [
                'cwd' => $path,
                'timeout' => 180,
                'max_turns' => 8,
                'allowed_tools' => 'Read,Glob,Grep',
            ],
        ], 'project_path:' . sha1($path), $model);
    }

    public function finishDescribeRun(array $run, array $data)
    {
        $line = isset($data['description']) ? trim((string) $data['description']) : '';
        $line = trim($line, "\"'` 　");
        if ($line === '') {
            throw new \RuntimeException('AI 未能生成描述,请重试或手动填写');
        }
        return ['description' => $line];
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
