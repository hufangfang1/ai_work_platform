<?php

namespace app\service\AiDev;

use think\facade\Db;

class WorkspaceService
{
    public function getRoots()
    {
        $row = Db::name('ai_dev_settings')->where('key', 'workspace_roots')->find();
        $roots = $row ? json_decode((string) $row['value'], true) : [];
        return is_array($roots) ? $roots : [];
    }

    public function saveRoots(array $roots)
    {
        $normalized = [];
        foreach ($roots as $root) {
            $root = $this->expand(trim((string) $root));
            if ($root === '') {
                continue;
            }
            if (!is_dir($root)) {
                throw new \RuntimeException('目录不存在: ' . $root);
            }
            if (!in_array($root, $normalized)) {
                $normalized[] = $root;
            }
        }
        $exists = Db::name('ai_dev_settings')->where('key', 'workspace_roots')->find();
        $value = json_encode($normalized, JSON_UNESCAPED_UNICODE);
        if ($exists) {
            Db::name('ai_dev_settings')->where('id', $exists['id'])->update(['value' => $value]);
        } else {
            Db::name('ai_dev_settings')->insert(['key' => 'workspace_roots', 'value' => $value]);
        }
        return $normalized;
    }

    /**
     * 扫描工作区根目录下(深度 <= 2)的 git 仓库。
     */
    public function scan()
    {
        $results = [];
        foreach ($this->getRoots() as $root) {
            $this->collect($root, 0, $results);
        }
        return $results;
    }

    private function collect($dir, $depth, array &$results)
    {
        if (!is_dir($dir) || $depth > 2) {
            return;
        }
        if (is_dir($dir . '/.git')) {
            $results[] = [
                'path' => $dir,
                'name' => basename($dir),
                'repo_url' => $this->gitOutput($dir, 'remote get-url origin'),
                'current_branch' => $this->gitOutput($dir, 'rev-parse --abbrev-ref HEAD'),
                'already_added' => (bool) Db::name('ai_dev_projects')
                    ->where('local_path', $dir)->where('status', 1)->count(),
            ];
            return;
        }
        $entries = @scandir($dir);
        if (!$entries) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || strpos($entry, '.') === 0) {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_dir($path)) {
                $this->collect($path, $depth + 1, $results);
            }
        }
    }

    private function gitOutput($dir, $args)
    {
        return trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' ' . $args . ' 2>/dev/null'));
    }

    private function expand($path)
    {
        if ($path !== '' && strpos($path, '~') === 0) {
            $home = getenv('HOME');
            if ($home) {
                $path = $home . substr($path, 1);
            }
        }
        return rtrim($path, '/');
    }
}
