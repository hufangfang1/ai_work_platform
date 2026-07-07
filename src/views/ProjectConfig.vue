<template>
  <section class="page">
    <div class="page-heading">
      <div>
        <h1>项目</h1>
        <p>从本地工作区扫描 git 仓库添加项目;仓库地址与分支自动读取,无需手填。</p>
      </div>
      <div class="toolbar">
        <el-button type="primary" @click="openScan">
          <el-icon><Plus /></el-icon>
          添加项目
        </el-button>
      </div>
    </div>

    <div class="panel" v-loading="loading">
      <div v-if="projects.length" class="card-grid">
        <div v-for="project in projects" :key="project.id" class="project-card">
          <div class="card-title">
            <span>{{ project.name }}</span>
            <span class="toolbar">
              <el-button text type="primary" size="small" @click="edit(project)">编辑</el-button>
              <el-button text type="danger" size="small" @click="remove(project)">停用</el-button>
            </span>
          </div>
          <div class="card-line">{{ project.description || '无描述(建议补充,AI 拆解时用于判断)' }}</div>
          <div class="card-line mono">{{ project.repo_url || '无远程仓库' }}</div>
          <div class="card-line mono">{{ project.local_path }}</div>
          <div class="card-line">
            基准分支 <span class="mono">{{ project.default_base_branch }}</span>
            · 前缀 <span class="mono">{{ project.default_branch_prefix || '-' }}</span>
          </div>
          <div class="card-line">测试:<span class="mono">{{ project.test_command || '-' }}</span></div>
        </div>
      </div>
      <div v-else-if="!loading" class="empty-state">还没有项目,点击"添加项目"从本地扫描</div>
    </div>

    <!-- 扫描添加弹框 -->
    <el-dialog v-model="scanVisible" title="添加项目" width="760px">
      <div style="display: grid; gap: 14px">
        <div>
          <div class="metric-label" style="margin-bottom: 6px">工作区根目录(扫描其下的 git 仓库)</div>
          <div class="toolbar">
            <el-input
              v-model="rootsEditor"
              style="flex: 1"
              placeholder="例如 /Users/xxx/www/local,多个用英文逗号分隔"
            />
            <el-button :loading="scanning" @click="saveRootsAndScan">保存并扫描</el-button>
          </div>
        </div>

        <el-table
          v-if="scanResults.length"
          :data="scanResults"
          max-height="360"
          @selection-change="(rows) => (selectedRepos = rows)"
        >
          <el-table-column type="selection" width="44" :selectable="(row) => !row.already_added" />
          <el-table-column prop="name" label="仓库" width="180" />
          <el-table-column label="路径" min-width="220">
            <template #default="{ row }"><span class="mono">{{ row.path }}</span></template>
          </el-table-column>
          <el-table-column label="远程地址" min-width="200">
            <template #default="{ row }">
              <span class="mono">{{ row.repo_url || '无 origin' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="当前分支" width="120">
            <template #default="{ row }"><span class="mono">{{ row.current_branch }}</span></template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <span v-if="row.already_added" class="muted">已添加</span>
              <span v-else class="success-text">可添加</span>
            </template>
          </el-table-column>
        </el-table>
        <div v-else-if="scanned && !scanning" class="empty-state" style="min-height: 120px">
          根目录下没有找到 git 仓库
        </div>

        <template v-if="selectedRepos.length">
          <el-divider style="margin: 0" />
          <div class="metric-label">补充项目信息({{ selectedRepos.length }} 个待添加)</div>
          <div v-for="repo in selectedRepos" :key="repo.path" class="panel" style="background: var(--surface-raised)">
            <div class="card-title" style="margin-bottom: 10px">
              <span>{{ repo.name }}</span>
              <span class="mono muted" style="font-size: 12px">{{ repo.path }}</span>
            </div>
            <div class="form-grid">
              <el-form-item label="项目描述(供 AI 拆解判断)">
                <el-input v-model="repo.description" placeholder="例如:运营中台 PHP API" />
              </el-form-item>
              <el-form-item label="基准分支">
                <el-input v-model="repo.base_branch" class="mono" />
              </el-form-item>
              <el-form-item label="分支前缀">
                <el-input v-model="repo.branch_prefix" class="mono" placeholder="future/" />
              </el-form-item>
              <el-form-item label="测试命令">
                <el-input v-model="repo.test_command" class="mono" placeholder="php think test" />
              </el-form-item>
              <el-form-item label="Lint 命令">
                <el-input v-model="repo.lint_command" class="mono" />
              </el-form-item>
              <el-form-item label="构建命令">
                <el-input v-model="repo.build_command" class="mono" />
              </el-form-item>
            </div>
          </div>
        </template>
      </div>
      <template #footer>
        <el-button @click="scanVisible = false">取消</el-button>
        <el-button
          type="primary"
          :disabled="!selectedRepos.length"
          :loading="adding"
          @click="addSelected"
        >
          添加 {{ selectedRepos.length }} 个项目
        </el-button>
      </template>
    </el-dialog>

    <!-- 编辑弹框 -->
    <el-dialog v-model="editVisible" title="编辑项目" width="640px">
      <el-form v-if="editForm" label-position="top">
        <div class="form-grid">
          <el-form-item label="项目名称">
            <el-input v-model="editForm.name" />
          </el-form-item>
          <el-form-item label="项目描述">
            <el-input v-model="editForm.description" />
          </el-form-item>
          <el-form-item label="仓库地址(自动读取)">
            <el-input :model-value="editForm.repo_url" class="mono" disabled />
          </el-form-item>
          <el-form-item label="本地目录(自动读取)">
            <el-input :model-value="editForm.local_path" class="mono" disabled />
          </el-form-item>
          <el-form-item label="基准分支">
            <el-input v-model="editForm.default_base_branch" class="mono" />
          </el-form-item>
          <el-form-item label="分支前缀">
            <el-input v-model="editForm.default_branch_prefix" class="mono" />
          </el-form-item>
          <el-form-item label="测试命令">
            <el-input v-model="editForm.test_command" class="mono" />
          </el-form-item>
          <el-form-item label="Lint 命令">
            <el-input v-model="editForm.lint_command" class="mono" />
          </el-form-item>
          <el-form-item label="构建命令">
            <el-input v-model="editForm.build_command" class="mono" />
          </el-form-item>
        </div>
      </el-form>
      <template #footer>
        <el-button @click="editVisible = false">取消</el-button>
        <el-button type="primary" @click="saveEdit">保存</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { api } from '../services/api'

const projects = ref([])
const loading = ref(false)
const scanVisible = ref(false)
const scanning = ref(false)
const scanned = ref(false)
const adding = ref(false)
const rootsEditor = ref('')
const scanResults = ref([])
const selectedRepos = ref([])
const editVisible = ref(false)
const editForm = ref(null)

async function load() {
  loading.value = true
  try {
    projects.value = await api.projects.list()
  } finally {
    loading.value = false
  }
}

async function openScan() {
  scanVisible.value = true
  scanResults.value = []
  selectedRepos.value = []
  scanned.value = false
  const config = await api.config.workspace()
  rootsEditor.value = (config.roots || []).join(',')
  if (config.roots?.length) {
    await scan()
  }
}

async function saveRootsAndScan() {
  const roots = rootsEditor.value.split(',').map((item) => item.trim()).filter(Boolean)
  if (!roots.length) {
    ElMessage.warning('请填写至少一个根目录')
    return
  }
  await api.config.saveWorkspace(roots)
  await scan()
}

async function scan() {
  scanning.value = true
  try {
    const results = await api.projects.scan()
    scanResults.value = results.map((repo) => ({
      ...repo,
      description: '',
      base_branch: repo.current_branch === 'HEAD' ? 'main' : repo.current_branch || 'main',
      branch_prefix: 'future/',
      test_command: '',
      lint_command: '',
      build_command: '',
    }))
    scanned.value = true
  } finally {
    scanning.value = false
  }
}

async function addSelected() {
  adding.value = true
  try {
    for (const repo of selectedRepos.value) {
      await api.projects.save({
        name: repo.name,
        description: repo.description,
        repo_url: repo.repo_url,
        local_path: repo.path,
        default_base_branch: repo.base_branch,
        default_branch_prefix: repo.branch_prefix,
        test_command: repo.test_command,
        lint_command: repo.lint_command,
        build_command: repo.build_command,
        allow_auto_commit: 1,
        allow_auto_push: 0,
      })
    }
    ElMessage.success(`已添加 ${selectedRepos.value.length} 个项目`)
    scanVisible.value = false
    await load()
  } finally {
    adding.value = false
  }
}

function edit(project) {
  editForm.value = { ...project }
  editVisible.value = true
}

async function saveEdit() {
  const { id, ...body } = editForm.value
  await api.projects.update(id, body)
  editVisible.value = false
  await load()
  ElMessage.success('已保存')
}

async function remove(project) {
  await ElMessageBox.confirm(`停用项目「${project.name}」?已有工单不受影响。`, '停用项目', {
    type: 'warning',
  })
  await api.projects.remove(project.id)
  await load()
}

onMounted(load)
</script>
