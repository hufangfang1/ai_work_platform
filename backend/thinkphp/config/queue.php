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
            // AI 编码任务耗时远超默认 60 秒；过短会导致任务被重复投递、
            // 第二次投递因 --tries 1 直接把 run 覆盖成"队列任务执行失败"
            'retry_after' => 3600,
        ],
    ],
];
