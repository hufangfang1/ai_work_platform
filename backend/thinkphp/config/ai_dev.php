<?php

return [
    'agent' => [
        'command' => env('ai_dev.claude_command', 'claude'),
        'max_turns' => env('ai_dev.max_turns', 50),
        'timeout' => env('ai_dev.timeout', 1800),
        'permission_mode' => 'acceptEdits',
        'output_format' => 'stream-json',
        // 追加到每次 agent 调用的 system 指令:统一用中文对话/说明,但技术标识符保持原样。
        // 留空则不注入。
        'language_prompt' => env(
            'ai_dev.language_prompt',
            '重要:请全程使用简体中文输出,包括你的思考过程(thinking)、分析、说明和总结,即使输入内容是英文也要用中文思考和回复。只有代码本身、分支名、变量名、文件名、commit message 等技术标识符按各自规范(通常为英文)书写,其余一律用中文。'
        ),
        // worker 起的 agent 子进程默认会读操作者个人的 ~/.claude(插件/技能/钩子/CLAUDE.md),
        // 导致编码任务被 superpowers 等技能流程劫持、日志里灌英文。这里指定独立配置目录做隔离。
        // 留空 = 用 runtime 下的默认目录;设为 'off' = 不隔离(沿用个人全局配置)。
        'config_dir' => env('ai_dev.claude_config_dir', ''),
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
            'agent' => 'claude',
            'model' => 'deepseek-v4-pro',
            'api_base' => 'https://api.deepseek.com/anthropic',
            'api_key_ref' => 'DEEPSEEK_API_KEY',
            'env' => [],
        ],
        'deepseek-v4-flash' => [
            // deepseek-reasoner 于 2026/07/24 弃用,官方对应 deepseek-v4-flash 的思考模式
            'label' => 'DeepSeek V4 Flash',
            'agent' => 'claude',
            'model' => 'deepseek-v4-flash',
            'api_base' => 'https://api.deepseek.com/anthropic',
            'api_key_ref' => 'DEEPSEEK_API_KEY',
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
        'branch_name' => env('ai_dev.model_branch_name', ''),
        'coding' => env('ai_dev.model_coding', ''),
        'fix' => env('ai_dev.model_fix', ''),
    ],
];

