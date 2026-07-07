<template>
  <div class="md-view" :style="maxHeight ? { maxHeight, overflow: 'auto' } : {}" v-html="html"></div>
</template>

<script setup>
import { computed } from 'vue'
import { marked } from 'marked'

const props = defineProps({
  source: { type: String, default: '' },
  maxHeight: { type: String, default: '' },
})

const html = computed(() => {
  try {
    return marked.parse(props.source || '', { gfm: true, breaks: true, async: false })
  } catch (error) {
    return `<pre>${(props.source || '').replace(/</g, '&lt;')}</pre>`
  }
})
</script>

<style scoped>
.md-view {
  min-width: 0;
  max-width: 100%;
  font-size: 13px;
  line-height: 1.7;
  color: var(--el-text-color-primary);
  word-break: break-word;
  overflow-wrap: anywhere;
}
.md-view :deep(h1),
.md-view :deep(h2),
.md-view :deep(h3),
.md-view :deep(h4) {
  margin: 14px 0 8px;
  font-weight: 600;
  line-height: 1.4;
}
.md-view :deep(h1) { font-size: 18px; }
.md-view :deep(h2) { font-size: 16px; padding-bottom: 4px; border-bottom: 1px solid var(--el-border-color-lighter); }
.md-view :deep(h3) { font-size: 14px; }
.md-view :deep(p) { margin: 6px 0; }
.md-view :deep(ul),
.md-view :deep(ol) { margin: 6px 0; padding-left: 22px; }
.md-view :deep(li) { margin: 2px 0; }
.md-view :deep(code) {
  white-space: break-spaces;
  overflow-wrap: anywhere;
  padding: 1px 5px;
  border-radius: 4px;
  background: var(--el-fill-color-light);
  font-family: 'JetBrains Mono', 'SFMono-Regular', Consolas, monospace;
  font-size: 12px;
}
.md-view :deep(pre) {
  max-width: 100%;
  margin: 8px 0;
  padding: 10px 12px;
  border-radius: 6px;
  background: var(--el-fill-color-light);
  overflow-x: auto;
}
.md-view :deep(pre code) {
  padding: 0;
  background: none;
  white-space: pre-wrap;
}
.md-view :deep(table) {
  display: block;
  max-width: 100%;
  overflow-x: auto;
  margin: 8px 0;
  border-collapse: collapse;
  width: 100%;
  font-size: 12.5px;
}
.md-view :deep(th),
.md-view :deep(td) {
  padding: 6px 10px;
  border: 1px solid var(--el-border-color-lighter);
  text-align: left;
}
.md-view :deep(th) { background: var(--el-fill-color-light); font-weight: 600; }
.md-view :deep(blockquote) {
  margin: 8px 0;
  padding: 4px 12px;
  border-left: 3px solid var(--el-border-color);
  color: var(--el-text-color-secondary);
}
.md-view :deep(hr) {
  margin: 12px 0;
  border: none;
  border-top: 1px solid var(--el-border-color-lighter);
}
.md-view :deep(a) { color: var(--el-color-primary); }
.md-view :deep(img) { max-width: 100%; }
</style>
