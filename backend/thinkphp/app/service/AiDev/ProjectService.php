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

    public function update($id, array $input)
    {
        $data = $this->filter($input);
        if ($data) {
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
