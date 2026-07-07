<template>
  <div class="diff-view">
    <div class="diff-view__bar">
      <span class="diff-view__label">{{ label }}</span>
      <el-radio-group v-model="mode" size="small">
        <el-radio-button value="side-by-side">双栏对比</el-radio-button>
        <el-radio-button value="line-by-line">单栏</el-radio-button>
      </el-radio-group>
    </div>
    <div v-if="!diff || !diff.trim()" class="diff-view__empty">暂无 diff 内容</div>
    <div v-else class="diff-view__body" :style="{ maxHeight }" v-html="renderedHtml"></div>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue'
import { html as diff2html } from 'diff2html'
import 'diff2html/bundles/css/diff2html.min.css'

const props = defineProps({
  diff: { type: String, default: '' },
  label: { type: String, default: 'git diff' },
  maxHeight: { type: String, default: '560px' },
})

const mode = ref('side-by-side')

const renderedHtml = computed(() => {
  try {
    return diff2html(props.diff || '', {
      drawFileList: true,
      matching: 'lines',
      outputFormat: mode.value,
      renderNothingWhenEmpty: true,
      colorScheme: 'dark',
    })
  } catch (error) {
    return `<pre style="white-space: pre-wrap">${(props.diff || '').replace(/</g, '&lt;')}</pre>`
  }
})
</script>

<style scoped>
.diff-view {
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 8px;
  overflow: hidden;
  /* 隔离内部 d2h 定位元素的层叠,避免绘制到页面 sticky 头部/侧栏之上 */
  isolation: isolate;
}
.diff-view__bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 6px 12px;
  background: var(--el-fill-color-light);
  border-bottom: 1px solid var(--el-border-color-lighter);
}
.diff-view__label {
  font-size: 12px;
  color: var(--el-text-color-secondary);
}
.diff-view__empty {
  padding: 24px;
  text-align: center;
  color: var(--el-text-color-secondary);
  font-size: 13px;
}
.diff-view__body {
  overflow: auto;
}
.diff-view__body :deep(.d2h-wrapper) { margin: 0; }
.diff-view__body :deep(.d2h-file-wrapper) {
  /* 行号是 position:absolute 停在静态位置的实现,必须在滚动容器内给它一个定位锚,
     否则容器滚动时行号不随内容移动,会飘出容器盖住页面其他区块 */
  position: relative;
  margin: 0;
  border: none;
  border-top: 1px solid var(--el-border-color-lighter);
  border-radius: 0;
}
.diff-view__body :deep(.d2h-file-header) {
  padding: 6px 10px;
  background: var(--el-fill-color-lighter);
}
.diff-view__body :deep(.d2h-file-list-wrapper) {
  margin: 8px 10px;
}
.diff-view__body :deep(.d2h-code-line),
.diff-view__body :deep(.d2h-code-side-line) {
  font-family: 'JetBrains Mono', 'SFMono-Regular', Consolas, monospace;
  font-size: 12px;
}
</style>
