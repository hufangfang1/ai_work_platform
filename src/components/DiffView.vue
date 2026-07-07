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
    <template v-else>
      <div v-if="files.length > 1" class="diff-view__files">
        <button
          v-for="(file, index) in files"
          :key="index"
          type="button"
          class="diff-view__file"
          :class="{ 'is-active': index === activeFile }"
          @click="scrollToFile(index)"
        >
          <span class="diff-view__file-name">{{ fileName(file) }}</span>
          <span class="diff-view__file-added">+{{ file.addedLines }}</span>
          <span class="diff-view__file-deleted">-{{ file.deletedLines }}</span>
        </button>
      </div>
      <div
        ref="bodyRef"
        class="diff-view__body"
        :style="{ maxHeight }"
        @click="onBodyClick"
        v-html="renderedHtml"
      ></div>
    </template>
  </div>
</template>

<script setup>
import { computed, ref, watch } from 'vue'
import { html as diff2html, parse as diffParse } from 'diff2html'
import 'diff2html/bundles/css/diff2html.min.css'

const props = defineProps({
  diff: { type: String, default: '' },
  label: { type: String, default: 'git diff' },
  maxHeight: { type: String, default: '560px' },
})

const mode = ref('side-by-side')
const bodyRef = ref(null)
const activeFile = ref(-1)

const files = computed(() => {
  try {
    return diffParse(props.diff || '')
  } catch (error) {
    return []
  }
})

const renderedHtml = computed(() => {
  try {
    return diff2html(props.diff || '', {
      drawFileList: false,
      matching: 'lines',
      outputFormat: mode.value,
      renderNothingWhenEmpty: true,
      colorScheme: 'dark',
    })
  } catch (error) {
    return `<pre style="white-space: pre-wrap">${(props.diff || '').replace(/</g, '&lt;')}</pre>`
  }
})

// 内容重渲染(切换单双栏/换 diff)后,清掉为滚动定位补的底部留白
watch(renderedHtml, () => {
  activeFile.value = -1
  if (bodyRef.value) bodyRef.value.style.paddingBottom = ''
})

function fileName(file) {
  return !file.newName || file.newName === '/dev/null' ? file.oldName : file.newName
}

function scrollToElement(element) {
  const body = bodyRef.value
  if (!body || !element) return
  const top = element.getBoundingClientRect().top - body.getBoundingClientRect().top + body.scrollTop
  // 末尾的文件剩余高度不足一屏时滚不到顶部,补足底部留白让它也能顶到可视区最上面
  const maxScroll = body.scrollHeight - body.clientHeight
  if (top > maxScroll) {
    body.style.paddingBottom = `${Math.ceil(top - maxScroll)}px`
  }
  body.scrollTo({ top })
}

function scrollToFile(index) {
  activeFile.value = index
  scrollToElement(bodyRef.value?.querySelectorAll('.d2h-file-wrapper')[index])
}

// diff 内部残留的 #d2h-xxx 锚点在 hash 路由下会被当成路由跳转导致白屏,拦截后改为容器内滚动
function onBodyClick(event) {
  const link = event.target.closest('a[href^="#"]')
  if (!link || !bodyRef.value?.contains(link)) return
  event.preventDefault()
  const id = decodeURIComponent(link.getAttribute('href').slice(1))
  if (!id) return
  scrollToElement(bodyRef.value.querySelector(`#${CSS.escape(id)}`))
}
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
.diff-view__files {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  padding: 8px 12px;
  background: var(--el-fill-color-lighter);
  border-bottom: 1px solid var(--el-border-color-lighter);
  max-height: 120px;
  overflow-y: auto;
  overscroll-behavior: contain;
}
.diff-view__file {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  border: 1px solid var(--el-border-color-lighter);
  border-radius: 999px;
  background: transparent;
  color: var(--el-text-color-regular);
  font-size: 12px;
  font-family: 'JetBrains Mono', 'SFMono-Regular', Consolas, monospace;
  cursor: pointer;
}
.diff-view__file:hover {
  border-color: var(--el-color-primary);
  color: var(--el-color-primary);
}
.diff-view__file.is-active {
  border-color: var(--el-color-primary);
  color: var(--el-color-primary);
  background: var(--el-color-primary-light-9);
}
.diff-view__file-added {
  color: var(--el-color-success);
}
.diff-view__file-deleted {
  color: var(--el-color-danger);
}
.diff-view__body {
  overflow: auto;
  /* 滚到头后不把滚动传递给弹框/页面 */
  overscroll-behavior: contain;
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
.diff-view__body :deep(.d2h-code-line),
.diff-view__body :deep(.d2h-code-side-line) {
  font-family: 'JetBrains Mono', 'SFMono-Regular', Consolas, monospace;
  font-size: 12px;
}
</style>
