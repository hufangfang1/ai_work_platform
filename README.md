# AI 开发工单台

基于开发计划实现的 AI 开发工单台。当前包含前端 Web MVP，以及按开发计划补齐的 ThinkPHP 后端模块。

## 运行

```bash
npm install
npm run dev
```

本地访问：http://localhost:5173/

## 已实现

### 前端

- 工单列表、筛选、创建与详情页
- 手动需求内容录入、读取与敏感信息脱敏
- AI 分支名生成、格式校验、远程分支占用模拟检查
- 开发计划生成、人工编辑、新版本保存和确认
- 模拟 Agent 执行、实时日志、变更摘要、变更文件和 git diff
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

## 当前边界

前端仍使用 `src/services/mockApi.js` 做本地演示闭环；后端已经是可启动的 ThinkPHP 项目。当前 `GET /` 可正常返回，业务接口需要先配置 MySQL 并执行 `backend/thinkphp/database/ai_dev_tables.sql` 建表。
