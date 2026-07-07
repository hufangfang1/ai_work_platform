<template>
  <div v-if="run" class="ai-run-panel">
    <div class="ai-run-panel__head">
      <div class="ai-run-panel__meta">
        <span class="chip mono">run #{{ run.id }}</span>
        <span class="chip">{{ runTypeLabel(run.run_type) }}</span>
        <span :class="statusClass(run.status)">{{ statusLabel(run.status) }}</span>
      </div>
      <div class="toolbar">
        <el-button size="small" plain @click="openLogs">查看日志</el-button>
        <el-button v-if="isRunning" size="small" type="danger" plain @click="cancel">取消</el-button>
        <el-button v-else-if="canRetry" size="small" plain @click="retry">重试</el-button>
      </div>
    </div>

    <el-dialog
      v-model="dialogVisible"
      :title="`run #${run.id} · ${runTypeLabel(run.run_type)}`"
      width="min(920px, 92vw)"
      top="6vh"
      class="ai-run-dialog"
      @open="pollLogs"
    >
      <div class="ai-run-panel__dialog">
        <div class="ai-run-panel__dialog-head">
          <span class="chip">{{ runTypeLabel(run.run_type) }}</span>
          <span :class="statusClass(run.status)">{{ statusLabel(run.status) }}</span>
        </div>
        <div v-if="run.error" class="danger-text ai-run-panel__error">{{ run.error }}</div>
        <div v-if="logLines.length" class="timeline-log ai-run-panel__logs" ref="logBox">
          <div
            v-for="log in logLines"
            :key="log.id"
            class="log-line"
            :class="`log-line--${logClass(log.event_type)}`"
          >
            <code>#{{ log.seq }}</code>
            <span class="log-type">{{ log.event_type }}</span>
            <span class="ai-run-panel__log-text">{{ logText(log) }}</span>
          </div>
        </div>
        <div v-else-if="run.status === 'queued'" class="muted">
          <div>任务已入队，等待 Worker 处理…</div>
          <div class="mono ai-run-panel__worker-tip">
            若长时间无日志，请在本机启动 Worker：<br />
            cd backend/thinkphp && php think queue:work --queue ai_dev_code
          </div>
        </div>
        <div v-else class="muted">暂无执行日志</div>
      </div>
      <template #footer>
        <div class="ai-run-panel__footer">
          <el-button @click="dialogVisible = false">关闭</el-button>
          <el-button v-if="isRunning" type="danger" plain @click="cancel">取消</el-button>
          <el-button v-else-if="canRetry" plain @click="retry">重试</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { api } from '../services/api'

const props = defineProps({
  run: { type: Object, default: null },
})
const emit = defineEmits(['refresh', 'retried'])

const logLines = ref([])
const logBox = ref(null)
const dialogVisible = ref(false)
const autoOpenedRunId = ref(null)
let logTimer = null

const isRunning = computed(() => props.run && ['queued', 'running'].includes(props.run.status))
const canRetry = computed(() => props.run && ['failed', 'cancelled'].includes(props.run.status))
const shouldAutoOpen = computed(() => props.run && ['queued', 'running', 'failed'].includes(props.run.status))

watch(
  () => props.run?.id,
  () => {
    logLines.value = []
    dialogVisible.value = false
    syncPolling()
    maybeAutoOpen()
  },
  { immediate: true },
)

watch(
  () => props.run?.status,
  () => {
    syncPolling()
    maybeAutoOpen()
  },
)

watch(dialogVisible, (visible) => {
  if (visible) pollLogs()
})

function statusLabel(status) {
  return {
    queued: '已入队',
    running: '运行中',
    succeeded: '已完成',
    failed: '失败',
    cancelled: '已取消',
  }[status] || status
}

function statusClass(status) {
  if (status === 'succeeded') return 'success-text'
  if (status === 'failed' || status === 'cancelled') return 'danger-text'
  return 'muted'
}

function runTypeLabel(type) {
  return {
    requirement_breakdown: '需求拆解',
    task_plan: '计划生成',
    project_description: '项目描述',
    ai_review: 'AI Review',
    commit_message: 'Commit Message',
    coding: '编码',
    fix: '继续修改',
  }[type] || type
}

function logClass(eventType) {
  if (eventType === 'assistant' || eventType === 'result') return 'assistant'
  if (eventType === 'error' || eventType === 'stderr') return 'error'
  if (eventType === 'git' || eventType === 'queue' || eventType === 'cancel') return 'git'
  return 'tool'
}

function logText(log) {
  const raw = log.content || ''
  try {
    const event = JSON.parse(raw)
    if (event?.message?.content) {
      return event.message.content
        .map((part) => part.text || (part.name ? `[tool] ${part.name}` : ''))
        .filter(Boolean)
        .join('\n')
    }
    if (event?.result) return String(event.result)
    return raw.length > 400 ? `${raw.slice(0, 400)}...` : raw
  } catch (error) {
    return raw
  }
}

function openLogs() {
  dialogVisible.value = true
}

function maybeAutoOpen() {
  if (!props.run || !shouldAutoOpen.value || autoOpenedRunId.value === props.run.id) return
  autoOpenedRunId.value = props.run.id
  dialogVisible.value = true
}

function syncPolling() {
  if (logTimer) {
    clearInterval(logTimer)
    logTimer = null
  }
  if (!props.run) return
  pollLogs()
  if (isRunning.value) {
    logTimer = setInterval(pollLogs, 2500)
  }
}

async function pollLogs() {
  if (!props.run) return
  try {
    const afterSeq = logLines.value.length ? logLines.value[logLines.value.length - 1].seq : 0
    const fresh = await api.runs.logs(props.run.id, afterSeq, { silent: true })
    if (fresh.length) {
      logLines.value = logLines.value.concat(fresh)
      await nextTick()
      if (logBox.value) logBox.value.scrollTop = logBox.value.scrollHeight
    }
  } catch (error) {
    /* 下一轮重试 */
  }
}

async function cancel() {
  await api.runs.cancel(props.run.id)
  emit('refresh')
}

async function retry() {
  const run = await api.runs.retry(props.run.id)
  emit('retried', run)
  emit('refresh')
}

onBeforeUnmount(() => {
  if (logTimer) clearInterval(logTimer)
})
</script>

<style scoped>
.ai-run-panel {
  min-width: 0;
  max-width: 100%;
}

.ai-run-panel__head {
  min-width: 0;
  max-width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.ai-run-panel__meta,
.ai-run-panel__dialog-head,
.ai-run-panel__footer {
  min-width: 0;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.ai-run-panel__dialog {
  min-width: 0;
  display: grid;
  gap: 12px;
}

.ai-run-panel__dialog-head {
  justify-content: flex-start;
}

.ai-run-panel__error {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.ai-run-panel__logs {
  min-width: 0;
  max-width: 100%;
  max-height: min(65vh, 640px);
  overflow: auto;
}

.ai-run-panel__log-text {
  min-width: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.ai-run-panel__worker-tip {
  margin-top: 8px;
  font-size: 12px;
  line-height: 1.6;
  white-space: normal;
  overflow-wrap: anywhere;
}

.ai-run-panel__footer {
  justify-content: flex-end;
}
</style>
