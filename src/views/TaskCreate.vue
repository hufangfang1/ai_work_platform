<template>
  <section class="page">
    <div class="page-heading">
      <div>
        <h1>创建工单</h1>
        <p>先生成可编辑分支名和开发计划，再进入详情页确认执行。</p>
      </div>
      <el-button @click="$router.push('/tasks')">
        <el-icon><Back /></el-icon>
        返回列表
      </el-button>
    </div>

    <div class="split">
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">基础信息</div>
          <div class="toolbar">
            <el-button @click="loadDoc">
              <el-icon><DocumentChecked /></el-icon>
              读取需求文档
            </el-button>
            <el-button @click="generateBranch">
              <el-icon><Connection /></el-icon>
              自动生成分支名
            </el-button>
            <el-button type="primary" @click="generatePlan">
              <el-icon><MagicStick /></el-icon>
              生成开发计划
            </el-button>
          </div>
        </div>

        <el-form :model="form" label-position="top" class="form-grid">
          <el-form-item label="工单标题">
            <el-input v-model="form.title" placeholder="例如：SPA 2.0 知识图谱能力接入" />
          </el-form-item>
          <el-form-item label="关联项目">
            <el-select v-model="form.projectId" placeholder="请选择项目" @change="syncProjectDefaults">
              <el-option
                v-for="project in projects"
                :key="project.id"
                :label="project.name"
                :value="project.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="需求文档地址" class="full-span">
            <el-input v-model="form.docUrl" placeholder="https://trtqq8w5sa.feishu.cn/wiki/..." />
          </el-form-item>
          <el-form-item label="基准分支">
            <el-input v-model="form.baseBranch" />
          </el-form-item>
          <el-form-item label="分支名前缀">
            <el-input v-model="form.branchPrefix" />
          </el-form-item>
          <el-form-item label="AI 建议分支名">
            <el-input v-model="form.branchName" @input="checkBranch" />
          </el-form-item>
          <el-form-item label="最终分支名">
            <el-input v-model="form.finalBranchName" @input="checkBranch" />
            <div :class="branchCheck.valid ? 'success-text' : 'danger-text'">
              {{ branchCheck.message }}
            </div>
          </el-form-item>
          <el-form-item label="需求内容" class="full-span">
            <CodeEditor v-model="form.docContent" label="需求快照" language="markdown" :rows="12" />
          </el-form-item>
        </el-form>
      </div>

      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">生成结果</div>
          <el-button type="success" @click="createTask">
            <el-icon><Select /></el-icon>
            创建并进入详情
          </el-button>
        </div>
        <div class="metric-row">
          <div class="metric">
            <div class="metric-label">分支原因</div>
            <div class="metric-value">{{ branchReason || '未生成' }}</div>
          </div>
          <div class="metric">
            <div class="metric-label">计划版本</div>
            <div class="metric-value">{{ planContent ? 'v1 AI 生成' : '未生成' }}</div>
          </div>
        </div>
        <div style="margin-top: 14px">
          <CodeEditor
            v-model="planContent"
            label="开发计划"
            language="markdown"
            :rows="24"
          />
        </div>
      </div>
    </div>
  </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import CodeEditor from '../components/CodeEditor.vue'
import { api } from '../services/mockApi'

const router = useRouter()
const projects = ref([])
const planContent = ref('')
const branchReason = ref('')
const branchCheck = reactive({
  valid: false,
  validFormat: false,
  exists: false,
  message: '待校验',
})

const form = reactive({
  title: 'SPA 2.0 知识图谱能力接入',
  docUrl: '',
  projectId: '',
  baseBranch: '',
  branchPrefix: 'future/',
  branchName: '',
  finalBranchName: '',
  docContent: '',
})

const selectedProject = computed(() => projects.value.find((project) => project.id === form.projectId))

function syncProjectDefaults() {
  if (!selectedProject.value) return
  form.baseBranch = selectedProject.value.defaultBaseBranch
  form.branchPrefix = selectedProject.value.defaultBranchPrefix
  form.finalBranchName = form.branchName ? `${form.branchPrefix}${form.branchName}` : ''
  checkBranch()
}

function loadDoc() {
  const raw = form.docContent || `# ${form.title || '需求文档'}

来源：${form.docUrl || '手动录入'}

目标：完成 AI 开发工单台第一版闭环，覆盖需求文档、分支生成、开发计划、AI 编码执行、Review、commit 和复盘。

password = "demo-password"
token = "demo-token"`
  const result = api.loadDoc(raw)
  form.docContent = result.docContent
  ElMessage.success(result.masked ? '需求内容已读取并脱敏' : '需求内容已读取')
}

function generateBranch() {
  if (!form.docContent) loadDoc()
  const result = api.generateBranch({ docContent: form.docContent, title: form.title })
  form.branchName = result.branchName
  form.finalBranchName = `${form.branchPrefix}${result.branchName}`
  branchReason.value = result.reason
  checkBranch()
}

function checkBranch() {
  if (!form.finalBranchName && form.branchName) {
    form.finalBranchName = `${form.branchPrefix}${form.branchName}`
  }
  const result = api.validateBranch(form.finalBranchName)
  Object.assign(branchCheck, result)
}

function generatePlan() {
  if (!form.projectId) {
    ElMessage.warning('请先选择项目')
    return
  }
  if (!form.docContent) loadDoc()
  if (!form.finalBranchName) generateBranch()
  planContent.value = api.generatePlan({ docContent: form.docContent, projectId: form.projectId })
  ElMessage.success('开发计划已生成')
}

function createTask() {
  if (!form.projectId) {
    ElMessage.warning('请选择项目')
    return
  }
  if (!form.docContent) loadDoc()
  if (!form.finalBranchName) generateBranch()
  checkBranch()
  if (!branchCheck.valid) {
    ElMessage.error(branchCheck.message)
    return
  }
  const task = api.createTask({
    ...form,
    planContent: planContent.value,
  })
  router.push(`/tasks/${task.id}`)
}

watch(
  () => [form.branchPrefix, form.branchName],
  () => {
    form.finalBranchName = form.branchName ? `${form.branchPrefix}${form.branchName}` : ''
    checkBranch()
  },
)

onMounted(() => {
  projects.value = api.listProjects()
  if (projects.value[0]) {
    form.projectId = projects.value[0].id
    syncProjectDefaults()
  }
})
</script>
