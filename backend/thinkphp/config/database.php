<?php

return [
    'default' => env('database.driver', 'mysql'),
    'connections' => [
        'mysql' => [
            'type' => env('database.type', 'mysql'),
            'hostname' => env('database.hostname', '127.0.0.1'),
            'database' => env('database.database', 'ai_work_platform'),
            'username' => env('database.username', 'root'),
            'password' => env('database.password', ''),
            'hostport' => env('database.hostport', '3306'),
            'params' => [
                \PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci',
            ],
            'charset' => env('database.charset', 'utf8mb4'),
            'prefix' => env('database.prefix', ''),
            'deploy' => 0,
            'rw_separate' => false,
            'master_num' => 1,
            'slave_no' => '',
            'fields_strict' => true,
            'break_reconnect' => true,
            'trigger_sql' => env('app_debug', true),
            'fields_cache' => false,
        ],
        // 本机无 MySQL 时的开发连接:.env 设 DATABASE_DRIVER=sqlite
        'sqlite' => [
            'type' => 'sqlite',
            'database' => env('database.sqlite_path', app()->getRootPath() . 'database/ai_work_platform.sqlite3'),
            'prefix' => '',
            'fields_strict' => true,
            'trigger_sql' => env('app_debug', true),
            'fields_cache' => false,
        ],
    ],
];
