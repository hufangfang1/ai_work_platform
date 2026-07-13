<?php

namespace app\service\AiDev;

/**
 * 模型档案:把设置页维护的模型清单解析成
 * 每次 agent CLI 调用要用的命令、--model 参数与进程环境变量。
 */
class ModelProfileService
{
    /** 供前端下拉框使用的模型清单 */
    public function available()
    {
        $list = [];
        foreach ($this->profiles(true) as $item) {
            $list[] = [
                'key' => (string) $item['key'],
                'label' => (string) $item['label'],
                'agent' => (string) $item['agent'],
                'command' => (string) $item['command'],
                'model' => (string) $item['model'],
            ];
        }
        return $list;
    }

    /** 每个步骤的默认模型 key(过滤掉配置里不存在的值) */
    public function stepDefaults()
    {
        $defaults = [];
        foreach ((array) config('ai_dev.step_models', []) as $runType => $key) {
            $defaults[$runType] = $this->profile($key) ? (string) $key : '';
        }
        return $defaults;
    }

    /** @return array|null 配置里的模型档案 */
    public function profile($key)
    {
        if (!is_string($key) || $key === '') {
            return null;
        }
        foreach ($this->profiles(true) as $profile) {
            if ($profile['key'] === $key) {
                return $profile;
            }
        }
        return null;
    }

    /**
     * 决定一次运行实际使用的模型 key:优先用户本次指定,否则步骤默认;都没有返回 ''(走 CLI 全局默认)。
     * 用户显式指定了不存在的 key 时报错,避免静默回落到错误模型。
     */
    public function resolveKey($runType, $override = '')
    {
        $override = trim((string) $override);
        if ($override !== '') {
            if (!$this->profile($override)) {
                throw new \RuntimeException('未知模型: ' . $override);
            }
            return $override;
        }
        $defaults = (array) config('ai_dev.step_models', []);
        $key = isset($defaults[$runType]) ? trim((string) $defaults[$runType]) : '';
        return $this->profile($key) ? $key : '';
    }

    /** 拼进 claude 命令行的 --model 片段,无档案时返回 '' */
    public function commandArg($key)
    {
        $profile = $this->profile($key);
        if (!$profile || empty($profile['model'])) {
            return '';
        }
        return ' --model ' . escapeshellarg((string) $profile['model']);
    }

    public function command($key)
    {
        $profile = $this->profile($key);
        if ($profile && !empty($profile['command'])) {
            return (string) $profile['command'];
        }
        return (string) config('ai_dev.agent.command', 'claude');
    }

    /** agent 类型:claude / codex / ...(取不到时默认 claude) */
    public function agentType($key)
    {
        $profile = $this->profile($key);
        $agent = $profile && !empty($profile['agent']) ? strtolower(trim((string) $profile['agent'])) : '';
        return $agent !== '' ? $agent : 'claude';
    }

    /** 是否为 HTTP 直调档案(不走 CLI) */
    public function isHttp($key)
    {
        return $this->agentType($key) === 'http';
    }

    /** 是否可用于编码步骤(HTTP 直调不能编码,需 CLI 的 agent loop) */
    public function codingCapable($key)
    {
        return !$this->isHttp($key);
    }

    /** 按环境变量名解析 API key: getenv 优先,回退 ThinkPHP .env;取不到返回 '' */
    public function resolveApiKey($ref)
    {
        $ref = trim((string) $ref);
        if ($ref === '') {
            return '';
        }
        $secret = getenv($ref);
        if ($secret === false) {
            $secret = env($ref, null);
        }
        if ($secret === false || $secret === null) {
            return '';
        }
        return (string) $secret;
    }

    /** 失败信息里用的 CLI 名称 */
    public function agentLabel($key)
    {
        return $this->agentType($key) === 'codex' ? 'Codex CLI' : 'Claude Code';
    }

    /**
     * 组装一次 agent CLI 调用的完整命令行。不同 agent 的 CLI 语法完全不同,
     * 这里按 agent 类型统一翻译,避免各执行器写死 claude 的参数(codex 不认 --output-format 等)。
     *
     * prompt 通过 "$(cat file)" 以单个 shell 参数注入:claude 走 -p,codex 走位置参数。
     *
     * @param array $opts max_turns:int, allowed_tools:string, permission_mode:string, edit:bool(可写代码)
     */
    public function buildCommand($key, $promptFile, array $opts = [])
    {
        $command = escapeshellcmd($this->command($key));
        $profile = $this->profile($key);
        $model = $profile && !empty($profile['model']) ? (string) $profile['model'] : '';
        $langPrompt = $this->languagePrompt();
        // 把中文要求前置到 prompt 正文:比 system 提示更能压住模型的思考语言(尤其输入本身是英文时),两种 agent 通用。
        $promptArg = $langPrompt !== ''
            ? '"' . $langPrompt . "\n\n" . '$(cat ' . escapeshellarg((string) $promptFile) . ')"'
            : '"$(cat ' . escapeshellarg((string) $promptFile) . ')"';

        if ($this->agentType($key) === 'codex') {
            // codex 非交互用 `codex exec --json`,stdout 变成 JSONL 事件流。
            $parts = [$command, 'exec', '--json', '--skip-git-repo-check'];
            // 代码修改步骤要能写文件且全程无人值守;只读步骤限制在 read-only 沙箱。
            $parts[] = !empty($opts['edit'])
                ? '--dangerously-bypass-approvals-and-sandbox'
                : '--sandbox read-only';
            if ($model !== '') {
                $parts[] = '--model ' . escapeshellarg($model);
            }
            // -- 之后一律当 prompt,避免以 - 开头的内容被解析成参数。
            $parts[] = '--';
            $parts[] = $promptArg;
            return implode(' ', $parts);
        }

        // claude:-p 打印模式下 stream-json 必须同时带 --verbose,否则直接报错退出。
        $parts = [$command, '-p', $promptArg, '--output-format', 'stream-json', '--verbose'];
        // 再叠加一层 system 级中文指令(和 prompt 前置双保险)。
        if ($langPrompt !== '') {
            $parts[] = '--append-system-prompt';
            $parts[] = escapeshellarg($langPrompt);
        }
        if (!empty($opts['permission_mode'])) {
            $parts[] = '--permission-mode';
            $parts[] = escapeshellarg((string) $opts['permission_mode']);
        }
        if (!empty($opts['allowed_tools'])) {
            $parts[] = '--allowedTools';
            $parts[] = escapeshellarg((string) $opts['allowed_tools']);
        }
        // 只读生成步骤(计划/规格/拆解/评审)禁止派生子代理:--allowedTools 只是免确认白名单,
        // 并不阻止 Task。放任 Task 会 fan-out 多个探索子代理,既拖慢速度,又让 stream 出现多段
        // init/result——主结果早已产出、进程却迟迟不退出,最终撞上超时。编码步骤(edit=true)不受限。
        $disallowed = [];
        if (empty($opts['edit'])) {
            $disallowed[] = 'Task';
        }
        if (!empty($opts['disallowed_tools'])) {
            $disallowed[] = (string) $opts['disallowed_tools'];
        }
        if ($disallowed) {
            $parts[] = '--disallowedTools';
            $parts[] = escapeshellarg(implode(',', $disallowed));
        }
        if (isset($opts['max_turns'])) {
            $parts[] = '--max-turns';
            $parts[] = (string) (int) $opts['max_turns'];
        }
        if ($model !== '') {
            $parts[] = '--model';
            $parts[] = escapeshellarg($model);
        }
        return implode(' ', $parts);
    }

    /**
     * 从一条流式事件里提取「最终结果文本」,不是结果事件则返回 null。
     * claude: type=result -> result 字段。
     * codex : item.completed 且 item.type=agent_message -> item.text(取最后一条 agent_message)。
     */
    public function streamResultText($key, array $event)
    {
        if ($this->agentType($key) === 'codex') {
            if (isset($event['type']) && $event['type'] === 'item.completed'
                && isset($event['item']['type']) && $event['item']['type'] === 'agent_message'
            ) {
                return isset($event['item']['text']) ? (string) $event['item']['text'] : '';
            }
            return null;
        }
        if (isset($event['type']) && $event['type'] === 'result') {
            return isset($event['result']) ? (string) $event['result'] : '';
        }
        return null;
    }

    /**
     * proc_open 用的环境变量数组;档案没有 env 覆盖时返回 null(继承当前进程环境)。
     * 注意 proc_open 传了数组就是子进程的完整环境,必须在当前环境基础上合并。
     */
    public function processEnv($key, $tempDir = '')
    {
        $overrides = [];
        $profile = $this->profile($key);

        // 隔离个人全局配置:子进程用独立 CLAUDE_CONFIG_DIR,不再加载 ~/.claude 的插件/技能/钩子/CLAUDE.md,
        // 否则编码 agent 会被 superpowers 等技能流程劫持,日志里灌满英文技能内容。
        $configDir = $this->isolatedConfigDir();
        if ($configDir !== '') {
            $overrides['CLAUDE_CONFIG_DIR'] = $configDir;
            // 隔离后读不到全局 settings.json 的 env;profile 自带端点时用 profile 的,否则兜底带上全局 API 路由,避免丢认证。
            if (!$profile || empty($profile['api_base'])) {
                foreach ($this->globalClaudeEnv() as $name => $value) {
                    $overrides[(string) $name] = (string) $value;
                }
            }
        }

        // 放宽单次输出上限,避免长计划/规格/拆解的 JSON 被截断。档案可用 env 覆盖或用 max_output_tokens 单独指定。
        $maxOutput = isset($profile['max_output_tokens']) && (int) $profile['max_output_tokens'] > 0
            ? (int) $profile['max_output_tokens']
            : (int) config('ai_dev.agent.max_output_tokens', 0);
        if ($maxOutput > 0) {
            $overrides['CLAUDE_CODE_MAX_OUTPUT_TOKENS'] = (string) $maxOutput;
        }

        if ($profile) {
            if (!empty($profile['env']) && is_array($profile['env'])) {
                foreach ($profile['env'] as $name => $value) {
                    $overrides[(string) $name] = $this->expandEnvValue((string) $value);
                }
            }
            $isCodex = strtolower((string) $profile['agent']) === 'codex';
            if (!empty($profile['api_base'])) {
                // codex 走 OpenAI 端点变量,claude 走 Anthropic 端点变量。
                $overrides[$isCodex ? 'OPENAI_BASE_URL' : 'ANTHROPIC_BASE_URL'] = (string) $profile['api_base'];
            }
            if (!empty($profile['api_key_ref'])) {
                $secret = $this->resolveApiKey((string) $profile['api_key_ref']);
                if ($secret !== '') {
                    if ($isCodex) {
                        $overrides['OPENAI_API_KEY'] = $secret;
                    } else {
                        $overrides['ANTHROPIC_AUTH_TOKEN'] = $secret;
                    }
                }
            }
        }

        // 兜底鉴权:claude 子进程若最终没拿到任何令牌,但用的正是全局 settings.json 里的那个端点
        // (常见于「刷新本机模型」时 shell 没 export ANTHROPIC_AUTH_TOKEN,导致 profile 的 api_key_ref 落空,
        //  但 api_base 又写上了代理地址),就从 settings.json 兜底取令牌,否则子进程带着代理端点却无凭证,
        // claude 直接返回 "Not logged in · Please run /login"。只在端点一致时注入,避免把令牌带到别家端点。
        $isCodexAgent = $profile && strtolower((string) $profile['agent']) === 'codex';
        if (!$isCodexAgent
            && empty($overrides['ANTHROPIC_AUTH_TOKEN'])
            && empty($overrides['ANTHROPIC_API_KEY'])) {
            $globalEnv = $this->globalClaudeEnv();
            $globalBase = isset($globalEnv['ANTHROPIC_BASE_URL']) ? (string) $globalEnv['ANTHROPIC_BASE_URL'] : '';
            $effectiveBase = isset($overrides['ANTHROPIC_BASE_URL'])
                ? (string) $overrides['ANTHROPIC_BASE_URL']
                : $globalBase;
            if ($globalBase !== '' && $effectiveBase === $globalBase) {
                foreach (['ANTHROPIC_AUTH_TOKEN', 'ANTHROPIC_API_KEY'] as $name) {
                    if (!empty($globalEnv[$name])) {
                        $overrides[$name] = (string) $globalEnv[$name];
                    }
                }
            }
        }

        if ($tempDir !== '') {
            $overrides = array_merge($overrides, [
                'TMPDIR' => rtrim((string) $tempDir, '/'),
                'TMP' => rtrim((string) $tempDir, '/'),
                'TEMP' => rtrim((string) $tempDir, '/'),
                'TMPPREFIX' => rtrim((string) $tempDir, '/') . '/zsh',
                'XDG_CACHE_HOME' => rtrim((string) $tempDir, '/') . '/cache',
                'DARWIN_USER_TEMP_DIR' => rtrim((string) $tempDir, '/') . '/',
                'DARWIN_USER_CACHE_DIR' => rtrim((string) $tempDir, '/') . '/cache/',
            ]);
        }

        if (!$overrides) {
            return null;
        }
        $env = getenv();
        return array_merge(is_array($env) ? $env : [], $overrides);
    }

    /**
     * 隔离用的 CLAUDE_CONFIG_DIR:一个干净、独立于操作者个人 ~/.claude 的配置目录,
     * 不含任何插件/技能/钩子/CLAUDE.md,保证编码 agent 行为可复现、不被个人环境带偏。
     * 配置为 'off' 时返回 '' 表示不隔离。
     */
    private function isolatedConfigDir()
    {
        $dir = trim((string) config('ai_dev.agent.config_dir', ''));
        if (strtolower($dir) === 'off') {
            return '';
        }
        if ($dir === '') {
            $base = function_exists('runtime_path') ? runtime_path() : rtrim(sys_get_temp_dir(), '/') . '/';
            $dir = rtrim($base, '/') . '/ai-dev/claude-home';
        }
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }

    /**
     * 读操作者全局 ~/.claude/settings.json 里的 env 段(通常是 API 端点/令牌/模型映射)。
     * 隔离后子进程读不到它,无 profile 端点的运行需要靠它兜底认证。只取 env,不碰插件/技能配置。
     */
    private function globalClaudeEnv()
    {
        $home = getenv('HOME');
        if (!$home) {
            return [];
        }
        $file = rtrim($home, '/') . '/.claude/settings.json';
        if (!is_file($file)) {
            return [];
        }
        $data = json_decode((string) @file_get_contents($file), true);
        if (!is_array($data) || empty($data['env']) || !is_array($data['env'])) {
            return [];
        }
        return $data['env'];
    }

    /**
     * 追加到每次调用的中文对话指令(可在 config/ai_dev.php 关掉或改文案)。
     * 去掉在 shell 双引号里有特殊含义的字符,避免 codex 前置路径被注入。
     */
    private function languagePrompt()
    {
        $prompt = trim((string) config('ai_dev.agent.language_prompt', ''));
        if ($prompt === '') {
            return '';
        }
        return str_replace(['\\', '"', '`', '$'], '', $prompt);
    }

    private function profiles($enabledOnly = false)
    {
        $profiles = (new ConfigService())->modelProfiles();
        if (!$enabledOnly) {
            return $profiles;
        }
        return array_values(array_filter($profiles, function ($profile) {
            return !empty($profile['enabled']);
        }));
    }

    private function expandEnvValue($value)
    {
        if (preg_match('/^\$\{?([A-Za-z_][A-Za-z0-9_]*)\}?$/', $value, $matches)) {
            $env = getenv($matches[1]);
            return $env === false ? '' : (string) $env;
        }
        return $value;
    }
}
