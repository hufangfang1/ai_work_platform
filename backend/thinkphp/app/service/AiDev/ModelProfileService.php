<?php

namespace app\service\AiDev;

/** OpenAI-compatible HTTP model profiles. */
class ModelProfileService
{
    public function available()
    {
        $list = [];
        foreach ($this->profiles(true) as $item) {
            $list[] = [
                'key' => (string) $item['key'],
                'label' => (string) $item['label'],
                'tier' => (string) $item['tier'],
                'agent' => 'http',
                'model' => (string) $item['model'],
                'api_base' => (string) $item['api_base'],
                'api_key_ref' => (string) $item['api_key_ref'],
            ];
        }
        return $list;
    }

    public function stepDefaults()
    {
        $defaults = [];
        $tierByRunType = [
            'task_plan' => 'complex',
            'ai_review' => 'complex',
            'coding' => 'medium',
            'fix' => 'medium',
        ];
        foreach ((array) config('ai_dev.step_models', []) as $runType => $key) {
            $profile = $this->profile($key);
            if ($profile) {
                $defaults[$runType] = (string) $key;
                continue;
            }
            $tier = $tierByRunType[$runType] ?? 'simple';
            $defaults[$runType] = $this->firstKeyForTier($tier);
        }
        return $defaults;
    }

    public function profile($key)
    {
        if (!is_string($key) || trim($key) === '') {
            return null;
        }
        foreach ($this->profiles(true) as $profile) {
            if ($profile['key'] === trim($key)) {
                return $profile;
            }
        }
        return null;
    }

    public function resolveKey($runType, $override = '')
    {
        $key = trim((string) $override);
        if ($key === '') {
            $defaults = (array) config('ai_dev.step_models', []);
            $key = isset($defaults[$runType]) ? trim((string) $defaults[$runType]) : '';
        }
        if ($key === '') {
            throw new \RuntimeException('未配置 ' . $runType . ' 的 HTTP 模型档案');
        }
        $profile = $this->profile($key);
        if (!$profile) {
            throw new \RuntimeException('未知模型: ' . $key);
        }
        if (strtolower((string) $profile['agent']) !== 'http') {
            throw new \RuntimeException('模型档案必须使用 HTTP 模式: ' . $key);
        }
        if (trim((string) $profile['api_base']) === '' || trim((string) $profile['model']) === '') {
            throw new \RuntimeException('HTTP 模型档案缺少 API 地址或模型参数: ' . $key);
        }
        return $key;
    }

    public function isHttp($key)
    {
        $profile = $this->profile($key);
        return $profile && strtolower((string) $profile['agent']) === 'http';
    }

    public function codingCapable($key)
    {
        return $this->isHttp($key);
    }

    public function resolveApiKey($ref)
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return '';
        }
        $secret = getenv($ref);
        if ($secret === false) {
            $secret = env($ref, null);
        }
        return $secret === false || $secret === null ? '' : (string) $secret;
    }

    public function agentLabel($key)
    {
        return 'HTTP API';
    }

    private function profiles($enabledOnly = false)
    {
        $profiles = (new ConfigService())->modelProfiles();
        $result = [];
        foreach ($profiles as $profile) {
            if ($enabledOnly && empty($profile['enabled'])) {
                continue;
            }
            if (strtolower((string) ($profile['agent'] ?? '')) !== 'http') {
                continue;
            }
            $result[] = $profile;
        }
        return $result;
    }

    private function firstKeyForTier($tier)
    {
        foreach ($this->profiles(true) as $profile) {
            if ((string) ($profile['tier'] ?? 'medium') === $tier) {
                return (string) $profile['key'];
            }
        }
        return '';
    }
}
