# 模型档案新增「HTTP 直调」调用方式（生成类专用）

日期：2026-07-09
状态：待实现

## 1. 背景与目标

当前平台所有 LLM 调用都通过 PHP `proc_open` 启动 `claude` / `codex` / `cursor` CLI 子进程完成（见 `ModelProfileService::buildCommand()`）。

本次新增**一种不走 CLI、直接发 HTTP 请求**调用大模型的方式，作为模型档案（model profile）的一个新 `agent` 类型 `http`，按 **OpenAI 兼容 `/chat/completions`** 协议调用。

该方式**仅用于生成类步骤**（单轮 `prompt → 文本/JSON`）。编码类步骤仍强制走 CLI，因为编码依赖 CLI 内置的 agent loop（多轮工具调用、读写文件、跑命令），HTTP 直调无法替代。

## 2. 范围

生成类步骤（可用 http 档案）。所有生成类都经统一入口 `RunService::enqueueGeneration($runType, ...)` 派发，按 run_type 分流到两个执行器：
- `commit_message`（提交信息）→ `CommitMessageExecutorService`
- 其余全部 → `GenerationExecutorService`：
  - `requirement_breakdown`（需求拆解）
  - `task_plan`（任务计划）
  - `task_spec`（Spec 生成）
  - `project_description`（项目描述）
  - `ai_review`（AI Review）
  - `branch_name`（分支名）

因此覆盖全部生成类只需改这两个执行器。

编码类步骤（**不可**用 http 档案，仍走 CLI）。经 `AiDevCodeJob` → `AgentExecutorService`，不走 `enqueueGeneration`：
- `coding`
- `fix`

### 非目标（YAGNI）
- 流式 SSE 与中途中断
- Anthropic `/v1/messages` 或其他协议（本期只做 OpenAI 兼容 `/chat/completions`）
- 编码类的 HTTP agent loop（自己重写 agent runtime）
- 自定义请求模板 / 多协议切换字段

## 3. 数据模型（方案 A：隐式推导，不新增字段）

复用现有 profile 字段：`key` / `label` / `agent` / `model` / `api_base` / `api_key_ref` / `enabled` / `env` / `command`。

变更：
- `agent` 新增合法值 `http`。
- 当 `agent == http`：
  - `command` 字段无意义，忽略（不参与拼命令）。
  - 必填：`api_base`、`model`。
  - `api_key_ref` 指向一个环境变量名（如 `DEEPSEEK_API_KEY`），解析逻辑复用现有 `getenv()` 优先、回退 ThinkPHP `env()` 的方式。
- **能力判定（核心规则）**：`isCliAgent = agent ∈ {claude, codex, cursor}`。`http` 不是 CLI，因此不能用于编码步骤。"能不能编码" = "走不走 CLI"，由 `agent` 类型隐式决定，不引入独立的 category 字段。

## 4. 后端改动

### 4.1 `ModelProfileService`
- `agentType()` 已返回小写 agent，天然支持 `http`，无需改。
- 新增 `isHttp($key)`：`agentType($key) === 'http'`。
- 新增 `codingCapable($key)`：`!isHttp($key)`（供编码类保护校验用）。
- 新增公共方法 `resolveApiKey($ref)`：把 `processEnv()` 里内联的 key 解析逻辑（`getenv` 优先、回退 `env()`）抽出来复用。
- `buildCommand()` / `processEnv()` 不需要处理 http（http 不会走到这两个方法）。
- 若 `ConfigService::normalizeModelProfiles()` 对 command 有强制要求，放开：http 档案允许 command 为空。

### 4.2 新增 `app/service/AiDev/HttpChatService.php`
职责：把一段 prompt 通过 OpenAI 兼容 `/chat/completions` 发出去，返回最终文本。

```
complete(array $profile, string $prompt, array $options = []): string
```
- URL：`rtrim($profile['api_base'], '/') . '/chat/completions'`
- Method：POST（curl）
- Headers：
  - `Content-Type: application/json`
  - `Authorization: Bearer {resolveApiKey(profile.api_key_ref)}`
- Body（`stream: false`）：
  ```json
  {
    "model": "<profile.model>",
    "messages": [
      {"role": "system", "content": "<中文语言指令，若启用>"},
      {"role": "user", "content": "<prompt>"}
    ],
    "stream": false
  }
  ```
  - 中文语言指令复用 `config('ai_dev.agent.language_prompt')`，作为 system 消息，与 CLI 路径行为对齐。为空则不加 system 消息。
  - 不设 temperature 等参数，用服务端默认。
- 超时：`CURLOPT_TIMEOUT = options['timeout']`（复用执行器的 timeout，默认 300）。
- 解析：取 `choices[0].message.content`。
- 错误 → 抛 `\RuntimeException`：
  - curl 传输失败（`curl_errno`）
  - HTTP 状态码 ≥ 400（消息带上响应体前若干字符）
  - 响应体非合法 JSON
  - `choices[0].message.content` 缺失或为空

### 4.3 `GenerationExecutorService::runClaude()` 与 `CommitMessageExecutorService::runClaude()`
两者结构一致，各加同一个前置分支：
- 若 `agentType($modelKey) === 'http'`：
  1. `$runService->markRunning($runId, 0)`（无子进程 pid）
  2. `$text = (new HttpChatService())->complete($profile, $prompt, ['timeout' => $timeout])`
  3. 把请求摘要 + 响应文本写入 run 日志（复用现有 append/日志机制）
  4. `return $text`（跳过整段 `proc_open` + 轮询）
- 否则走原有 CLI 逻辑，保持不变。

外层 `execute()` 的 `extractJsonObject()` / `applyResult()` / `finish()` 不变——HTTP 与 CLI 返回的都是文本，后续解析一致。

### 4.4 编码类硬保护
在派发编码 run（`coding` / `fix`）解析出模型档案后，若该档案 `isHttp()` 为真，抛错：`编码步骤不支持 HTTP 直调档案，请选择 CLI 档案`。位置：`AgentExecutorService` 入口或 `RunService` 派发编码 run 处（取先拿到 modelKey 的一处）。这是前端过滤之外的服务端兜底，防止绕过 UI 直接塞 http 档案给编码。

## 5. 前端改动

### 5.1 `src/views/Settings.vue`（档案编辑表单）
- `agent` 下拉新增选项：`http（OpenAI 兼容直调）`。
- 选中 `http` 时：
  - 显示并要求 `api_base`、`model`、`api_key_ref`。
  - 隐藏 / 禁用 `command` 字段（或标注"HTTP 直调无需"）。
- 前端校验：`agent == http` 时 `api_base` 和 `model` 必填。

### 5.2 `src/components/ModelPicker.vue`
- 新增按 `step` 过滤：当 `props.step ∈ {coding, fix}` 时，从 `models` 中剔除 `agent === 'http'` 的档案。
- `groupedModels` 基于过滤后的列表构建。
- 每个档案 item 已带 `agent`（`config.modelOptions` 返回），前端直接过滤，无需后端改接口。

## 6. 错误处理与已知局限
- HTTP 路径任何失败（curl/超时/HTTP≥400/空 content/非法 JSON）→ `RuntimeException` → `RunService::finish` 标 `failed` 并写 error 文本，与 CLI 路径失败表现一致。
- **HTTP 非流式无 pid**：`markRunning` 传 0，`RunService::stop` 只在 `pid > 0` 时 `kill`，因此对 http run 的"停止运行"无效，只能等 `CURLOPT_TIMEOUT` 到点。本期不做流式中断，作为已知局限。

## 7. 测试策略
- `HttpChatService`：验证 URL / headers / body 组装、成功解析 `choices[0].message.content`、各类错误分支抛异常。curl 部分设计为可替换（如把发送逻辑提取到受保护方法便于覆盖）以便单测。
- `ModelProfileService`：`agentType` / `isHttp` / `codingCapable` / `resolveApiKey`；http 档案 normalize 不因缺 command 报错。
- `ModelPicker`：`step=coding` 过滤掉 http 档案；生成类 step 保留。
- 端到端手测：在设置页配一个 DeepSeek 的 http 档案（`api_base=https://api.deepseek.com/v1`、`api_key_ref=DEEPSEEK_API_KEY`、`model=deepseek-chat`），跑一次 commit message 生成，确认落库与日志正常。

## 8. 涉及文件清单
后端：
- `backend/thinkphp/app/service/AiDev/ModelProfileService.php`（新增 isHttp/codingCapable/resolveApiKey）
- `backend/thinkphp/app/service/AiDev/HttpChatService.php`（新增）
- `backend/thinkphp/app/service/AiDev/GenerationExecutorService.php`（runClaude 加 http 分支）
- `backend/thinkphp/app/service/AiDev/CommitMessageExecutorService.php`（runClaude 加 http 分支）
- `backend/thinkphp/app/service/AiDev/ConfigService.php`（normalize 放开 http 的 command 必填，若有）
- `backend/thinkphp/app/service/AiDev/AgentExecutorService.php` 或 `RunService.php`（编码类拒绝 http 档案）

前端：
- `src/views/Settings.vue`（agent 增加 http 选项 + 表单联动）
- `src/components/ModelPicker.vue`（编码步骤过滤 http 档案）
