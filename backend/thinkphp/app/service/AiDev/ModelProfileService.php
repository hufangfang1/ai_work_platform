<?php

namespace app\service\AiDev;

/**
 * 模型档案:把 config/ai_dev.php 里的 models/step_models 解析成
 * 每次 claude CLI 调用要用的 --model 参数与进程环境变量。
 */
class ModelProfileService
{
    /** 供前端下拉框使用的模型清单 */
    public function available()
    {
        $list = [];
        foreach ((array) config('ai_dev.models', []) as $key => $item) {
            $list[] = [
                'key' => (string) $key,
                'label' => isset($item['label']) ? (string) $item['label'] : (string) $key,
                'model' => isset($item['model']) ? (string) $item['model'] : (string) $key,
            ];
        }
        return $list;
    }

    /** 每个步骤的默认模型 key(过滤掉配置里不存在的值) */
    public function stepDefaults()
    {
        $defaults = [];
        foreach ((array) config('ai_dev.step_models', []) as $runType => $key) {
            $defaults[$runType] = $this->profile($key) ? (string) $key : '';
        }
        return $defaults;
    }

    /** @return array|null 配置里的模型档案 */
    public function profile($key)
    {
        if (!is_string($key) || $key === '') {
            return null;
        }
        $models = (array) config('ai_dev.models', []);
        return isset($models[$key]) && is_array($models[$key]) ? $models[$key] : null;
    }

    /**
     * 决定一次运行实际使用的模型 key:优先用户本次指定,否则步骤默认;都没有返回 ''(走 CLI 全局默认)。
     * 用户显式指定了不存在的 key 时报错,避免静默回落到错误模型。
     */
    public function resolveKey($runType, $override = '')
    {
        $override = trim((string) $override);
        if ($override !== '') {
            if (!$this->profile($override)) {
                throw new \RuntimeException('未知模型: ' . $override);
            }
            return $override;
        }
        $defaults = (array) config('ai_dev.step_models', []);
        $key = isset($defaults[$runType]) ? trim((string) $defaults[$runType]) : '';
        return $this->profile($key) ? $key : '';
    }

    /** 拼进 claude 命令行的 --model 片段,无档案时返回 '' */
    public function commandArg($key)
    {
        $profile = $this->profile($key);
        if (!$profile || empty($profile['model'])) {
            return '';
        }
        return ' --model ' . escapeshellarg((string) $profile['model']);
    }

    /**
     * proc_open 用的环境变量数组;档案没有 env 覆盖时返回 null(继承当前进程环境)。
     * 注意 proc_open 传了数组就是子进程的完整环境,必须在当前环境基础上合并。
     */
    public function processEnv($key)
    {
        $profile = $this->profile($key);
        if (!$profile || empty($profile['env']) || !is_array($profile['env'])) {
            return null;
        }
        $overrides = [];
        foreach ($profile['env'] as $name => $value) {
            $overrides[(string) $name] = (string) $value;
        }
        return array_merge(getenv(), $overrides);
    }
}
