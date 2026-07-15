<template>
  <div v-if="run" class="ai-run-panel">
    <div class="ai-run-panel__head">
      <div class="ai-run-panel__meta">
        <span class="chip mono">run #{{ run.id }}</span>
        <span class="chip">{{ runTypeLabel(run.run_type) }}</span>
        <span :class="statusClass(run.status)">{{ statusLabel(run.status) }}</span>
      </div>
      <div class="toolbar">
        <el-button v-if="!isDraft || !hideDraftOpenButton" size="small" plain @click="openLogs">
          {{ isDraft ? '编辑提示语' : '查看日志' }}
        </el-button>
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

        <!-- 草稿态:直接编辑本次发给大模型的提示语,不改则用默认内容 -->
        <div v-if="isDraft" class="ai-run-panel__draft">
          <div class="ai-run-panel__draft-head">
            <span class="muted ai-run-panel__prompt-hint">
              以下是本次将发送给大模型的提示语,可直接编辑;不修改则按默认内容执行。
            </span>
            <el-radio-group v-model="draftPreview" size="small">
              <el-radio-button :value="false">编辑</el-radio-button>
              <el-radio-button :value="true">预览</el-radio-button>
            </el-radio-group>
          </div>
          <MarkdownView
            v-if="draftPreview"
            :source="draftPrompt"
            max-height="520px"
            class="ai-run-panel__draft-preview"
          />
          <el-input
            v-else
            v-model="draftPrompt"
            type="textarea"
            :autosize="{ minRows: 10, maxRows: 26 }"
            class="mono ai-run-panel__draft-input"
          />
        </div>

        <!-- 非草稿态:只读提示语折叠 + 详细日志 -->
        <template v-else>
          <el-collapse v-if="promptText" class="ai-run-panel__prompt">
            <el-collapse-item name="prompt">
              <template #title>
                <div class="ai-run-panel__prompt-title">
                  <span class="ai-run-panel__prompt-label">请求提示语</span>
                  <span class="ai-run-panel__prompt-hint">实际发送给大模型的完整 prompt</span>
                </div>
              </template>
              <div class="ai-run-panel__prompt-body">
                <el-button size="small" plain @click="copyPrompt">复制</el-button>
                <pre class="ai-run-panel__prompt-text">{{ promptText }}</pre>
              </div>
            </el-collapse-item>
          </el-collapse>

          <div v-if="run.error" class="danger-text ai-run-panel__error">{{ run.error }}</div>
          <div v-if="visibleLogs.length" class="timeline-log ai-run-panel__logs" ref="logBox" @scroll="onLogScroll">
            <div
              v-for="log in visibleLogs"
              :key="log.seq"
              class="log-line"
              :class="`log-line--${log.cls}`"
            >
              <code>#{{ log.seq }}</code>
              <span class="log-type">{{ log.label }}</span>
              <span class="ai-run-panel__log-text">{{ displayText(log) }}</span>
              <el-button
                v-if="isClipped(log)"
                link
                size="small"
                class="ai-run-panel__expand"
                @click="toggleExpand(log.seq)"
              >
                {{ isExpanded(log.seq) ? '收起' : '展开全部' }}
              </el-button>
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
        </template>
      </div>
      <template #footer>
        <div class="ai-run-panel__footer">
          <template v-if="isDraft">
            <el-button plain :disabled="saving" @click="dialogVisible = false">取消</el-button>
            <el-button type="primary" :loading="saving" @click="saveDraft">确定</el-button>
          </template>
          <template v-else>
            <el-button @click="dialogVisible = false">关闭</el-button>
            <el-button v-if="isRunning" type="danger" plain @click="cancel">取消</el-button>
            <el-button v-else-if="canRetry" plain @click="retry">重试</el-button>
          </template>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { api } from '../services/api'
import MarkdownView from './MarkdownView.vue'

const props = defineProps({
  run: { type: Object, default: null },
  // 页面已有统一的“编辑提示语”入口时，草稿行不再重复显示同名按钮。
  hideDraftOpenButton: { type: Boolean, default: false },
  // 传入则重试时改用该模型档案(如需求拆解按当前下拉框选择重试);不传则沿用原 run 的模型。
  retryModel: { type: String, default: undefined },
})
const emit = defineEmits(['refresh', 'retried'])

const logLines = ref([])
const logBox = ref(null)
const dialogVisible = ref(false)
const draftPrompt = ref('')
const draftPreview = ref(false)
const saving = ref(false)
const expandedSeqs = ref(new Set())
const stickToBottom = ref(true)
const SCROLL_BOTTOM_THRESHOLD = 48
let logTimer = null

const isRunning = computed(() => props.run && ['queued', 'running'].includes(props.run.status))
const canRetry = computed(() => props.run && ['failed', 'cancelled'].includes(props.run.status))
const isDraft = computed(() => props.run?.status === 'draft')

// run.input:编码/继续修改是纯文本 prompt;生成类是 {prompt,options} JSON,取其中的 prompt。
function extractPrompt(input) {
  if (!input || typeof input !== 'string') return ''
  try {
    const parsed = JSON.parse(input)
    if (parsed && typeof parsed === 'object' && typeof parsed.prompt === 'string') return parsed.prompt
  } catch (error) {
    /* 纯文本 prompt */
  }
  return input
}

const promptText = computed(() => extractPrompt(props.run?.input))

async function copyPrompt() {
  try {
    await navigator.clipboard.writeText(promptText.value)
    ElMessage.success('提示语已复制')
  } catch (error) {
    ElMessage.error('复制失败,请手动选择')
  }
}

// 日志展开:describeLog 保留完整文本,这里默认截断展示,超长时可逐行展开。
const DISPLAY_LIMIT = 800
function isExpanded(seq) {
  return expandedSeqs.value.has(seq)
}
function toggleExpand(seq) {
  const next = new Set(expandedSeqs.value)
  next.has(seq) ? next.delete(seq) : next.add(seq)
  expandedSeqs.value = next
}
function isClipped(log) {
  return (log.text || '').length > DISPLAY_LIMIT
}
function displayText(log) {
  const text = log.text || ''
  if (isExpanded(log.seq) || text.length <= DISPLAY_LIMIT) return text
  return `${text.slice(0, DISPLAY_LIMIT)}…`
}

async function saveDraft() {
  if (!props.run) return
  saving.value = true
  try {
    if (draftPrompt.value !== promptText.value) {
      await api.runs.updatePrompt(props.run.id, draftPrompt.value)
    }
    dialogVisible.value = false
    emit('refresh')
    ElMessage.success('提示语草稿已保存，尚未执行')
  } catch (error) {
    /* request 已弹错误提示 */
  } finally {
    saving.value = false
  }
}

watch(
  () => props.run?.id,
  () => {
    logLines.value = []
    dialogVisible.value = false
    expandedSeqs.value = new Set()
    stickToBottom.value = true
    draftPrompt.value = extractPrompt(props.run?.input)
    draftPreview.value = false
    syncPolling()
  },
  { immediate: true },
)

watch(
  () => props.run?.status,
  () => {
    syncPolling()
  },
)

watch(dialogVisible, (visible) => {
  if (visible) {
    stickToBottom.value = true
    pollLogs()
  }
})

function statusLabel(status) {
  return {
    draft: '草稿·待编辑执行',
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
    branch_name: '分支名',
    task_spec: '需求子文档',
    task_plan: '计划生成',
    project_description: '项目描述',
    ai_review: 'AI Review',
    commit_message: 'Commit Message',
    coding: '编码',
    fix: '继续修改',
  }[type] || type
}

// 后端把 Claude Code 每行流式 JSON 原样入库,这里统一翻译成干净的中文行,
// 并过滤掉纯噪音(hook 心跳、空内容),避免面板里出现原始 JSON 和空行。
const EVENT_LABEL = {
  system: '系统',
  assistant: 'AI',
  user: '工具返回',
  result: '结果',
  stdout: '输出',
  stderr: '错误输出',
  error: '错误',
  git: 'Git',
  test: '测试',
  queue: '队列',
  cancel: '取消',
  prompt: '提示语',
  http_request: 'HTTP 请求',
  http_response: 'HTTP 响应',
  http_stream: '模型输出',
  http_thinking: '模型思考',
  step: '进度',
  json: '事件',
}

const SYSTEM_SUBTYPE_LABEL = {
  init: '初始化',
  task_started: '子任务',
  task_progress: '子任务',
}

const visibleLogs = computed(() => logLines.value.map(describeLog).filter(Boolean))

function eventClass(eventType) {
  if (eventType === 'assistant' || eventType === 'result' || eventType === 'http_response' || eventType === 'http_stream' || eventType === 'http_thinking') return 'assistant'
  if (eventType === 'error' || eventType === 'stderr') return 'error'
  if (['git', 'queue', 'cancel', 'prompt', 'http_request', 'step'].includes(eventType)) return 'git'
  return 'tool'
}

// 不在此处截断:保留完整文本,由模板 displayText 按需截断并提供「展开全部」。
function clip(text) {
  return String(text ?? '').trim()
}

// tool_result 的 content 可能是字符串,也可能是 [{type:'text',text}] 数组。
function toolResultText(content) {
  if (typeof content === 'string') return content
  if (Array.isArray(content)) {
    return content.map((part) => (typeof part === 'string' ? part : part?.text || '')).filter(Boolean).join('\n')
  }
  return ''
}

// 把一条 message.content(assistant / user 事件)汇总成可读文本。
function summarizeContent(content) {
  if (!Array.isArray(content)) return ''
  const parts = []
  for (const part of content) {
    if (!part || typeof part !== 'object') continue
    if (part.type === 'text' && part.text) parts.push(part.text)
    else if (part.type === 'thinking' && part.thinking) parts.push(`思考:${part.thinking}`)
    else if (part.type === 'tool_use') parts.push(`调用工具 ${part.name || ''}`.trim())
    else if (part.type === 'tool_result') {
      const text = toolResultText(part.content)
      if (text) parts.push(text)
    } else if (part.text) parts.push(part.text)
  }
  return parts.join('\n')
}

// codex CLI 的流式事件用 type 形如 thread.started / turn.started / item.started /
// item.completed / turn.completed / error,和 Claude Code 的 system/assistant/... 完全不同。
// 后端把每条事件原样按 event_type=type 入库,这里按 item.type 翻译成可读行,
// 否则 describeLog 会把它们全部当成未知类型过滤掉,面板在 codex 执行时看起来"卡住"。
function isCodexEventType(type) {
  return (
    type === 'thread.started' ||
    type === 'turn.started' ||
    type === 'turn.completed' ||
    type === 'item.started' ||
    type === 'item.updated' ||
    type === 'item.completed'
  )
}

function describeCodexItem(phase, item) {
  const itemType = item.type
  // 文本类只在完成时输出最终文本,避免流式增量重复刷屏
  if (itemType === 'agent_message') {
    if (phase !== 'item.completed') return null
    const text = clip(item.text || '')
    return text ? { label: 'AI', cls: 'assistant', text } : null
  }
  if (itemType === 'reasoning') {
    if (phase !== 'item.completed') return null
    const text = clip(item.text || '')
    return text ? { label: '思考', cls: 'tool', text: `思考:${text}` } : null
  }
  if (itemType === 'command_execution') {
    // 开始时提示执行的命令;完成时只在失败(退出码非 0)才补一条,成功不刷屏
    if (phase === 'item.started' || phase === 'item.updated') {
      const cmd = clip(item.command || '', 300)
      return cmd ? { label: '命令', cls: 'git', text: `执行命令:${cmd}` } : null
    }
    if (phase === 'item.completed') {
      const code = item.exit_code
      if (code != null && Number(code) !== 0) {
        return { label: '命令', cls: 'error', text: `命令退出码 ${code}:${clip(item.aggregated_output || '', 400)}` }
      }
    }
    return null
  }
  if (itemType === 'file_change' || itemType === 'patch' || itemType === 'patch_apply') {
    if (phase !== 'item.completed') return null
    const changes = Array.isArray(item.changes) ? item.changes : []
    const files = changes.map((c) => (typeof c === 'string' ? c : c?.path)).filter(Boolean)
    const text = files.length ? `修改文件:${files.join(', ')}` : '修改文件'
    return { label: '文件', cls: 'tool', text: clip(text) }
  }
  if (itemType === 'mcp_tool_call') {
    if (phase !== 'item.completed') return null
    return { label: '工具', cls: 'tool', text: clip(`调用工具 ${item.server || ''} ${item.tool || ''}`.trim()) }
  }
  if (itemType === 'web_search') {
    if (phase !== 'item.completed') return null
    return { label: '搜索', cls: 'tool', text: clip(`联网搜索:${item.query || ''}`) }
  }
  return null
}

function describeCodex(phase, event) {
  if (phase === 'thread.started' || phase === 'turn.started' || phase === 'turn.completed') {
    return null
  }
  const item = event.item && typeof event.item === 'object' ? event.item : null
  if (!item) return null
  return describeCodexItem(phase, item)
}

function describeSystem(event) {
  const sub = event.subtype
  // hook 心跳 / thinking 心跳无信息量,直接隐藏
  if (sub === 'hook_started' || sub === 'hook_response' || sub === 'thinking_tokens') return null
  const label = SYSTEM_SUBTYPE_LABEL[sub] || '系统'
  let text
  if (sub === 'init') {
    text = event.model ? `会话已初始化(模型 ${event.model})` : '会话已初始化'
  } else if (sub === 'task_started') {
    const agent = event.subagent_type ? `${event.subagent_type} · ` : ''
    text = `启动子任务:${agent}${event.description || ''}`
  } else if (sub === 'task_progress') {
    const tool = event.last_tool_name ? `${event.last_tool_name} · ` : ''
    text = `执行中:${tool}${event.description || ''}`
  } else {
    text = event.description || event.subtype || '系统事件'
  }
  return { label, cls: 'git', text: clip(text) }
}

// 返回 { seq, label, cls, text };无信息量的行返回 null 以便过滤。
function describeLog(log) {
  const type = log.event_type
  const raw = log.content || ''
  let event = null
  try {
    event = JSON.parse(raw)
  } catch (error) {
    event = null
  }

  // 后端自定义事件(stderr/git/test/error/queue/cancel/stdout)存的是纯文本
  if (!event || typeof event !== 'object') {
    const trimmed = raw.trim()
    // 结构化事件(codex/claude JSON)解析失败,通常是历史超大日志被截断成非法 JSON。
    // 别把一大段原始 JSON 直接倒出来,给个占位即可(新日志已在后端截断,不会再出现)。
    if (trimmed.startsWith('{') || trimmed.startsWith('[')) {
      return { seq: log.seq, label: EVENT_LABEL[type] || type, cls: eventClass(type), text: '（日志过大，已省略）' }
    }
    const text = clip(raw)
    if (!text) return null
    return { seq: log.seq, label: EVENT_LABEL[type] || type, cls: eventClass(type), text }
  }

  let info
  if (isCodexEventType(type)) {
    info = describeCodex(type, event)
  } else if (type === 'system') {
    info = describeSystem(event)
  } else if (type === 'assistant') {
    const text = summarizeContent(event?.message?.content)
    info = text.trim() ? { label: 'AI', cls: 'assistant', text: clip(text) } : null
  } else if (type === 'user') {
    const text = summarizeContent(event?.message?.content)
    info = text.trim() ? { label: '工具返回', cls: 'tool', text: clip(text) } : null
  } else if (type === 'result') {
    const text = event.result != null ? String(event.result) : ''
    info = text.trim() ? { label: '结果', cls: 'assistant', text: clip(text) } : null
  } else {
    const text = clip(event.description || event.message || '')
    info = text ? { label: EVENT_LABEL[type] || type, cls: eventClass(type), text } : null
  }

  return info ? { seq: log.seq, ...info } : null
}

function openLogs() {
  stickToBottom.value = true
  dialogVisible.value = true
}

function isNearBottom(el) {
  if (!el) return true
  return el.scrollHeight - el.scrollTop - el.clientHeight <= SCROLL_BOTTOM_THRESHOLD
}

function onLogScroll() {
  if (logBox.value) {
    stickToBottom.value = isNearBottom(logBox.value)
  }
}

function scrollLogsToBottom() {
  if (logBox.value) {
    logBox.value.scrollTop = logBox.value.scrollHeight
  }
}

defineExpose({ open: openLogs })

function syncPolling() {
  if (logTimer) {
    clearInterval(logTimer)
    logTimer = null
  }
  if (!props.run) return
  pollLogs()
  if (isRunning.value) {
    logTimer = setInterval(pollLogs, 800)
  }
}

async function pollLogs() {
  if (!props.run) return
  try {
    const afterSeq = logLines.value.length ? logLines.value[logLines.value.length - 1].seq : 0
    const fresh = await api.runs.logs(props.run.id, afterSeq, { silent: true })
    if (fresh.length) {
      // 多处触发的 pollLogs 可能并发,按 seq 去重避免同一条日志被追加两次
      const seen = new Set(logLines.value.map((line) => line.seq))
      const added = fresh.filter((line) => !seen.has(line.seq))
      if (added.length) {
        logLines.value = logLines.value.concat(added)
        await nextTick()
        if (stickToBottom.value) scrollLogsToBottom()
      }
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
  const run = await api.runs.retry(props.run.id, props.retryModel)
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

.ai-run-panel__prompt {
  --el-collapse-header-height: 40px;
  min-width: 0;
  border: 1px solid var(--line-soft);
  border-radius: 6px;
  overflow: hidden;
  background: var(--page-bg);
}

.ai-run-panel__prompt :deep(.el-collapse-item__header) {
  display: flex;
  align-items: center;
  min-width: 0;
  height: var(--el-collapse-header-height);
  padding: 0 12px;
  background: var(--surface-raised);
  border-bottom: none;
  line-height: 1.2;
}

.ai-run-panel__prompt :deep(.el-collapse-item__wrap) {
  background: transparent;
  border-bottom: none;
}

.ai-run-panel__prompt :deep(.el-collapse-item__content) {
  padding: 10px 12px 12px;
}

.ai-run-panel__prompt :deep(.el-collapse-item__title) {
  flex: 1;
  min-width: 0;
  display: flex;
  align-items: center;
  overflow: hidden;
}

.ai-run-panel__prompt :deep(.el-collapse-item__arrow) {
  margin: 0 0 0 8px;
  flex-shrink: 0;
}

.ai-run-panel__prompt-title {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
  width: 100%;
  flex-wrap: nowrap;
}

.ai-run-panel__prompt-label {
  flex-shrink: 0;
  padding: 1px 8px;
  border: 1px solid var(--line);
  border-radius: 999px;
  font-size: 12px;
  color: var(--text-muted);
  background: var(--surface-raised);
  white-space: nowrap;
}

.ai-run-panel__prompt-title .ai-run-panel__prompt-hint {
  flex: 1;
  min-width: 0;
  font-size: 12px;
  color: var(--text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
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

.ai-run-panel__prompt-hint {
  font-size: 12px;
}

.ai-run-panel__prompt-body {
  display: grid;
  gap: 8px;
  justify-items: start;
  width: 100%;
}

.ai-run-panel__prompt-text {
  width: 100%;
  min-width: 0;
  max-height: min(40vh, 360px);
  overflow: auto;
  margin: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  word-break: break-word;
  font-size: 12px;
  line-height: 1.6;
  padding: 10px 12px;
  border: 1px solid var(--line-soft);
  border-radius: 6px;
  background: #0a0e14;
  color: #d5dde5;
  font-family: var(--mono);
}

.ai-run-panel__draft {
  display: grid;
  gap: 8px;
}

.ai-run-panel__draft-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
}

.ai-run-panel__draft-preview {
  border: 1px solid var(--el-border-color, #dcdfe6);
  border-radius: 4px;
  padding: 8px 12px;
}

.ai-run-panel__draft-input :deep(textarea) {
  font-size: 12px;
  line-height: 1.6;
}

.ai-run-panel__expand {
  margin-left: 6px;
  vertical-align: baseline;
}
</style>
