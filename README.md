# AI 开发工单台

把需求文档逐步转化为项目拆解、开发计划、隔离代码改动、Review 和 Git 提交的 AI 开发工单台。技术栈为 Vue 3 + Vite 7 + ThinkPHP 6。

## 运行

```bash
npm install
npm run dev
```

本地访问：http://localhost:6173/

后端安装、数据库和 Worker 启动方式见 `backend/thinkphp/README.md`。

## 已实现

### 前端

- 工单列表、筛选、创建与详情页
- 手动需求内容录入、读取与敏感信息脱敏
- AI 分支名生成、格式校验、远程分支占用模拟检查
- 开发计划生成、人工编辑、新版本保存和确认
- Redis Queue 异步 Agent 执行、实时日志、变更摘要、变更文件和 git diff
- AI Review 通过/不通过、继续修改入口
- commit message 生成、人工确认提交、commit hash 记录
- 复盘 Markdown 生成与保存
- 项目配置页
- 模型与脱敏规则配置页

### 后端

- 真实 ThinkPHP 项目：`backend/thinkphp`
- Composer 配置：`backend/thinkphp/composer.json`
- Web 入口：`backend/thinkphp/public/index.php`
- CLI 入口：`backend/thinkphp/think`
- ThinkPHP 路由：`backend/thinkphp/route/ai_dev.php`
- 控制器：`backend/thinkphp/app/controller/AiDev`
- 服务层：`backend/thinkphp/app/service/AiDev`
- Redis Queue Job：`backend/thinkphp/app/job/AiDevCodeJob.php`
- MySQL 建表 SQL：`backend/thinkphp/database/ai_dev_tables.sql`
- Claude Code headless 执行封装：`AgentExecutorService`
- supervisor Worker 示例：`backend/thinkphp/supervisor/ai_dev_worker.conf`

## 主流程质量门槛

1. 需求拆解确认后，才会生成项目工单和分项目需求文档。
2. 下游只读取已确认拆解；未确认的新拆解不会改变计划、编码或 Review 上下文。
3. 开发计划需满足角色对应的结构和完整性校验，人工确认后才能编码。
4. 编码在独立 git worktree 中执行，并记录完整 diff 与项目检查结果。
5. AI Review 读取 worktree 的完整 `git diff HEAD`，并结合 lint/test/build 结果判定；存在阻塞项不能进入提交。
6. 提交前再次比对当前 diff 与已 Review 快照，防止 Review 后代码漂移。

后端轻量回归检查：

```bash
cd backend/thinkphp
php tests/run.php
```
