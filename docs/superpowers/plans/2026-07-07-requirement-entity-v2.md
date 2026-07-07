# AI 开发工单台 v2 实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 引入需求实体(需求 1:N 工单)与两级 AI 计划,项目配置改为本地扫描,前端接真实后端并重做为暗色开发者工具风。

**Architecture:** 后端 ThinkPHP 新增 Requirement/Breakdown 层,所有 AI 生成调用统一封装为 `claude -p` 子进程(ClaudeCliService);前端 Vue3 + Element Plus 换暗色主题,mockApi 替换为真实 fetch 客户端,vite 代理到本地 PHP 服务。

**Tech Stack:** ThinkPHP 6.0.16 (PHP >= 7.2 语法)、MySQL、think-queue、Vue 3、Element Plus 2.x(含 dark css-vars)、Vite 7、claude CLI headless。

**Spec:** `docs/superpowers/specs/2026-07-07-requirement-entity-v2-design.md`

## Global Constraints

- PHP 代码兼容 7.2:不用 match/enum/构造器提升;沿用现有 `isset($x)?$x:''` 风格与 `Db::name()` 查询构造器。
- API 响应格式固定 `{code:0, message, data}`(BaseController::ok/fail),前端视图直接使用后端 snake_case 字段,不做 camelCase 映射。
- 所有 AI 生成(拆解/计划)统一走 `ClaudeCliService`(`claude -p` 子进程),不接 Anthropic HTTP API;`claude` 命令名取 `config('ai_dev.agent.command')`。
- 不新增任何 npm 依赖;编辑器沿用现有 `CodeEditor.vue`(textarea 方案)。
- 暗色主题:`index.html` 的 `<html>` 加 `class="dark"`,main.js 引入 `element-plus/theme-chalk/dark/css-vars.css`,自定义 token 见 Task 9。
- 工单状态机删除 `doc_loaded`;其余状态沿用 v1(见 src/services/status.js)。
- 数据库无存量数据,schema 直接重建,不写迁移。
- 每个任务完成后 `git add -A && git commit`(Task 0 会初始化仓库);commit message 用 `feat:`/`refactor:`/`chore:` 前缀。

---

### Task 0: 环境基线(git、依赖、运行时探测)

**Files:**
- Create: `.gitignore`
- Create: `backend/thinkphp/.env`(从 .env.example 复制修改,不入库)

**Interfaces:**
- Produces: 可运行的 `php think run`、`npm run dev`、可用的 `git` 仓库。

- [ ] **Step 1: 初始化 git 仓库与 .gitignore**

```bash
cd /Volumes/SSD/MB_Mapped/Users/hufangfang/www/local/ai_work_platform
git init -b main
```

`.gitignore`:

```text
node_modules/
dist/
backend/thinkphp/vendor/
backend/thinkphp/runtime/
backend/thinkphp/.env
.DS_Store
```

- [ ] **Step 2: 探测运行时**

```bash
php -v; php -m | grep -iE 'pdo_mysql|curl'
mysql --version && mysqladmin -h127.0.0.1 -uroot ping   # 若失败,询问/探测 MAMP socket 或改用本机可用 MySQL
redis-cli ping                                          # 失败则 .env 中 queue 连接改 sync 驱动(spec §11 降级)
which claude && claude --version
node -v && npm -v
```

记录结果;Redis 不可用时在 `config/queue.php` 确认 `default` 支持 `sync`,`.env` 增加 `QUEUE_CONNECTION=sync`(检查该配置文件实际键名后再定)。

- [ ] **Step 3: 安装依赖**

```bash
cd backend/thinkphp && php tools/composer.phar install
cd ../.. && npm install
```

- [ ] **Step 4: 创建 .env**(按 Step 2 探测结果填 DB 账号)

- [ ] **Step 5: 首次提交**

```bash
git add -A && git commit -m "chore: v1 baseline before v2 rework"
```

---

### Task 1: 数据库 schema v2

**Files:**
- Modify: `backend/thinkphp/database/ai_dev_tables.sql`(整文件重写)

**Interfaces:**
- Produces: 表 `ai_dev_requirements` / `ai_dev_requirement_docs` / `ai_dev_breakdowns` / `ai_dev_settings`;`ai_dev_tasks` 新列 `requirement_id, doc_version_id, scope_summary`(删 `doc_url, doc_content_snapshot`);`ai_dev_projects.description`;`ai_dev_changes` 无 `commit_hash`。

- [ ] **Step 1: 重写 SQL 文件**。在 v1 基础上做如下增删(其余表原样保留):

```sql
CREATE TABLE ai_dev_requirements (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL DEFAULT '',
  doc_url VARCHAR(1000) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'draft',
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_requirement_docs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requirement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  version INT NOT NULL DEFAULT 1,
  content MEDIUMTEXT,
  source VARCHAR(32) NOT NULL DEFAULT 'manual',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_requirement_id (requirement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_breakdowns (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requirement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  version INT NOT NULL DEFAULT 1,
  content MEDIUMTEXT,
  projects_json TEXT,
  source VARCHAR(32) NOT NULL DEFAULT 'ai',
  model_name VARCHAR(255) NOT NULL DEFAULT '',
  confirmed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_requirement_id (requirement_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ai_dev_settings (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  `key` VARCHAR(64) NOT NULL DEFAULT '',
  `value` TEXT,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

`ai_dev_tasks` 变更列:

```sql
  requirement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- 新增,加 KEY idx_requirement_id
  doc_version_id BIGINT UNSIGNED NOT NULL DEFAULT 0,   -- 新增
  scope_summary TEXT,                                   -- 新增
  -- 删除 doc_url、doc_content_snapshot
```

`ai_dev_projects` 新增 `description VARCHAR(500) NOT NULL DEFAULT ''`;`ai_dev_changes` 删除 `commit_hash`。种子数据:保留 model_configs 与 security_rules 的 INSERT;删除 opcenterapi 示例项目 INSERT(项目改为扫描添加);新增 `INSERT INTO ai_dev_settings (\`key\`,\`value\`) VALUES ('workspace_roots','[]');`

- [ ] **Step 2: 建库导入并验证**

```bash
mysql -h127.0.0.1 -uroot -e "DROP DATABASE IF EXISTS ai_work_platform; CREATE DATABASE ai_work_platform CHARSET utf8mb4;"
mysql -h127.0.0.1 -uroot ai_work_platform < backend/thinkphp/database/ai_dev_tables.sql
mysql -h127.0.0.1 -uroot ai_work_platform -e "SHOW TABLES; DESC ai_dev_tasks;"
```

Expected: 12 张表;ai_dev_tasks 含 requirement_id 无 doc_url。

- [ ] **Step 3: Commit** `feat: v2 database schema with requirement entity`

---

### Task 2: 后端清理(空壳 controller、路由)

**Files:**
- Delete: `backend/thinkphp/app/controller/AiDev/Task.php`、`Project.php`、`Config.php`、`Run.php`
- Modify: `backend/thinkphp/route/ai_dev.php`(`AiDev.Task/index` → `AiDev.TaskController/index` 等全部替换)

- [ ] **Step 1: 删除四个空壳文件,替换路由目标**
- [ ] **Step 2: 验证路由可达**

```bash
cd backend/thinkphp && php think run -p 8000 &
curl -s http://127.0.0.1:8000/api/ai-dev/projects   # Expected: {"code":0,...,"data":[]}
```

- [ ] **Step 3: Commit** `refactor: remove shell controllers, route to real classes`

---

### Task 3: ClaudeCliService(统一 AI 调用封装)

**Files:**
- Create: `backend/thinkphp/app/service/AiDev/ClaudeCliService.php`
- Create: `backend/thinkphp/app/command/AiDevClaudeSmoke.php`(冒烟命令,注册进 `config/console.php` 若存在,否则 `app/command` 自动扫描按框架约定处理)

**Interfaces:**
- Produces: `ClaudeCliService::runText($prompt, array $options = []): string`、`runJson($prompt, array $options = []): array`。options 键:`cwd`(默认 runtime 目录)、`allowed_tools`(逗号串,默认 `''` 即纯生成)、`max_turns`(默认 8)、`timeout`(秒,默认 300)。失败抛 `\RuntimeException`。

- [ ] **Step 1: 实现服务**

```php
<?php
namespace app\service\AiDev;

class ClaudeCliService
{
    public function runText($prompt, array $options = [])
    {
        $cwd = isset($options['cwd']) && $options['cwd'] !== '' ? $options['cwd'] : runtime_path();
        $timeout = isset($options['timeout']) ? (int) $options['timeout'] : 300;
        $maxTurns = isset($options['max_turns']) ? (int) $options['max_turns'] : 8;
        $cmd = escapeshellcmd(config('ai_dev.agent.command', 'claude'))
            . ' -p --output-format text --max-turns ' . $maxTurns;
        if (!empty($options['allowed_tools'])) {
            $cmd .= ' --allowedTools ' . escapeshellarg($options['allowed_tools']);
        }
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $cwd);
        if (!is_resource($process)) {
            throw new \RuntimeException('claude 子进程启动失败');
        }
        fwrite($pipes[0], $prompt);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $error = '';
        $startedAt = time();
        while (true) {
            $output .= (string) stream_get_contents($pipes[1]);
            $error .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (time() - $startedAt > $timeout) {
                proc_terminate($process);
                throw new \RuntimeException('claude 调用超时(' . $timeout . 's)');
            }
            usleep(200000);
        }
        $output .= (string) stream_get_contents($pipes[1]);
        $error .= (string) stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || trim($output) === '') {
            throw new \RuntimeException('claude 调用失败: ' . ($error !== '' ? $error : '空输出'));
        }
        return trim($output);
    }

    public function runJson($prompt, array $options = [])
    {
        $raw = $this->runText($prompt, $options);
        $cleaned = preg_replace('/^```(json)?|```$/m', '', $raw);
        $start = strpos($cleaned, '{');
        $end = strrpos($cleaned, '}');
        if ($start === false || $end === false) {
            throw new \RuntimeException('claude 未返回 JSON: ' . mb_substr($raw, 0, 200));
        }
        $data = json_decode(substr($cleaned, $start, $end - $start + 1), true);
        if (!is_array($data)) {
            throw new \RuntimeException('claude JSON 解析失败: ' . mb_substr($raw, 0, 200));
        }
        return $data;
    }
}
```

- [ ] **Step 2: 冒烟命令**(`php think ai-dev:claude-smoke`,内部 `runJson('只返回 JSON:{"ok":true}')` 并打印),按 ThinkPHP 6 command 注册方式接入。
- [ ] **Step 3: 运行冒烟** Expected: 打印 `{"ok":true}`。若 claude CLI 不可用,停下向用户报告,不要造假降级。
- [ ] **Step 4: Commit** `feat: unified claude cli service`

---

### Task 4: 需求实体后端

**Files:**
- Create: `app/service/AiDev/RequirementService.php`、`app/controller/AiDev/RequirementController.php`
- Modify: `route/ai_dev.php`、`app/service/AiDev/DocService.php`

**Interfaces:**
- Produces(路由,均返回 ok 包装):
  - `POST /api/ai-dev/requirements` body `{title, doc_url}` → 需求行
  - `GET /api/ai-dev/requirements` → 数组,每项含 `task_total`, `task_committed`, `project_names`(逗号串), `latest_doc_version`
  - `GET /api/ai-dev/requirements/{id}` → `{...requirement, docs:[...], breakdowns:[...], tasks:[{..., project_name}]}`
  - `PUT /api/ai-dev/requirements/{id}`、`POST .../close`
  - `POST /api/ai-dev/requirements/{id}/load-doc` body `{doc_url?, content}` → 脱敏后存 `ai_dev_requirement_docs` 新版本,需求 status 置 `active`,返回 `{version, content}`
- `DocService::loadForTask` 删除(调用点同步清理);`mask()` 保留供复用。

- [ ] **Step 1: RequirementService** — `create/query/detail/update/close/loadDoc`。`query()` 聚合:

```php
$requirements = Db::name('ai_dev_requirements')->order('updated_at', 'desc')->select()->toArray();
$ids = array_column($requirements, 'id');
$tasks = $ids ? Db::name('ai_dev_tasks')->alias('t')
    ->leftJoin('ai_dev_projects p', 'p.id = t.project_id')
    ->whereIn('t.requirement_id', $ids)
    ->field('t.id,t.requirement_id,t.status,p.name as project_name')
    ->select()->toArray() : [];
// 按 requirement_id 汇总 task_total / task_committed(status in committed,retrospected)/ project_names
```

- [ ] **Step 2: Controller + 路由**(REST 同 tasks 组风格)。
- [ ] **Step 3: curl 验证**:创建需求 → load-doc(带 `password = 123` 内容验证脱敏)→ 列表/详情字段齐全。
- [ ] **Step 4: Commit** `feat: requirement entity api`

---

### Task 5: 需求拆解 + 工单生成改造

**Files:**
- Create: `app/service/AiDev/BreakdownService.php`
- Modify: `app/service/AiDev/TaskService.php`、`app/service/AiDev/RunService.php`、`app/service/AiDev/BranchService.php`、`app/controller/AiDev/RequirementController.php`、`app/controller/AiDev/TaskController.php`、`route/ai_dev.php`

**Interfaces:**
- `BreakdownService::generate($requirementId): array`(拆解行)— prompt 输入最新文档快照 + 全部启用项目 `name/description/repo_url`,要求输出 JSON:

```json
{"breakdown_markdown": "## 涉及项目...", "projects": [{"project_name": "opcenterapi", "scope_summary": "...", "interfaces": "..."}]}
```

  解析时按 `project_name` 匹配 `ai_dev_projects.name` 得 project_id(匹配不到的项加 `unmatched:true` 原样存返回,由前端标红人工处理);存 `ai_dev_breakdowns`(content=markdown,projects_json=结构化)。
- `BreakdownService::saveHuman($requirementId, $content, $projectsJson)` 存新版本 source=human。
- `BreakdownService::confirm($requirementId): array` — 事务:最新版本置 confirmed;按 projects_json 逐项 `TaskService::createFromBreakdown($requirement, $doc, $item)`;返回创建的工单数组。已有同需求同项目的未终止工单时跳过并在返回里标注 `skipped`。
- `TaskService::createFromBreakdown` 产出:title=`{需求标题}-{项目名}`、requirement_id、doc_version_id=最新快照 id、scope_summary、base_branch/branch_prefix 取项目默认、status=`created`;分支名此时不生成(进工单后由 BranchService 生成)。
- `TaskService::create`(手动建单)强制要求 `requirement_id`,去掉 doc 相关入参;`detail()` 附带 `requirement`(id/title)与 `doc_content`(按 doc_version_id 查快照);`query()` 联查 requirement 标题,支持 `requirement_id` 筛选。
- `RunService::buildPrompt` 的需求文档段改为按 `doc_version_id` 查 `ai_dev_requirement_docs.content`,并追加 `# 本项目职责\n{scope_summary}` 段。
- `BranchService::generateForTask` 语义来源改为 `title + scope_summary + 需求快照前 500 字`(仍用现有 makeSlug 规则,不接 AI——分支名不值得一次模型调用,YAGNI)。
- 路由:`POST requirements/{id}/generate-breakdown`、`PUT requirements/{id}/breakdown`、`POST requirements/{id}/confirm-breakdown`;删除 `POST tasks/{id}/load-doc`。

- [ ] **Step 1: BreakdownService 三方法**(generate 调 `ClaudeCliService::runJson`,timeout 300,无工具)。拆解 prompt(组装成单串):

```text
你是研发负责人。阅读需求文档,从下方候选项目中判断本需求涉及哪些项目,并给出拆解。
只返回 JSON,结构:{"breakdown_markdown":"...","projects":[{"project_name":"...","scope_summary":"...","interfaces":"..."}]}
breakdown_markdown 用中文 Markdown,含:## 需求理解 / ## 涉及项目与分工 / ## 跨项目接口约定 / ## 风险点。
projects 只列确实需要改动的项目;project_name 必须从候选列表原样取。

# 候选项目
{每行:- name: xxx | description: xxx | repo: xxx}

# 需求文档(已脱敏)
{content}
```

- [ ] **Step 2: TaskService/RunService/BranchService 按上述接口改造**,全局搜索 `doc_content_snapshot`、`doc_url` 清理残留引用(TaskController::loadDoc 方法删除)。
- [ ] **Step 3: curl 全链路验证**(先在设置里造 2 个真实本地项目数据,或手工 INSERT):创建需求 → load-doc(贴一段涉及前后端两个项目的需求)→ generate-breakdown(Expected: projects ≥ 2 且 project_id 匹配成功)→ confirm-breakdown(Expected: 生成对应工单,requirement_id/scope_summary/doc_version_id 落库)。
- [ ] **Step 4: Commit** `feat: requirement breakdown and task generation`

---

### Task 6: 项目级计划真实化

**Files:**
- Modify: `app/service/AiDev/PlanService.php`

**Interfaces:**
- `PlanService::generate($taskId)` 改为:取 task → requirement doc(按 doc_version_id)→ project;调 `ClaudeCliService::runText($prompt, ['cwd' => $project['local_path'], 'allowed_tools' => 'Read,Glob,Grep', 'max_turns' => 25, 'timeout' => 600])`;结果 `saveVersion($taskId, $content, 'ai', $modelName)`。`buildPlan()` 模板方法删除。model_name 取 `ConfigService::model()['model_name']`。

- [ ] **Step 1: 改造 generate + prompt**:

```text
你在该项目代码库根目录,可用 Read/Glob/Grep 阅读代码(禁止修改)。为以下需求产出本项目的开发计划,Markdown 输出,不要输出计划以外内容。
必须引用真实存在的文件路径;结构:## 需求理解 / ## 涉及模块与文件 / ## 实施步骤 / ## 配置变更 / ## SQL 变更 / ## 验证计划 / ## 风险点

# 需求文档(已脱敏)
{doc_content}

# 本项目职责(来自需求拆解)
{scope_summary}
```

- [ ] **Step 2: 验证**:对 Task 5 生成的工单调 `POST tasks/{id}/generate-plan`。Expected: 返回计划中出现该项目真实文件路径(人工抽查 1-2 个路径存在)。
- [ ] **Step 3: Commit** `feat: codebase-aware plan generation via claude readonly run`

---

### Task 7: 项目扫描与工作区配置

**Files:**
- Create: `app/service/AiDev/WorkspaceService.php`
- Modify: `app/service/AiDev/ProjectService.php`、`app/controller/AiDev/ProjectController.php`、`app/controller/AiDev/ConfigController.php`、`route/ai_dev.php`

**Interfaces:**
- `WorkspaceService::getRoots(): array` / `saveRoots(array $roots)`(读写 ai_dev_settings key=workspace_roots,值 JSON 数组;保存前校验 `is_dir`,`~` 展开为 `getenv('HOME')`)。
- `WorkspaceService::scan(): array` — 遍历每个 root 下深度 ≤ 2 的目录,凡含 `.git` 的返回:

```php
['path' => $dir, 'name' => basename($dir),
 'repo_url' => trim(shell_exec('git -C ' . escapeshellarg($dir) . ' remote get-url origin 2>/dev/null')),
 'current_branch' => trim(shell_exec('git -C ' . escapeshellarg($dir) . ' rev-parse --abbrev-ref HEAD 2>/dev/null')),
 'already_added' => (bool) Db::name('ai_dev_projects')->where('local_path', $dir)->where('status', 1)->count()]
```

- 路由:`POST projects/scan`、`GET/PUT workspace-config`。
- `ProjectService::create` 入参白名单化:`name,repo_url,local_path,description,default_base_branch,default_branch_prefix,test_command,lint_command,build_command,allow_auto_commit,allow_auto_push`(防止任意字段注入)。

- [ ] **Step 1: 实现 + 路由**
- [ ] **Step 2: curl 验证**:PUT workspace-config 设为本机真实目录 → POST scan Expected: 返回本机 git 仓库列表含 repo_url。
- [ ] **Step 3: Commit** `feat: workspace scan for project onboarding`

---

### Task 8: 前端真实 API 客户端 + 代理

**Files:**
- Create: `src/services/api.js`
- Modify: `vite.config.js`

**Interfaces:**
- Produces: `export const api` 命名空间方法,签名与后端路由一一对应(全部 async,返回 `data` 字段,业务失败 throw Error 且已经 `ElMessage.error`)。关键方法名(后续视图任务按此调用):
  `api.requirements.list() / create(body) / detail(id) / update(id, body) / close(id) / loadDoc(id, body) / generateBreakdown(id) / saveBreakdown(id, body) / confirmBreakdown(id) / tasks(id)`
  `api.tasks.list(params) / detail(id) / create(body) / update(id, body) / terminate(id) / generateBranch(id) / checkBranch(id, name) / generatePlan(id) / savePlan(id, content) / confirmPlan(id) / execute(id) / review(id) / fix(id, feedback) / generateCommitMessage(id) / commit(id, message) / push(id) / retrospect(id) / getRetrospective(id) / saveRetrospective(id, content) / runs(id)`
  `api.runs.detail(runId) / logs(runId, afterSeq) / cancel(runId)`
  `api.projects.list() / save(body) / update(id, body) / remove(id) / scan()`
  `api.config.workspace() / saveWorkspace(roots) / model() / saveModel(body) / securityRules() / saveSecurityRules(rules)`

- [ ] **Step 1: 实现 request 核心**

```js
import { ElMessage } from 'element-plus'

const BASE = '/api/ai-dev'

async function request(method, path, body, { silent = false } = {}) {
  let res
  try {
    res = await fetch(BASE + path, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch (error) {
    if (!silent) ElMessage.error('无法连接后端服务')
    throw error
  }
  const json = await res.json().catch(() => ({ code: res.status || -1, message: res.statusText }))
  if (json.code !== 0) {
    if (!silent) ElMessage.error(json.message || '请求失败')
    const err = new Error(json.message || '请求失败')
    err.code = json.code
    throw err
  }
  return json.data
}
```

其余按 Interfaces 逐个薄封装(GET 查询参数用 `URLSearchParams`)。

- [ ] **Step 2: vite proxy**

```js
export default defineConfig({
  plugins: [vue()],
  server: { proxy: { '/api': 'http://127.0.0.1:8000' } },
})
```

- [ ] **Step 3: 验证**:起两端服务,浏览器 console `fetch('/api/ai-dev/projects')` 返回 code 0。
- [ ] **Step 4: Commit** `feat: real api client and dev proxy`

---

### Task 9: 暗色主题与应用壳

**Files:**
- Modify: `index.html`(`<html lang="zh-CN" class="dark">`)、`src/main.js`(增 `import 'element-plus/theme-chalk/dark/css-vars.css'`)、`src/assets/styles.css`(整文件重写)、`src/App.vue`(导航:需求/工单/项目/设置)、`src/components/StatusTag.vue`(发光点样式)、`src/services/status.js`(删 doc_loaded)

**Interfaces:**
- Produces CSS tokens(全站视图任务依赖这些变量与既有类名 `.page/.panel/.page-heading/.toolbar/.metric*/.empty-state/.split/.muted` — 类名保持不变,视图无需大改即可换肤):

```css
:root, html.dark {
  --page-bg: #0d1117;
  --surface: #151b23;
  --surface-raised: #1c2330;
  --line: #2a3240;
  --line-soft: #222937;
  --text: #e6edf3;
  --text-muted: #8b98a9;
  --brand: #4d9fff;
  --accent: #8b5cf6;
  --success: #3fb950;
  --warning: #d29922;
  --danger: #f85149;
  --mono: "JetBrains Mono", "SF Mono", ui-monospace, Menlo, monospace;
  --panel-radius: 6px;
}
```

并覆盖 Element Plus 暗色变量对齐色板:

```css
html.dark {
  --el-bg-color: var(--surface);
  --el-bg-color-overlay: var(--surface-raised);
  --el-border-color: var(--line);
  --el-border-color-lighter: var(--line-soft);
  --el-text-color-primary: var(--text);
  --el-text-color-regular: var(--text);
  --el-text-color-secondary: var(--text-muted);
  --el-color-primary: var(--brand);
  --el-fill-color-blank: var(--surface);
}
```

- [ ] **Step 1: 重写 styles.css**:保留全部既有类名与响应式断点,替换配色;新增 `.status-dot`(8px 圆点 + `box-shadow: 0 0 8px` 同色)、`.status-dot--pulse`(运行态 keyframes 呼吸)、`.mono`(等宽);分支名/hash/命令处统一 `.mono`。侧边栏与主区同底色,导航激活项用 `--brand` 左边框 + 微高亮。
- [ ] **Step 2: App.vue 新导航**:需求(`/requirements`, icon Document)、工单(`/tasks`, Tickets)、项目(`/projects`, FolderOpened)、设置(`/settings`, Setting);品牌区副标题"AI Dev Workbench"。
- [ ] **Step 3: StatusTag.vue**:改为 `status-dot + label` 形式(替代 el-tag),颜色映射沿用 status.js 的 type→token(info=muted, primary=brand, success/warning/danger 对应 token);`coding/reviewing/committing/fixing` 加 `--pulse`。
- [ ] **Step 4: status.js 删除 doc_loaded 项**(canTerminate/stableStatuses 同步)。
- [ ] **Step 5: 浏览器截图验证**(preview 工具):全站暗色、无白底残留。
- [ ] **Step 6: Commit** `feat: dark developer-tool theme and new app shell`

---

### Task 10: 需求列表页 + 新建需求

**Files:**
- Create: `src/views/RequirementList.vue`
- Modify: `src/router.js`(`/` redirect `/requirements`;新增 requirements 两条路由;移除 `/tasks/new`)

**Interfaces:**
- Consumes: `api.requirements.list() / create({title, doc_url})`
- 行数据字段:`id,title,status,task_total,task_committed,project_names,updated_at`

- [ ] **Step 1: 页面**:page-heading(标题"需求" + "新建需求"按钮)→ el-table:标题(链接到 `/requirements/{id}`)、状态(draft=草稿/active=进行中/closed=已关闭,用 status-dot)、涉及项目(project_names 拆分为小徽章)、工单进度(`task_committed/task_total` + el-progress 细条,total 为 0 显示"未拆解")、更新时间;空态 empty-state。"新建需求"为 el-dialog:title 必填 + doc_url 选填,提交后直接 `router.push` 到详情页。
- [ ] **Step 2: 手工验证**:新建 → 列表出现 → 进详情路由(详情页下个任务做,404 占位可接受)。
- [ ] **Step 3: Commit** `feat: requirement list page`

---

### Task 11: 需求详情页(核心新页面)

**Files:**
- Create: `src/views/RequirementDetail.vue`
- Modify: `src/router.js`

**Interfaces:**
- Consumes: `api.requirements.detail/loadDoc/generateBreakdown/saveBreakdown/confirmBreakdown/close`,`api.projects.list()`(拆解项目匹配提示)
- detail 返回:`{id,title,doc_url,status,docs:[{id,version,content}],breakdowns:[{id,version,content,projects_json,source,confirmed_at}],tasks:[{id,title,status,final_branch_name,project_name,scope_summary}]}`

页面结构(自上而下):

1. **头部**:标题、状态点、doc_url 链接、快照版本徽章(`快照 v{latest}`)、关闭需求按钮。
2. **需求文档卡**:最新快照只读展示(CodeEditor readonly);"粘贴/更新需求文档"按钮弹 dialog(doc_url + content textarea)→ loadDoc → 刷新;历史版本下拉切换查看。
3. **拆解卡**:无拆解时大按钮"AI 拆解需求"(需已有快照,loading 态提示"AI 拆解中,约需 1-2 分钟");有拆解后左侧 Markdown 编辑器(CodeEditor,可改后"保存新版本"),右侧结构化项目清单(projects_json 渲染:项目名、scope_summary,unmatched 项标红提示"未匹配到已配置项目");底部"确认拆解并生成工单"(已确认后禁用,显示确认时间)。
4. **子工单看板**:tasks 网格卡片,每卡:项目名、工单标题、status-dot、分支名(mono)、scope_summary 摘要、点击进 `/tasks/{id}`;无工单显示"确认拆解后自动生成"。

- [ ] **Step 1: 实现页面与路由**
- [ ] **Step 2: 全链路手工验证**(真实 claude 调用):建需求 → 贴跨项目文档 → 拆解 → 确认 → 看板出现 N 张工单。
- [ ] **Step 3: Commit** `feat: requirement detail with breakdown workflow`

---

### Task 12: 工单列表页改造

**Files:**
- Modify: `src/views/TaskList.vue`

**Interfaces:**
- Consumes: `api.tasks.list({status, project_id, requirement_id, submitted})`、`api.projects.list()`、`api.requirements.list()`

- [ ] **Step 1: 改造**:换 `api`(async onMounted + 筛选变化时重查,去掉 mock 的 computed/seed 模式与"重置示例"按钮);删"新建工单"按钮(工单来自需求);新增列"所属需求"(requirement_title,链接到需求详情)与筛选项;"创建人"筛选删除(单机版无用户体系,YAGNI);字段全部切 snake_case(`final_branch_name` 等,分支列加 `.mono`)。
- [ ] **Step 2: 验证**:列表显示 Task 11 生成的工单,按需求筛选生效。
- [ ] **Step 3: Commit** `refactor: task list against real api with requirement column`

---

### Task 13: 工单详情页时间线重构(最大单页)

**Files:**
- Modify: `src/views/TaskDetail.vue`(整文件重写)

**Interfaces:**
- Consumes: `api.tasks.*`、`api.runs.*`(见 Task 8 清单);detail 返回含 `requirement:{id,title}`、`doc_content`、`scope_summary`、`plans/runs/changes/reviews/retrospective`。

页面结构:

- **左侧主列 = 6 步时间线**,每步一个 panel,由状态决定 展开/折叠/锁定:
  1. **分支与计划**:分支名生成(status=created 时"生成分支"按钮 → generateBranch;结果可编辑,checkBranch 校验,update 保存)→ "生成开发计划"(loading 提示读代码耗时)→ 计划编辑器 + 版本表(沿用 v1 交互)→ 确认计划。
  2. **AI 执行**:"开始 AI 修改"(plan_confirmed 才可用)→ 执行中显示实时日志流(2.5s 轮询 `api.runs.logs(runId, afterSeq)` 增量追加,event_type 着色:assistant=text/tool=brand/error=danger/git=muted)+ 取消按钮;历史 runs 折叠列表。
  3. **代码改动**:最新 change 的 changed_files 列表 + diff(CodeEditor readonly, language diff)+ 测试输出。
  4. **Review**:"发起 Review" → 展示 review_result JSON 渲染(结论/风险/阻塞项/建议)+ test_result;失败时反馈输入框 + "继续修改"(fix,回到步骤 2 轮询)。
  5. **提交**:generateCommitMessage → 可编辑 → commit;成功显示 hash(mono)。
  6. **复盘**:retrospect 生成 → 编辑保存。
- **右侧信息栏**(sticky):所属需求(链接)、项目、base→target 分支(mono)、本项目职责(scope_summary)、需求快照(折叠查看 doc_content)、状态、终止按钮。
- **状态→步骤映射**:`stepIndexByStatus` 常量(created/branch_generated/plan_generated/plan_confirmed→1或2,coding/fixing→2,code_changed→3,reviewing/review_failed/ready_to_commit→4,committing/committed→5,retrospected→6);当前步骤高亮展开,之前步骤可点开,之后锁定灰显。
- **轮询**:status 为 coding/fixing/reviewing/committing 时 3s 轮询 detail;卸载清理 timer。

- [ ] **Step 1: 重写页面**(上述结构,交互逻辑均沿用 v1 已有调用序列,只是布局与数据源变化)
- [ ] **Step 2: 全链路手工验证**:对一张真实工单从生成分支跑到 commit(项目用本仓库自身或测试仓库),日志实时滚动、状态时间线推进正确。
- [ ] **Step 3: Commit** `feat: timeline-based task detail page`

---

### Task 14: 项目页重做(扫描弹框)

**Files:**
- Modify: `src/views/ProjectConfig.vue`(整文件重写)

**Interfaces:**
- Consumes: `api.projects.list/save/update/remove/scan`、`api.config.workspace/saveWorkspace`

- [ ] **Step 1: 重写**:项目卡片网格(名称、description、repo_url(mono)、默认分支、测试/lint 命令、编辑/停用);"添加项目"弹框:第一步若 workspace_roots 为空,先让用户填根目录(inline 输入,保存);第二步调 scan 列出仓库(checkbox,already_added 置灰),勾选后逐项展开补 description/前缀/命令(repo_url/local_path/分支只读展示)→ 批量 save。编辑弹框中 repo_url/local_path 只读。
- [ ] **Step 2: 验证**:扫描本机工作区 → 添加 2 个真实项目 → 卡片展示正确。
- [ ] **Step 3: Commit** `feat: project onboarding via workspace scan`

---

### Task 15: 设置页(合并模型/脱敏/工作区)

**Files:**
- Create: `src/views/Settings.vue`(基于 ModelSecurity.vue 改造迁移)
- Delete: `src/views/ModelSecurity.vue`
- Modify: `src/router.js`(`/security` → `/settings`)

- [ ] **Step 1: 三段式单页**:模型配置表单(接 `api.config.model/saveModel`)、脱敏规则表格(增删行,接 securityRules)、工作区根目录(标签式增删,接 workspace)。沿用 ModelSecurity 现有表单交互,仅换数据源与暗色适配。
- [ ] **Step 2: 验证**:改一项保存刷新仍在。
- [ ] **Step 3: Commit** `feat: unified settings page`

---

### Task 16: 清理与端到端验收

**Files:**
- Delete: `src/services/mockApi.js`、`src/views/TaskCreate.vue`
- Modify: 全局 grep 校验

- [ ] **Step 1: 删除文件后全局检查**

```bash
grep -rn "mockApi\|TaskCreate\|doc_loaded\|doc_content_snapshot" src backend/thinkphp/app
```

Expected: 无结果(status.js、后端服务均已清理)。

- [ ] **Step 2: `npm run build`** Expected: 构建成功无引用错误。
- [ ] **Step 3: 按 spec §10 六条验收标准逐条走查**(真实 claude、真实项目仓库),记录结果;截图需求列表/需求详情/工单详情三页。
- [ ] **Step 4: Commit** `chore: remove mock layer, v2 complete`

---

## Self-Review 结论

- Spec 覆盖:§2 схема→Task 1;§3 流程→Task 5/6/13;§4→Task 7/14;§5→Task 4/5/7;§6→Task 10-15;§7→Task 9;§8→Task 8/12/13;§9 清理→Task 2/16;§10 验收→Task 16。无缺口。
- 已知取舍(记录备查):分支名生成保持规则算法不接 AI(YAGNI);Review/commit message/复盘仍为 v1 模板逻辑,真实化不在本轮验收内;后端无单测框架,验证以 curl/冒烟命令/端到端为主。
