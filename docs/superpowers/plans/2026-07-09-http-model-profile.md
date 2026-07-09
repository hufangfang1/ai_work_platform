# HTTP 直调模型档案 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 给模型档案新增 `agent = http` 类型，通过 OpenAI 兼容 `/chat/completions` 直接 HTTP 调用大模型（不走 CLI），仅用于生成类步骤。

**Architecture:** 生成类两个执行器（`GenerationExecutorService`、`CommitMessageExecutorService`）在 `runClaude()` 入口按 `agent` 类型分叉：`http` 档案走新增的 `HttpChatService`（curl 非流式），其余走原有 `proc_open` CLI 逻辑。编码类（`coding`/`fix`）在派发处兜底拒绝 http 档案。前端设置页新增 http 选项，`ModelPicker` 在编码步骤过滤掉 http 档案。

**Tech Stack:** PHP 7+/ThinkPHP（后端，原生 curl，无外部 HTTP 库）、Vue 3 + Element Plus（前端）。

## Global Constraints

- 本项目**没有自动化测试框架**（无 phpunit / vitest）。后端纯逻辑用**独立可运行的 `php` 断言脚本**验证（放 scratchpad，验证后删除）；集成与前端用**手动 / preview 验证**。不要为本功能引入测试框架。
- 只做 OpenAI 兼容 `/chat/completions`，非流式，`stream: false`。不做流式、不做 Anthropic 协议、不做编码类 HTTP。
- API key 通过环境变量名（`api_key_ref`）解析：`getenv()` 优先，回退 ThinkPHP `env()`。
- 中文语言指令复用 `config('ai_dev.agent.language_prompt')`，作为 `system` 消息发出。
- "能否编码" = "走不走 CLI"，由 `agent` 类型隐式决定，不新增 category 字段。
- 提交前若在默认分支 `main`，先开 feature 分支再提交（遵循仓库规则）。

## File Structure

后端：
- `backend/thinkphp/app/service/AiDev/ModelProfileService.php` — 修改：新增 `isHttp` / `codingCapable` / `resolveApiKey`，`processEnv` 复用 `resolveApiKey`。
- `backend/thinkphp/app/service/AiDev/HttpChatService.php` — 新建：OpenAI 兼容 `/chat/completions` 非流式调用。
- `backend/thinkphp/app/service/AiDev/GenerationExecutorService.php` — 修改：`runClaude()` 加 http 分支。
- `backend/thinkphp/app/service/AiDev/CommitMessageExecutorService.php` — 修改：`runClaude()` 加 http 分支。
- `backend/thinkphp/app/service/AiDev/RunService.php` — 修改：`enqueueCode` / `enqueueFix` 拒绝 http 档案。

前端：
- `src/views/Settings.vue` — 修改：`agentOptions` 加 `http`，`saveAll` 加 http 档案校验。
- `src/components/ModelPicker.vue` — 修改：编码步骤过滤 http 档案。

注：`ConfigService::normalizeModelProfiles()` **无需改动**——它对 `agent` 不做枚举校验、`command` 有默认值不强制，`http` 档案可原样存取。

---

### Task 1: ModelProfileService 增加 http 判定与 key 解析

**Files:**
- Modify: `backend/thinkphp/app/service/AiDev/ModelProfileService.php`
- Test: scratchpad 独立脚本

**Interfaces:**
- Produces:
  - `isHttp(string $key): bool` — 档案 agent 是否为 `http`
  - `codingCapable(string $key): bool` — 是否可用于编码（非 http 即 true）
  - `resolveApiKey(string $ref): string` — 按环境变量名解析 key，取不到返回 `''`

- [ ] **Step 1: 写验证脚本**

创建 `SCRATCH/test_modelprofile_http.php`（`SCRATCH` = 会话 scratchpad 目录）：

```php
<?php
// 独立验证 resolveApiKey 的取值优先级(不依赖框架)。
// 模拟 getenv 优先: 设一个进程环境变量,确认能读到。
putenv('AI_DEV_TEST_KEY=secret-123');
require __DIR__ . '/stub_env.php'; // 提供全局 env() 兜底桩

// 手动内联一份 resolveApiKey 逻辑做对照(实现完成后改为 require 真类):
function resolveApiKeyRef($ref) {
    $ref = trim((string) $ref);
    if ($ref === '') return '';
    $secret = getenv($ref);
    if ($secret === false) $secret = env($ref, null);
    if ($secret === false || $secret === null) return '';
    return (string) $secret;
}
assert(resolveApiKeyRef('AI_DEV_TEST_KEY') === 'secret-123');
assert(resolveApiKeyRef('') === '');
assert(resolveApiKeyRef('NOT_SET_ANYWHERE') === '');
echo "OK\n";
```

创建 `SCRATCH/stub_env.php`：

```php
<?php
if (!function_exists('env')) {
    function env($k, $d = null) { return $d; }
}
```

- [ ] **Step 2: 跑验证脚本，确认逻辑成立**

Run: `php SCRATCH/test_modelprofile_http.php`
Expected: 输出 `OK`（验证 resolveApiKey 的取值规则本身正确）

- [ ] **Step 3: 在 ModelProfileService 实现三个方法**

在 `agentType()` 方法之后（约 `ModelProfileService.php:94` 后）新增：

```php
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
```

- [ ] **Step 4: 让 processEnv 复用 resolveApiKey（DRY）**

在 `processEnv()` 里，把原来内联的 key 解析（约 `ModelProfileService.php:217-232`）：

```php
            if (!empty($profile['api_key_ref'])) {
                $ref = (string) $profile['api_key_ref'];
                $secret = getenv($ref);
                if ($secret === false) {
                    $secret = env($ref, null);
                }
                if ($secret !== false && $secret !== null && $secret !== '') {
                    if ($isCodex) {
                        $overrides['OPENAI_API_KEY'] = (string) $secret;
                    } else {
                        $overrides['ANTHROPIC_AUTH_TOKEN'] = (string) $secret;
                    }
                }
            }
```

替换为：

```php
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
```

- [ ] **Step 5: 用真类验证 resolveApiKey**

改写 `SCRATCH/test_modelprofile_http.php` 末尾，直接实例化真类（ThinkPHP 需 bootstrap，若难以独立加载，则用 `php think` 的一个临时命令或在后续集成手测中覆盖）。最简可行验证：

Run: `cd backend/thinkphp && php -r "require 'vendor/autoload.php'; \$s=new app\service\AiDev\ModelProfileService(); putenv('AI_DEV_TEST_KEY=secret-123'); var_dump(\$s->resolveApiKey('AI_DEV_TEST_KEY'), \$s->resolveApiKey(''), \$s->isHttp('nonexistent'));"`
Expected: 依次输出 `string(9) "secret-123"`、`string(0) ""`、`bool(false)`
（若 autoload 路径不同，调整为项目实际入口；跑不通则记录，留待 Task 3 集成手测覆盖。）

- [ ] **Step 6: Commit**

```bash
git add backend/thinkphp/app/service/AiDev/ModelProfileService.php
git commit -m "feat(ai-dev): ModelProfileService 增加 http 判定与 resolveApiKey"
```

---

### Task 2: 新增 HttpChatService

**Files:**
- Create: `backend/thinkphp/app/service/AiDev/HttpChatService.php`
- Test: scratchpad 独立脚本

**Interfaces:**
- Consumes: `ModelProfileService::resolveApiKey()`（Task 1）
- Produces: `complete(array $profile, string $prompt, array $options = []): string` — 返回模型回复文本；失败抛 `\RuntimeException`。内部 `post()` 为 `protected`，便于子类替换做离线断言。

- [ ] **Step 1: 写 body 组装的验证脚本**

创建 `SCRATCH/test_http_body.php`：

```php
<?php
// 验证请求 body 组装:含 system(语言指令)+user,stream:false,model 正确。
function buildBody($model, $lang, $prompt) {
    $messages = [];
    if ($lang !== '') $messages[] = ['role' => 'system', 'content' => $lang];
    $messages[] = ['role' => 'user', 'content' => $prompt];
    return json_encode(['model' => $model, 'messages' => $messages, 'stream' => false], JSON_UNESCAPED_UNICODE);
}
$b = json_decode(buildBody('deepseek-chat', '请用中文', '你好'), true);
assert($b['model'] === 'deepseek-chat');
assert($b['stream'] === false);
assert($b['messages'][0]['role'] === 'system' && $b['messages'][0]['content'] === '请用中文');
assert($b['messages'][1]['role'] === 'user' && $b['messages'][1]['content'] === '你好');
// 无语言指令时不加 system
$b2 = json_decode(buildBody('m', '', 'hi'), true);
assert(count($b2['messages']) === 1 && $b2['messages'][0]['role'] === 'user');
echo "OK\n";
```

- [ ] **Step 2: 跑验证脚本**

Run: `php SCRATCH/test_http_body.php`
Expected: 输出 `OK`

- [ ] **Step 3: 创建 HttpChatService**

创建 `backend/thinkphp/app/service/AiDev/HttpChatService.php`：

```php
<?php

namespace app\service\AiDev;

/**
 * OpenAI 兼容 /chat/completions 的非流式 HTTP 直调。
 * 供生成类执行器在 agent=http 档案下替代 CLI 子进程调用。
 */
class HttpChatService
{
    public function complete(array $profile, $prompt, array $options = [])
    {
        $apiBase = isset($profile['api_base']) ? rtrim(trim((string) $profile['api_base']), '/') : '';
        if ($apiBase === '') {
            throw new \RuntimeException('HTTP 直调档案缺少 api_base');
        }
        $model = isset($profile['model']) ? trim((string) $profile['model']) : '';
        if ($model === '') {
            throw new \RuntimeException('HTTP 直调档案缺少 model');
        }
        $url = $apiBase . '/chat/completions';
        $apiKey = (new ModelProfileService())->resolveApiKey(
            isset($profile['api_key_ref']) ? (string) $profile['api_key_ref'] : ''
        );

        $messages = [];
        $lang = trim((string) config('ai_dev.agent.language_prompt', ''));
        if ($lang !== '') {
            $messages[] = ['role' => 'system', 'content' => $lang];
        }
        $messages[] = ['role' => 'user', 'content' => (string) $prompt];

        $body = json_encode([
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);

        // 档案自带 timeout_seconds 优先,否则用执行器传入的 timeout,再兜底 300。
        $timeout = isset($profile['timeout_seconds']) && (int) $profile['timeout_seconds'] > 0
            ? (int) $profile['timeout_seconds']
            : (isset($options['timeout']) ? (int) $options['timeout'] : 300);

        $headers = ['Content-Type: application/json'];
        if ($apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $raw = $this->post($url, $headers, $body, $timeout);
        return $this->parseContent($raw);
    }

    /** 发 POST 并返回响应体;传输失败或 HTTP>=400 抛异常。protected 便于测试替换。 */
    protected function post($url, array $headers, $body, $timeout)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('HTTP 直调请求失败: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($code >= 400) {
            throw new \RuntimeException('HTTP 直调返回状态 ' . $code . ': ' . mb_substr((string) $resp, 0, 300));
        }
        return (string) $resp;
    }

    private function parseContent($raw)
    {
        $data = json_decode((string) $raw, true);
        if (!is_array($data)) {
            throw new \RuntimeException('HTTP 直调响应非 JSON: ' . mb_substr((string) $raw, 0, 200));
        }
        $content = isset($data['choices'][0]['message']['content'])
            ? (string) $data['choices'][0]['message']['content']
            : '';
        if (trim($content) === '') {
            throw new \RuntimeException('HTTP 直调响应缺少 choices[0].message.content: ' . mb_substr((string) $raw, 0, 300));
        }
        return $content;
    }
}
```

- [ ] **Step 4: 用测试子类离线验证解析与错误分支**

创建 `SCRATCH/test_http_service.php`：

```php
<?php
require 'backend/thinkphp/vendor/autoload.php';
if (!function_exists('config')) { function config($k, $d = null) { return '请用中文'; } }

use app\service\AiDev\HttpChatService;

// 用匿名子类替换 post(),不真发网络。
class FakeHttp extends HttpChatService {
    public $resp;
    protected function post($url, array $headers, $body, $timeout) { return $this->resp; }
}

$svc = new FakeHttp();
$profile = ['api_base' => 'https://x/v1', 'model' => 'm', 'api_key_ref' => ''];

// 正常解析
$svc->resp = json_encode(['choices' => [['message' => ['content' => '结果文本']]]]);
assert($svc->complete($profile, 'hi') === '结果文本');

// 空 content 抛异常
$svc->resp = json_encode(['choices' => [['message' => ['content' => '']]]]);
try { $svc->complete($profile, 'hi'); assert(false); } catch (\RuntimeException $e) { assert(strpos($e->getMessage(), 'content') !== false); }

// 缺 api_base 抛异常
try { $svc->complete(['model' => 'm'], 'hi'); assert(false); } catch (\RuntimeException $e) { assert(strpos($e->getMessage(), 'api_base') !== false); }

echo "OK\n";
```

- [ ] **Step 5: 跑测试子类脚本**

Run: `php SCRATCH/test_http_service.php`
Expected: 输出 `OK`（若 autoload 路径需调整则改为项目实际路径）

- [ ] **Step 6: Commit**

```bash
git add backend/thinkphp/app/service/AiDev/HttpChatService.php
git commit -m "feat(ai-dev): 新增 HttpChatService(OpenAI 兼容 /chat/completions 直调)"
```

---

### Task 3: 两个生成执行器加 http 分支

**Files:**
- Modify: `backend/thinkphp/app/service/AiDev/GenerationExecutorService.php`
- Modify: `backend/thinkphp/app/service/AiDev/CommitMessageExecutorService.php`

**Interfaces:**
- Consumes: `ModelProfileService::isHttp()`、`ModelProfileService::profile()`、`HttpChatService::complete()`、`RunService::markRunning()`、`RunService::appendLog()`

- [ ] **Step 1: GenerationExecutorService 加分支**

在 `GenerationExecutorService::runClaude()` 中，`$this->modelKey = $modelKey;`（约 `GenerationExecutorService.php:43`）之后、`$promptFile = ...`（约 `:45`）之前插入：

```php
        // HTTP 直调档案不起子进程,直接发 /chat/completions。
        if ($modelProfile->isHttp($modelKey)) {
            $runService->markRunning($runId, 0);
            $runService->appendLog($runId, 'stdout', 'HTTP 直调: ' . $modelKey);
            $text = (new HttpChatService())->complete(
                $modelProfile->profile($modelKey),
                $prompt,
                ['timeout' => $timeout]
            );
            $runService->appendLog($runId, 'stdout', $text);
            return trim($text);
        }
```

- [ ] **Step 2: CommitMessageExecutorService 加分支**

在 `CommitMessageExecutorService::runClaude()` 中，`$this->modelKey = $modelKey;`（约 `CommitMessageExecutorService.php:45`）之后、`$promptFile = ...`（约 `:47`）之前插入**同样的代码块**（与 Step 1 完全一致，逐字复制）：

```php
        // HTTP 直调档案不起子进程,直接发 /chat/completions。
        if ($modelProfile->isHttp($modelKey)) {
            $runService->markRunning($runId, 0);
            $runService->appendLog($runId, 'stdout', 'HTTP 直调: ' . $modelKey);
            $text = (new HttpChatService())->complete(
                $modelProfile->profile($modelKey),
                $prompt,
                ['timeout' => $timeout]
            );
            $runService->appendLog($runId, 'stdout', $text);
            return trim($text);
        }
```

- [ ] **Step 3: 语法自检**

Run: `php -l backend/thinkphp/app/service/AiDev/GenerationExecutorService.php && php -l backend/thinkphp/app/service/AiDev/CommitMessageExecutorService.php`
Expected: 两个文件都输出 `No syntax errors detected`

- [ ] **Step 4: 端到端手测（依赖真实环境）**

前置：`.env` 里配好 `DEEPSEEK_API_KEY`。在设置页新增一个 http 档案（Task 5 完成后 UI 可填；此刻可直接在 DB / 配置里造一条：`agent=http`、`api_base=https://api.deepseek.com/v1`、`api_key_ref=DEEPSEEK_API_KEY`、`model=deepseek-chat`、`enabled=1`）。
触发一次 commit message 生成（选该 http 档案），确认：run 状态 `succeeded`、`output` 是正常 JSON、日志里有 `HTTP 直调: <key>`。
（若此步暂不具备条件，记录为待验证，Task 5 完成后统一手测。）

- [ ] **Step 5: Commit**

```bash
git add backend/thinkphp/app/service/AiDev/GenerationExecutorService.php backend/thinkphp/app/service/AiDev/CommitMessageExecutorService.php
git commit -m "feat(ai-dev): 生成类执行器支持 http 档案直调分支"
```

---

### Task 4: 编码类拒绝 http 档案

**Files:**
- Modify: `backend/thinkphp/app/service/AiDev/RunService.php`

**Interfaces:**
- Consumes: `ModelProfileService::isHttp()`

- [ ] **Step 1: enqueueCode 加拒绝**

在 `RunService::enqueueCode()` 中，`$modelKey = (new ModelProfileService())->resolveKey('coding', $model);`（约 `RunService.php:22`）之后插入：

```php
        if ((new ModelProfileService())->isHttp($modelKey)) {
            throw new \RuntimeException('编码步骤不支持 HTTP 直调档案,请选择 CLI 档案(claude/codex/cursor)');
        }
```

- [ ] **Step 2: enqueueFix 加拒绝**

在 `RunService::enqueueFix()` 中，`$modelKey = (new ModelProfileService())->resolveKey('fix', $model);`（约 `RunService.php:36`）之后插入**同样的代码块**：

```php
        if ((new ModelProfileService())->isHttp($modelKey)) {
            throw new \RuntimeException('编码步骤不支持 HTTP 直调档案,请选择 CLI 档案(claude/codex/cursor)');
        }
```

- [ ] **Step 3: 语法自检**

Run: `php -l backend/thinkphp/app/service/AiDev/RunService.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add backend/thinkphp/app/service/AiDev/RunService.php
git commit -m "feat(ai-dev): 编码步骤兜底拒绝 http 直调档案"
```

---

### Task 5: 前端设置页新增 http 选项与校验

**Files:**
- Modify: `src/views/Settings.vue`

**Interfaces:**
- Consumes: `modelProfiles`（响应式档案数组）、`ElMessage`（已 import）

- [ ] **Step 1: agentOptions 加 http**

把 `src/views/Settings.vue:277`：

```js
const agentOptions = ['claude', 'codex', 'cursor']
```

改为：

```js
const agentOptions = ['claude', 'codex', 'cursor', 'http']
```

- [ ] **Step 2: saveAll 加 http 档案校验**

把 `saveAll()`（约 `src/views/Settings.vue:396`）开头改为先校验 http 档案必填项：

```js
async function saveAll() {
  const invalid = modelProfiles.value.find(
    (p) => (p.agent || '').trim() === 'http' && (!(p.api_base || '').trim() || !(p.model || '').trim())
  )
  if (invalid) {
    ElMessage.error(`HTTP 直调档案「${invalid.key || invalid.label || ''}」必须填写 API 地址和模型参数`)
    return
  }
  await Promise.all([
    api.config.saveModelProfiles(modelProfiles.value.map(toPayloadModelProfile)),
    api.config.saveSecurityRules(rules.value),
    api.config.saveWorkspace(roots.value),
  ])
  window.dispatchEvent(new CustomEvent('ai-dev-model-options-updated'))
  ElMessage.success('设置已保存')
  await load()
}
```

- [ ] **Step 3: 手动验证（preview）**

启动前端 dev server，打开设置页：
1. 新增一行档案，代理选 `http`，只填 key/label，不填 API 地址 → 点保存 → 应弹错误提示，且不请求保存。
2. 补上 API 地址（`https://api.deepseek.com/v1`）和模型参数（`deepseek-chat`）→ 保存成功。

- [ ] **Step 4: Commit**

```bash
git add src/views/Settings.vue
git commit -m "feat(ai-dev): 设置页支持 http 直调档案(选项+必填校验)"
```

---

### Task 6: ModelPicker 编码步骤过滤 http 档案

**Files:**
- Modify: `src/components/ModelPicker.vue`

**Interfaces:**
- Consumes: `models`（含每项 `agent`）、`props.step`

- [ ] **Step 1: groupedModels 过滤 http**

把 `src/components/ModelPicker.vue` 的 `groupedModels`（约 `:94-107`）改为在编码步骤剔除 http 档案：

```js
// coding/fix 步骤需要 CLI 的 agent loop,http 直调档案不可用,过滤掉。
const CODING_STEPS = ['coding', 'fix']

const groupedModels = computed(() => {
  const groups = []
  const index = new Map()
  const isCodingStep = CODING_STEPS.includes(props.step)
  for (const item of models.value) {
    if (isCodingStep && (item.agent || '') === 'http') continue
    const agent = item.agent || 'default'
    if (!index.has(agent)) {
      const group = { agent, models: [] }
      groups.push(group)
      index.set(agent, group)
    }
    index.get(agent).models.push(item)
  }
  return groups
})
```

- [ ] **Step 2: 手动验证（preview）**

前置：已存在一个 http 档案（Task 5）。
1. 在编码相关入口（`step="coding"` 或 `"fix"` 的 ModelPicker）打开下拉 → **看不到** http 档案。
2. 在生成相关入口（如 commit message，`step="commit_message"`）打开下拉 → **能看到** http 档案。

- [ ] **Step 3: Commit**

```bash
git add src/components/ModelPicker.vue
git commit -m "feat(ai-dev): 编码步骤的模型选择器过滤 http 直调档案"
```

---

## Self-Review

**Spec coverage：**
- 数据模型（方案 A / `agent=http` / 复用字段）→ Task 1（判定方法）+ Task 5（UI 选项）。ConfigService 无需改已在 File Structure 说明。✓
- 后端 4.1 ModelProfileService（isHttp/codingCapable/resolveApiKey）→ Task 1。✓
- 后端 4.2 HttpChatService → Task 2。✓
- 后端 4.3 两执行器 http 分支 → Task 3。✓
- 后端 4.4 编码类硬保护 → Task 4。✓
- 前端 5.1 Settings http 选项 + 校验 → Task 5。✓
- 前端 5.2 ModelPicker 编码过滤 → Task 6。✓
- 错误处理（curl/超时/HTTP≥400/空 content/非法 JSON）→ Task 2 `post()`/`parseContent()`。✓
- 已知局限（http 无 pid、stop 无效）→ 由 `markRunning($runId, 0)` 落实（Task 3），行为符合 spec。✓
- 语言指令作为 system 消息 → Task 2。✓

**Placeholder scan：** 无 TBD/TODO；所有代码步骤含完整代码。测试步骤在无框架下用真实可运行的独立脚本 + 明确手测项，非占位。✓

**Type consistency：** `isHttp` / `codingCapable` / `resolveApiKey` / `complete(profile, prompt, options)` 在 Task 1/2 定义，Task 3/4 按同名同签名调用；`complete` 第一参数统一传 `profile()` 数组。✓
