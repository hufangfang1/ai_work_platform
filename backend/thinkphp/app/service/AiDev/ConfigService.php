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
}
