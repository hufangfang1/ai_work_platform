<?php

return [
    // Redis 不可用的环境可在 .env 设 QUEUE_DRIVER=sync(同步执行,接口会阻塞到任务完成)
    'default' => env('QUEUE_DRIVER', 'redis'),
    'connections' => [
        'sync' => [
            'type' => 'sync',
        ],
        'redis' => [
            'type' => 'redis',
            'queue' => 'ai_dev_code',
            'host' => env('redis.host', '127.0.0.1'),
            'port' => env('redis.port', 6379),
            'password' => env('redis.password', ''),
            'select' => env('redis.select', 0),
            'timeout' => 0,
            'persistent' => false,
            // AI 任务可能长时间运行。保留的消息在 worker 异常退出后会于此时间后恢复；
            // worker 必须配合 --tries 2，允许一次恢复执行，避免恢复前就被框架判为失败。
            'retry_after' => 3600,
        ],
    ],
];
