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
    <el-option v-for="item in models" :key="item.key" :value="item.key" :label="item.label" />
  </el-select>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue'
import { api } from '../services/api'

const props = defineProps({
  modelValue: { type: String, default: '' },
  // 对应后端 run_type,用于展示该步骤配置的默认模型
  step: { type: String, default: '' },
})
const emit = defineEmits(['update:modelValue'])

// 模块级缓存:所有 picker 共用一次请求
let optionsPromise = null
const models = ref([])
const stepDefaults = ref({})

onMounted(async () => {
  if (!optionsPromise) optionsPromise = api.config.modelOptions()
  try {
    const data = await optionsPromise
    models.value = data.models || []
    stepDefaults.value = data.step_defaults || {}
  } catch (error) {
    optionsPromise = null
  }
})

const placeholder = computed(() => {
  const key = stepDefaults.value[props.step] || ''
  const found = models.value.find((item) => item.key === key)
  return found ? `默认(${found.label})` : '默认模型'
})

function onChange(value) {
  emit('update:modelValue', value || '')
}
</script>

<style scoped>
.model-picker {
  width: 200px;
}
</style>
