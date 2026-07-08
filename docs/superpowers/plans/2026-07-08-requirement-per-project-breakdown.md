# 需求拆解:每项目子文档 + 共享契约 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 让跨多项目的需求文档,按每个项目的职责拆成"每项目一份落地需求子文档 + 一份共享接口契约",并让下游"生成开发文档(计划)/编码/Review"读子文档而非整篇原文;单项目需求保持现状零回归。

**Architecture:** 拆解(BreakdownService)第一步只产共享理解+契约+每项目一句话分工(带 role);人工确认后,若涉及项目>1,给每张工单排一个 `task_spec` 生成 run(新 SpecService),按 role 选前端/后端模板生成 `task.spec_markdown`;PlanService / RunService 的上下文改为"有 spec 时用 子文档+共享拆解,否则用原文",由 TaskService 统一提供。

**Tech Stack:** ThinkPHP 后端(sqlite,迁移走 `database/migrations/*.sql` 由 MigrationService 执行)、Vue3 + Element Plus 前端、Claude CLI 生成 run(RunService::enqueueGeneration → GenerationExecutorService::finishRun 按 run_type 分发)。

## Global Constraints

- 后端无 phpunit/测试目录;验证用 `php -l` 语法检查 + 迁移状态 + 接口/UI 冒烟,不引入测试框架。
- DB 为 sqlite,迁移 SQL 必须 sqlite 兼容(`ALTER TABLE ... ADD COLUMN`,单条语句)。
- 迁移文件命名沿用 `database/migrations/YYYYMMDDNNNN_<name>.sql`,当前最新为 `202607080001_schema_migrations.sql`。
- run 分发靠 `run_type`;新增类型 `task_spec` 必须在 `GenerationExecutorService::finishRun` 注册,否则报"未知 AI 生成任务类型"。
- 生成类 prompt 一律要求"只返回 JSON",与现有 BreakdownService/PlanService 风格一致。
- 所有工作在特性分支上进行(见 Task 0),提交信息结尾加 `Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>`。

---

### Task 0: 建特性分支

**Files:** 无(git 操作)

- [ ] **Step 1: 从 main 切分支**

```bash
cd /Volumes/SSD/MB_Mapped/Users/hufangfang/www/local/ai_work_platform
git checkout -b feat/per-project-requirement-spec
```

- [ ] **Step 2: 提交已写好的设计与计划文档**

```bash
git add docs/superpowers/specs/2026-07-08-requirement-per-project-breakdown-design.md \
        docs/superpowers/plans/2026-07-08-requirement-per-project-breakdown.md
git commit -m "docs: 需求拆解每项目子文档设计与实现计划

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 1: 迁移 —— ai_dev_tasks 增加 spec_markdown 列

**Files:**
- Create: `backend/thinkphp/database/migrations/202607080002_task_spec_markdown.sql`

**Interfaces:**
- Produces: `ai_dev_tasks.spec_markdown`(TEXT,可空)——Task 3/5/6 依赖此列。

- [ ] **Step 1: 写迁移文件**

`backend/thinkphp/database/migrations/202607080002_task_spec_markdown.sql`:

```sql
-- 每项目需求子文档(仅多项目拆解时生成;单项目留空)
ALTER TABLE `ai_dev_tasks` ADD COLUMN `spec_markdown` TEXT NULL;
```

- [ ] **Step 2: 执行迁移**

先确认迁移入口(项目里 MigrationService 由某控制器/命令触发)。查找触发方式:

```bash
grep -rn "MigrationService\|->migrate()\|/migrate" backend/thinkphp/app backend/thinkphp/route
```

按查到的入口执行迁移(HTTP 路由则 curl 该接口;若无则临时用 tinker/console 调 `(new \app\service\AiDev\MigrationService())->migrate()`)。

- [ ] **Step 3: 验证列已建**

```bash
# 找到 sqlite 库文件路径后:
sqlite3 <db_file> "PRAGMA table_info(ai_dev_tasks);" | grep spec_markdown
```
Expected: 输出包含 `spec_markdown|TEXT`

- [ ] **Step 4: 验证迁移状态无 pending**

调用迁移状态入口(同 Step 2 入口的 status),Expected: `pending_count` 为 0。

- [ ] **Step 5: Commit**

```bash
git add backend/thinkphp/database/migrations/202607080002_task_spec_markdown.sql
git commit -m "feat(ai-dev): ai_dev_tasks 增加 spec_markdown 列

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: 拆解第一步 prompt 强化(职责按简介 + 契约独立成节 + role)

**Files:**
- Modify: `backend/thinkphp/app/service/AiDev/BreakdownService.php`(`buildPrompt` ~179-203;`finishRun` ~51-80)

**Interfaces:**
- Produces: `projects_json` 每条新增 `role` 字段(取值 `前端`/`后端`/`其他`);`breakdown_markdown` 的"跨项目接口契约"为独立完整一节。Task 3 消费 `role` 与 `breakdown_markdown`。

- [ ] **Step 1: 改 buildPrompt —— JSON schema 加 role,强化职责与契约要求**

将 `buildPrompt` 两个分支(manual / 非 manual)的 JSON 结构与要求改为(以 manual 分支为例,非 manual 分支同样加 role 与职责说明):

```php
$task = "你是研发负责人。阅读需求文档,下方项目已由人工确认为本需求涉及的项目。\n"
    . "只返回 JSON,结构:{\"breakdown_markdown\":\"...\",\"projects\":[{\"project_name\":\"...\",\"role\":\"前端|后端|其他\",\"scope_summary\":\"...\",\"interfaces\":\"...\"}]}\n"
    . "breakdown_markdown 用中文 Markdown,包含:## 需求理解 / ## 涉及项目与分工 / ## 跨项目接口契约 / ## 风险点。\n"
    . "其中 ## 跨项目接口契约 必须独立且完整:写清接口路径、入参出参、字段口径、用户标识来源、谁读谁写;它是后续各项目子文档共享的唯一事实源。\n"
    . "判定分工时,必须依据每个项目下方的 description(项目简介)界定该项目在本需求中的职责边界,只把属于它的部分划给它,不要展开任何一个项目的代码级实现细节。\n"
    . "role 依据项目简介判定:以页面/H5/PC 展示为主填『前端』,以接口/代理/数据/日志为主填『后端』,无法归类填『其他』。\n"
    . "projects 必须为下方**每一个**项目各输出一条,不得新增或遗漏;project_name 必须从列表原样取;"
    . "scope_summary 说明该项目在本需求中要做什么;interfaces 说明与其他项目的接口约定,没有则留空。\n\n"
    . "# 本需求涉及的项目(人工确认)\n";
```

非 manual 分支同法:JSON schema 加 `"role"`,并追加"依据项目简介判定职责/role""跨项目接口契约独立成节"两句。

- [ ] **Step 2: 改 finishRun —— 归一化 role**

`finishRun` 里组装 `$normalized[]` 处(~70-77 行)加入 role:

```php
$normalized[] = [
    'project_id' => isset($nameMap[$projectName]) ? $nameMap[$projectName] : 0,
    'project_name' => $projectName,
    'role' => isset($item['role']) ? trim($item['role']) : '其他',
    'scope_summary' => isset($item['scope_summary']) ? $item['scope_summary'] : '',
    'interfaces' => isset($item['interfaces']) ? $item['interfaces'] : '',
    'unmatched' => !isset($nameMap[$projectName]),
];
```

- [ ] **Step 3: 语法检查**

```bash
php -l backend/thinkphp/app/service/AiDev/BreakdownService.php
```
Expected: `No syntax errors detected`

- [ ] **Step 4: 冒烟 —— 跑一次多项目拆解**

在 UI 对一个多项目需求点"生成拆解",等 run 完成后查 `ai_dev_breakdowns` 最新一条:

```bash
sqlite3 <db_file> "SELECT projects_json FROM ai_dev_breakdowns ORDER BY id DESC LIMIT 1;"
```
Expected: 每个 project 条目含 `"role"`;`content` 里有独立的"## 跨项目接口契约"节。

- [ ] **Step 5: Commit**

```bash
git add backend/thinkphp/app/service/AiDev/BreakdownService.php
git commit -m "feat(ai-dev): 拆解按项目简介定职责,契约独立成节并标注 role

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: 新增 SpecService —— 逐项目生成子文档

**Files:**
- Create: `backend/thinkphp/app/service/AiDev/SpecService.php`
- Modify: `backend/thinkphp/app/service/AiDev/GenerationExecutorService.php`(finishRun 分发 ~128-146)

**Interfaces:**
- Consumes: `RunService::enqueueGeneration($taskId,$runType,$payload,$targetKey,$model)`;确认后的 `ai_dev_breakdowns`(取 role/scope_summary/breakdown_markdown);`ai_dev_tasks.spec_markdown`(Task 1)。
- Produces: run_type `task_spec`;`SpecService::generate($taskId,$model=''): array(run)`;`SpecService::finishRun(array $run, array $data): array` 落 `task.spec_markdown`。Task 4 调 `generate`,Task 5/6 读 `spec_markdown`。

- [ ] **Step 1: 写 SpecService**

`backend/thinkphp/app/service/AiDev/SpecService.php`:

```php
<?php

namespace app\service\AiDev;

use think\facade\Db;

class SpecService
{
    public function generate($taskId, $model = '')
    {
        $task = Db::name('ai_dev_tasks')->where('id', $taskId)->find();
        if (!$task) {
            throw new \RuntimeException('工单不存在');
        }
        $doc = Db::name('ai_dev_requirement_docs')->where('id', $task['doc_version_id'])->find();
        if (!$doc || trim((string) $doc['content']) === '') {
            throw new \RuntimeException('需求文档快照缺失');
        }
        $breakdown = Db::name('ai_dev_breakdowns')
            ->where('requirement_id', (int) $task['requirement_id'])
            ->whereNotNull('confirmed_at')
            ->order('version', 'desc')->find();
        if (!$breakdown) {
            throw new \RuntimeException('未找到已确认的需求拆解');
        }
        $items = json_decode((string) $breakdown['projects_json'], true) ?: [];
        $entry = ['role' => '其他', 'scope_summary' => ''];
        foreach ($items as $it) {
            if ((int) ($it['project_id'] ?? 0) === (int) $task['project_id']) {
                $entry = ['role' => $it['role'] ?? '其他', 'scope_summary' => $it['scope_summary'] ?? ''];
                break;
            }
        }
        $this->assertNoRunning($taskId);
        return (new RunService())->enqueueGeneration((int) $taskId, 'task_spec', [
            'operation' => 'task_spec',
            'task_id' => (int) $taskId,
            'prompt' => $this->buildPrompt($doc['content'], $breakdown['content'], $entry['role'], $entry['scope_summary']),
            'options' => ['timeout' => 300, 'max_turns' => 3],
        ], 'task:' . (int) $taskId, $model);
    }

    public function finishRun(array $run, array $data)
    {
        $taskId = (int) $run['task_id'];
        $content = isset($data['spec_markdown']) ? trim((string) $data['spec_markdown']) : '';
        if ($content === '') {
            throw new \RuntimeException('claude 未返回 spec_markdown');
        }
        Db::name('ai_dev_tasks')->where('id', $taskId)->update([
            'spec_markdown' => $content,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return Db::name('ai_dev_tasks')->where('id', $taskId)->find();
    }

    private function assertNoRunning($taskId)
    {
        foreach ((new RunService())->listByTask($taskId) as $run) {
            if ($run['run_type'] === 'task_spec' && in_array($run['status'], ['queued', 'running'], true)) {
                throw new \RuntimeException('已有本项目需求文档生成任务正在运行');
            }
        }
    }

    private function buildPrompt($docContent, $breakdownContent, $role, $scopeSummary)
    {
        if (mb_strpos($role, '前端') !== false) {
            $tpl = "本项目是前端。产出【本项目视角】的落地需求子文档,聚焦:页面结构与信息层级、交互流程、每个展示字段及其数据来源标注、敏感字段脱敏规则、空态/异常态。禁止写 SQL、表结构、后端实现。";
        } elseif (mb_strpos($role, '后端') !== false) {
            $tpl = "本项目是后端/代理。产出【本项目视角】的落地需求子文档,聚焦:接口清单(路径/入参/出参/口径)、数据来源分层、SQL 聚合口径、日志与快照字段。禁止写页面布局与前端交互细节。";
        } else {
            $tpl = "产出【本项目视角】的落地需求子文档,只写属于本项目职责范围内的内容。";
        }
        return "你是本项目的负责人。" . $tpl . "\n"
            . "只返回 JSON,结构:{\"spec_markdown\":\"...\"},不要 JSON 以外的内容。\n"
            . "凡涉及跨项目交互,一律写『见共享接口契约』并引用下方拆解中的『跨项目接口契约』小节,不要复述其内容。\n\n"
            . "# 本项目职责(来自拆解)\n" . ($scopeSummary !== '' ? $scopeSummary : '(未提供,请从原文中判定属于本项目的部分)') . "\n\n"
            . "# 需求拆解(含共享接口契约,唯一事实源)\n" . $breakdownContent . "\n\n"
            . "# 原始需求文档(已脱敏,供补全细节)\n" . $docContent . "\n";
    }
}
```

- [ ] **Step 2: 在 GenerationExecutorService::finishRun 注册 task_spec**

在 `task_plan` 分发块之后加入(约 132 行后):

```php
        if ($run['run_type'] === 'task_spec') {
            return (new SpecService())->finishRun($run, $data);
        }
```

确认文件顶部 use 区可用 `SpecService`(同命名空间 `app\service\AiDev`,无需 use)。

- [ ] **Step 3: 语法检查**

```bash
php -l backend/thinkphp/app/service/AiDev/SpecService.php
php -l backend/thinkphp/app/service/AiDev/GenerationExecutorService.php
```
Expected: 两个都 `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add backend/thinkphp/app/service/AiDev/SpecService.php backend/thinkphp/app/service/AiDev/GenerationExecutorService.php
git commit -m "feat(ai-dev): 新增 SpecService 按 role 生成每项目需求子文档

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: 拆解确认后,多项目自动为每张工单排子文档生成

**Files:**
- Modify: `backend/thinkphp/app/service/AiDev/BreakdownService.php`(`confirm` ~101-157)

**Interfaces:**
- Consumes: `SpecService::generate($taskId)`(Task 3)。
- Produces: 确认多项目拆解 → 每张新建工单入队一个 `task_spec` run。

- [ ] **Step 1: confirm() 事务提交后,按项目数触发**

在 `confirm()` 的 `Db::commit();` 之后、`return $created;` 之前加入:

```php
        // 多项目才拆分:为每张新建(非跳过)工单排一个子文档生成 run;单项目沿用原文,不生成。
        if (count($created) > 1) {
            foreach ($created as $c) {
                if (!empty($c['skipped'])) {
                    continue;
                }
                try {
                    (new SpecService())->generate((int) $c['task_id']);
                } catch (\Throwable $e) {
                    // 单个失败不阻断确认;可在工单页手动重新生成
                }
            }
        }
```

- [ ] **Step 2: 语法检查**

```bash
php -l backend/thinkphp/app/service/AiDev/BreakdownService.php
```
Expected: `No syntax errors detected`

- [ ] **Step 3: 冒烟 —— 确认多项目拆解后每工单有 task_spec run**

UI 确认一个双项目拆解后:

```bash
sqlite3 <db_file> "SELECT task_id, run_type, status FROM ai_dev_runs WHERE run_type='task_spec' ORDER BY id DESC LIMIT 5;"
```
Expected: 两张工单各出现一条 `task_spec` run。run 完成后:

```bash
sqlite3 <db_file> "SELECT id, project_id, length(spec_markdown) FROM ai_dev_tasks ORDER BY id DESC LIMIT 2;"
```
Expected: 两张工单 `spec_markdown` 长度 > 0。

- [ ] **Step 4: 冒烟 —— 单项目确认不生成子文档**

对一个单项目需求确认拆解:

```bash
sqlite3 <db_file> "SELECT count(*) FROM ai_dev_runs WHERE run_type='task_spec' AND task_id=<该工单id>;"
```
Expected: 0(单项目沿用原文,零回归)。

- [ ] **Step 5: Commit**

```bash
git add backend/thinkphp/app/service/AiDev/BreakdownService.php
git commit -m "feat(ai-dev): 多项目拆解确认后自动为各工单生成需求子文档

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: 统一上下文来源 —— TaskService::projectContext,并接入 Plan/编码/Review

**Files:**
- Modify: `backend/thinkphp/app/service/AiDev/TaskService.php`(新增 public 方法)
- Modify: `backend/thinkphp/app/service/AiDev/PlanService.php`(`generate` ~19-28、`buildPrompt` ~100-108)
- Modify: `backend/thinkphp/app/service/AiDev/RunService.php`(`buildPrompt` ~247-263)

**Interfaces:**
- Produces: `TaskService::projectContext(array $task): string` —— 有 `spec_markdown` 时返回"子文档 + 已确认拆解(含共享契约)",否则返回原始需求文档全文。Plan/编码/Review 统一消费,替代直接读 `doc.content`。

- [ ] **Step 1: TaskService 加 projectContext**

`backend/thinkphp/app/service/AiDev/TaskService.php` 内新增:

```php
    /**
     * 生成计划/编码/Review 时喂给 AI 的"本项目上下文"。
     * 多项目:本项目子文档 + 已确认拆解(含共享接口契约);单项目:原始需求文档全文。
     */
    public function projectContext(array $task)
    {
        $spec = isset($task['spec_markdown']) ? trim((string) $task['spec_markdown']) : '';
        if ($spec !== '') {
            $breakdown = Db::name('ai_dev_breakdowns')
                ->where('requirement_id', (int) $task['requirement_id'])
                ->whereNotNull('confirmed_at')
                ->order('version', 'desc')->find();
            $contract = $breakdown ? (string) $breakdown['content'] : '';
            return "# 本项目需求文档(按本项目职责拆解)\n" . $spec . "\n\n"
                . "# 需求拆解与共享接口契约\n" . $contract . "\n";
        }
        $doc = Db::name('ai_dev_requirement_docs')->where('id', (int) $task['doc_version_id'])->find();
        return "# 需求文档(已脱敏)\n" . ($doc ? (string) $doc['content'] : '') . "\n";
    }
```

- [ ] **Step 2: PlanService 改用 projectContext**

`PlanService::generate` 里,把 buildPrompt 调用改为传入 task 上下文。将 ~19-28 段中 `'prompt' => $this->buildPrompt($doc['content'], $task['scope_summary'])` 改为:

```php
            'prompt' => $this->buildPrompt((new TaskService())->projectContext($task), $task['scope_summary']),
```

并把 `buildPrompt` 签名与正文里的"# 需求文档(已脱敏)\n" . $docContent 段改为直接使用传入的已成型上下文:

```php
    private function buildPrompt($projectContext, $scopeSummary)
    {
        return "你在该项目代码库根目录,可用 Read/Glob/Grep 阅读代码(禁止修改任何文件)。"
            . "为以下需求产出本项目的开发计划。只返回 JSON,结构:{\"plan_markdown\":\"...\"},不要 JSON 以外的内容。\n"
            . "计划必须引用真实存在的文件路径;结构固定为:\n"
            . "## 需求理解 / ## 涉及模块与文件 / ## 实施步骤 / ## 配置变更 / ## SQL 变更 / ## 验证计划 / ## 风险点\n\n"
            . $projectContext . "\n\n"
            . "# 本项目职责(来自需求拆解)\n" . ($scopeSummary !== '' ? $scopeSummary : '整个需求都在本项目内实现') . "\n";
    }
```

注意:`generate` 里仍保留对 `$doc` 是否存在的既有校验(需求文档快照缺失时报错),`projectContext` 内部会在无 spec 时自行读 doc。

- [ ] **Step 3: RunService::buildPrompt 改用 projectContext**

`RunService::buildPrompt`(~247-263)把开头读 doc 并注入原文的两行:

```php
        $doc = Db::name('ai_dev_requirement_docs')->where('id', $task['doc_version_id'])->find();
        $prompt = "# 任务\n按以下已确认的开发计划修改代码，不要偏离计划范围。\n\n";
        $prompt .= "# 需求文档（已脱敏快照）\n" . ($doc ? $doc['content'] : '') . "\n\n";
```

改为:

```php
        $prompt = "# 任务\n按以下已确认的开发计划修改代码，不要偏离计划范围。\n\n";
        $prompt .= (new TaskService())->projectContext($task) . "\n\n";
```

其余(本项目职责、已确认开发计划、约束、Review 反馈)保持不变。

- [ ] **Step 4: 语法检查**

```bash
php -l backend/thinkphp/app/service/AiDev/TaskService.php
php -l backend/thinkphp/app/service/AiDev/PlanService.php
php -l backend/thinkphp/app/service/AiDev/RunService.php
```
Expected: 三个都 `No syntax errors detected`

- [ ] **Step 5: 冒烟 —— 多项目工单生成计划的 prompt 用子文档**

对一张已有 `spec_markdown` 的工单点"AI 生成开发计划",生成 run 完成后查该 run 的 input:

```bash
sqlite3 <db_file> "SELECT substr(input,1,400) FROM ai_dev_runs WHERE run_type='task_plan' ORDER BY id DESC LIMIT 1;"
```
Expected: input 含"# 本项目需求文档(按本项目职责拆解)",不含整篇原文的后端 SQL 表段(前端工单场景)。

- [ ] **Step 6: 冒烟 —— 单项目工单仍用原文**

对一张 `spec_markdown` 为空的工单生成计划,查其 run input:
Expected: input 含"# 需求文档(已脱敏)"即原文(零回归)。

- [ ] **Step 7: Commit**

```bash
git add backend/thinkphp/app/service/AiDev/TaskService.php backend/thinkphp/app/service/AiDev/PlanService.php backend/thinkphp/app/service/AiDev/RunService.php
git commit -m "feat(ai-dev): 计划/编码/Review 上下文改用每项目子文档+共享契约

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: 工单页展示子文档 + 手动重生成(controller/route/api/前端)

**Files:**
- Modify: `backend/thinkphp/app/controller/AiDev/TaskController.php`(仿 `generatePlan` ~56)
- Modify: `backend/thinkphp/route/ai_dev.php`(仿第 26 行 generate-plan)
- Modify: `src/services/api.js`(仿第 60 行 generatePlan)
- Modify: `src/views/TaskDetail.vue`(开发计划区上方加"本项目需求文档"区)

**Interfaces:**
- Consumes: `SpecService::generate`(Task 3);`task.spec_markdown`(Task 1,`TaskService::detail` 查整行时已自动带出)。
- Produces: 路由 `POST tasks/:id/generate-spec`;`api.generateSpec(id, model)`。

- [ ] **Step 1: 控制器加 generateSpec**

`TaskController.php` 顶部 use 区加 `use app\service\AiDev\SpecService;`,并仿 generatePlan 加:

```php
    public function generateSpec($id, SpecService $service)
    {
        return json(['code' => 0, 'data' => $service->generate((int) $id, $this->modelParam())]);
    }
```

注:`$this->modelParam()` 若不存在,则沿用 generatePlan 里取 model 的同款写法(打开 generatePlan 确认后照抄其取 model 参数方式)。

- [ ] **Step 2: 路由**

`route/ai_dev.php` 在第 26 行 generate-plan 后加:

```php
    Route::post('tasks/:id/generate-spec', 'AiDev.TaskController/generateSpec');
```

- [ ] **Step 3: api.js**

`src/services/api.js` 在 generatePlan(第 60 行)后加:

```js
    generateSpec: (id, model = '') => request('POST', `/tasks/${id}/generate-spec`, { model }),
```

- [ ] **Step 4: TaskDetail.vue 加"本项目需求文档"展示区**

在"开发计划"流程步骤(约 32 行)之前插入一个块,仅当 `task.spec_markdown` 有值或存在 task_spec run 时显示:

```vue
<div v-if="task.spec_markdown || specRunning" class="flow-step">
  <span class="flow-step__title">本项目需求文档</span>
  <el-button size="small" :loading="specRunning" @click="generateSpec">
    {{ task.spec_markdown ? '重新生成本项目需求文档' : '生成本项目需求文档' }}
  </el-button>
  <div v-if="specRunning" class="empty-state">生成任务已入队,完成后自动刷新。</div>
  <MarkdownView v-else-if="task.spec_markdown" :source="task.spec_markdown" max-height="360px" />
</div>
```

script 区加:

```js
const generatingSpec = ref(false)
const specRunning = computed(() =>
  task.value?.runs?.find((run) => run.run_type === 'task_spec' && ['running', 'queued'].includes(run.status)),
)
async function generateSpec() {
  generatingSpec.value = true
  try {
    await api.generateSpec(task.value.id, '')
    await load() // 复用本页已有的加载函数;若名字不同,调用现有刷新工单的函数
  } finally {
    generatingSpec.value = false
  }
}
```

注:`load()` 用 TaskDetail 里现有的刷新函数名(打开文件确认,如 `fetchTask`/`load`),保持一致。

- [ ] **Step 5: 语法检查 + 前端构建**

```bash
php -l backend/thinkphp/app/controller/AiDev/TaskController.php
cd /Volumes/SSD/MB_Mapped/Users/hufangfang/www/local/ai_work_platform && npm run build 2>&1 | tail -20
```
Expected: php `No syntax errors detected`;前端构建无报错(或 dev 服务无编译错误)。

- [ ] **Step 6: 冒烟 —— UI 手动重生成**

多项目工单详情页出现"本项目需求文档"区;点"重新生成"→ 出现入队提示 → run 完成后展示更新后的子文档。单项目工单该区不出现。

- [ ] **Step 7: Commit**

```bash
git add backend/thinkphp/app/controller/AiDev/TaskController.php backend/thinkphp/route/ai_dev.php src/services/api.js src/views/TaskDetail.vue
git commit -m "feat(ai-dev): 工单页展示本项目需求文档并支持手动重生成

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

## Self-Review

**Spec coverage(逐节对照设计文档):**
- §3 决策1 每项目子文档+共享契约 → Task 3(子文档 prompt 引用契约不复述)✓
- §3 决策2 两步生成、可单项重跑 → Task 4(确认后排 run)+ Task 6(手动重生成)✓
- §3 决策3 需求子文档 vs 计划叠加 → Task 3 产子文档、Task 5 子文档喂计划 ✓
- §4 单/多项目分流 → Task 4(count>1 才生成)+ Task 5(spec 空则用原文)✓
- §5 按简介定职责 → Task 2 ✓
- §6 完整数据流下游三处(计划/编码/Review)→ Task 5(Plan + RunService::buildPrompt 同时覆盖编码与 fix/review)✓
- §7 数据模型:tasks.spec_markdown → Task 1;breakdowns 加 role → Task 2 ✓
- §8 触发时机/交互 → Task 4 自动、Task 6 手动、计划功能交互不变 ✓
- §10 非目标:未建子文档历史表、未下沉契约、单项目沿用原文 —— 计划均未越界 ✓

**Placeholder scan:** 无 TBD/TODO;每个改动步骤都给了具体代码。两处"照抄现有写法"(modelParam 取参、刷新函数名)是让实现者对齐现有命名而非留空,已标注确认点。

**Type consistency:** run_type `task_spec` 在 Task 3(enqueue+dispatch)、Task 4(触发)、Task 5(specRunning 前端)、Task 6 一致;`spec_markdown` 列在 Task 1 建、Task 3 写、Task 5/6 读一致;`projectContext(array $task): string` 在 Task 5 定义并被 Plan/Run 消费,签名一致;`SpecService::generate/finishRun` 签名跨 Task 3/4 一致。

**已知实现期需确认点(非阻塞):** 迁移触发入口(Task 1 Step 2)、sqlite 库文件路径、TaskController 取 model 参数写法、TaskDetail 刷新函数名 —— 均在对应步骤标注,实现者按现有代码对齐。
