<template>
  <el-select
    :model-value="modelValue"
    class="model-picker"
    size="default"
    clearable
    :placeholder="placeholder"
    @change="onChange"
    @clear="onChange('')"
  >
    <el-option-group
      v-for="group in groupedModels"
      :key="group.agent"
      :label="group.agent"
    >
      <el-option
        v-for="item in group.models"
        :key="item.key"
        :value="item.key"
        :label="item.label"
      >
        <div class="model-option">
          <span>{{ item.label }}</span>
          <span class="model-option__meta">{{ item.model }}</span>
        </div>
      </el-option>
    </el-option-group>
  </el-select>
</template>

<script setup>
import { computed, ref, onBeforeUnmount, onMounted } from 'vue'
import { api } from '../services/api'

const props = defineProps({
  modelValue: { type: String, default: '' },
  // 对应后端 run_type,用于展示该步骤配置的默认模型
  step: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

// 模块级缓存:所有 picker 共用一次请求
let optionsPromise = null
let cachedOptions = null
const models = ref([])
const stepDefaults = ref({})

async function fetchOptions(force = false) {
  if (force) {
    optionsPromise = null
    cachedOptions = null
  }
  if (cachedOptions) return cachedOptions
  if (!optionsPromise) optionsPromise = api.config.modelOptions()
  cachedOptions = await optionsPromise
  return cachedOptions
}

function applyOptions(data) {
  models.value = data.models || []
  stepDefaults.value = data.step_defaults || {}
  if (props.modelValue && !models.value.some((item) => item.key === props.modelValue)) {
    localStorage.removeItem(rememberKey.value)
    emit('update:modelValue', '')
    return
  }
  applyRememberedModel()
}

async function loadOptions(force = false) {
  try {
    applyOptions(await fetchOptions(force))
  } catch (error) {
    optionsPromise = null
    cachedOptions = null
  }
}

onMounted(() => {
  loadOptions()
  window.addEventListener('ai-dev-model-options-updated', onModelOptionsUpdated)
})

onBeforeUnmount(() => {
  window.removeEventListener('ai-dev-model-options-updated', onModelOptionsUpdated)
})

const placeholder = computed(() => {
  const key = stepDefaults.value[props.step] || ''
  const found = models.value.find((item) => item.key === key)
  return found ? `默认(${found.label})` : '默认模型'
})

// coding/fix 步骤需要 CLI 的 agent loop,http 直调档案不可用,过滤掉。
const CODING_STEPS = ['coding', 'fix']

const groupedModels = computed(() => {
  const groups = []
  const index = new Map()
  const isCodingStep = CODING_STEPS.includes(props.step)
  for (const item of models.value) {
    if (isCodingStep && (item.agent || '') === 'http') continue
    const agent = item.agent || 'default'
    if (!index.has(agent)) {
      const group = { agent, models: [] }
      groups.push(group)
      index.set(agent, group)
    }
    index.get(agent).models.push(item)
  }
  return groups
})

const rememberKey = computed(() => `ai-dev:model:last:${props.step || 'global'}`)

function onChange(value) {
  if (value) {
    localStorage.setItem(rememberKey.value, value)
  } else {
    localStorage.removeItem(rememberKey.value)
  }
  emit('update:modelValue', value || '')
}

function applyRememberedModel() {
  if (props.modelValue) return
  const remembered = localStorage.getItem(rememberKey.value)
  if (!remembered) return
  if (models.value.some((item) => item.key === remembered)) {
    emit('update:modelValue', remembered)
  } else {
    localStorage.removeItem(rememberKey.value)
  }
}

function onModelOptionsUpdated() {
  loadOptions(true)
}
</script>

<style scoped>
.model-picker {
  width: 200px;
}

.model-option {
  min-width: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.model-option__meta {
  color: var(--text-muted);
  font-size: 12px;
  font-family: var(--mono);
}
</style>
