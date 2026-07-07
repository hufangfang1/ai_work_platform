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
];
