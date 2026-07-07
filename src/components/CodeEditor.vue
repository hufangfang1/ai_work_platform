<template>
  <div class="code-editor">
    <div class="code-editor__bar">
      <span>{{ label }}</span>
      <span>{{ language }}</span>
    </div>
    <el-input
      :model-value="modelValue"
      :readonly="readonly"
      :rows="rows"
      type="textarea"
      spellcheck="false"
      @input="$emit('update:modelValue', $event)"
    />
  </div>
</template>

<script setup>
defineProps({
  modelValue: {
    type: String,
    default: '',
  },
  label: {
    type: String,
    default: 'Editor',
  },
  language: {
    type: String,
    default: 'markdown',
  },
  readonly: {
    type: Boolean,
    default: false,
  },
  rows: {
    type: Number,
    default: 14,
  },
})

defineEmits(['update:modelValue'])
</script>
