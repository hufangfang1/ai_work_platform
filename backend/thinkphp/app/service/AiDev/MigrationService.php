<?php

namespace app\service\AiDev;

use think\facade\Db;

class MigrationService
{
    const TABLE = 'ai_dev_schema_migrations';

    public function status()
    {
        $this->ensureTable();
        $executed = $this->executedMap();
        $items = [];
        foreach ($this->migrationFiles() as $file) {
            $version = $this->versionFromFile($file);
            $items[] = [
                'version' => $version,
                'name' => basename($file),
                'executed' => isset($executed[$version]),
                'executed_at' => isset($executed[$version]) ? $executed[$version]['executed_at'] : '',
            ];
        }
        return [
            'current_version' => $this->currentVersion($executed),
            'pending_count' => count(array_filter($items, function ($item) {
                return !$item['executed'];
            })),
            'migrations' => $items,
        ];
    }

    public function migrate()
    {
        $this->ensureTable();
        $executed = $this->executedMap();
        $applied = [];
        foreach ($this->migrationFiles() as $file) {
            $version = $this->versionFromFile($file);
            if (isset($executed[$version])) {
                continue;
            }
            $this->runFile($file);
            Db::name(self::TABLE)->insert([
                'version' => $version,
                'name' => basename($file),
                'executed_at' => date('Y-m-d H:i:s'),
            ]);
            $applied[] = basename($file);
        }
        return [
            'applied' => $applied,
            'status' => $this->status(),
        ];
    }

    public function ensureTable()
    {
        $type = $this->driverType();
        if ($type === 'sqlite') {
            Db::execute("CREATE TABLE IF NOT EXISTS " . self::TABLE . " (
                version TEXT PRIMARY KEY,
                name TEXT NOT NULL DEFAULT '',
                executed_at TEXT NOT NULL DEFAULT ''
            )");
            return;
        }
        Db::execute("CREATE TABLE IF NOT EXISTS `" . self::TABLE . "` (
            `version` VARCHAR(64) NOT NULL,
            `name` VARCHAR(255) NOT NULL DEFAULT '',
            `executed_at` DATETIME NOT NULL,
            PRIMARY KEY (`version`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private function migrationFiles()
    {
        $dir = app()->getRootPath() . 'database/migrations';
        $files = glob($dir . '/*.sql') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private function runFile($file)
    {
        $sql = file_get_contents($file);
        foreach ($this->splitStatements($sql) as $statement) {
            Db::execute($statement);
        }
    }

    private function splitStatements($sql)
    {
        $sql = preg_replace('/^\s*--.*$/m', '', (string) $sql);
        $parts = array_map('trim', explode(';', $sql));
        return array_values(array_filter($parts, function ($part) {
            return $part !== '';
        }));
    }

    private function executedMap()
    {
        $rows = Db::name(self::TABLE)->select()->toArray();
        $map = [];
        foreach ($rows as $row) {
            $map[$row['version']] = $row;
        }
        return $map;
    }

    private function currentVersion(array $executed)
    {
        if (!$executed) {
            return '';
        }
        $versions = array_keys($executed);
        sort($versions, SORT_STRING);
        return end($versions);
    }

    private function versionFromFile($file)
    {
        return preg_replace('/[^0-9A-Za-z_].*$/', '', basename($file, '.sql'));
    }

    private function driverType()
    {
        $config = config('database.connections.' . config('database.default'));
        return isset($config['type']) ? strtolower($config['type']) : 'mysql';
    }
}
