# AI 开发工单台 ThinkPHP 后端

这是一个可启动的 ThinkPHP 6 后端项目。Composer 当前固定 `topthink/framework` 6.0.16，并实现 API、MySQL 表结构、Redis Queue Worker 与多种 Agent CLI/HTTP 模型执行封装。

```text
ThinkPHP API
  -> MySQL
  -> Redis Queue
  -> PHP Worker
  -> git worktree + Claude Code headless
```

## 环境

- PHP 7.2+
- MySQL
- Redis
- Claude Code CLI

本机没有全局 Composer，因此项目内提供了 `tools/composer.phar` 的安装位置；如果该文件不存在，可重新下载 Composer 2.2 LTS。

## 安装

```bash
cd backend/thinkphp
php tools/composer.phar install
cp .env.example .env
```

编辑 `.env` 中的数据库、Redis 和 Claude Code 命令配置。

建表：

```bash
mysql -u root -p ai_work_platform < database/ai_dev_tables.sql
```

启动 API：

```bash
php think run -p 8787
```

启动 Worker：

```bash
php think queue:work --queue ai_dev_code --tries 1 --sleep 2
```

生产环境可参考 `supervisor/ai_dev_worker.conf`。

## 核心接口

- `POST /api/ai-dev/tasks`
- `GET /api/ai-dev/tasks`
- `GET /api/ai-dev/tasks/{id}`
- `POST /api/ai-dev/tasks/{id}/load-doc`
- `POST /api/ai-dev/tasks/{id}/generate-branch`
- `POST /api/ai-dev/tasks/{id}/generate-plan`
- `PUT /api/ai-dev/tasks/{id}/plan`
- `POST /api/ai-dev/tasks/{id}/confirm-plan`
- `POST /api/ai-dev/tasks/{id}/execute`
- `GET /api/ai-dev/runs/{runId}/logs?after_seq=0`
- `POST /api/ai-dev/tasks/{id}/review`
- `POST /api/ai-dev/tasks/{id}/fix`
- `POST /api/ai-dev/tasks/{id}/generate-commit-message`
- `POST /api/ai-dev/tasks/{id}/commit`
- `POST /api/ai-dev/tasks/{id}/retrospect`

## 注意

- `execute` 只负责入队，不在 HTTP 请求里跑 Claude Code。
- Worker 使用 `git worktree` 隔离每个工单。
- Agent 支持 Claude Code/Codex CLI；生成类步骤也可使用 OpenAI 兼容 HTTP 档案。
- Worker 会把 stream-json 逐行写入 `ai_dev_run_logs`，前端按 `after_seq` 轮询。

## 检查

```bash
php tests/run.php
find app config route tests -name '*.php' -print0 | xargs -0 -n1 php -l
```
