<?php

namespace app\service\AiDev;

/** 构造小而稳定的仓库快照，避免让 Agent 自由遍历大型仓库。 */
class RepositorySnapshotService
{
    const EXCLUDED_DIRS = ['.git', '.idea', '.vscode', 'vendor', 'node_modules', 'dist', 'build', 'runtime', 'coverage'];
    const INVENTORY_LIMIT = 180;

    public function build($path)
    {
        $path = rtrim((string) $path, '/');
        $sections = ["## 根目录\n" . implode("\n", $this->rootEntries($path))];
        $readme = $this->firstExisting($path, ['README.md', 'README.MD', 'readme.md', 'README.txt']);
        if ($readme !== '') {
            $sections[] = "## README(最多 2000 字)\n" . $this->readLimited($readme, 2000);
        }
        $manifest = $this->manifestSummary($path);
        if ($manifest !== '') {
            $sections[] = "## 依赖与脚本摘要\n" . $manifest;
        }
        $files = [];
        $this->collectRelevantFiles($path, $path, $files, 0);
        sort($files);
        if ($files) {
            $sections[] = "## 业务相关文件(最多 " . self::INVENTORY_LIMIT . " 个)\n" . implode("\n", $files);
        }
        return implode("\n\n", $sections);
    }

    private function rootEntries($path)
    {
        $entries = @scandir($path);
        if (!is_array($entries)) {
            return ['(无法读取根目录)'];
        }
        $result = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::EXCLUDED_DIRS, true)) {
                continue;
            }
            $result[] = $entry . (is_dir($path . '/' . $entry) ? '/' : '');
            if (count($result) >= 100) {
                break;
            }
        }
        sort($result);
        return $result ?: ['(空目录)'];
    }

    private function manifestSummary($path)
    {
        foreach (['package.json', 'composer.json'] as $name) {
            $file = $path . '/' . $name;
            if (!is_file($file)) {
                continue;
            }
            $data = json_decode((string) @file_get_contents($file), true);
            if (!is_array($data)) {
                return $name . "\n" . $this->readLimited($file, 4000);
            }
            $lines = [$name];
            foreach (['name', 'description', 'type', 'version'] as $key) {
                if (isset($data[$key]) && is_scalar($data[$key])) {
                    $lines[] = $key . ': ' . (string) $data[$key];
                }
            }
            if (!empty($data['scripts']) && is_array($data['scripts'])) {
                $lines[] = 'scripts: ' . implode(', ', array_slice(array_keys($data['scripts']), 0, 30));
            }
            foreach (['require', 'dependencies', 'require-dev', 'devDependencies'] as $key) {
                if (!empty($data[$key]) && is_array($data[$key])) {
                    $lines[] = $key . ': ' . implode(', ', array_slice(array_keys($data[$key]), 0, 80));
                }
            }
            return implode("\n", $lines);
        }
        foreach (['go.mod', 'pom.xml', 'pyproject.toml', 'requirements.txt', 'Gemfile'] as $name) {
            $file = $path . '/' . $name;
            if (is_file($file)) {
                return $name . "\n" . $this->readLimited($file, 4000);
            }
        }
        return '';
    }

    private function collectRelevantFiles($dir, $root, array &$files, $depth)
    {
        if ($depth > 6 || count($files) >= self::INVENTORY_LIMIT) {
            return;
        }
        $entries = @scandir($dir);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, self::EXCLUDED_DIRS, true)) {
                continue;
            }
            $full = $dir . '/' . $entry;
            $relative = ltrim(substr($full, strlen($root)), '/');
            if (is_dir($full)) {
                $this->collectRelevantFiles($full, $root, $files, $depth + 1);
            } elseif ($this->isRelevant($relative)) {
                $files[] = $relative;
                if (count($files) >= self::INVENTORY_LIMIT) {
                    return;
                }
            }
        }
    }

    private function isRelevant($relative)
    {
        return (bool) preg_match(
            '#^(route|routes|controllers?|pages|api)/|^app/[^/]+/(controller|route)/|^src/(api|router|views|pages|store)/#i',
            (string) $relative
        );
    }

    private function firstExisting($path, array $names)
    {
        foreach ($names as $name) {
            $file = $path . '/' . $name;
            if (is_file($file)) {
                return $file;
            }
        }
        return '';
    }

    private function readLimited($file, $limit)
    {
        $content = (string) @file_get_contents($file, false, null, 0, $limit * 4);
        if (mb_strlen($content) > $limit) {
            $content = mb_substr($content, 0, $limit) . "\n…(已截断)";
        }
        return trim($content);
    }
}
