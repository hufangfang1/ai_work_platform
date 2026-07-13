<?php

return [
    'agent' => [
        'command' => env('ai_dev.claude_command', 'claude'),
        'max_turns' => env('ai_dev.max_turns', 50),
        'timeout' => env('ai_dev.timeout', 1800),
        // 单次回复的最大输出 token。生成类步骤(计划/规格/拆解)的 markdown 很长,
        // 默认值过小会让模型把 JSON 说到一半就被截断("JSON 解析失败")。给足预算,
        // 最终上限仍由模型/网关裁剪。设 0 = 不注入,沿用 CLI/网关默认。
        'max_output_tokens' => (int) env('ai_dev.max_output_tokens', 32000),
        // 开发计划这类"先读代码再产出长文档"的步骤耗时较长(强模型会多轮探索),
        // 600s 常在模型正要输出最终 JSON 时被掐断("执行超时")。给足时间预算。
        'plan_timeout' => (int) env('ai_dev.plan_timeout', 1200),
        // AI Review 需多轮 Read/Grep 读 diff 与代码,fix 后改动面更大;300s 易超时。
        'review_timeout' => (int) env('ai_dev.review_timeout', 900),
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
        // 编码发生在独立 worktree，主工作目录存在未提交改动不会污染工单分支。
        'require_clean_repo' => false,
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
        'task_spec' => env('ai_dev.model_task_spec', ''),
        'task_plan' => env('ai_dev.model_plan', ''),
        'project_description' => env('ai_dev.model_project_description', ''),
        'ai_review' => env('ai_dev.model_ai_review', ''),
        'commit_message' => env('ai_dev.model_commit_message', ''),
        'branch_name' => env('ai_dev.model_branch_name', ''),
        'coding' => env('ai_dev.model_coding', ''),
        'fix' => env('ai_dev.model_fix', ''),
    ],
];
