<?php

return [
    'agent' => [
        // 单次回复的最大输出 token。生成类步骤(计划/规格/拆解)的 markdown 很长,
        // 默认值过小会让模型把 JSON 说到一半就被截断("JSON 解析失败")。给足预算,
        // 最终上限仍由模型/网关裁剪。设 0 = 使用网关默认值。
        'max_output_tokens' => (int) env('ai_dev.max_output_tokens', 32000),
        // 开发计划这类"先读代码再产出长文档"的步骤耗时较长(强模型会多轮探索),
        // 600s 常在模型正要输出最终 JSON 时被掐断("执行超时")。给足时间预算。
        'plan_timeout' => (int) env('ai_dev.plan_timeout', 1200),
        'plan_finalize_timeout' => (int) env('ai_dev.plan_finalize_timeout', 90),
        // AI Review 需多轮 Read/Grep 读 diff 与代码,fix 后改动面更大;300s 易超时。
        'review_timeout' => (int) env('ai_dev.review_timeout', 900),
        'code_http_timeout' => (int) env('ai_dev.code_http_timeout', 300),
        // 追加到每次 agent 调用的 system 指令:统一用中文对话/说明,但技术标识符保持原样。
        // 留空则不注入。
        'language_prompt' => env(
            'ai_dev.language_prompt',
            '重要:请全程使用简体中文输出,包括你的思考过程(thinking)、分析、说明和总结,即使输入内容是英文也要用中文思考和回复。只有代码本身、分支名、变量名、文件名、commit message 等技术标识符按各自规范(通常为英文)书写,其余一律用中文。'
        ),
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
     * 所有模型均通过 OpenAI-compatible /chat/completions 直连；api_key_ref 只保存环境变量名。
     */
    'models' => [
        'deepseek-v4-pro' => [
            'label' => 'DeepSeek V4 Pro',
            'tier' => 'complex',
            'agent' => 'http',
            'model' => 'deepseek-v4-pro',
            'api_base' => 'https://api.deepseek.com',
            'api_key_ref' => 'DEEPSEEK_API_KEY',
            'env' => [],
        ],
        'deepseek-v4-flash' => [
            // deepseek-reasoner 于 2026/07/24 弃用,官方对应 deepseek-v4-flash 的思考模式
            'label' => 'DeepSeek V4 Flash',
            'tier' => 'simple',
            'agent' => 'http',
            'model' => 'deepseek-v4-flash',
            'api_base' => 'https://api.deepseek.com',
            'api_key_ref' => 'DEEPSEEK_API_KEY',
            'env' => [],
        ],
    ],
    // 三档默认模型：复杂负责计划/Review，中等负责编码/Fix，简单负责其余生成任务。
    // 每个步骤仍可用 model_* 单独覆盖；模型档案应使用 agent=http 才会走直连 API。
    'model_tiers' => [
        'complex' => env('ai_dev.model_complex', ''),
        'medium' => env('ai_dev.model_medium', ''),
        'simple' => env('ai_dev.model_simple', ''),
    ],
    'step_models' => [
        'requirement_breakdown' => env('ai_dev.model_breakdown', env('ai_dev.model_simple', '')),
        'task_spec' => env('ai_dev.model_task_spec', env('ai_dev.model_simple', '')),
        'task_plan' => env('ai_dev.model_plan', env('ai_dev.model_complex', '')),
        'project_description' => env('ai_dev.model_project_description', env('ai_dev.model_simple', '')),
        'ai_review' => env('ai_dev.model_ai_review', env('ai_dev.model_complex', '')),
        'commit_message' => env('ai_dev.model_commit_message', env('ai_dev.model_simple', '')),
        'branch_name' => env('ai_dev.model_branch_name', env('ai_dev.model_simple', '')),
        'coding' => env('ai_dev.model_coding', env('ai_dev.model_medium', '')),
        'fix' => env('ai_dev.model_fix', env('ai_dev.model_medium', '')),
    ],
];
