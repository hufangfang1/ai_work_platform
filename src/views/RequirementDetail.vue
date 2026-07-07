<template>
  <section v-if="requirement" class="page">
    <div class="page-heading">
      <div>
        <h1>{{ requirement.title }}</h1>
        <p>
          <StatusTag :status="requirement.status" />
          <template v-if="requirement.doc_url">
            ·
            <a class="muted" :href="requirement.doc_url" target="_blank">{{ requirement.doc_url }}</a>
          </template>
          <span v-if="latestDoc" class="chip mono" style="margin-left: 8px">快照 v{{ latestDoc.version }}</span>
        </p>
      </div>
      <div class="toolbar">
        <el-button @click="$router.push('/requirements')">
          <el-icon><Back /></el-icon>
          返回列表
        </el-button>
        <el-button
          v-if="requirement.status !== 'closed'"
          type="danger"
          plain
          @click="closeRequirement"
        >
          <el-icon><CircleClose /></el-icon>
          关闭需求
        </el-button>
      </div>
    </div>

    <!-- 需求文档 -->
    <div class="panel">
      <div class="panel-header">
        <div>
          <div class="panel-title">需求文档</div>
          <div class="muted">进入 AI 前已按脱敏规则处理;更新文档会保存为新版本快照。</div>
        </div>
        <div class="toolbar">
          <el-select
            v-if="requirement.docs.length > 1"
            v-model="viewDocId"
            style="width: 130px"
            size="small"
          >
            <el-option
              v-for="doc in requirement.docs"
              :key="doc.id"
              :label="`快照 v${doc.version}`"
              :value="doc.id"
            />
          </el-select>
          <el-button type="primary" plain @click="docDialogVisible = true">
            <el-icon><DocumentAdd /></el-icon>
            {{ latestDoc ? '更新需求文档' : '粘贴需求文档' }}
          </el-button>
        </div>
      </div>
      <template v-if="viewingDoc">
        <div class="doc-preview-toolbar">
          <el-radio-group v-model="docPreview" size="small">
            <el-radio-button :value="true">预览</el-radio-button>
            <el-radio-button :value="false">原文</el-radio-button>
          </el-radio-group>
        </div>
        <MarkdownView v-if="docPreview" class="doc-preview" :source="viewingDoc.content" max-height="420px" />
        <CodeEditor
          v-else
          :model-value="viewingDoc.content"
          label="需求文档快照"
          language="markdown"
          readonly
          :rows="10"
        />
      </template>
      <div v-else class="empty-state">还没有需求文档,粘贴飞书文档内容开始</div>
    </div>

    <!-- AI 拆解 -->
    <div class="panel">
      <div class="panel-header">
        <div>
          <div class="panel-title">需求拆解</div>
          <div class="muted">
            AI 判断需求涉及哪些项目、各项目职责与接口约定;确认后按项目生成工单。
            <template v-if="latestBreakdown">
              当前版本 v{{ latestBreakdown.version }}({{ latestBreakdown.source === 'ai' ? 'AI 生成' : '人工编辑' }})
              <span v-if="latestBreakdown.confirmed_at" class="success-text">
                · 已于 {{ formatTime(latestBreakdown.confirmed_at) }} 确认
              </span>
            </template>
          </div>
        </div>
        <div class="toolbar">
          <el-select
            v-model="selectedProjectIds"
            multiple
            collapse-tags
            collapse-tags-tooltip
            placeholder="涉及项目(留空=AI 自动判断)"
            style="width: 260px"
            :disabled="breakdownRunning || requirement.status === 'closed'"
          >
            <el-option
              v-for="p in allProjects"
              :key="p.id"
              :label="p.name"
              :value="p.id"
            />
          </el-select>
          <ModelPicker v-model="breakdownModel" step="requirement_breakdown" />
          <el-button
            :loading="breakdownRunning"
            :disabled="!latestDoc || requirement.status === 'closed'"
            @click="generateBreakdown"
          >
            <el-icon><MagicStick /></el-icon>
            {{ latestBreakdown ? '重新拆解' : 'AI 拆解需求' }}
          </el-button>
          <template v-if="latestBreakdown && !latestBreakdown.confirmed_at">
            <el-button @click="saveBreakdown">保存编辑为新版本</el-button>
            <el-button type="success" :loading="confirming" @click="confirmBreakdown">
              <el-icon><Select /></el-icon>
              确认拆解并生成工单
            </el-button>
          </template>
        </div>
      </div>
      <AiRunPanel
        v-if="latestBreakdownRun"
        class="requirement-run-panel"
        :run="latestBreakdownRun"
        @refresh="load"
      />

      <div v-if="breakdownRunning" class="empty-state">
        AI 拆解任务已入队，可查看上方日志；完成后会自动刷新拆解结果。
      </div>
      <template v-else-if="latestBreakdown">
        <div class="split">
          <div class="breakdown-doc">
            <div class="toolbar" style="justify-content: flex-end">
              <el-radio-group v-model="breakdownPreview" size="small">
                <el-radio-button :value="true">预览</el-radio-button>
                <el-radio-button :value="false">{{ latestBreakdown.confirmed_at ? '原文' : '编辑' }}</el-radio-button>
              </el-radio-group>
            </div>
            <MarkdownView v-if="breakdownPreview" :source="breakdownEditor" max-height="520px" />
            <CodeEditor
              v-else
              v-model="breakdownEditor"
              label="拆解说明(Markdown,可编辑)"
              language="markdown"
              :rows="16"
              :readonly="!!latestBreakdown.confirmed_at"
            />
          </div>
          <div style="display: grid; gap: 10px; align-content: start">
            <div class="metric-label">项目分工</div>
            <div
              v-for="(item, index) in breakdownProjects"
              :key="index"
              class="task-card"
              style="cursor: default"
            >
              <div class="card-title">
                <span :class="{ 'danger-text': item.unmatched }">
                  {{ item.project_name }}
                  <template v-if="item.unmatched">(未匹配到已配置项目)</template>
                </span>
              </div>
              <div class="card-line">{{ item.scope_summary || '未填写职责' }}</div>
              <div v-if="item.interfaces" class="card-line">接口约定:{{ item.interfaces }}</div>
            </div>
            <div v-if="!breakdownProjects.length" class="muted">拆解结果中没有项目条目</div>
          </div>
        </div>
      </template>
      <div v-else class="empty-state">
        {{ latestDoc ? '点击"AI 拆解需求",让 AI 判断涉及的项目' : '请先录入需求文档' }}
      </div>
    </div>

    <!-- 子工单看板 -->
    <div class="panel">
      <div class="panel-header">
        <div>
          <div class="panel-title">工单({{ requirement.tasks.length }})</div>
          <div class="muted">每个项目一张工单,独立走 计划 → 编码 → Review → 提交 流程。</div>
        </div>
      </div>
      <div v-if="requirement.tasks.length" class="card-grid">
        <div
          v-for="task in requirement.tasks"
          :key="task.id"
          class="task-card"
          @click="$router.push(`/tasks/${task.id}`)"
        >
          <div class="card-title">
            <span class="chip chip--brand">{{ task.project_name }}</span>
            <StatusTag :status="task.status" />
          </div>
          <div style="font-weight: 600">{{ task.title }}</div>
          <div v-if="task.final_branch_name" class="card-line mono">{{ task.final_branch_name }}</div>
          <div class="card-line">{{ task.scope_summary || '无职责摘要' }}</div>
        </div>
      </div>
      <div v-else class="empty-state">确认拆解后将自动为每个项目生成工单</div>
    </div>

    <!-- 粘贴文档弹窗 -->
    <el-dialog v-model="docDialogVisible" title="需求文档" width="720px">
      <el-form label-position="top">
        <el-form-item label="需求文档地址(选填)">
          <el-input v-model="docForm.doc_url" placeholder="https://xxx.feishu.cn/wiki/..." />
        </el-form-item>
        <el-form-item label="文档内容(粘贴飞书文档全文)" required>
          <el-input
            v-model="docForm.content"
            type="textarea"
            :rows="14"
            placeholder="粘贴需求文档内容,保存前会自动脱敏"
          />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="docDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="savingDoc" @click="saveDoc">保存为新快照</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import StatusTag from '../components/StatusTag.vue'
import CodeEditor from '../components/CodeEditor.vue'
import AiRunPanel from '../components/AiRunPanel.vue'
import MarkdownView from '../components/MarkdownView.vue'
import ModelPicker from '../components/ModelPicker.vue'
import { api } from '../services/api'

const props = defineProps({ id: { type: String, required: true } })

const requirement = ref(null)
const allProjects = ref([])
const selectedProjectIds = ref([])
const breakdownEditor = ref('')
const breakdownModel = ref('')
const breakdownPreview = ref(true)
const docPreview = ref(true)
const viewDocId = ref(null)
const docDialogVisible = ref(false)
const savingDoc = ref(false)
const confirming = ref(false)
const docForm = reactive({ doc_url: '', content: '' })
let detailTimer = null

const latestDoc = computed(() => (requirement.value?.docs?.length ? requirement.value.docs[0] : null))
const viewingDoc = computed(() => {
  if (!requirement.value?.docs?.length) return null
  return requirement.value.docs.find((doc) => doc.id === viewDocId.value) || requirement.value.docs[0]
})
const latestBreakdown = computed(() =>
  requirement.value?.breakdowns?.length ? requirement.value.breakdowns[0] : null,
)
const breakdownProjects = computed(() => {
  if (!latestBreakdown.value?.projects_json) return []
  try {
    return JSON.parse(latestBreakdown.value.projects_json)
  } catch (error) {
    return []
  }
})
const activeBreakdownRun = computed(() =>
  requirement.value?.runs?.find((run) => ['queued', 'running'].includes(run.status)),
)
const latestBreakdownRun = computed(() =>
  activeBreakdownRun.value
  || requirement.value?.runs?.find((run) => ['failed', 'cancelled'].includes(run.status))
  || requirement.value?.runs?.find((run) => run.run_type === 'requirement_breakdown')
  || null,
)
const breakdownRunning = computed(() => !!activeBreakdownRun.value)

watch(latestBreakdown, (value) => {
  breakdownEditor.value = value ? value.content : ''
})

function formatTime(value) {
  if (!value) return '-'
  return new Date(value.replace(' ', 'T')).toLocaleString('zh-CN', { hour12: false })
}

async function load() {
  const [detail, projects] = await Promise.all([
    api.requirements.detail(props.id),
    api.projects.list(),
  ])
  requirement.value = detail
  allProjects.value = projects
  docForm.doc_url = requirement.value.doc_url
  if (latestDoc.value) viewDocId.value = latestDoc.value.id
  // 已有拆解时,回填其涉及项目,方便「重新拆解」时沿用人工范围
  const ids = breakdownProjects.value.map((item) => item.project_id).filter(Boolean)
  if (ids.length) selectedProjectIds.value = ids
  syncTimer()
}

function syncTimer() {
  if (breakdownRunning.value && !detailTimer) {
    detailTimer = setInterval(() => load().catch(() => {}), 3000)
  }
  if (!breakdownRunning.value && detailTimer) {
    clearInterval(detailTimer)
    detailTimer = null
  }
}

async function saveDoc() {
  if (!docForm.content.trim()) {
    ElMessage.warning('请粘贴文档内容')
    return
  }
  savingDoc.value = true
  try {
    await api.requirements.loadDoc(props.id, { ...docForm })
    docForm.content = ''
    docDialogVisible.value = false
    ElMessage.success('快照已保存(已脱敏)')
    await load()
  } finally {
    savingDoc.value = false
  }
}

async function generateBreakdown() {
  await api.requirements.generateBreakdown(props.id, {
    project_ids: selectedProjectIds.value,
    model: breakdownModel.value,
  })
  ElMessage.success('需求拆解任务已入队')
  await load()
}

async function saveBreakdown() {
  await api.requirements.saveBreakdown(props.id, {
    content: breakdownEditor.value,
    projects_json: breakdownProjects.value,
  })
  ElMessage.success('已保存为新版本')
  await load()
}

async function confirmBreakdown() {
  await ElMessageBox.confirm(
    `将按拆解结果为 ${breakdownProjects.value.length} 个项目生成工单,确认?`,
    '确认拆解',
    { type: 'warning' },
  )
  confirming.value = true
  try {
    const created = await api.requirements.confirmBreakdown(props.id)
    const fresh = created.filter((item) => !item.skipped).length
    ElMessage.success(`已生成 ${fresh} 张工单`)
    await load()
  } finally {
    confirming.value = false
  }
}

async function closeRequirement() {
  await ElMessageBox.confirm('关闭后需求不再推进,确认关闭?', '关闭需求', { type: 'warning' })
  await api.requirements.close(props.id)
  await load()
}

onMounted(load)
onBeforeUnmount(() => {
  if (detailTimer) clearInterval(detailTimer)
})
</script>

<style scoped>
.requirement-run-panel {
  margin-bottom: 12px;
}

.doc-preview-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 8px;
}

.doc-preview :deep(.md-view),
.breakdown-doc {
  min-width: 0;
}

.breakdown-doc {
  display: grid;
  gap: 8px;
  align-content: start;
}

.doc-preview,
.breakdown-doc :deep(.md-view) {
  padding: 12px;
  border: 1px solid var(--line-soft);
  border-radius: 6px;
  background: var(--page-bg);
}
</style>
