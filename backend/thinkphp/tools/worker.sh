#!/usr/bin/env bash
#
# AI 编码队列 worker 管理脚本(开发机用)
# 用法: ./tools/worker.sh {start|stop|restart|status|log}
#
# worker 消费 Redis 队列 ai_dev_code,承载 AI 编码异步任务。
# 本机没有 supervisor,所以用这个脚本手工管理:start/stop/restart 都按
# 进程特征匹配,不需要记 PID。

set -euo pipefail

# 脚本所在目录的上一级 = thinkphp 项目根
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP="/opt/homebrew/bin/php"
QUEUE="ai_dev_code"
# 用于精确匹配 / 杀进程的特征串
PATTERN="queue:work --queue ${QUEUE}"
ARGS="queue:work --queue ${QUEUE} --sleep 1 --tries 2 --timeout 3600"
LOG="${ROOT}/runtime/queue_worker.log"

pid() { pgrep -f "${PATTERN}" || true; }

start() {
  if [ -n "$(pid)" ]; then
    echo "worker 已在运行 (PID: $(pid))"
    return 0
  fi
  cd "${ROOT}"
  nohup "${PHP}" ${ARGS} >> "${LOG}" 2>&1 &
  sleep 1
  if [ -n "$(pid)" ]; then
    echo "worker 已启动 (PID: $(pid)),日志: ${LOG}"
  else
    echo "启动失败,查看日志: ${LOG}" >&2
    return 1
  fi
}

stop() {
  local p; p="$(pid)"
  if [ -z "${p}" ]; then
    echo "worker 未运行"
    return 0
  fi
  pkill -f "${PATTERN}"
  echo "已停止 worker (原 PID: ${p})"
}

status() {
  local p; p="$(pid)"
  if [ -n "${p}" ]; then
    echo "运行中 (PID: ${p})"
  else
    echo "未运行"
  fi
}

case "${1:-}" in
  start)   start ;;
  stop)    stop ;;
  restart) stop; sleep 1; start ;;
  status)  status ;;
  log)     tail -f "${LOG}" ;;
  *)       echo "用法: $0 {start|stop|restart|status|log}" >&2; exit 1 ;;
esac
