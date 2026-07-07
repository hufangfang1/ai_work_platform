<template>
  <section class="page">
    <div class="page-heading">
      <div>
        <h1>模型与安全配置</h1>
        <p>管理模型调用参数和进入模型前的脱敏规则，不展示真实密钥。</p>
      </div>
      <el-button type="primary" @click="saveAll">
        <el-icon><Select /></el-icon>
        保存配置
      </el-button>
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
            <el-input v-model="modelConfig.modelName" />
          </el-form-item>
          <el-form-item label="API 地址">
            <el-input v-model="modelConfig.apiBase" />
          </el-form-item>
          <el-form-item label="API Key 引用">
            <el-input v-model="modelConfig.apiKeyRef" />
          </el-form-item>
          <el-form-item label="最大上下文长度">
            <el-input-number v-model="modelConfig.contextLength" :min="4000" :step="1000" />
          </el-form-item>
          <el-form-item label="超时时间（秒）">
            <el-input-number v-model="modelConfig.timeoutSeconds" :min="30" :step="30" />
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
        <el-table :data="rules" border stripe>
          <el-table-column label="启用" width="78">
            <template #default="{ row }">
              <el-switch v-model="row.enabled" />
            </template>
          </el-table-column>
          <el-table-column label="匹配规则" min-width="210">
            <template #default="{ row }">
              <el-input v-model="row.pattern" />
            </template>
          </el-table-column>
          <el-table-column label="替换内容" min-width="160">
            <template #default="{ row }">
              <el-input v-model="row.replacement" />
            </template>
          </el-table-column>
          <el-table-column label="操作" width="90">
            <template #default="{ $index }">
              <el-button text type="danger" @click="rules.splice($index, 1)">
                <el-icon><Delete /></el-icon>
                删除
              </el-button>
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
import { api } from '../services/mockApi'

const modelConfig = reactive({
  provider: '',
  modelName: '',
  apiBase: '',
  apiKeyRef: '',
  contextLength: 0,
  timeoutSeconds: 0,
})
const rules = ref([])

function loadConfig() {
  Object.assign(modelConfig, api.getModelConfig())
  rules.value = api.listSecurityRules()
}

function addRule() {
  rules.value.push({
    id: '',
    pattern: '',
    replacement: '***',
    enabled: true,
  })
}

function saveAll() {
  api.saveModelConfig({ ...modelConfig })
  api.saveSecurityRules(rules.value)
  ElMessage.success('模型和安全配置已保存')
}

onMounted(loadConfig)
</script>
