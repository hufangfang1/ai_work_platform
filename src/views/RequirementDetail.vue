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

    <el-tabs v-model="activeRequirementTab" class="requirement-tabs" stretch>
      <el-tab-pane
        v-for="(item, index) in requirementProgress"
        :key="item.target"
        :name="item.target"
      >
        <template #label>
          <span class="requirement-tab-label" :class="`is-${item.state}`">
            <span class="requirement-tab-label__index">{{ index + 1 }}</span>
            <span class="requirement-tab-label__text">
              <strong>{{ item.label }}</strong>
              <small>{{ item.hint }}</small>
            </span>
          </span>
        </template>
      </el-tab-pane>
    </el-tabs>

    <!-- 需求文档 -->
    <div v-show="activeRequirementTab === 'requirement-doc'" id="requirement-doc" class="panel requirement-section">
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
          <el-button v-if="viewingDoc" plain @click="docExpanded = !docExpanded">
            {{ docExpanded ? '收起正文' : '展开正文' }}
          </el-button>
        </div>
      </div>
      <template v-if="docExpanded && viewingDoc">
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
      <div v-else-if="!viewingDoc" class="empty-state">还没有需求文档,粘贴飞书文档内容开始</div>
      <div v-else class="collapsed-summary">正文已收起，需要核对原始需求时再展开。</div>
    </div>

    <!-- AI 拆解 -->
    <div v-show="activeRequirementTab === 'requirement-breakdown'" id="requirement-breakdown" class="panel breakdown-panel requirement-section">
      <div class="panel-header breakdown-header">
        <div>
          <div class="panel-title">需求拆解</div>
          <div class="muted">
            AI 判断需求涉及哪些项目、各项目职责与接口约定；确认后按项目生成开发任务。
            <template v-if="latestBreakdown">
              当前版本 v{{ latestBreakdown.version }}({{ latestBreakdown.source === 'ai' ? 'AI 生成' : '人工编辑' }})
              <span v-if="latestBreakdown.confirmed_at" class="success-text">
                · 已于 {{ formatTime(latestBreakdown.confirmed_at) }} 确认
              </span>
            </template>
          </div>
        </div>
      </div>
      <div class="breakdown-actions">
        <div class="breakdown-generate-actions">
          <el-select
            v-model="selectedProjectIds"
            class="breakdown-project-select"
            multiple
            collapse-tags
            collapse-tags-tooltip
            placeholder="涉及项目(留空=AI 自动判断)"
            :disabled="breakdownRunning || requirement.status === 'closed'"
          >
            <el-option
              v-for="p in allProjects"
              :key="p.id"
              :label="p.name"
              :value="p.id"
            />
          </el-select>
          <ModelPicker
            v-model="breakdownModel"
            class="breakdown-model-picker"
            step="requirement_breakdown"
          />
          <el-button
            :loading="breakdownRunning"
            :disabled="!latestDoc || requirement.status === 'closed'"
            @click="generateBreakdown"
          >
            <el-icon><MagicStick /></el-icon>
            {{ latestBreakdownRun?.status === 'draft' ? '执行当前草稿' : (latestBreakdown ? '重新拆解' : 'AI 拆解需求') }}
          </el-button>
          <el-button
            plain
            :disabled="!latestDoc || requirement.status === 'closed'"
            @click="generateBreakdown(true)"
          >
            编辑提示语
          </el-button>
        </div>
        <div v-if="latestBreakdown && !latestBreakdown.confirmed_at" class="breakdown-version-actions">
          <el-button @click="saveBreakdown">保存编辑为新版本</el-button>
          <el-button type="success" :loading="confirming" @click="confirmBreakdown">
            <el-icon><Select /></el-icon>
            确认拆解并生成任务
          </el-button>
        </div>
      </div>
      <AiRunPanel
        v-if="latestBreakdownRun"
        ref="breakdownRunPanel"
        class="requirement-run-panel"
        :run="latestBreakdownRun"
        :retry-model="breakdownModel"
        hide-draft-open-button
        @refresh="load"
      />

      <div v-if="breakdownRunning" class="empty-state">
        AI 拆解任务已入队，可查看上方日志；完成后会自动刷新拆解结果。
      </div>
      <template v-else-if="latestBreakdown">
        <div class="breakdown-workspace">
          <div class="breakdown-doc">
            <div class="breakdown-section-head">
              <div class="metric-label">拆解内容</div>
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
          <div class="breakdown-projects">
            <div class="metric-label">项目分工</div>
            <div
              v-for="(item, index) in breakdownProjects"
              :key="index"
              class="task-card"
            >
              <div class="card-title">
                <span :class="{ 'danger-text': item.unmatched }">
                  {{ item.project_name }}
                  <template v-if="item.unmatched">(未匹配到已配置项目)</template>
                </span>
              </div>
              <div class="card-line">{{ item.scope_summary || '未填写职责' }}</div>
              <div v-if="item.interfaces" class="card-line">接口约定:{{ item.interfaces }}</div>
              <div v-if="item.depends_on_projects?.length" class="dependency-line">
                依赖:
                <span v-for="name in item.depends_on_projects" :key="name">{{ name }}</span>
              </div>
              <div v-if="item.dependency_reason" class="card-line">依赖说明:{{ item.dependency_reason }}</div>
              <el-collapse v-if="item.spec_markdown" class="embedded-spec" @click.stop>
                <el-collapse-item title="本项目需求文档">
                  <MarkdownView :source="item.spec_markdown" max-height="300px" />
                </el-collapse-item>
              </el-collapse>
            </div>
            <div v-if="!breakdownProjects.length" class="muted">拆解结果中没有项目条目</div>
          </div>
        </div>
      </template>
      <div v-else class="empty-state">
        {{ latestDoc ? '点击"AI 拆解需求",让 AI 判断涉及的项目' : '请先录入需求文档' }}
      </div>

      <!-- 开发任务是确认拆解后的产物，和拆解结果放在同一页 -->
      <div class="breakdown-tasks">
        <div class="breakdown-tasks__header">
        <div>
            <div class="panel-title">开发任务（{{ requirement.tasks.length }}）</div>
            <div class="muted">确认拆解后按项目生成；点击任务进入开发计划 → 代码实现 → 代码审查 → 代码提交流程。</div>
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
            <div v-if="task.dependencies?.length" class="dependency-line">
              依赖:
              <span
                v-for="dependency in task.dependencies"
                :key="dependency.project_id"
                :class="dependency.ready ? 'success-text' : 'danger-text'"
              >
                {{ dependency.project_name || `project#${dependency.project_id}` }}({{ dependency.ready ? '已完成' : '未完成' }})
              </span>
            </div>
            <div v-if="task.dependents?.length" class="dependency-line">
              被依赖:
              <span v-for="dependent in task.dependents" :key="dependent.project_id">
                {{ dependent.project_name || `project#${dependent.project_id}` }}
              </span>
            </div>
            <div class="task-card-spec" @click.stop>
              <el-collapse v-if="task.spec_markdown" class="embedded-spec">
                <el-collapse-item title="本项目需求文档">
                  <MarkdownView :source="task.spec_markdown" max-height="340px" />
                </el-collapse-item>
              </el-collapse>
              <div v-else class="task-card-spec__empty">
                本项目需求文档生成中或尚未生成，可进入任务页单独生成。
              </div>
            </div>
          </div>
        </div>
        <div v-else class="empty-state">确认拆解后将自动为每个项目生成开发任务</div>
      </div>
    </div>

    <!-- 需求级项目复盘 -->
    <div v-show="activeRequirementTab === 'requirement-retrospective'" id="requirement-retrospective" class="panel retrospective-panel requirement-section">
      <div class="panel-header">
        <div>
          <div class="panel-title">项目复盘</div>
          <div class="muted">
            在本需求全部项目提交后，按项目汇总执行失败、Review 问题、验证结果和后续优化；可人工补充跨项目协作问题。
          </div>
        </div>
        <div class="toolbar">
          <el-button
            :loading="retroGenerating"
            :disabled="!canGenerateRetrospective"
            @click="generateRetrospective"
          >
            {{ requirement.retrospective ? '重新生成' : '生成项目复盘' }}
          </el-button>
          <el-button
            type="primary"
            :loading="retroSaving"
            :disabled="!retroEditor.trim()"
            @click="saveRetrospective"
          >
            保存复盘
          </el-button>
        </div>
      </div>
      <el-alert
        v-if="unfinishedRetrospectiveProjects.length"
        type="warning"
        :closable="false"
        show-icon
        :title="`需等待以下项目提交：${unfinishedRetrospectiveProjects.join('、')}`"
      />
      <template v-if="retroEditor">
        <div class="retro-view-toolbar">
          <el-radio-group v-model="retroPreview" size="small">
            <el-radio-button :value="true">预览</el-radio-button>
            <el-radio-button :value="false">编辑</el-radio-button>
          </el-radio-group>
        </div>
        <MarkdownView v-if="retroPreview" :source="retroEditor" max-height="620px" />
        <CodeEditor v-else v-model="retroEditor" label="需求项目复盘(Markdown)" language="markdown" :rows="22" />
      </template>
      <div v-else class="empty-state">
        {{ requirement.tasks.length ? '所有项目提交后可生成本次需求的项目复盘' : '确认拆解并生成开发任务后，才能形成复盘' }}
      </div>
    </div>

    <!-- 需求分支 -->
    <div v-show="activeRequirementTab === 'requirement-branch'" id="requirement-branch" class="panel requirement-section">
      <div class="panel-header">
        <div>
          <div class="panel-title">需求分支</div>
          <div class="muted">开发任务生成后维护；同一需求下的所有开发任务复用这一条分支名。</div>
        </div>
      </div>
      <div class="branch-toolbar">
        <ModelPicker v-model="branchModel" class="branch-model-picker" step="branch_name" />
        <el-button
          :loading="branchRunning"
          :disabled="!canManageBranch || requirement.status === 'closed' || branchRunning"
          @click="generateBranch"
        >
          <el-icon><MagicStick /></el-icon>
          {{ latestBranchRun?.status === 'draft' ? '执行当前草稿' : (requirement.final_branch_name ? '重新生成分支名' : 'AI 生成分支名') }}
        </el-button>
        <el-button
          plain
          :disabled="!canManageBranch || requirement.status === 'closed' || branchRunning"
          @click="generateBranch(true)"
        >
          编辑提示语
        </el-button>
        <el-input
          v-model="branchEditor"
          class="mono branch-input"
          placeholder="future/xxx"
          :disabled="!canManageBranch || requirement.status === 'closed'"
        />
        <el-button
          type="primary"
          plain
          :disabled="!canManageBranch || !branchEditor || requirement.status === 'closed'"
          @click="saveBranch"
        >
          保存同步任务
        </el-button>
        <el-button :disabled="!canManageBranch || !branchEditor" @click="checkBranch">校验</el-button>
      </div>
      <div v-if="!canManageBranch" class="muted branch-check">
        请先确认需求拆解并生成开发任务，再生成需求分支。
      </div>
      <div v-if="branchCheck" class="branch-check">
        <span :class="branchCheck.valid ? 'success-text' : 'danger-text'">{{ branchCheck.message }}</span>
        <span v-if="branchCheck.projects?.length" class="muted">
          · {{ branchCheck.projects.map((item) => `${item.project_name}:${item.message}`).join('；') }}
        </span>
      </div>
      <AiRunPanel
        v-if="latestBranchRun"
        ref="branchRunPanel"
        class="requirement-run-panel"
        :run="latestBranchRun"
        :retry-model="branchModel"
        hide-draft-open-button
        @refresh="load"
      />
      <div v-if="branchRunning" class="empty-state">
        AI 分支名生成任务已入队，可查看日志；完成后会自动同步到该需求下的开发任务。
      </div>
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
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
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
const breakdownRunPanel = ref(null)
const breakdownModel = ref('')
const branchModel = ref('')
const branchEditor = ref('')
const branchCheck = ref(null)
const branchRunPanel = ref(null)
const breakdownPreview = ref(true)
const docPreview = ref(true)
const docExpanded = ref(true)
const activeRequirementTab = ref('requirement-doc')
const viewDocId = ref(null)
const docDialogVisible = ref(false)
const savingDoc = ref(false)
const confirming = ref(false)
const retroEditor = ref('')
const retroPreview = ref(true)
const retroProjectSummaries = ref([])
const retroGenerating = ref(false)
const retroSaving = ref(false)
const docForm = reactive({ doc_url: '', content: '' })
let detailTimer = null
let tabInitialized = false

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
// 按 id 倒序,最新一次拆解运行永远排在最前
const breakdownRuns = computed(() =>
  (requirement.value?.runs || [])
    .filter((run) => run.run_type === 'requirement_breakdown')
    .slice()
    .sort((a, b) => (b.id || 0) - (a.id || 0)),
)
const activeBreakdownRun = computed(() =>
  breakdownRuns.value.find((run) => ['queued', 'running'].includes(run.status)),
)
// 始终展示最新一次运行,而不是永远停在某条历史失败记录上
const latestBreakdownRun = computed(() => breakdownRuns.value[0] || null)
const breakdownRunning = computed(() => !!activeBreakdownRun.value)
const branchRuns = computed(() =>
  (requirement.value?.runs || [])
    .filter((run) => run.run_type === 'branch_name')
    .slice()
    .sort((a, b) => (b.id || 0) - (a.id || 0)),
)
const activeBranchRun = computed(() =>
  branchRuns.value.find((run) => ['queued', 'running'].includes(run.status)),
)
const latestBranchRun = computed(() => branchRuns.value[0] || null)
const branchRunning = computed(() => !!activeBranchRun.value)
const canManageBranch = computed(() =>
  (requirement.value?.tasks || []).some((task) => task.status !== 'terminated'),
)
const unfinishedRetrospectiveProjects = computed(() =>
  (requirement.value?.tasks || [])
    .filter((task) => task.status !== 'terminated' && !['committed', 'retrospected'].includes(task.status))
    .map((task) => `${task.project_name || `project#${task.project_id}`}（${task.status}）`),
)
const canGenerateRetrospective = computed(() =>
  (requirement.value?.tasks || []).some((task) => ['committed', 'retrospected'].includes(task.status))
    && unfinishedRetrospectiveProjects.value.length === 0,
)
const activeRequirementTasks = computed(() =>
  (requirement.value?.tasks || []).filter((task) => task.status !== 'terminated'),
)
const completedTaskCount = computed(() =>
  activeRequirementTasks.value.filter((task) => ['committed', 'retrospected'].includes(task.status)).length,
)
const requirementProgress = computed(() => {
  const hasDoc = Boolean(latestDoc.value)
  const hasConfirmedBreakdown = Boolean(latestBreakdown.value?.confirmed_at)
  const hasBranch = Boolean(requirement.value?.final_branch_name)
  const taskTotal = activeRequirementTasks.value.length
  const tasksDone = taskTotal > 0 && completedTaskCount.value === taskTotal
  const hasRetro = Boolean(requirement.value?.retrospective)
  const reached = [hasDoc, hasConfirmedBreakdown, hasBranch, hasRetro]
  const current = Math.max(0, reached.findIndex((done) => !done))
  const state = (index) => (reached[index] ? 'done' : index === current ? 'current' : 'pending')
  return [
    { label: '需求文档', hint: hasDoc ? `快照 v${latestDoc.value.version}` : '待录入', target: 'requirement-doc', state: state(0) },
    {
      label: '需求拆解',
      hint: hasConfirmedBreakdown ? `已确认 · ${taskTotal} 个任务` : '待确认',
      target: 'requirement-breakdown',
      state: state(1),
    },
    { label: '需求分支', hint: hasBranch ? requirement.value.final_branch_name : '待生成', target: 'requirement-branch', state: state(2) },
    {
      label: '项目复盘',
      hint: hasRetro ? '已保存' : tasksDone ? '待复盘' : `等待项目 ${completedTaskCount.value}/${taskTotal}`,
      target: 'requirement-retrospective',
      state: state(3),
    },
  ]
})

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
  branchEditor.value = requirement.value.final_branch_name || ''
  if (latestDoc.value) viewDocId.value = latestDoc.value.id
  if (!tabInitialized) {
    const current = requirementProgress.value.find((item) => item.state === 'current')
    activeRequirementTab.value = current?.target || 'requirement-retrospective'
    tabInitialized = true
  }
  // 已有拆解时,回填其涉及项目,方便「重新拆解」时沿用人工范围
  const ids = breakdownProjects.value.map((item) => item.project_id).filter(Boolean)
  if (ids.length) selectedProjectIds.value = ids
  if (requirement.value.retrospective && !retroEditor.value) {
    retroEditor.value = requirement.value.retrospective.content || ''
    retroProjectSummaries.value = requirement.value.retrospective.project_summaries || []
  }
  syncTimer()
}

function syncTimer() {
  if ((breakdownRunning.value || branchRunning.value) && !detailTimer) {
    detailTimer = setInterval(() => load().catch(() => {}), 3000)
  }
  if (!breakdownRunning.value && !branchRunning.value && detailTimer) {
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

async function generateBreakdown(draft = false) {
  if (draft === true && latestBreakdownRun.value?.status === 'draft') {
    breakdownRunPanel.value?.open()
    return
  }
  if (draft !== true && latestBreakdownRun.value?.status === 'draft') {
    await api.runs.execute(latestBreakdownRun.value.id)
    await load()
    ElMessage.success('已按当前提示语草稿启动需求拆解')
    return
  }
  await api.requirements.generateBreakdown(props.id, {
    project_ids: selectedProjectIds.value,
    model: breakdownModel.value,
    draft: draft === true ? 1 : 0,
  })
  await load()
  if (draft === true) {
    await nextTick()
    breakdownRunPanel.value?.open()
    return
  }
  ElMessage.success('需求拆解任务已入队')
}

async function generateBranch(draft = false) {
  if (draft === true && latestBranchRun.value?.status === 'draft') {
    branchRunPanel.value?.open()
    return
  }
  if (draft !== true && latestBranchRun.value?.status === 'draft') {
    await api.runs.execute(latestBranchRun.value.id)
    await load()
    ElMessage.success('已按当前提示语草稿启动分支名生成')
    return
  }
  await api.requirements.generateBranch(props.id, branchModel.value, draft === true)
  branchCheck.value = null
  await load()
  if (draft === true) {
    await nextTick()
    branchRunPanel.value?.open()
    return
  }
  ElMessage.success('需求分支名生成任务已入队')
}

async function saveBranch() {
  const name = branchEditor.value.trim()
  await api.requirements.saveBranch(props.id, name)
  branchCheck.value = await api.requirements.checkBranch(props.id, name)
  ElMessage.success('需求分支已同步到相关开发任务')
  await load()
}

async function checkBranch() {
  branchCheck.value = await api.requirements.checkBranch(props.id, branchEditor.value.trim())
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
    `将按拆解结果为 ${breakdownProjects.value.length} 个项目生成开发任务，确认？`,
    '确认拆解',
    { type: 'warning' },
  )
  confirming.value = true
  try {
    const created = await api.requirements.confirmBreakdown(props.id)
    const fresh = created.filter((item) => !item.skipped).length
    ElMessage.success(`已生成 ${fresh} 个开发任务`)
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

async function generateRetrospective() {
  retroGenerating.value = true
  try {
    const data = await api.requirements.retrospect(props.id)
    retroEditor.value = data.content
    retroProjectSummaries.value = data.project_summaries || []
    retroPreview.value = true
    ElMessage.success('已按项目汇总本次需求的实际执行记录')
  } finally {
    retroGenerating.value = false
  }
}

async function saveRetrospective() {
  retroSaving.value = true
  try {
    await api.requirements.saveRetrospective(
      props.id,
      retroEditor.value,
      retroProjectSummaries.value,
    )
    await load()
    ElMessage.success('需求项目复盘已保存')
  } finally {
    retroSaving.value = false
  }
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

.requirement-tabs {
  min-width: 0;
  padding: 0 14px;
  border: 1px solid var(--line-soft);
  border-radius: var(--panel-radius);
  background: var(--surface);
}

.requirement-tabs :deep(.el-tabs__header) {
  margin: 0;
}

.requirement-tabs :deep(.el-tabs__nav-wrap::after) {
  display: none;
}

.requirement-tabs :deep(.el-tabs__item) {
  height: 60px;
  padding: 0 8px;
}

.requirement-tabs :deep(.el-tabs__content) {
  display: none;
}

.requirement-tab-label {
  min-width: 0;
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  color: var(--text-muted);
}

.requirement-tab-label__index {
  width: 24px;
  height: 24px;
  flex: none;
  display: grid;
  place-items: center;
  border: 1px solid var(--line);
  border-radius: 50%;
  font: 700 12px var(--mono);
}

.requirement-tab-label__text {
  min-width: 0;
  text-align: left;
}

.requirement-tab-label strong,
.requirement-tab-label small {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.requirement-tab-label strong {
  color: inherit;
  font-size: 13px;
}

.requirement-tab-label small {
  margin-top: 2px;
  font-size: 11px;
}

.requirement-tab-label.is-done .requirement-tab-label__index {
  border-color: var(--success);
  color: var(--success);
}

.requirement-tab-label.is-current .requirement-tab-label__index {
  border-color: var(--brand);
  color: var(--brand);
}

.collapsed-summary {
  padding: 14px;
  border: 1px dashed var(--line);
  border-radius: 6px;
  color: var(--text-muted);
  text-align: center;
}

.branch-toolbar {
  display: grid;
  grid-template-columns: minmax(180px, 220px) max-content minmax(260px, 1fr) max-content max-content;
  gap: 10px;
  align-items: center;
}

.branch-model-picker,
.branch-input {
  width: 100%;
}

.branch-check {
  margin-top: 10px;
  font-size: 13px;
}

.doc-preview-toolbar {
  display: flex;
  justify-content: flex-end;
  margin-bottom: 8px;
}

.retrospective-panel {
  display: grid;
  gap: 12px;
}

.retro-view-toolbar {
  display: flex;
  justify-content: flex-end;
}

.breakdown-panel {
  display: grid;
  gap: 14px;
}

.breakdown-tasks {
  min-width: 0;
  display: grid;
  gap: 12px;
  padding-top: 18px;
  border-top: 1px solid var(--line-soft);
}

.breakdown-tasks__header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
}

.breakdown-tasks .card-grid {
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
}

.breakdown-header {
  align-items: flex-start;
  margin-bottom: 0;
}

.breakdown-actions {
  min-width: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr) max-content;
  gap: 10px;
  align-items: end;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--line-soft);
}

.breakdown-generate-actions {
  min-width: 0;
  display: grid;
  grid-template-columns: minmax(240px, 1fr) minmax(180px, 220px) max-content max-content;
  gap: 10px;
  align-items: center;
}

.breakdown-project-select,
.breakdown-model-picker {
  width: 100%;
}

.breakdown-version-actions {
  min-width: 0;
  display: flex;
  flex-wrap: nowrap;
  justify-content: flex-end;
  gap: 10px;
}

.breakdown-actions :deep(.el-button + .el-button) {
  margin-left: 0;
}

.breakdown-actions :deep(.el-button) {
  min-width: max-content;
}

.breakdown-workspace {
  min-width: 0;
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
  align-items: start;
}

.breakdown-section-head {
  min-height: 32px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.breakdown-projects {
  min-width: 0;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  align-content: start;
}

.breakdown-projects > .metric-label,
.breakdown-projects > .muted {
  grid-column: 1 / -1;
}

.breakdown-projects .task-card,
.breakdown-projects .task-card:hover {
  cursor: default;
}

.breakdown-projects .task-card:hover {
  border-color: var(--line-soft);
  transform: none;
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

.task-card-spec {
  min-width: 0;
  margin-top: 2px;
}

.task-card-spec__empty {
  padding-top: 8px;
  border-top: 1px solid var(--line-soft);
  color: var(--text-muted);
  font-size: 12.5px;
  line-height: 1.6;
}

.dependency-line {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  color: var(--text-muted);
  font-size: 12.5px;
  line-height: 1.6;
}

.embedded-spec {
  --el-collapse-header-height: 34px;
  border-top: 1px solid var(--line-soft);
  border-bottom: 0;
}

.embedded-spec :deep(.el-collapse-item__header) {
  background: transparent;
  border-bottom-color: var(--line-soft);
  color: var(--text);
  font-size: 12.5px;
  font-weight: 600;
}

.embedded-spec :deep(.el-collapse-item__wrap) {
  background: transparent;
  border-bottom: 0;
}

.embedded-spec :deep(.el-collapse-item__content) {
  padding-bottom: 0;
}

.embedded-spec :deep(.md-view) {
  padding: 10px 12px;
  border: 1px solid var(--line-soft);
  border-radius: 6px;
  background: var(--page-bg);
}

@media (max-width: 1100px) {
  .requirement-tabs :deep(.el-tabs__item) {
    min-width: 145px;
  }

  .breakdown-actions {
    grid-template-columns: 1fr;
  }

  .branch-toolbar {
    grid-template-columns: minmax(180px, 220px) minmax(260px, 1fr);
  }

  .branch-toolbar > .el-button {
    justify-self: start;
  }

  .breakdown-generate-actions {
    grid-template-columns: minmax(220px, 1fr) minmax(180px, 220px);
  }

  .breakdown-generate-actions > .el-button {
    justify-self: start;
  }

  .breakdown-version-actions {
    justify-content: flex-start;
  }

  .breakdown-workspace {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .branch-toolbar {
    grid-template-columns: 1fr;
  }

  .branch-toolbar :deep(.el-button) {
    width: 100%;
    min-width: 0;
    justify-content: center;
  }

  .breakdown-generate-actions {
    grid-template-columns: 1fr;
  }

  .breakdown-projects {
    grid-template-columns: 1fr;
  }

  .breakdown-section-head,
  .breakdown-version-actions {
    align-items: stretch;
    flex-direction: column;
  }

  .breakdown-actions :deep(.el-button) {
    width: 100%;
    min-width: 0;
    justify-content: center;
  }
}
</style>
