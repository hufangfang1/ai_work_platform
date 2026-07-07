<?php

namespace app\service\AiDev;

class CommandSafetyService
{
    public function assertSafe($command, $label = '检查命令')
    {
        $command = trim((string) $command);
        if ($command === '') {
            return;
        }

        $patterns = [
            '/\brm\s+-[^&;|]*r[^&;|]*f\b/i' => '禁止递归强制删除',
            '/\bsudo\b/i' => '禁止 sudo',
            '/\b(git\s+push|git\s+commit|git\s+reset|git\s+checkout)\b/i' => '禁止修改 git 状态',
            '/\b(chmod|chown|kill|pkill|dd|mkfs|mount|umount)\b/i' => '禁止高风险系统命令',
            '/(curl|wget)[^|;&]*\|\s*(sh|bash|php|python|perl|ruby)/i' => '禁止下载后直接执行',
            '/(^|[^>])>\s*[^&]/' => '禁止输出重定向写文件',
        ];

        foreach ($patterns as $pattern => $reason) {
            if (preg_match($pattern, $command)) {
                throw new \RuntimeException($label . '不安全：' . $reason . '。命令：' . $command);
            }
        }
    }

    public function assertProjectChecks(array $project)
    {
        $labels = [
            'lint_command' => 'Lint 命令',
            'test_command' => '测试命令',
            'build_command' => '构建命令',
        ];
        foreach ($labels as $field => $label) {
            $this->assertSafe(isset($project[$field]) ? $project[$field] : '', $label);
        }
    }
}
