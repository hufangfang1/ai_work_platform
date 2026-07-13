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
              {{ latestPlan ? `计划 v${latestPlan.version}` : '使用需求分支生成计划,人工确认后执行' }}
            </span>
          </div>
          <div v-if="isOpen(1)" class="flow-step__body">
            <div class="branch-inherited">
              <div>
                <div class="metric-label">需求分支</div>
                <div class="mono">{{ task.final_branch_name || '未生成' }}</div>
              </div>
              <el-button v-if="task.requirement" plain @click="$router.push(`/requirements/${task.requirement.id}`)">
                到需求页维护
              </el-button>
            </div>
            <el-divider style="margin: 4px 0" />
            <div class="toolbar">
              <ModelPicker v-model="planModel" step="task_plan" />
              <el-button
                :loading="planning || planRunning"
                :disabled="task.status === 'created' || planLocked || planRunning"
                @click="generatePlan"
              >
                <el-icon><MagicStick /></el-icon>
                {{ latestPlan ? '重新生成计划' : 'AI 生成开发计划' }}
              </el-button>
              <el-button
                plain
                :disabled="task.status === 'created' || planLocked || planRunning"
                @click="generatePlan(true)"
              >
                编辑提示语
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
            <AiRunPanel
              v-if="latestPlanRun"
              style="margin: 8px 0"
              :run="latestPlanRun"
              @refresh="load"
            />
            <div v-if="planRunning" class="empty-state">AI 计划生成任务已入队，可查看上方日志；完成后会自动刷新。</div>
            <template v-else-if="latestPlan">
              <div class="toolbar" style="justify-content: flex-end">
                <el-radio-group v-model="planPreview" size="small">
                  <el-radio-button :value="true">预览</el-radio-button>
                  <el-radio-button :value="false">{{ planLocked ? '原文' : '编辑' }}</el-radio-button>
                </el-radio-group>
              </div>
              <MarkdownView v-if="planPreview" :source="planEditor" max-height="480px" />
              <CodeEditor
                v-else
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
            <span class="flow-step__hint">{{ runHint }}</span>
          </div>
          <div v-if="isOpen(2)" class="flow-step__body">
            <el-alert
              v-if="task.dependency_blocked"
              type="warning"
              :closable="false"
              title="上游依赖工单 AI Review 未通过，暂不能开始 AI 修改"
            >
              <template #default>
                <div class="dependency-list">
                  <span
                    v-for="dependency in task.dependencies"
                    :key="dependency.project_id"
                    :class="dependency.ready ? 'success-text' : 'danger-text'"
                  >
                    {{ dependency.project_name || `project#${dependency.project_id}` }}：{{ dependency.ready ? '已完成' : getStatusMeta(dependency.status).label }}
                  </span>
                </div>
                <div v-if="task.dependency_reason" class="muted">{{ task.dependency_reason }}</div>
              </template>
            </el-alert>
            <div class="toolbar">
              <ModelPicker v-model="codeModel" step="coding" />
              <el-button
                type="primary"
                :disabled="!['plan_confirmed', 'failed'].includes(task.status) || task.dependency_blocked"
                @click="execute"
              >
                <el-icon><VideoPlay /></el-icon>
                开始 AI 修改
              </el-button>
              <el-button
                plain
                :disabled="!['plan_confirmed', 'failed'].includes(task.status) || task.dependency_blocked"
                @click="execute(true)"
              >
                编辑提示语
              </el-button>
            </div>
            <AiRunPanel v-if="latestCodeRun" :run="latestCodeRun" @refresh="load" @retried="load" />
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

        <!-- Step 3 Review -->
        <div class="flow-step" :class="stepClass(3)">
          <div class="flow-step__head" @click="toggleStep(3)">
            <span class="flow-step__index">3</span>
            <span class="flow-step__title">Review</span>
            <span class="flow-step__hint">
              <template v-if="latestReview">结论:{{ latestReview.status }}</template>
              <template v-else-if="latestChange">{{ changedFiles.length }} 个文件变更</template>
              <template v-else>检查与结论</template>
            </span>
          </div>
          <div v-if="isOpen(3)" class="flow-step__body">
            <template v-if="latestChange">
              <el-collapse>
                <el-collapse-item :title="`变更文件(${changedFiles.length})`">
                  <div v-for="file in changedFiles" :key="file" class="mono" style="padding: 2px 0">
                    {{ file }}
                  </div>
                </el-collapse-item>
              </el-collapse>
              <div class="diff-entry">
                <div>
                  <div class="metric-label">git diff</div>
                  <div class="muted">完整代码差异已收起到弹框中查看</div>
                </div>
                <el-button
                  type="primary"
                  plain
                  :disabled="!latestChange.git_diff_snapshot"
                  @click="diffDialogVisible = true"
                >
                  <el-icon><Files /></el-icon>
                  查看 git diff
                </el-button>
              </div>
            </template>
            <div v-else class="muted">AI 执行完成后展示改动</div>
            <div class="toolbar">
              <el-button
                type="primary"
                :loading="reviewing"
                :disabled="!['code_changed', 'review_failed'].includes(task.status)"
                @click="review"
              >
                运行自动检查
              </el-button>
              <ModelPicker v-model="reviewModel" step="ai_review" />
              <el-button
                :loading="aiReviewing || aiReviewRunning"
                :disabled="aiReviewRunning || !['code_changed', 'review_passed', 'review_failed'].includes(task.status)"
                @click="aiReview"
              >
                AI 只读 Review
              </el-button>
              <el-button
                plain
                :disabled="aiReviewRunning || !['code_changed', 'review_passed', 'review_failed'].includes(task.status)"
                @click="aiReview(true)"
              >
                编辑提示语
              </el-button>
            </div>
            <AiRunPanel
              v-if="latestAiReviewRun"
              style="margin-bottom: 8px"
              :run="latestAiReviewRun"
              @refresh="load"
            />
            <template v-if="reviewResult">
              <div class="metric-row" style="grid-template-columns: repeat(2, 1fr)">
                <div class="metric">
                  <div class="metric-label">结论</div>
                  <div class="metric-value" :class="latestReview.status === 'fail' ? 'danger-text' : 'success-text'">
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
            <div
              v-if="['review_passed', 'ready_to_commit'].includes(task.status)"
              style="display: grid; gap: 8px"
            >
              <el-alert
                :closable="false"
                :type="task.status === 'review_passed' ? 'warning' : 'success'"
                :title="task.status === 'review_passed'
                  ? (hasReviewFixables
                    ? '自动检查通过,但 Review 仍有待处理问题,可直接继续修改或人工确认后驳回'
                    : '自动检查通过,请打开 git diff 核对后确认放行或驳回')
                  : '人工 Review 已通过,可在下方提交;如需反悔可填写意见驳回'"
              />
              <el-input
                v-model="rejectFeedback"
                type="textarea"
                :rows="3"
                placeholder="驳回时必填:说明需要修改的问题,驳回后可让 AI 按此意见继续修改"
              />
              <div class="toolbar">
                <el-button
                  v-if="task.status === 'review_passed'"
                  type="primary"
                  :loading="approving"
                  @click="approveReview"
                >
                  人工 Review 通过
                </el-button>
                <el-button type="danger" plain :disabled="!rejectFeedback.trim()" @click="rejectReview">
                  驳回
                </el-button>
              </div>
            </div>
            <div v-if="showFixPanel" style="display: grid; gap: 8px">
              <el-input
                v-model="fixFeedback"
                type="textarea"
                :rows="3"
                placeholder="补充修改要求,AI 将基于 Review 反馈继续修改"
              />
              <div class="toolbar">
                <ModelPicker v-model="fixModel" step="fix" />
                <el-button type="warning" :disabled="!canStartFix" @click="fix">
                  继续修改(fix 轮次)
                </el-button>
                <el-button plain :disabled="!canStartFix" @click="fix(true)">
                  编辑提示语
                </el-button>
              </div>
            </div>
            <AiRunPanel
              v-if="latestFixRun"
              style="margin-top: 8px"
              :run="latestFixRun"
              @refresh="load"
              @retried="load"
            />
          </div>
        </div>

        <!-- Step 4 提交 -->
        <div class="flow-step" :class="stepClass(4)">
          <div class="flow-step__head" @click="toggleStep(4)">
            <span class="flow-step__index">4</span>
            <span class="flow-step__title">提交</span>
            <span class="flow-step__hint">
              <span v-if="task.commit_hash" class="mono">{{ task.commit_hash.slice(0, 10) }}</span>
              <template v-else>人工确认 git commit</template>
            </span>
          </div>
          <div v-if="isOpen(4)" class="flow-step__body">
            <div class="toolbar">
              <ModelPicker v-model="commitModel" step="commit_message" />
              <el-button
                :loading="commitMessageRunning"
                :disabled="task.status !== 'ready_to_commit' || commitMessageRunning"
                @click="generateCommitMessage"
              >
                生成 commit message
              </el-button>
              <el-button
                plain
                :disabled="task.status !== 'ready_to_commit' || commitMessageRunning"
                @click="generateCommitMessage(true)"
              >
                编辑提示语
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
            <AiRunPanel
              v-if="latestCommitMessageRun"
              style="margin-bottom: 8px"
              :run="latestCommitMessageRun"
              @refresh="load"
            />
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

        <!-- Step 5 复盘 -->
        <div class="flow-step" :class="stepClass(5)">
          <div class="flow-step__head" @click="toggleStep(5)">
            <span class="flow-step__index">5</span>
            <span class="flow-step__title">复盘</span>
            <span class="flow-step__hint">{{ task.retrospective ? '已生成' : '沉淀本次交付' }}</span>
          </div>
          <div v-if="isOpen(5)" class="flow-step__body">
            <div class="toolbar">
              <el-button :disabled="task.status !== 'committed' && !task.retrospective" @click="generateRetro">
                生成复盘
              </el-button>
              <el-button type="primary" :disabled="!retroEditor.trim()" @click="saveRetro">保存复盘</el-button>
            </div>
            <div class="toolbar" style="justify-content: flex-end">
              <el-radio-group v-model="retroPreview" size="small">
                <el-radio-button :value="true">预览</el-radio-button>
                <el-radio-button :value="false">编辑</el-radio-button>
              </el-radio-group>
            </div>
            <MarkdownView v-if="retroPreview" :source="retroEditor" max-height="420px" />
            <CodeEditor v-else v-model="retroEditor" label="复盘(Markdown)" language="markdown" :rows="14" />
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
            <div class="metric-label">Worktree</div>
            <div v-if="task.worktree?.exists" class="worktree-box">
              <div class="mono">{{ task.worktree.path }}</div>
              <div class="muted">
                {{ task.worktree.branch || '-' }}
                <span v-if="task.worktree.head"> @ {{ task.worktree.head }}</span>
                <span v-if="task.worktree.dirty" class="danger-text"> · 有未提交改动</span>
              </div>
              <el-button size="small" plain :loading="cleaningWorktree" @click="cleanupWorktree">
                清理 worktree
              </el-button>
            </div>
            <div v-else class="muted">未占用</div>
          </div>
          <div class="side-item">
            <div class="metric-label">本项目职责</div>
            <div style="font-size: 13px">{{ task.scope_summary || '未填写' }}</div>
          </div>
          <div v-if="task.dependencies?.length || task.dependents?.length" class="side-item">
            <div class="metric-label">项目依赖</div>
            <div v-if="task.dependencies?.length" class="dependency-list">
              <span
                v-for="dependency in task.dependencies"
                :key="dependency.project_id"
                :class="dependency.ready ? 'success-text' : 'danger-text'"
              >
                依赖 {{ dependency.project_name || `project#${dependency.project_id}` }}：{{ dependency.ready ? '已完成' : getStatusMeta(dependency.status).label }}
              </span>
            </div>
            <div v-if="task.dependents?.length" class="dependency-list">
              <span v-for="dependent in task.dependents" :key="dependent.project_id">
                被 {{ dependent.project_name || `project#${dependent.project_id}` }} 依赖
              </span>
            </div>
          </div>
          <div v-if="task.has_multi_project_breakdown || task.spec_markdown || specRunning" class="side-item">
            <div class="side-item__head">
              <div class="metric-label">本项目需求文档</div>
              <el-button
                v-if="task.has_multi_project_breakdown"
                size="small"
                plain
                :loading="specGenerating || specRunning"
                @click="generateSpec"
              >
                {{ task.spec_markdown ? '重新生成' : '生成' }}
              </el-button>
            </div>
            <div v-if="specRunning" class="muted" style="font-size: 12px">
              生成任务进行中,完成后自动刷新。
            </div>
            <el-collapse v-else-if="task.spec_markdown">
              <el-collapse-item title="查看本项目需求文档">
                <MarkdownView :source="task.spec_markdown" max-height="360px" />
              </el-collapse-item>
            </el-collapse>
            <div v-else class="muted" style="font-size: 12px">
              未生成本项目需求文档；可以直接在这里生成。
            </div>
          </div>
          <div class="side-item">
            <div class="metric-label">需求快照 v{{ task.doc_version }}</div>
            <el-collapse>
              <el-collapse-item title="查看需求文档">
                <MarkdownView :source="task.doc_content" max-height="300px" />
              </el-collapse-item>
            </el-collapse>
          </div>
        </div>
      </aside>
    </div>

    <el-dialog
      v-model="diffDialogVisible"
      title="git diff"
      width="92vw"
      top="4vh"
      class="diff-dialog"
      destroy-on-close
    >
      <DiffView :diff="latestChange?.git_diff_snapshot || ''" max-height="calc(92vh - 150px)" />
    </el-dialog>
  </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import StatusTag from '../components/StatusTag.vue'
import CodeEditor from '../components/CodeEditor.vue'
import MarkdownView from '../components/MarkdownView.vue'
import DiffView from '../components/DiffView.vue'
import AiRunPanel from '../components/AiRunPanel.vue'
import ModelPicker from '../components/ModelPicker.vue'
import { canTerminate, getStatusMeta, runningStatuses } from '../services/status'
import { api } from '../services/api'

const props = defineProps({ id: { type: String, required: true } })

const task = ref(null)
const branchEditor = ref('')
const branchCheck = ref(null)
const planEditor = ref('')
const commitEditor = ref('')
const lastTaskCommitMessage = ref('')
const retroEditor = ref('')
const fixFeedback = ref('')
const rejectFeedback = ref('')
const approving = ref(false)
const planPreview = ref(false)
const retroPreview = ref(false)
const diffDialogVisible = ref(false)
// 各 AI 步骤本次执行指定的模型 key,空 = 走配置默认
const planModel = ref('')
const branchModel = ref('')
const codeModel = ref('')
const fixModel = ref('')
const reviewModel = ref('')
const commitModel = ref('')
const openSteps = ref(new Set())
const branching = ref(false)
const planning = ref(false)
const reviewing = ref(false)
const aiReviewing = ref(false)
const committing = ref(false)
const cleaningWorktree = ref(false)
const specGenerating = ref(false)

let detailTimer = null

const stepByStatus = {
  created: 1,
  branch_generated: 1,
  plan_generated: 1,
  plan_confirmed: 2,
  coding: 2,
  failed: 2,
  fixing: 3,
  code_changed: 3,
  reviewing: 3,
  review_passed: 3,
  review_failed: 3,
  ready_to_commit: 4,
  committing: 4,
  committed: 5,
  retrospected: 5,
  terminated: 1,
}

const currentStep = computed(() => {
  if (!task.value) return 1
  const status = task.value.status
  if (status === 'failed') {
    const lastCodeRun = task.value.runs?.find((run) => ['coding', 'fix'].includes(run.run_type))
    if (lastCodeRun?.run_type === 'fix') return 3
  }
  return stepByStatus[status] || 1
})
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
const hasReviewFixables = computed(() => reviewFeedbackHasContent(parseReviewFeedback(findReviewResultForFix(task.value?.reviews))))
const showFixPanel = computed(() => {
  const status = task.value?.status
  if (['review_failed', 'code_changed'].includes(status)) return true
  if (status === 'failed') {
    const lastCodeRun = task.value?.runs?.find((run) => ['coding', 'fix'].includes(run.run_type))
    if (lastCodeRun?.run_type === 'fix') return true
  }
  return status === 'review_passed' && hasReviewFixables.value
})
const canStartFix = computed(() => Boolean(fixFeedback.value.trim()) || hasReviewFixables.value)
const runningRun = computed(() =>
  task.value?.runs?.find((run) => ['coding', 'fix'].includes(run.run_type) && ['running', 'queued'].includes(run.status)),
)
const latestCodeRun = computed(() => {
  if (runningRun.value?.run_type === 'coding') return runningRun.value
  const codingRunning = task.value?.runs?.find(
    (run) => run.run_type === 'coding' && ['running', 'queued'].includes(run.status),
  )
  if (codingRunning) return codingRunning
  return task.value?.runs?.find((run) => run.run_type === 'coding') || null
})
const latestFixRun = computed(() => {
  const runs = (task.value?.runs || []).filter((run) => run.run_type === 'fix')
  if (!runs.length) return null
  const running = runs.find((run) => ['queued', 'running'].includes(run.status))
  if (running) return running
  const recent = runs.find((run) => run.status !== 'draft')
  if (recent) return recent
  return runs.find((run) => run.status === 'draft') || null
})
const runningBranchRun = computed(() =>
  task.value?.runs?.find((run) => run.run_type === 'branch_name' && ['running', 'queued'].includes(run.status)),
)
const latestBranchRun = computed(() =>
  runningBranchRun.value || task.value?.runs?.find((run) => run.run_type === 'branch_name') || null,
)
const branchRunning = computed(() => !!runningBranchRun.value)
const runningPlanRun = computed(() =>
  task.value?.runs?.find((run) => run.run_type === 'task_plan' && ['running', 'queued'].includes(run.status)),
)
const latestPlanRun = computed(() =>
  runningPlanRun.value || task.value?.runs?.find((run) => run.run_type === 'task_plan') || null,
)
const planRunning = computed(() => !!runningPlanRun.value)
const specRunning = computed(() =>
  !!task.value?.runs?.find((run) => run.run_type === 'task_spec' && ['running', 'queued'].includes(run.status)),
)
const runningAiReviewRun = computed(() =>
  task.value?.runs?.find((run) => run.run_type === 'ai_review' && ['running', 'queued'].includes(run.status)),
)
const latestAiReviewRun = computed(() =>
  runningAiReviewRun.value || task.value?.runs?.find((run) => run.run_type === 'ai_review') || null,
)
const aiReviewRunning = computed(() => !!runningAiReviewRun.value)
const runningCommitMessageRun = computed(() =>
  task.value?.runs?.find((run) => run.run_type === 'commit_message' && ['running', 'queued'].includes(run.status)),
)
const latestCommitMessageRun = computed(() =>
  runningCommitMessageRun.value || task.value?.runs?.find((run) => run.run_type === 'commit_message') || null,
)
const commitMessageRunning = computed(() => !!runningCommitMessageRun.value)
const activeAnyRun = computed(() =>
  task.value?.runs?.find((run) => ['running', 'queued'].includes(run.status)),
)
const runHint = computed(() => {
  if (runningRun.value?.run_type === 'fix') {
    if (runningRun.value.status === 'queued') return 'fix 已入队,等待 Worker'
    if (runningRun.value.status === 'running') return 'fix 执行中,实时日志'
    return '继续修改(fix 轮次)'
  }
  if (runningRun.value?.status === 'queued') return '已入队,等待 Worker'
  if (runningRun.value?.status === 'running') return '执行中,实时日志'
  if (task.value?.status === 'fixing') return '继续修改(fix 轮次)'
  return 'Claude Code 修改代码'
})
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

function parseReviewFeedback(text) {
  if (!text || typeof text !== 'string') return null
  try {
    return JSON.parse(text)
  } catch (error) {
    return null
  }
}

function reviewFeedbackHasContent(data) {
  if (!data || typeof data !== 'object') return false
  return Boolean(
    (data.summary && String(data.summary).trim())
    || (Array.isArray(data.blocking_issues) && data.blocking_issues.length)
    || (Array.isArray(data.warnings) && data.warnings.length)
    || (Array.isArray(data.suggestions) && data.suggestions.length),
  )
}

function isShellHumanReject(parsed) {
  if (!parsed || parsed.status !== 'human_reject') return false
  if ((parsed.warnings?.length || 0) > 0 || (parsed.suggestions?.length || 0) > 0) return false
  const blocking = parsed.blocking_issues || []
  if (blocking.length > 1) return false
  return parsed.summary === '人工 Review 驳回。'
}

function findReviewResultForFix(reviews) {
  for (const review of reviews || []) {
    if (review.status === 'human_pass') continue
    const parsed = parseReviewFeedback(review.review_result)
    if (!reviewFeedbackHasContent(parsed)) continue
    if (review.status === 'human_reject' || review.status === 'fail') {
      return review.review_result
    }
    if (review.status === 'pass') {
      const hasFixables = (parsed.blocking_issues?.length || 0) > 0
        || (parsed.warnings?.length || 0) > 0
        || (parsed.suggestions?.length || 0) > 0
      if (hasFixables) return review.review_result
    }
  }
  return null
}

function syncFixFeedbackFromReview() {
  const status = task.value?.status
  if (!['review_failed', 'code_changed', 'review_passed'].includes(status)) return
  const feedback = findReviewResultForFix(task.value?.reviews)
  if (!feedback) return
  const latestParsed = parseReviewFeedback(feedback)
  const currentParsed = parseReviewFeedback(fixFeedback.value)
  const latestHasContent = reviewFeedbackHasContent(latestParsed)
  const currentHasContent = reviewFeedbackHasContent(currentParsed) || (fixFeedback.value.trim() && !currentParsed)
  if (!fixFeedback.value.trim() || (!currentHasContent && latestHasContent) || isShellHumanReject(currentParsed)) {
    fixFeedback.value = feedback
  }
}

async function load({ silent = false } = {}) {
  task.value = await api.tasks.detail(props.id, { silent })
  branchEditor.value = task.value.final_branch_name || ''
  if (latestPlan.value && !planEditor.value) {
    planEditor.value = latestPlan.value.plan_content
    // 计划已确认(锁定)时默认渲染预览,编辑期默认原文编辑
    planPreview.value = planLocked.value
  }
  if (task.value.commit_message && task.value.commit_message !== lastTaskCommitMessage.value) {
    commitEditor.value = task.value.commit_message
    lastTaskCommitMessage.value = task.value.commit_message
  }
  if (task.value.retrospective && !retroEditor.value) retroEditor.value = task.value.retrospective.content
  syncFixFeedbackFromReview()
  syncTimers()
}

function syncTimers() {
  const running = task.value && (runningStatuses.has(task.value.status) || !!activeAnyRun.value)
  if (running && !detailTimer) {
    detailTimer = setInterval(() => load({ silent: true }).catch(() => {}), 3000)
  }
  if (!running && detailTimer) {
    clearInterval(detailTimer)
    detailTimer = null
  }
}

async function generateBranch(draft = false) {
  branching.value = true
  try {
    await api.tasks.generateBranch(props.id, branchModel.value, draft === true)
    await load()
    ElMessage.success(draft === true ? '已生成草稿，请在弹窗中编辑提示语后执行' : '分支名生成任务已入队,完成后自动回填')
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

async function generatePlan(draft = false) {
  planning.value = true
  try {
    await api.tasks.generatePlan(props.id, planModel.value, draft === true)
    await load()
    ElMessage.success(draft === true ? '已生成草稿，请在弹窗中编辑提示语后执行' : '计划生成任务已入队')
  } finally {
    planning.value = false
  }
}

async function generateSpec() {
  specGenerating.value = true
  try {
    await api.tasks.generateSpec(props.id)
    await load()
    ElMessage.success('本项目需求文档生成任务已入队')
  } finally {
    specGenerating.value = false
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

async function execute(draft = false) {
  await api.tasks.execute(props.id, codeModel.value, draft === true)
  await load()
  if (draft === true) ElMessage.success('已生成草稿，请在弹窗中编辑提示语后执行')
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

async function aiReview(draft = false) {
  aiReviewing.value = true
  try {
    await api.tasks.aiReview(props.id, reviewModel.value, draft === true)
    await load()
    ElMessage.success(draft === true ? '已生成草稿，请在弹窗中编辑提示语后执行' : 'AI Review 任务已入队')
  } finally {
    aiReviewing.value = false
  }
}

async function cleanupWorktree() {
  await ElMessageBox.confirm('将移除该工单的独立 worktree,未提交改动会被丢弃。确认清理?', '清理 worktree', {
    type: 'warning',
  })
  cleaningWorktree.value = true
  try {
    await api.tasks.cleanupWorktree(props.id)
    await load()
    ElMessage.success('worktree 已清理')
  } finally {
    cleaningWorktree.value = false
  }
}

async function fix(draft = false) {
  await api.tasks.fix(props.id, fixFeedback.value, fixModel.value, draft === true)
  fixFeedback.value = ''
  await load()
  if (draft === true) {
    ElMessage.success('已生成草稿，请在弹窗中编辑提示语后执行')
    return
  }
  // fix 轮次留在 Step 3,确保面板挂载并能自动弹出日志
  openSteps.value = new Set([...openSteps.value, 3])
}

async function approveReview() {
  await ElMessageBox.confirm('确认人工 Review 通过?通过后工单进入待提交状态。', '人工 Review', {
    type: 'warning',
  })
  approving.value = true
  try {
    await api.tasks.approveReview(props.id)
    await load()
    ElMessage.success('人工 Review 已通过')
  } finally {
    approving.value = false
  }
}

async function rejectReview() {
  await api.tasks.rejectReview(props.id, rejectFeedback.value)
  rejectFeedback.value = ''
  await load()
  ElMessage.warning('已驳回,可在下方发起 fix 轮次让 AI 继续修改')
}

async function generateCommitMessage(draft = false) {
  await api.tasks.generateCommitMessage(props.id, commitModel.value, draft === true)
  ElMessage.success(draft === true ? '已生成草稿，请在弹窗中编辑提示语后执行' : 'commit message 生成任务已入队')
  await load()
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
})
</script>

<style scoped>
.branch-inherited {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border: 1px solid var(--line-soft);
  border-radius: 6px;
  background: var(--page-bg);
}

.dependency-list {
  display: grid;
  gap: 4px;
  font-size: 12.5px;
}

.side-item__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
}

@media (max-width: 720px) {
  .branch-inherited {
    align-items: stretch;
    flex-direction: column;
  }
}
</style>
