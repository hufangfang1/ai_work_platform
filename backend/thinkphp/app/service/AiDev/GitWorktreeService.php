<?php

namespace app\service\AiDev;

/**
 * 以原始字节读取 git 输出。
 *
 * PHP exec() 会逐行收集输出并在部分环境下裁去行尾字节；UTF-8 汉字第三个字节
 * 恰好被当成空白字符时，拼回的 diff 便不再是合法 UTF-8，无法写入 utf8mb4 字段。
 */
class GitWorktreeService
{
    public function output($worktree, array $args)
    {
        $parts = ['git', '-C', escapeshellarg((string) $worktree)];
        foreach ($args as $arg) {
            $parts[] = escapeshellarg((string) $arg);
        }
        $pipes = [];
        $process = proc_open(implode(' ', $parts), [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('无法启动 git 命令');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' 执行失败: ' . trim((string) $stderr));
        }
        return (string) $stdout;
    }

    public function lines($worktree, array $args)
    {
        $output = rtrim($this->output($worktree, $args), "\r\n");
        return $output === '' ? [] : preg_split('/\r?\n/', $output);
    }
}
