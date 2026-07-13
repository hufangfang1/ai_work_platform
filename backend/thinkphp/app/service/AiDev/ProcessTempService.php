<?php

namespace app\service\AiDev;

/**
 * 为一次 AI/项目命令执行准备项目内临时目录，并把 TMPDIR 等环境变量指过去。
 */
class ProcessTempService
{
    public function create($cwd, $purpose, $runId = '')
    {
        $base = $this->baseDir($cwd);
        $parent = $base . '/tmp';
        $suffix = $runId !== '' ? '-' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) $runId) : '';
        $name = 'ai-dev-run-' . preg_replace('/[^A-Za-z0-9_.-]+/', '-', (string) $purpose)
            . $suffix . '-' . bin2hex(random_bytes(4));
        $dir = $parent . '/' . $name;
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            throw new \RuntimeException('无法创建执行临时目录: ' . $dir);
        }
        @mkdir($dir . '/cache', 0700, true);
        return $dir;
    }

    public function writeFile($tempDir, $filename, $content)
    {
        $file = rtrim($tempDir, '/') . '/' . ltrim($filename, '/');
        $parent = dirname($file);
        if (!is_dir($parent) && !@mkdir($parent, 0700, true)) {
            throw new \RuntimeException('无法创建临时文件目录: ' . $parent);
        }
        if (file_put_contents($file, $content) === false) {
            throw new \RuntimeException('无法写入临时文件: ' . $file);
        }
        return $file;
    }

    public function env(array $env = null, $tempDir)
    {
        $base = $env;
        if ($base === null) {
            $base = getenv();
            if (!is_array($base)) {
                $base = [];
            }
        }
        return array_merge($base, $this->envOverrides($tempDir));
    }

    public function exec($cwd, $command, array &$output = null, &$code = null, $purpose = 'command', $runId = '')
    {
        $tempDir = $this->create($cwd, $purpose, $runId);
        $output = [];
        try {
            $wrapped = 'cd ' . escapeshellarg($this->baseDir($cwd))
                . ' && { ' . $this->exportPrefix($tempDir) . $command . '; } 2>&1';
            exec($wrapped, $output, $code);
        } finally {
            $this->cleanup($tempDir);
        }
    }

    public function cleanup($tempDir)
    {
        $tempDir = rtrim((string) $tempDir, '/');
        if ($tempDir === '' || !is_dir($tempDir) || !$this->isManagedRunDir($tempDir)) {
            return;
        }
        $this->deleteTree($tempDir);
        @rmdir(dirname($tempDir));
    }

    private function envOverrides($tempDir)
    {
        $tempDir = rtrim($tempDir, '/');
        return [
            'TMPDIR' => $tempDir,
            'TMP' => $tempDir,
            'TEMP' => $tempDir,
            'TMPPREFIX' => $tempDir . '/zsh',
            'XDG_CACHE_HOME' => $tempDir . '/cache',
            'DARWIN_USER_TEMP_DIR' => $tempDir . '/',
            'DARWIN_USER_CACHE_DIR' => $tempDir . '/cache/',
        ];
    }

    private function exportPrefix($tempDir)
    {
        $parts = [];
        foreach ($this->envOverrides($tempDir) as $name => $value) {
            $parts[] = 'export ' . $name . '=' . escapeshellarg($value);
        }
        return implode('; ', $parts) . '; ';
    }

    private function baseDir($cwd)
    {
        $cwd = rtrim((string) $cwd, '/');
        if ($cwd !== '' && is_dir($cwd)) {
            $real = realpath($cwd);
            return $real !== false ? $real : $cwd;
        }
        return rtrim(function_exists('runtime_path') ? runtime_path() : getcwd(), '/');
    }

    private function isManagedRunDir($path)
    {
        return strpos(basename($path), 'ai-dev-run-') === 0 && basename(dirname($path)) === 'tmp';
    }

    private function deleteTree($path)
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        $items = @scandir($path);
        if (!is_array($items)) {
            @rmdir($path);
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $this->deleteTree($path . '/' . $item);
        }
        @rmdir($path);
    }
}
