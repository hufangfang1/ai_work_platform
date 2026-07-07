<?php

return [
    'agent' => [
        'command' => env('ai_dev.claude_command', 'claude'),
        'max_turns' => env('ai_dev.max_turns', 50),
        'timeout' => env('ai_dev.timeout', 1800),
        'permission_mode' => 'acceptEdits',
        'output_format' => 'stream-json',
    ],
    'worktree' => [
        'prefix' => env('ai_dev.worktree_prefix', 'wt-task-'),
        'cleanup_on_terminate' => true,
    ],
    'safety' => [
        'allow_push_default' => false,
        'require_clean_repo' => true,
    ],
    /*
     * 可选模型档案:key 是前端/接口里用的标识。
     * model = 传给 claude CLI --model 的模型名;env = 起子进程时覆盖的环境变量(空数组 = 沿用全局 settings.json 的端点)。
     * 拿到 Anthropic 官方 key 后,取消注释 claude-sonnet 段并在 .env 配 ai_dev.anthropic_api_key 即可出现真 Claude 选项。
     */
    'models' => [
        'deepseek-v4-pro' => [
            'label' => 'DeepSeek V4 Pro',
            'model' => 'deepseek-v4-pro',
            'env' => [],
        ],
        'deepseek-reasoner' => [
            'label' => 'DeepSeek Reasoner(推理)',
            'model' => 'deepseek-reasoner',
            'env' => [],
        ],
        // 'claude-sonnet' => [
        //     'label' => 'Claude Sonnet(官方)',
        //     'model' => 'claude-sonnet-5',
        //     'env' => [
        //         'ANTHROPIC_BASE_URL' => 'https://api.anthropic.com',
        //         'ANTHROPIC_AUTH_TOKEN' => env('ai_dev.anthropic_api_key', ''),
        //         // 全局 settings.json 把各档模型映射到了 DeepSeek,这里必须一并覆盖回来
        //         'ANTHROPIC_MODEL' => 'claude-sonnet-5',
        //         'ANTHROPIC_DEFAULT_SONNET_MODEL' => 'claude-sonnet-5',
        //         'ANTHROPIC_DEFAULT_OPUS_MODEL' => 'claude-sonnet-5',
        //         'ANTHROPIC_DEFAULT_HAIKU_MODEL' => 'claude-haiku-4-5-20251001',
        //         'ANTHROPIC_REASONING_MODEL' => 'claude-sonnet-5',
        //     ],
        // ],
    ],
    // 每个步骤(run_type)的默认模型 key,留空 = 不传 --model,走 CLI 全局默认
    'step_models' => [
        'requirement_breakdown' => env('ai_dev.model_breakdown', ''),
        'task_plan' => env('ai_dev.model_plan', ''),
        'project_description' => env('ai_dev.model_project_description', ''),
        'ai_review' => env('ai_dev.model_ai_review', ''),
        'commit_message' => env('ai_dev.model_commit_message', ''),
        'coding' => env('ai_dev.model_coding', ''),
        'fix' => env('ai_dev.model_fix', ''),
    ],
];

