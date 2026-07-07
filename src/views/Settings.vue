<template>
  <section class="page">
    <div class="page-heading">
      <div>
        <h1>设置</h1>
        <p>模型参数、进入模型前的脱敏规则,以及项目扫描的工作区根目录。</p>
      </div>
      <el-button type="primary" @click="saveAll">
        <el-icon><Select /></el-icon>
        保存全部
      </el-button>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div class="panel-title">工作区根目录</div>
        <div class="muted">添加项目时扫描这些目录下的 git 仓库</div>
      </div>
      <div class="toolbar">
        <el-tag
          v-for="root in roots"
          :key="root"
          closable
          class="mono"
          @close="roots = roots.filter((item) => item !== root)"
        >
          {{ root }}
        </el-tag>
        <el-input
          v-model="newRoot"
          class="mono"
          style="width: 340px"
          placeholder="/Users/xxx/www/local"
          @keyup.enter="addRootFromInput"
        />
        <el-button type="primary" @click="openDirPicker">添加目录</el-button>
      </div>
    </div>

    <div class="panel">
      <div class="panel-header">
        <div>
          <div class="panel-title">同步与迁移</div>
          <div class="muted" style="margin-top: 4px">
            当前版本 {{ migrationStatus.current_version || '-' }}，待执行 {{ migrationStatus.pending_count || 0 }} 个迁移
          </div>
        </div>
        <div class="toolbar">
          <el-button :loading="migrationLoading" @click="loadMigrationStatus">刷新</el-button>
          <el-button type="primary" :loading="migrationRunning" @click="runMigrations">
            执行迁移
          </el-button>
        </div>
      </div>
      <div class="toolbar">
        <el-button @click="exportConfig">
          <el-icon><Download /></el-icon>
          导出配置
        </el-button>
        <el-button @click="importVisible = true">
          <el-icon><Upload /></el-icon>
          导入配置
        </el-button>
      </div>
      <el-table v-if="migrationStatus.migrations?.length" :data="migrationStatus.migrations" size="small" style="margin-top: 12px">
        <el-table-column prop="version" label="版本" width="150" />
        <el-table-column prop="name" label="文件" min-width="260" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <StatusTag :status="row.executed ? 'committed' : 'ready_to_commit'" />
          </template>
        </el-table-column>
        <el-table-column prop="executed_at" label="执行时间" width="170" />
      </el-table>
    </div>

    <el-dialog v-model="importVisible" title="导入配置" width="760px">
      <div class="form-grid">
        <el-form-item label="旧路径前缀">
          <el-input v-model="pathMapFrom" class="mono" placeholder="/Users/old/www/local" />
        </el-form-item>
        <el-form-item label="新路径前缀">
          <el-input v-model="pathMapTo" class="mono" placeholder="/Users/new/www/local" />
        </el-form-item>
        <el-form-item label="配置 JSON" class="full-span">
          <el-input
            v-model="importJson"
            type="textarea"
            :rows="14"
            class="mono"
            placeholder="粘贴从另一台电脑导出的配置 JSON"
          />
        </el-form-item>
      </div>
      <template #footer>
        <el-button @click="importVisible = false">取消</el-button>
        <el-button type="primary" :loading="importing" :disabled="!importJson.trim()" @click="importConfig">
          导入
        </el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="dirPickerVisible" title="添加目录" width="640px">
      <div class="dir-picker">
        <div class="toolbar" style="margin-bottom: 10px">
          <el-input
            v-model="browsePath"
            class="mono"
            style="flex: 1"
            placeholder="/Users/xxx/www/local"
            @keyup.enter="loadBrowseDir(browsePath)"
          />
          <el-button :loading="browseLoading" @click="loadBrowseDir(browsePath)">前往</el-button>
        </div>
        <div class="toolbar" style="margin-bottom: 10px">
          <el-button :disabled="!browseParent || browseLoading" @click="loadBrowseDir(browseParent)">
            上级目录
          </el-button>
          <el-button :loading="browseLoading" @click="loadBrowseDir(browsePath)">刷新</el-button>
        </div>
        <el-table
          v-loading="browseLoading"
          :data="browseDirs"
          max-height="320"
          highlight-current-row
          empty-text="此目录下没有子文件夹"
          @row-dblclick="(row) => loadBrowseDir(row.path)"
        >
          <el-table-column label="文件夹" min-width="360">
            <template #default="{ row }">
              <button type="button" class="dir-picker-item" @click="loadBrowseDir(row.path)">
                <el-icon><Folder /></el-icon>
                <span>{{ row.name }}</span>
              </button>
            </template>
          </el-table-column>
        </el-table>
      </div>
      <template #footer>
        <el-button @click="dirPickerVisible = false">取消</el-button>
        <el-button type="primary" :disabled="!browsePath" @click="confirmDirPick">选择此目录</el-button>
      </template>
    </el-dialog>

    <div class="split">
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">模型配置</div>
        </div>
        <el-form :model="modelConfig" label-position="top" class="form-grid">
          <el-form-item label="模型供应商">
            <el-input v-model="modelConfig.provider" />
          </el-form-item>
          <el-form-item label="模型名称">
            <el-input v-model="modelConfig.model_name" class="mono" />
          </el-form-item>
          <el-form-item label="API 地址">
            <el-input v-model="modelConfig.api_base" class="mono" />
          </el-form-item>
          <el-form-item label="API Key 引用(环境变量名,不存明文)">
            <el-input v-model="modelConfig.api_key_ref" class="mono" />
          </el-form-item>
          <el-form-item label="最大上下文长度">
            <el-input-number v-model="modelConfig.context_length" :min="4000" :step="1000" />
          </el-form-item>
          <el-form-item label="超时时间(秒)">
            <el-input-number v-model="modelConfig.timeout_seconds" :min="30" :step="30" />
          </el-form-item>
        </el-form>
      </div>

      <div class="panel">
        <div class="panel-header">
          <div class="panel-title">脱敏规则</div>
          <el-button @click="addRule">
            <el-icon><Plus /></el-icon>
            添加规则
          </el-button>
        </div>
        <el-table :data="rules">
          <el-table-column label="启用" width="70">
            <template #default="{ row }">
              <el-switch :model-value="!!row.enabled" @update:model-value="row.enabled = $event ? 1 : 0" />
            </template>
          </el-table-column>
          <el-table-column label="匹配正则" min-width="200">
            <template #default="{ row }">
              <el-input v-model="row.pattern" class="mono" />
            </template>
          </el-table-column>
          <el-table-column label="替换内容" min-width="140">
            <template #default="{ row }">
              <el-input v-model="row.replacement" class="mono" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="80">
            <template #default="{ $index }">
              <el-button text type="danger" @click="rules.splice($index, 1)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>
    </div>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import StatusTag from '../components/StatusTag.vue'
import { api } from '../services/api'

const modelConfig = reactive({
  provider: '',
  model_name: '',
  api_base: '',
  api_key_ref: '',
  context_length: 0,
  timeout_seconds: 0,
})
const rules = ref([])
const roots = ref([])
const newRoot = ref('')
const dirPickerVisible = ref(false)
const browsePath = ref('')
const browseParent = ref(null)
const browseDirs = ref([])
const browseLoading = ref(false)
const migrationStatus = ref({})
const migrationLoading = ref(false)
const migrationRunning = ref(false)
const importVisible = ref(false)
const importJson = ref('')
const pathMapFrom = ref('')
const pathMapTo = ref('')
const importing = ref(false)

async function load() {
  const [model, securityRules, workspace, migrations] = await Promise.all([
    api.config.model(),
    api.config.securityRules(),
    api.config.workspace(),
    api.config.migrationStatus(),
  ])
  if (model) Object.assign(modelConfig, model)
  rules.value = securityRules
  roots.value = workspace.roots || []
  migrationStatus.value = migrations || {}
}

function addRootFromInput() {
  const root = newRoot.value.trim()
  if (!root) {
    openDirPicker()
    return
  }
  if (roots.value.includes(root)) {
    ElMessage.warning('该目录已在列表中')
    return
  }
  roots.value.push(root)
  newRoot.value = ''
}

async function loadBrowseDir(path = '') {
  browseLoading.value = true
  try {
    const data = await api.config.browseWorkspace(path)
    browsePath.value = data.path
    browseParent.value = data.parent
    browseDirs.value = data.dirs || []
  } finally {
    browseLoading.value = false
  }
}

async function openDirPicker() {
  dirPickerVisible.value = true
  browseDirs.value = []
  const startPath = newRoot.value.trim() || (roots.value.length ? roots.value[roots.value.length - 1] : '')
  browsePath.value = startPath
  browseParent.value = null
  await loadBrowseDir(startPath)
}

function confirmDirPick() {
  if (!browsePath.value) return
  if (roots.value.includes(browsePath.value)) {
    ElMessage.warning('该目录已在列表中')
    dirPickerVisible.value = false
    return
  }
  roots.value.push(browsePath.value)
  dirPickerVisible.value = false
}

function addRule() {
  rules.value.push({ pattern: '', replacement: '***', enabled: 1 })
}

async function saveAll() {
  await Promise.all([
    api.config.saveModel({ ...modelConfig }),
    api.config.saveSecurityRules(rules.value),
    api.config.saveWorkspace(roots.value),
  ])
  ElMessage.success('设置已保存')
  await load()
}

async function loadMigrationStatus() {
  migrationLoading.value = true
  try {
    migrationStatus.value = await api.config.migrationStatus()
  } finally {
    migrationLoading.value = false
  }
}

async function runMigrations() {
  migrationRunning.value = true
  try {
    const result = await api.config.migrate()
    migrationStatus.value = result.status
    ElMessage.success(result.applied?.length ? `已执行 ${result.applied.length} 个迁移` : '没有待执行迁移')
  } finally {
    migrationRunning.value = false
  }
}

async function exportConfig() {
  const data = await api.config.exportConfig()
  const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = `ai-dev-config-${Date.now()}.json`
  link.click()
  URL.revokeObjectURL(url)
}

async function importConfig() {
  let payload
  try {
    payload = JSON.parse(importJson.value)
  } catch (error) {
    ElMessage.error('配置 JSON 格式不正确')
    return
  }
  payload.path_map = {
    from: pathMapFrom.value.trim(),
    to: pathMapTo.value.trim(),
  }
  importing.value = true
  try {
    const result = await api.config.importConfig(payload)
    ElMessage.success(`导入完成: ${result.projects || 0} 个项目, ${result.workspace_roots || 0} 个工作区`)
    importVisible.value = false
    importJson.value = ''
    await load()
  } finally {
    importing.value = false
  }
}

onMounted(load)
</script>
