<?php

namespace app\service\AiDev;

use think\facade\Db;

class DocService
{
    public function mask($content)
    {
        $rules = Db::name('ai_dev_security_rules')->where('enabled', 1)->select()->toArray();
        foreach ($rules as $rule) {
            if ($rule['pattern'] === '') {
                continue;
            }
            $replacement = $rule['replacement'] !== '' ? $rule['replacement'] : '***';
            $content = @preg_replace('/' . $rule['pattern'] . '/i', $replacement, $content);
        }
        return $content;
    }
}
