<?php

namespace app\service\AiDev;

use think\facade\Db;

class ConfigService
{
    const MODEL_FIELDS = ['provider', 'model_name', 'api_base', 'api_key_ref', 'context_length', 'timeout_seconds'];
    const MODEL_PROFILE_SETTING = 'model_profiles';

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

    public function modelProfiles()
    {
        $stored = $this->settingJson(self::MODEL_PROFILE_SETTING, null);
        if (is_array($stored)) {
            return $this->normalizeModelProfiles($stored);
        }
        return $this->defaultModelProfiles();
    }

    public function saveModelProfiles(array $profiles)
    {
        $normalized = $this->normalizeModelProfiles($profiles);
        $this->saveSettingJson(self::MODEL_PROFILE_SETTING, $normalized);
        return $normalized;
    }

    public function refreshLocalModelProfiles()
    {
        $profiles = array_merge(
            $this->localClaudeProfiles(),
            $this->localCodexProfiles(),
            $this->localCursorProfiles()
        );
        if (!$profiles) {
            throw new \RuntimeException('未发现本机 Claude/Codex/Cursor 模型配置');
        }
        return $this->saveModelProfiles($profiles);
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
            'model_profiles' => $this->modelProfiles(),
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
        if (isset($payload['model_profiles']) && is_array($payload['model_profiles'])) {
            $this->saveModelProfiles($payload['model_profiles']);
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

    private function defaultModelProfiles()
    {
        $profiles = [];
        foreach ((array) config('ai_dev.models', []) as $key => $item) {
            $profiles[] = [
                'key' => (string) $key,
                'label' => isset($item['label']) ? (string) $item['label'] : (string) $key,
                'agent' => isset($item['agent']) ? (string) $item['agent'] : 'claude',
                'command' => isset($item['command'])
                    ? (string) $item['command']
                    : (string) config('ai_dev.agent.command', 'claude'),
                'model' => isset($item['model']) ? (string) $item['model'] : (string) $key,
                'api_base' => isset($item['api_base']) ? (string) $item['api_base'] : '',
                'api_key_ref' => isset($item['api_key_ref']) ? (string) $item['api_key_ref'] : '',
                'context_length' => isset($item['context_length']) ? (int) $item['context_length'] : 0,
                'timeout_seconds' => isset($item['timeout_seconds']) ? (int) $item['timeout_seconds'] : 0,
                'env' => isset($item['env']) && is_array($item['env']) ? $item['env'] : [],
                'enabled' => isset($item['enabled']) ? (!empty($item['enabled']) ? 1 : 0) : 1,
                'description' => isset($item['description']) ? (string) $item['description'] : '',
            ];
        }
        return $this->normalizeModelProfiles($profiles);
    }

    private function localClaudeProfiles()
    {
        $settings = $this->readJsonFile($this->homePath('.claude/settings.json'));
        $localSettings = $this->readJsonFile($this->homePath('.claude/settings.local.json'));
        $command = $this->commandPath('claude') ?: (string) config('ai_dev.agent.command', 'claude');
        if (!$settings && !$localSettings && !$command) {
            return [];
        }

        $env = isset($settings['env']) && is_array($settings['env']) ? $settings['env'] : [];
        $model = isset($settings['model']) ? trim((string) $settings['model']) : '';
        if ($model === '') {
            foreach (['ANTHROPIC_MODEL', 'ANTHROPIC_DEFAULT_SONNET_MODEL', 'ANTHROPIC_DEFAULT_OPUS_MODEL'] as $name) {
                if (!empty($env[$name])) {
                    $model = trim((string) $env[$name]);
                    break;
                }
            }
        }
        $label = $model !== '' ? 'Claude ' . $model : 'Claude 默认模型';
        $envOverrides = $this->nonSecretEnv($env);
        $apiBase = isset($env['ANTHROPIC_BASE_URL']) ? (string) $env['ANTHROPIC_BASE_URL'] : (string) getenv('ANTHROPIC_BASE_URL');
        $apiKeyRef = getenv('ANTHROPIC_AUTH_TOKEN') !== false
            ? 'ANTHROPIC_AUTH_TOKEN'
            : (getenv('ANTHROPIC_API_KEY') !== false ? 'ANTHROPIC_API_KEY' : '');

        return [[
            'key' => 'claude-' . $this->slug($model !== '' ? $model : 'default'),
            'label' => $label,
            'agent' => 'claude',
            'command' => $command,
            'model' => $model,
            'api_base' => $apiBase,
            'api_key_ref' => $apiKeyRef,
            'context_length' => 0,
            'timeout_seconds' => 0,
            'env' => $envOverrides,
            'enabled' => 1,
            'description' => '刷新自 ~/.claude/settings.json; 密钥值不会写入模型档案。',
        ]];
    }

    private function localCodexProfiles()
    {
        $config = $this->homePath('.codex/config.toml');
        $command = $this->commandPath('codex');
        if (!is_file($config) && !$command) {
            return [];
        }
        $model = is_file($config) ? $this->readTomlTopLevelString($config, 'model') : '';
        if ($model === '') {
            $model = getenv('CODEX_MODEL') !== false ? (string) getenv('CODEX_MODEL') : '';
        }
        $label = $model !== '' ? 'Codex ' . $model : 'Codex 默认模型';
        return [[
            'key' => 'codex-' . $this->slug($model !== '' ? $model : 'default'),
            'label' => $label,
            'agent' => 'codex',
            'command' => $command ?: 'codex',
            'model' => $model,
            'api_base' => getenv('OPENAI_BASE_URL') !== false ? (string) getenv('OPENAI_BASE_URL') : '',
            'api_key_ref' => getenv('OPENAI_API_KEY') !== false ? 'OPENAI_API_KEY' : '',
            'context_length' => 0,
            'timeout_seconds' => 0,
            'env' => [],
            'enabled' => 1,
            'description' => '刷新自 ~/.codex/config.toml。',
        ]];
    }

    private function localCursorProfiles()
    {
        $config = $this->homePath('.cursor/config.json');
        if (!is_file($config)) {
            return [];
        }
        $data = $this->readJsonFile($config);
        $model = isset($data['model']) ? trim((string) $data['model']) : '';
        return [[
            'key' => 'cursor-' . $this->slug($model !== '' ? $model : 'default'),
            'label' => $model !== '' ? 'Cursor ' . $model : 'Cursor 默认模型',
            'agent' => 'cursor',
            'command' => $this->commandPath('cursor') ?: 'cursor',
            'model' => $model,
            'api_base' => '',
            'api_key_ref' => '',
            'context_length' => 0,
            'timeout_seconds' => 0,
            'env' => [],
            'enabled' => $model !== '' ? 1 : 0,
            'description' => '刷新自 ~/.cursor/config.json。',
        ]];
    }

    private function normalizeModelProfiles(array $profiles)
    {
        $normalized = [];
        $seen = [];
        $index = 1;
        foreach ($profiles as $profile) {
            if (!is_array($profile)) {
                continue;
            }
            $key = isset($profile['key']) ? trim((string) $profile['key']) : '';
            if ($key === '') {
                $key = 'model-' . $index;
            }
            $key = trim((string) preg_replace('/[^A-Za-z0-9_.:-]+/', '-', $key), '-');
            if ($key === '') {
                $key = 'model-' . $index;
            }
            $baseKey = $key;
            $dedupe = 2;
            while (isset($seen[$key])) {
                $key = $baseKey . '-' . $dedupe;
                $dedupe++;
            }
            $seen[$key] = true;

            $env = [];
            if (isset($profile['env']) && is_array($profile['env'])) {
                foreach ($profile['env'] as $name => $value) {
                    $name = trim((string) $name);
                    if ($name === '') {
                        continue;
                    }
                    $env[$name] = (string) $value;
                }
            } elseif (isset($profile['env_text'])) {
                $env = $this->parseEnvText((string) $profile['env_text']);
            }

            $normalized[] = [
                'key' => $key,
                'label' => isset($profile['label']) && trim((string) $profile['label']) !== ''
                    ? trim((string) $profile['label'])
                    : $key,
                'agent' => isset($profile['agent']) && trim((string) $profile['agent']) !== ''
                    ? trim((string) $profile['agent'])
                    : 'claude',
                'command' => isset($profile['command']) && trim((string) $profile['command']) !== ''
                    ? trim((string) $profile['command'])
                    : (string) config('ai_dev.agent.command', 'claude'),
                'model' => isset($profile['model']) ? trim((string) $profile['model']) : '',
                'api_base' => isset($profile['api_base']) ? trim((string) $profile['api_base']) : '',
                'api_key_ref' => isset($profile['api_key_ref']) ? trim((string) $profile['api_key_ref']) : '',
                'context_length' => isset($profile['context_length']) ? (int) $profile['context_length'] : 0,
                'timeout_seconds' => isset($profile['timeout_seconds']) ? (int) $profile['timeout_seconds'] : 0,
                'env' => $env,
                'enabled' => !array_key_exists('enabled', $profile) || !empty($profile['enabled']) ? 1 : 0,
                'description' => isset($profile['description']) ? trim((string) $profile['description']) : '',
            ];
            $index++;
        }
        return $normalized;
    }

    private function parseEnvText($text)
    {
        $env = [];
        foreach (preg_split('/\r?\n/', $text) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            if ($name === '') {
                continue;
            }
            $env[$name] = trim($value);
        }
        return $env;
    }

    private function nonSecretEnv(array $env)
    {
        $safe = [];
        foreach ($env as $name => $value) {
            $name = trim((string) $name);
            if ($name === '' || preg_match('/TOKEN|KEY|SECRET|PASSWORD|AUTH/i', $name)) {
                continue;
            }
            $safe[$name] = (string) $value;
        }
        return $safe;
    }

    private function readJsonFile($path)
    {
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);
        return is_array($data) ? $data : [];
    }

    private function readTomlTopLevelString($path, $key)
    {
        $lines = @file($path);
        if (!$lines) {
            return '';
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0) {
                continue;
            }
            if (strpos($line, '[') === 0) {
                break;
            }
            if (preg_match('/^' . preg_quote($key, '/') . '\s*=\s*([\'"])(.*?)\1/', $line, $matches)) {
                return trim((string) $matches[2]);
            }
        }
        return '';
    }

    private function commandPath($command)
    {
        $path = trim((string) shell_exec('command -v ' . escapeshellarg($command) . ' 2>/dev/null'));
        if ($path !== '') {
            return $path;
        }
        $candidates = [
            'claude' => [
                $this->homePath('.local/bin/claude'),
                '/usr/local/bin/claude',
                '/opt/homebrew/bin/claude',
            ],
            'codex' => [
                '/Applications/Codex.app/Contents/Resources/codex',
                $this->homePath('.local/bin/codex'),
                '/usr/local/bin/codex',
                '/opt/homebrew/bin/codex',
            ],
            'cursor' => [
                '/usr/local/bin/cursor',
                '/opt/homebrew/bin/cursor',
                '/Applications/Cursor.app/Contents/Resources/app/bin/cursor',
            ],
        ];
        foreach ($candidates[$command] ?? [] as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return '';
    }

    private function homePath($path)
    {
        return rtrim((string) getenv('HOME'), '/') . '/' . ltrim($path, '/');
    }

    private function slug($value)
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $value), '-'));
        return $slug !== '' ? $slug : 'default';
    }

    private function settingJson($key, $default)
    {
        $row = Db::name('ai_dev_settings')->where('key', $key)->find();
        if (!$row) {
            return $default;
        }
        $data = json_decode((string) $row['value'], true);
        return json_last_error() === JSON_ERROR_NONE ? $data : $default;
    }

    private function saveSettingJson($key, $value)
    {
        $payload = json_encode($value, JSON_UNESCAPED_UNICODE);
        $existing = Db::name('ai_dev_settings')->where('key', $key)->find();
        if ($existing) {
            Db::name('ai_dev_settings')->where('id', $existing['id'])->update(['value' => $payload]);
        } else {
            Db::name('ai_dev_settings')->insert(['key' => $key, 'value' => $payload]);
        }
    }
}
