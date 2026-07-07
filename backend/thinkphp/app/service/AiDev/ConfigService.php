<?php

namespace app\service\AiDev;

use think\facade\Db;

class ConfigService
{
    const MODEL_FIELDS = ['provider', 'model_name', 'api_base', 'api_key_ref', 'context_length', 'timeout_seconds'];

    public function model()
    {
        return Db::name('ai_dev_model_configs')->order('id', 'desc')->find();
    }

    public function saveModel(array $input)
    {
        $data = [];
        foreach (self::MODEL_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $data[$field] = in_array($field, ['context_length', 'timeout_seconds'])
                    ? (int) $input[$field]
                    : $input[$field];
            }
        }
        $existing = $this->model();
        if ($existing) {
            if ($data) {
                Db::name('ai_dev_model_configs')->where('id', $existing['id'])->update($data);
            }
            return Db::name('ai_dev_model_configs')->where('id', $existing['id'])->find();
        }
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = date('Y-m-d H:i:s');
        $id = Db::name('ai_dev_model_configs')->insertGetId($data);
        return Db::name('ai_dev_model_configs')->where('id', $id)->find();
    }

    public function securityRules()
    {
        return Db::name('ai_dev_security_rules')->order('id', 'asc')->select()->toArray();
    }

    public function saveSecurityRules(array $rules)
    {
        Db::name('ai_dev_security_rules')->delete(true);
        foreach ($rules as $rule) {
            if (!is_array($rule) || !isset($rule['pattern']) || trim((string) $rule['pattern']) === '') {
                continue;
            }
            Db::name('ai_dev_security_rules')->insert([
                'pattern' => $rule['pattern'],
                'replacement' => isset($rule['replacement']) && $rule['replacement'] !== '' ? $rule['replacement'] : '***',
                'enabled' => !empty($rule['enabled']) ? 1 : 0,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return $this->securityRules();
    }

    public function exportConfig()
    {
        return [
            'version' => 1,
            'exported_at' => date('Y-m-d H:i:s'),
            'workspace_roots' => (new WorkspaceService())->getRoots(),
            'projects' => Db::name('ai_dev_projects')->where('status', 1)->order('id', 'asc')->select()->toArray(),
            'model_config' => $this->model(),
            'security_rules' => $this->securityRules(),
        ];
    }

    public function importConfig(array $payload)
    {
        $oldPrefix = isset($payload['path_map']['from']) ? rtrim((string) $payload['path_map']['from'], '/') : '';
        $newPrefix = isset($payload['path_map']['to']) ? rtrim((string) $payload['path_map']['to'], '/') : '';

        $workspaceRoots = isset($payload['workspace_roots']) && is_array($payload['workspace_roots'])
            ? array_map(function ($path) use ($oldPrefix, $newPrefix) {
                return $this->mapPath($path, $oldPrefix, $newPrefix);
            }, $payload['workspace_roots'])
            : [];
        if ($workspaceRoots) {
            (new WorkspaceService())->saveRoots($workspaceRoots);
        }

        if (isset($payload['model_config']) && is_array($payload['model_config'])) {
            $this->saveModel($payload['model_config']);
        }
        if (isset($payload['security_rules']) && is_array($payload['security_rules'])) {
            $this->saveSecurityRules($payload['security_rules']);
        }

        $projectsImported = 0;
        if (isset($payload['projects']) && is_array($payload['projects'])) {
            foreach ($payload['projects'] as $project) {
                if (!is_array($project)) {
                    continue;
                }
                $data = [];
                foreach (ProjectService::FIELDS as $field) {
                    if (array_key_exists($field, $project)) {
                        $data[$field] = $project[$field];
                    }
                }
                if (isset($data['local_path'])) {
                    $data['local_path'] = $this->mapPath($data['local_path'], $oldPrefix, $newPrefix);
                }
                if (empty($data['name']) || empty($data['local_path'])) {
                    continue;
                }
                (new CommandSafetyService())->assertProjectChecks($data);
                $existing = Db::name('ai_dev_projects')
                    ->where('status', 1)
                    ->where('local_path', $data['local_path'])
                    ->find();
                if (!$existing) {
                    $existing = Db::name('ai_dev_projects')
                        ->where('status', 1)
                        ->where('name', $data['name'])
                        ->find();
                }
                $data['updated_at'] = date('Y-m-d H:i:s');
                if ($existing) {
                    Db::name('ai_dev_projects')->where('id', $existing['id'])->update($data);
                } else {
                    $data['status'] = 1;
                    $data['created_at'] = date('Y-m-d H:i:s');
                    Db::name('ai_dev_projects')->insert($data);
                }
                $projectsImported++;
            }
        }

        return [
            'workspace_roots' => count($workspaceRoots),
            'projects' => $projectsImported,
            'security_rules' => isset($payload['security_rules']) && is_array($payload['security_rules'])
                ? count($payload['security_rules'])
                : 0,
        ];
    }

    private function mapPath($path, $oldPrefix, $newPrefix)
    {
        $path = rtrim((string) $path, '/');
        if ($oldPrefix !== '' && $newPrefix !== '' && strpos($path, $oldPrefix) === 0) {
            return $newPrefix . substr($path, strlen($oldPrefix));
        }
        return $path;
    }
}
