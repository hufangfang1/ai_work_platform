<template>
  <section v-if="task" class="page">
    <div class="page-heading">
      <div>
        <h1>{{ task.title }}</h1>
        <p>
          <StatusTag :status="task.status" />
          <span v-if="task.final_branch_name" class="mono" style="margin-left: 8px">
            {{ task.final_branch_name }}
          </span>
        </p>
      </div>
      <div class="toolbar">
        <el-button @click="$router.push('/tasks')">
          <el-icon><Back /></el-icon>
          工单列表
        </el-button>
        <el-button v-if="canTerminateTask" type="danger" plain @click="terminateTask">
          <el-icon><CircleClose /></el-icon>
          终止工单
        </el-button>
      </div>
    </div>

    <div class="detail-layout">
      <!-- 主列:流程时间线 -->
      <div class="flow">
        <!-- Step 1 分支与计划 -->
        <div class="flow-step" :class="stepClass(1)">
          <div class="flow-step__head" @click="toggleStep(1)">
            <span class="flow-step__index">1</span>
            <span class="flow-step__title">开发计划</span>
            <span class="flow-step__hint">
              {{ latestPlan ? `计划 v${latestPlan.version}` : '生成分支与计划,人工确认后执行' }}
            </span>
          </div>
          <div v-if="isOpen(1)" class="flow-step__body">
            <div class="toolbar">
              <el-button :loading="branching" :disabled="task.status !== 'created'" @click="generateBranch">
                生成分支名
              </el-button>
              <el-input
                v-model="branchEditor"
                class="mono"
                style="width: 320px"
                placeholder="future/xxx"
                :disabled="task.status === 'created'"
              />
              <el-button :disabled="!branchEditor" @click="saveBranch">保存并校验</el-button>
              <span v-if="branchCheck" :class="branchCheck.valid ? 'success-text' : 'danger-text'">
                {{ branchCheck.message }}
              </span>
            </div>
            <el-divider style="margin: 4px 0" />
            <div class="toolbar">
              <el-button
                :loading="planning"
                :disabled="task.status === 'created' || planLocked"
                @click="generatePlan"
              >
                <el-icon><MagicStick /></el-icon>
                {{ latestPlan ? '重新生成计划' : 'AI 生成开发计划' }}
              </el-button>
              <el-button :disabled="!latestPlan || planLocked" @click="savePlan">保存编辑为新版本</el-button>
              <el-button
                type="success"
                :disabled="!latestPlan || task.status !== 'plan_generated'"
                @click="confirmPlan"
              >
                <el-icon><Select /></el-icon>
                确认计划
              </el-button>
            </div>
            <div v-if="planning" class="empty-state">AI 正在阅读项目代码生成计划,约需 1-3 分钟…</div>
            <template v-else-if="latestPlan">
              <CodeEditor
                v-model="planEditor"
                label="开发计划(Markdown)"
                language="markdown"
                :rows="18"
                :readonly="planLocked"
              />
              <el-table :data="task.plans" size="small">
                <el-table-column label="版本" width="70">
                  <template #default="{ row }">v{{ row.version }}</template>
                </el-table-column>
                <el-table-column prop="source" label="来源" width="80" />
                <el-table-column label="确认时间" min-width="150">
                  <template #default="{ row }">{{ row.confirmed_at ? formatTime(row.confirmed_at) : '-' }}</template>
                </el-table-column>
                <el-table-column label="操作" width="80">
                  <template #default="{ row }">
                    <el-button text type="primary" @click="planEditor = row.plan_content">查看</el-button>
                  </template>
                </el-table-column>
              </el-table>
            </template>
          </div>
        </div>

        <!-- Step 2 AI 执行 -->
        <div class="flow-step" :class="stepClass(2)">
          <div class="flow-step__head" @click="toggleStep(2)">
            <span class="flow-step__index">2</span>
            <span class="flow-step__title">AI 执行</span>
            <span class="flow-step__hint">{{ runningRun ? '执行中,实时日志' : 'Claude Code 修改代码' }}</span>
          </div>
          <div v-if="isOpen(2)" class="flow-step__body">
            <div class="toolbar">
              <el-button
                type="primary"
                :disabled="!['plan_confirmed', 'failed'].includes(task.status)"
                @click="execute"
              >
                <el-icon><VideoPlay /></el-icon>
                开始 AI 修改
              </el-button>
              <el-button v-if="runningRun" type="danger" plain @click="cancelRun">取消执行</el-button>
            </div>
            <div v-if="logLines.length" class="timeline-log" ref="logBox">
              <div
                v-for="log in logLines"
                :key="log.id"
                class="log-line"
                :class="`log-line--${logClass(log.event_type)}`"
              >
                <code>#{{ log.seq }}</code>
                <span class="log-type">{{ log.event_type }}</span>
                <span style="white-space: pre-wrap; word-break: break-word">{{ logText(log) }}</span>
              </div>
            </div>
            <div v-else class="muted">暂无执行日志</div>
            <el-collapse v-if="task.runs.length">
              <el-collapse-item :title="`历史执行记录(${task.runs.length})`">
                <el-table :data="task.runs" size="small">
                  <el-table-column prop="id" label="ID" width="70" />
                  <el-table-column prop="run_type" label="类型" width="90" />
                  <el-table-column prop="status" label="状态" width="110" />
                  <el-table-column label="开始时间" min-width="150">
                    <template #default="{ row }">{{ row.started_at ? formatTime(row.started_at) : '-' }}</template>
                  </el-table-column>
                  <el-table-column label="错误" min-width="180">
                    <template #default="{ row }">
                      <span class="danger-text">{{ row.error || '-' }}</span>
                    </template>
                  </el-table-column>
                </el-table>
              </el-collapse-item>
            </el-collapse>
          </div>
        </div>

        <!-- Step 3 代码改动 -->
        <div class="flow-step" :class="stepClass(3)">
          <div class="flow-step__head" @click="toggleStep(3)">
            <span class="flow-step__index">3</span>
            <span class="flow-step__title">代码改动</span>
            <span class="flow-step__hint">
              {{ latestChange ? `${changedFiles.length} 个文件变更` : 'diff 与测试输出' }}
            </span>
          </div>
          <div v-if="isOpen(3)" class="flow-step__body">
            <template v-if="latestChange">
              <div>
                <div class="metric-label" style="margin-bottom: 6px">变更文件</div>
                <div v-for="file in changedFiles" :key="file" class="mono" style="padding: 2px 0">
                  {{ file }}
                </div>
              </div>
              <CodeEditor
                :model-value="latestChange.git_diff_snapshot || ''"
                label="git diff"
                language="diff"
                readonly
                :rows="18"
              />
            </template>
            <div v-else class="muted">AI 执行完成后展示改动</div>
          </div>
        </div>

        <!-- Step 4 Review -->
        <div class="flow-step" :class="stepClass(4)">
          <div class="flow-step__head" @click="toggleStep(4)">
            <span class="flow-step__index">4</span>
            <span class="flow-step__title">Review</span>
            <span class="flow-step__hint">{{ latestReview ? `结论:${latestReview.status}` : '检查与结论' }}</span>
          </div>
          <div v-if="isOpen(4)" class="flow-step__body">
            <div class="toolbar">
              <el-button
                type="primary"
                :loading="reviewing"
                :disabled="task.status !== 'code_changed'"
                @click="review"
              >
                发起 Review
              </el-button>
            </div>
            <template v-if="reviewResult">
              <div class="metric-row" style="grid-template-columns: repeat(2, 1fr)">
                <div class="metric">
                  <div class="metric-label">结论</div>
                  <div class="metric-value" :class="latestReview.status === 'failed' ? 'danger-text' : 'success-text'">
                    {{ latestReview.status }}
                  </div>
                </div>
                <div class="metric">
                  <div class="metric-label">风险等级</div>
                  <div class="metric-value">{{ latestReview.risk_level }}</div>
                </div>
              </div>
              <div v-if="reviewResult.summary" class="muted">{{ reviewResult.summary }}</div>
              <div v-for="(group, name) in reviewGroups" :key="name">
                <template v-if="group.items.length">
                  <div class="metric-label" style="margin-bottom: 4px">{{ group.label }}</div>
                  <ul style="margin: 0 0 8px; padding-left: 18px">
                    <li v-for="(item, index) in group.items" :key="index">{{ item }}</li>
                  </ul>
                </template>
              </div>
              <el-collapse v-if="latestReview.test_result">
                <el-collapse-item title="测试/检查输出">
                  <pre class="mono" style="white-space: pre-wrap">{{ latestReview.test_result }}</pre>
                </el-collapse-item>
              </el-collapse>
            </template>
            <div v-if="['review_failed', 'code_changed'].includes(task.status)" style="display: grid; gap: 8px">
              <el-input
                v-model="fixFeedback"
                type="textarea"
                :rows="3"
                placeholder="补充修改要求,AI 将基于 Review 反馈继续修改"
              />
              <div class="toolbar">
                <el-button type="warning" :disabled="!fixFeedback.trim()" @click="fix">
                  继续修改(fix 轮次)
                </el-button>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 5 提交 -->
        <div class="flow-step" :class="stepClass(5)">
          <div class="flow-step__head" @click="toggleStep(5)">
            <span class="flow-step__index">5</span>
            <span class="flow-step__title">提交</span>
            <span class="flow-step__hint">
              <span v-if="task.commit_hash" class="mono">{{ task.commit_hash.slice(0, 10) }}</span>
              <template v-else>人工确认 git commit</template>
            </span>
          </div>
          <div v-if="isOpen(5)" class="flow-step__body">
            <div class="toolbar">
              <el-button :disabled="task.status !== 'ready_to_commit'" @click="generateCommitMessage">
                生成 commit message
              </el-button>
              <el-button
                type="success"
                :loading="committing"
                :disabled="task.status !== 'ready_to_commit' || !commitEditor.trim()"
                @click="commit"
              >
                确认提交
              </el-button>
            </div>
            <CodeEditor
              v-model="commitEditor"
              label="commit message(可编辑)"
              language="text"
              :rows="6"
              :readonly="task.status === 'committed' || task.status === 'retrospected'"
            />
            <div v-if="task.commit_hash" class="muted">
              已提交:<span class="mono">{{ task.commit_hash }}</span>
            </div>
          </div>
        </div>

        <!-- Step 6 复盘 -->
        <div class="flow-step" :class="stepClass(6)">
          <div class="flow-step__head" @click="toggleStep(6)">
            <span class="flow-step__index">6</span>
            <span class="flow-step__title">复盘</span>
            <span class="flow-step__hint">{{ task.retrospective ? '已生成' : '沉淀本次交付' }}</span>
          </div>
          <div v-if="isOpen(6)" class="flow-step__body">
            <div class="toolbar">
              <el-button :disabled="task.status !== 'committed' && !task.retrospective" @click="generateRetro">
                生成复盘
              </el-button>
              <el-button type="primary" :disabled="!retroEditor.trim()" @click="saveRetro">保存复盘</el-button>
            </div>
            <CodeEditor v-model="retroEditor" label="复盘(Markdown)" language="markdown" :rows="14" />
          </div>
        </div>
      </div>

      <!-- 侧栏 -->
      <aside class="detail-side">
        <div class="panel" style="display: grid; gap: 12px">
          <div class="side-item">
            <div class="metric-label">所属需求</div>
            <el-link
              v-if="task.requirement"
              type="primary"
              @click="$router.push(`/requirements/${task.requirement.id}`)"
            >
              {{ task.requirement.title }}
            </el-link>
            <span v-else class="muted">-</span>
          </div>
          <div class="side-item">
            <div class="metric-label">项目</div>
            <div>{{ task.project?.name || task.repo_name }}</div>
          </div>
          <div class="side-item">
            <div class="metric-label">分支</div>
            <div class="mono">
              {{ task.base_branch }} → {{ task.final_branch_name || '未生成' }}
            </div>
          </div>
          <div class="side-item">
            <div class="metric-label">本项目职责</div>
            <div style="font-size: 13px">{{ task.scope_summary || '未填写' }}</div>
          </div>
          <div class="side-item">
            <div class="metric-label">需求快照 v{{ task.doc_version }}</div>
            <el-collapse>
              <el-collapse-item title="查看需求文档">
                <pre class="mono" style="white-space: pre-wrap; max-height: 300px; overflow: auto">{{ task.doc_content }}</pre>
              </el-collapse-item>
            </el-collapse>
          </div>
        </div>
      </aside>
    </div>
  </section>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import StatusTag from '../components/StatusTag.vue'
import CodeEditor from '../components/CodeEditor.vue'
import { canTerminate, runningStatuses } from '../services/status'
import { api } from '../services/api'

const props = defineProps({ id: { type: String, required: true } })

const task = ref(null)
const branchEditor = ref('')
const branchCheck = ref(null)
const planEditor = ref('')
const commitEditor = ref('')
const retroEditor = ref('')
const fixFeedback = ref('')
const logLines = ref([])
const logBox = ref(null)
const openSteps = ref(new Set())
const branching = ref(false)
const planning = ref(false)
const reviewing = ref(false)
const committing = ref(false)

let detailTimer = null
let logTimer = null
let logRunId = null

const stepByStatus = {
  created: 1,
  branch_generated: 1,
  plan_generated: 1,
  plan_confirmed: 2,
  coding: 2,
  fixing: 2,
  failed: 2,
  code_changed: 4,
  reviewing: 4,
  review_failed: 4,
  ready_to_commit: 5,
  committing: 5,
  committed: 6,
  retrospected: 6,
  terminated: 1,
}

const currentStep = computed(() => (task.value ? stepByStatus[task.value.status] || 1 : 1))
const latestPlan = computed(() => (task.value?.plans?.length ? task.value.plans[task.value.plans.length - 1] : null))
const planLocked = computed(() =>
  task.value ? !['created', 'branch_generated', 'plan_generated'].includes(task.value.status) : true,
)
const latestChange = computed(() => (task.value?.changes?.length ? task.value.changes[0] : null))
const changedFiles = computed(() => {
  if (!latestChange.value?.changed_files) return []
  try {
    return JSON.parse(latestChange.value.changed_files) || []
  } catch (error) {
    return []
  }
})
const latestReview = computed(() => (task.value?.reviews?.length ? task.value.reviews[0] : null))
const reviewResult = computed(() => {
  if (!latestReview.value?.review_result) return null
  try {
    return JSON.parse(latestReview.value.review_result)
  } catch (error) {
    return null
  }
})
const reviewGroups = computed(() => ({
  blocking: { label: '阻塞问题', items: reviewResult.value?.blocking_issues || [] },
  warnings: { label: '风险提示', items: reviewResult.value?.warnings || [] },
  suggestions: { label: '建议优化', items: reviewResult.value?.suggestions || [] },
}))
const runningRun = computed(() =>
  task.value?.runs?.find((run) => ['running', 'queued'].includes(run.status)),
)
const canTerminateTask = computed(() => task.value && canTerminate(task.value.status))

function stepClass(step) {
  return {
    'flow-step--done': step < currentStep.value,
    'flow-step--active': step === currentStep.value,
    'flow-step--locked': step > currentStep.value,
  }
}

function isOpen(step) {
  return step === currentStep.value || openSteps.value.has(step)
}

function toggleStep(step) {
  if (step > currentStep.value) return
  const set = new Set(openSteps.value)
  set.has(step) ? set.delete(step) : set.add(step)
  openSteps.value = set
}

function formatTime(value) {
  if (!value) return '-'
  return new Date(value.replace(' ', 'T')).toLocaleString('zh-CN', { hour12: false })
}

function logClass(eventType) {
  if (eventType === 'assistant' || eventType === 'result') return 'assistant'
  if (eventType === 'error' || eventType === 'stderr') return 'error'
  if (eventType === 'git' || eventType === 'queue') return 'git'
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
    return raw.length > 400 ? `${raw.slice(0, 400)}…` : raw
  } catch (error) {
    return raw
  }
}

async function load({ silent = false } = {}) {
  task.value = await api.tasks.detail(props.id, { silent })
  branchEditor.value = task.value.final_branch_name || ''
  if (latestPlan.value && !planEditor.value) planEditor.value = latestPlan.value.plan_content
  if (task.value.commit_message && !commitEditor.value) commitEditor.value = task.value.commit_message
  if (task.value.retrospective && !retroEditor.value) retroEditor.value = task.value.retrospective.content
  syncTimers()
}

function syncTimers() {
  const running = task.value && runningStatuses.has(task.value.status)
  if (running && !detailTimer) {
    detailTimer = setInterval(() => load({ silent: true }).catch(() => {}), 3000)
  }
  if (!running && detailTimer) {
    clearInterval(detailTimer)
    detailTimer = null
  }
  const run = runningRun.value
  if (run && logRunId !== run.id) {
    logRunId = run.id
    logLines.value = []
    startLogPolling()
  }
  if (!run && logTimer) {
    clearInterval(logTimer)
    logTimer = null
  }
}

function startLogPolling() {
  if (logTimer) clearInterval(logTimer)
  const poll = async () => {
    if (!logRunId) return
    try {
      const afterSeq = logLines.value.length ? logLines.value[logLines.value.length - 1].seq : 0
      const fresh = await api.runs.logs(logRunId, afterSeq, { silent: true })
      if (fresh.length) {
        logLines.value = logLines.value.concat(fresh)
        await nextTick()
        if (logBox.value) logBox.value.scrollTop = logBox.value.scrollHeight
      }
    } catch (error) {
      /* 轮询失败忽略,下轮重试 */
    }
  }
  poll()
  logTimer = setInterval(poll, 2500)
}

async function generateBranch() {
  branching.value = true
  try {
    await api.tasks.generateBranch(props.id)
    await load()
    ElMessage.success('分支名已生成,可直接修改')
  } finally {
    branching.value = false
  }
}

async function saveBranch() {
  const name = branchEditor.value.trim()
  await api.tasks.update(props.id, {
    final_branch_name: name,
    branch_name: name.split('/').pop(),
  })
  branchCheck.value = await api.tasks.checkBranch(props.id, name)
  await load()
}

async function generatePlan() {
  planning.value = true
  try {
    const plan = await api.tasks.generatePlan(props.id)
    planEditor.value = plan.plan_content
    await load()
    ElMessage.success('计划已生成,请审阅')
  } finally {
    planning.value = false
  }
}

async function savePlan() {
  await api.tasks.savePlan(props.id, planEditor.value)
  await load()
  ElMessage.success('已保存为新版本')
}

async function confirmPlan() {
  await ElMessageBox.confirm('确认后 AI 将按当前最新版本计划执行修改。', '确认计划', { type: 'warning' })
  await api.tasks.confirmPlan(props.id)
  await load()
}

async function execute() {
  await api.tasks.execute(props.id)
  await load()
}

async function cancelRun() {
  if (!runningRun.value) return
  await api.runs.cancel(runningRun.value.id)
  await load()
}

async function review() {
  reviewing.value = true
  try {
    await api.tasks.review(props.id)
    await load()
  } finally {
    reviewing.value = false
  }
}

async function fix() {
  await api.tasks.fix(props.id, fixFeedback.value)
  fixFeedback.value = ''
  await load()
}

async function generateCommitMessage() {
  const data = await api.tasks.generateCommitMessage(props.id)
  commitEditor.value = data.commit_message
}

async function commit() {
  await ElMessageBox.confirm('将执行 git add -A && git commit,确认?', '确认提交', { type: 'warning' })
  committing.value = true
  try {
    await api.tasks.commit(props.id, commitEditor.value)
    await load()
    ElMessage.success('提交完成')
  } finally {
    committing.value = false
  }
}

async function generateRetro() {
  const data = await api.tasks.retrospect(props.id)
  retroEditor.value = data.content
}

async function saveRetro() {
  await api.tasks.saveRetrospective(props.id, retroEditor.value)
  await load()
  ElMessage.success('复盘已保存')
}

async function terminateTask() {
  await ElMessageBox.confirm('终止后工单关闭,不可恢复。', '终止工单', { type: 'warning' })
  await api.tasks.terminate(props.id)
  await load()
}

watch(currentStep, () => {
  openSteps.value = new Set()
})

onMounted(load)
onBeforeUnmount(() => {
  if (detailTimer) clearInterval(detailTimer)
  if (logTimer) clearInterval(logTimer)
})
</script>
