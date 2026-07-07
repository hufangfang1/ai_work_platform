# AI 开发工单台 v2 设计:需求实体 + 两级计划 + 暗色 UI + 前后端打通

日期:2026-07-07
状态:已与产品负责人对齐方向(需求 1:N 工单、暗色开发者工具风、结构+UI+打通一步到位),细节效果待成品验收。

## 1. 背景与目标

v1 的问题:

1. **没有"需求"实体**:需求只是工单上的 `doc_url` 字段,一个需求跨多个项目时只能建多张互不相识的工单,文档快照重复且易不一致,无法回答"这个需求整体做到哪了"。
2. **开发计划无代码上下文**:计划只靠需求文档纯文本生成,AI 看不到真实代码,"可能涉及模块"靠猜。
3. **项目配置本末倒置**:项目都在本地,却要人手填仓库地址和本地目录。
4. **前后端未打通**:前端五个页面全部使用 `mockApi`,后端 API 一个未接。
5. **布局复杂、样式古板**:详情页 7 个分区平铺,Element Plus 默认亮色主题缺乏工具感。

v2 目标:引入需求实体和两级计划,项目配置改为本地扫描,前端接真实后端,UI 重做为暗色开发者工具风。

## 2. 实体模型

```text
需求 (ai_dev_requirements)  1 ─── N  工单 (ai_dev_tasks)  N ─── 1  项目 (ai_dev_projects)
   │
   ├── 需求文档快照 (ai_dev_requirement_docs, 按版本)
   └── 需求拆解 (ai_dev_breakdowns, 按版本, source = ai/human)
```

### 2.1 新表

```sql
CREATE TABLE ai_dev_requirements (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  title VARCHAR(255) NOT NULL DEFAULT '',
  doc_url VARCHAR(1000) NOT NULL DEFAULT '',
  status VARCHAR(32) NOT NULL DEFAULT 'draft',   -- draft / active / closed(人工态)
  created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_status (status)
);

CREATE TABLE ai_dev_requirement_docs (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requirement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  version INT NOT NULL DEFAULT 1,
  content MEDIUMTEXT,
  source VARCHAR(32) NOT NULL DEFAULT 'manual',  -- manual / feishu
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_requirement_id (requirement_id)
);

CREATE TABLE ai_dev_breakdowns (
  id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
  requirement_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  version INT NOT NULL DEFAULT 1,
  content MEDIUMTEXT,                             -- Markdown:涉及项目/各项目职责/接口约定
  projects_json TEXT,                             -- 结构化:[{project_id, scope_summary}]
  source VARCHAR(32) NOT NULL DEFAULT 'ai',       -- ai / human
  model_name VARCHAR(255) NOT NULL DEFAULT '',
  confirmed_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
  confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_requirement_id (requirement_id)
);
```

### 2.2 表变更

`ai_dev_tasks`:

- 删除 `doc_url`、`doc_content_snapshot`(上移到需求层)。
- 新增 `requirement_id BIGINT`、`doc_version_id BIGINT`(基于哪版需求快照)、`scope_summary TEXT`(拆解分给本项目的职责摘要)。
- 保留 `commit_message / commit_hash / is_pushed / pr_url`(提交是工单级一次性结果)。

`ai_dev_projects`:

- 新增 `description VARCHAR(500)`(一句话说明项目用途,供 AI 拆解时判断)。

`ai_dev_changes`:删除 `commit_hash`(收敛到 tasks)。

其余表(plans / runs / run_logs / reviews / retrospectives / model_configs / security_rules)不变。项目处于 mock 阶段无真实数据,表结构直接重建,不写迁移脚本。

### 2.3 需求状态

需求只存人工态 `draft / active / closed`;进度(N 个工单各处于什么状态、几个已提交)由子工单实时聚合计算返回,不落库,避免两套状态机同步问题。

## 3. 核心流程(两级计划)

```text
创建需求(标题 + 贴文档内容/飞书地址)
  → 文档脱敏后存快照 v1
  → AI 拆解:涉及项目 + 各项目职责 + 跨项目接口约定(可人工编辑,按版本保存)
  → 人工确认拆解 → 一键生成 N 张工单(每张挂 requirement_id + scope_summary + doc_version_id)
  → 工单内:AI 在该项目 worktree 只读分析代码,生成项目级开发计划
    (输入 = 需求快照 + 本项目职责摘要 + 项目约束)
  → 后续沿用 v1:确认计划 → 编码 → Review(不过则 fix 循环)→ commit → 复盘
```

- 单项目需求不增加负担:拆解结果只有一个项目时直接生成一张工单。
- 允许人工在拆解结果上增删项目后再确认。
- **项目级计划生成改为 `claude -p` 带只读工具**(Read/Glob/Grep,禁 Edit/Write/Bash)在项目 worktree 里运行,计划能引用真实文件;需求级拆解仍走 Claude API 纯文本调用,输入附上所有启用项目的名称与 description。
- 工单执行(编码)、Review、commit 流程与 v1 一致,不动。
- 工单状态机变更:删除 `doc_loaded`(文档在需求层完成),工单从 `created` 直接进入 `branch_generated`;其余状态不变。

## 4. 项目配置改造

- 新增全局配置项 `workspace_roots`(JSON 数组,如 `["~/www/local"]`),存 `ai_dev_model_configs` 同级的新配置表或 config 表,由设置页维护。
- 新接口 `POST /api/ai-dev/projects/scan`:遍历根目录(深度 ≤ 2)找 `.git` 目录,返回 `{path, repo_url(git remote get-url origin), current_branch, already_added}`。
- "添加项目"改为弹框勾选扫描结果:`repo_url / local_path / default_base_branch` 自动带出;手填仅剩 `description`、分支前缀、测试/lint/构建命令。
- 项目表单里 repo 地址与本地目录变为只读展示。

## 5. API 设计

新增 requirements 组:

```text
POST   /api/ai-dev/requirements                       # 创建(标题+doc_url)
GET    /api/ai-dev/requirements                       # 列表(含子工单进度聚合)
GET    /api/ai-dev/requirements/{id}                  # 详情(需求+最新快照+最新拆解+子工单)
PUT    /api/ai-dev/requirements/{id}
POST   /api/ai-dev/requirements/{id}/load-doc         # 存快照新版本(手贴内容,脱敏)
POST   /api/ai-dev/requirements/{id}/generate-breakdown
PUT    /api/ai-dev/requirements/{id}/breakdown        # 人工编辑,存新版本(source=human)
POST   /api/ai-dev/requirements/{id}/confirm-breakdown # 确认并批量生成工单
GET    /api/ai-dev/requirements/{id}/tasks
POST   /api/ai-dev/requirements/{id}/close
```

tasks 组变更:

- 删除 `POST tasks/{id}/load-doc`(上移)。
- `POST tasks` 保留手动建单,但必须携带 `requirement_id`。
- 其余(generate-branch / plan / execute / review / fix / commit / retrospect / runs)不变。

projects 组新增 `POST projects/scan`;新增 `GET/PUT workspace-config`。

## 6. 前端页面与导航

导航(左侧窄边栏,图标+文字):**需求 / 工单 / 项目 / 设置**。

1. **需求列表页**(主入口):卡片或行式列表,展示标题、状态、涉及项目徽章、子工单进度(如 `2/3 已提交`)、更新时间;入口"新建需求"。
2. **需求详情页**:头部(标题/状态/文档地址/快照版本) + 拆解区(Markdown 渲染,可编辑、重新生成、确认) + **子工单进度看板**(每个项目一张卡:项目名、分支、当前状态、进入工单)。
3. **工单列表页**(全局视图):保留 v1 筛选,增加"所属需求"列与筛选。
4. **工单详情页**:从 7 分区平铺改为**纵向流程时间线**(步骤条:计划 → 执行 → 改动 → Review → 提交 → 复盘),当前步骤展开、历史步骤折叠可点开;右侧固定信息栏(需求链接、项目、分支、状态)。执行中步骤内嵌实时日志流(按 seq 轮询)。
5. **项目页**:项目卡片 + "添加项目"扫描弹框(见 §4)。
6. **设置页**:模型配置 + 脱敏规则 + 工作区根目录,合并为一页三段。

创建工单页取消(工单由需求拆解生成或需求详情内手动添加);创建需求为轻量弹层或独立简页。

## 7. UI 视觉系统(暗色开发者工具风)

参考 Linear / Vercel / GitHub Dark 的气质,基于 Element Plus dark 模式 + 自定义 design tokens:

- **色板**:背景 `#0d1117`,面板 `#151b23`,浮层 `#1c2330`,边框 `#2a3240`;主强调色电光蓝 `#4d9fff`,辅助紫 `#8b5cf6`;成功 `#3fb950`、警告 `#d29922`、危险 `#f85149`。
- **状态点**:各工单状态用小圆点 + 发光(box-shadow 同色低透明度),运行中状态脉冲动画。
- **字体**:界面用系统无衬线;分支名、commit hash、日志、diff、命令一律等宽字体(SF Mono / JetBrains Mono fallback)。
- **组件**:Element Plus 深度换肤(CSS 变量覆盖),卡片直角微圆(6px)、1px 边框而非阴影;按钮主操作实色、次操作 ghost。
- **diff/日志**:保留 CodeEditor(Monaco 或轻量方案)暗色主题,日志流带 event 类型着色。
- 单一暗色主题,不做亮色切换(下一轮如需再加)。

## 8. 前后端打通

- 新建 `src/services/api.js`:基于 fetch/axios 的真实 API 客户端,统一 baseURL(`/api/ai-dev`)、错误处理(接口错误 → 全局消息提示)。
- vite `server.proxy` 把 `/api` 代理到本地 ThinkPHP(端口按本地环境定)。
- 所有页面从 `mockApi` 切换到真实 API;`mockApi.js` 删除(不保留降级,避免双份数据结构漂移)。
- 后端按 §2/§5 同步改:新增 Requirement 控制器/服务/模型,TaskService 相关逻辑上移拆分。
- 长任务(拆解、计划、编码、Review)沿用队列 + 轮询模式。

## 9. 顺手清理

- 删除空壳 controller:`Task.php` / `Project.php` / `Config.php` / `Run.php`,路由直接指向 `TaskController` 等实际类。
- `ai_dev_changes.commit_hash` 删除。
- 计划文档(飞书导出的开发计划 md)与实现漂移的字段以本 spec 为准。

## 10. 验收标准

1. 创建一个需求,贴入需求文档,AI 拆解出 ≥2 个项目,人工调整后确认,自动生成对应工单。
2. 进入任一工单,生成的开发计划中引用了该项目的真实文件路径。
3. 工单走完 计划确认 → 执行 → Review → commit 全流程,需求详情页进度同步变化。
4. 添加项目通过扫描弹框完成,仓库地址与分支自动读取,无手填路径。
5. 全站暗色主题,无 mock 数据残留,前端所有数据来自 ThinkPHP 接口。
6. 单项目需求从创建到生成工单不超过 3 步操作。

## 11. 风险与边界

- 本地环境需 MySQL/Redis 与 PHP 运行时就绪;若队列环境缺失,执行类接口先以同步降级方式跑通(标注 TODO)。
- 飞书文档自动读取仍在后续阶段,本轮 load-doc 为手动粘贴。
- 脱敏仍为正则规则,不在本轮增强。
