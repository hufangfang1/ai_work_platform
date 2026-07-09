<template>
  <section class="page">
    <div class="page-heading">
      <div>
        <h1>设置</h1>
        <p>模型档案、进入模型前的脱敏规则,以及项目扫描的工作区根目录。</p>
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

    <div class="panel">
      <div class="panel-header">
        <div>
          <div class="panel-title">模型档案</div>
          <div class="muted">按代理分组管理可选模型；AI 按钮旁的模型下拉会使用这里的启用项。</div>
        </div>
        <div class="toolbar">
          <el-button :loading="refreshingModels" @click="refreshLocalModelProfiles">
            <el-icon><Refresh /></el-icon>
            刷新本机配置
          </el-button>
          <el-button @click="addModelProfile">
            <el-icon><Plus /></el-icon>
            添加模型
          </el-button>
        </div>
      </div>
      <el-table :data="modelProfiles" class="model-profile-table" row-key="key">
        <el-table-column type="expand" width="42">
          <template #default="{ row }">
            <div class="model-profile-extra">
              <el-form-item label="环境变量覆盖(KEY=value,每行一项)">
                <el-input
                  v-model="row.env_text"
                  type="textarea"
                  :rows="3"
                  class="mono"
                  placeholder="ANTHROPIC_BASE_URL=https://api.example.com"
                />
              </el-form-item>
              <el-form-item label="备注">
                <el-input v-model="row.description" placeholder="例如:用于长上下文规划、低成本 review 等" />
              </el-form-item>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="启用" width="70">
          <template #default="{ row }">
            <el-switch :model-value="!!row.enabled" @update:model-value="row.enabled = $event ? 1 : 0" />
          </template>
        </el-table-column>
        <el-table-column label="Key" min-width="160">
          <template #default="{ row }">
            <el-input v-model="row.key" class="mono" placeholder="codex-gpt-5" />
          </template>
        </el-table-column>
        <el-table-column label="代理" width="150">
          <template #default="{ row }">
            <el-select
              v-model="row.agent"
              filterable
              allow-create
              default-first-option
              placeholder="选择或输入"
            >
              <el-option v-for="agent in agentOptions" :key="agent" :label="agent" :value="agent" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column label="显示名称" min-width="180">
          <template #default="{ row }">
            <el-input v-model="row.label" placeholder="GPT-5 High" />
          </template>
        </el-table-column>
        <el-table-column label="命令" min-width="150">
          <template #default="{ row }">
            <el-input v-model="row.command" class="mono" placeholder="claude" />
          </template>
        </el-table-column>
        <el-table-column label="模型参数" min-width="180">
          <template #default="{ row }">
            <el-input v-model="row.model" class="mono" placeholder="传给 --model 的值" />
          </template>
        </el-table-column>
        <el-table-column label="API 地址" min-width="220">
          <template #default="{ row }">
            <el-input v-model="row.api_base" class="mono" placeholder="可选" />
          </template>
        </el-table-column>
        <el-table-column label="Key 环境变量" min-width="150">
          <template #default="{ row }">
            <el-input v-model="row.api_key_ref" class="mono" placeholder="ANTHROPIC_API_KEY" />
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80" fixed="right">
          <template #default="{ $index }">
            <el-button text type="danger" @click="modelProfiles.splice($index, 1)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
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
  </section>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import StatusTag from '../components/StatusTag.vue'
import { api } from '../services/api'

const agentOptions = ['claude', 'codex', 'cursor', 'http']
const modelProfiles = ref([])
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
const refreshingModels = ref(false)
const importVisible = ref(false)
const importJson = ref('')
const pathMapFrom = ref('')
const pathMapTo = ref('')
const importing = ref(false)

async function load() {
  const [profiles, securityRules, workspace, migrations] = await Promise.all([
    api.config.modelProfiles(),
    api.config.securityRules(),
    api.config.workspace(),
    api.config.migrationStatus(),
  ])
  modelProfiles.value = (profiles || []).map(toEditableModelProfile)
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

function addModelProfile() {
  const stamp = Date.now()
  modelProfiles.value.push({
    key: `codex-model-${stamp}`,
    label: '新模型',
    agent: 'codex',
    command: 'codex',
    model: '',
    api_base: '',
    api_key_ref: '',
    context_length: 0,
    timeout_seconds: 0,
    env: {},
    env_text: '',
    enabled: 1,
    description: '',
  })
}

async function refreshLocalModelProfiles() {
  await ElMessageBox.confirm(
    '将从这台电脑的 Claude/Codex/Cursor 配置重新生成模型档案，并覆盖当前列表。确认刷新?',
    '刷新本机配置',
    { type: 'warning' },
  )
  refreshingModels.value = true
  try {
    const profiles = await api.config.refreshModelProfiles()
    modelProfiles.value = (profiles || []).map(toEditableModelProfile)
    window.dispatchEvent(new CustomEvent('ai-dev-model-options-updated'))
    ElMessage.success(`已刷新 ${modelProfiles.value.length} 个本机模型档案`)
  } finally {
    refreshingModels.value = false
  }
}

async function saveAll() {
  const invalid = modelProfiles.value.find(
    (p) => (p.agent || '').trim() === 'http' && (!(p.api_base || '').trim() || !(p.model || '').trim())
  )
  if (invalid) {
    ElMessage.error(`HTTP 直调档案「${invalid.key || invalid.label || ''}」必须填写 API 地址和模型参数`)
    return
  }
  await Promise.all([
    api.config.saveModelProfiles(modelProfiles.value.map(toPayloadModelProfile)),
    api.config.saveSecurityRules(rules.value),
    api.config.saveWorkspace(roots.value),
  ])
  window.dispatchEvent(new CustomEvent('ai-dev-model-options-updated'))
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

function toEditableModelProfile(profile) {
  return {
    ...profile,
    env_text: envToText(profile.env),
    enabled: profile.enabled ? 1 : 0,
  }
}

function toPayloadModelProfile(profile) {
  return {
    ...profile,
    key: (profile.key || '').trim(),
    label: (profile.label || '').trim(),
    agent: (profile.agent || '').trim(),
    command: (profile.command || '').trim(),
    model: (profile.model || '').trim(),
    api_base: (profile.api_base || '').trim(),
    api_key_ref: (profile.api_key_ref || '').trim(),
    env_text: profile.env_text || '',
    enabled: profile.enabled ? 1 : 0,
  }
}

function envToText(env) {
  if (!env || typeof env !== 'object') return ''
  return Object.entries(env)
    .map(([key, value]) => `${key}=${value}`)
    .join('\n')
}
</script>

<style scoped>
.model-profile-table {
  width: 100%;
}

.model-profile-extra {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(260px, 0.6fr);
  gap: 16px;
  padding: 8px 16px 4px 54px;
}

.model-profile-extra :deep(.el-form-item) {
  margin-bottom: 0;
}

@media (max-width: 980px) {
  .model-profile-extra {
    grid-template-columns: 1fr;
    padding-left: 16px;
  }
}
</style>
