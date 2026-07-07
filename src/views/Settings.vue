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
          @keyup.enter="addRoot"
        />
        <el-button @click="addRoot">添加目录</el-button>
      </div>
    </div>

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

async function load() {
  const [model, securityRules, workspace] = await Promise.all([
    api.config.model(),
    api.config.securityRules(),
    api.config.workspace(),
  ])
  if (model) Object.assign(modelConfig, model)
  rules.value = securityRules
  roots.value = workspace.roots || []
}

function addRoot() {
  const root = newRoot.value.trim()
  if (root && !roots.value.includes(root)) {
    roots.value.push(root)
  }
  newRoot.value = ''
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

onMounted(load)
</script>
