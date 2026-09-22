<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'

const emit = defineEmits(['whisper', 'memories'])
const props = defineProps({ message: { type: String, default: '' } })
const storageKey = 'momo:little-room:v1'
let saved = {}
try { saved = JSON.parse(localStorage.getItem(storageKey) || '{}') || {} } catch {}
const rug = ref(Boolean(saved.rug)), dim = ref(Boolean(saved.dim)), seated = ref(false)
const stage = ref(null), ready = ref(false), failed = ref(false), busy = ref(false), activity = ref('idle')
const line = ref(rug.value ? '你回来啦。那一半还给你留着。' : '听说，这里以后就是我们的地方了。')
const dragging = ref(false), dragX = ref(0), dragY = ref(0)
const hint = computed(() => busy.value ? '等一下，它正认真地做一件小事。' : dim.value && rug.value ? '灯暗一点，心里亮一点。' : '试着摸摸它。它也在悄悄看着你。')
let scene, disposed = false, idleTimer, protectedUntil = 0, touches = 0, startX = 0, startY = 0
function speak(text, duration = 10000) { line.value = text; protectedUntil = Date.now() + duration }
function save() { try { localStorage.setItem(storageKey, JSON.stringify({ rug: rug.value, dim: dim.value })) } catch { speak('这一刻先留在这里。浏览器暂时没能保存房间。') } }
function sync() { scene?.update({ rug: rug.value, dim: dim.value, seated: seated.value }) }
function act(name) { if (!ready.value || busy.value) return false; scene?.act(name); return true }
function pet() {
  if (!act('pet')) return
  speak(['嗯？是你。', '再往左一点……对，就是那里。', '耳朵也想被摸一下。', '我没有偷懒。我在替你把地毯坐软。'][touches++ % 4])
}
function lamp() { if (!ready.value) return; dim.value = !dim.value; sync(); save(); speak(dim.value ? '把今天的声音，也调小一点。' : '啊，我的小影子也醒了。') }
function sit() { if (busy.value || !rug.value) return; seated.value = !seated.value; sync(); speak(seated.value ? '挪一点。我们一人一半。' : '好，我替你把位置暖着。') }
function layRug() {
  if (rug.value || !act('rug')) return
  rug.value = true; busy.value = true; activity.value = 'rug'; sync(); save()
  speak('这是……给我的？', 10000)
}
function foldRug() { if (busy.value) return; rug.value = false; seated.value = false; sync(); save(); speak('换个地方铺也好。我跟着你。') }
function play() { if (!act('play')) return; busy.value = true; activity.value = 'play'; speak('圆圆的那个，等一下！') }
function stretch() { if (!act('stretch')) return; busy.value = true; activity.value = 'stretch'; speak('从耳朵尖……伸到脚趾头。') }
function sceneAction(name) {
  if (disposed) return
  if (name === 'pet') pet()
  if (name === 'lamp') lamp()
  if (name === 'sit') sit()
  if (name === 'rug-touch' && activity.value === 'rug') speak('先用爪子试一下。软不软？')
  if (name === 'rug-rest' && activity.value === 'rug') speak('噢。整只都想待在上面了。')
  if (name === 'play-chase' && activity.value === 'play') speak('差一点点。再差一点点。')
  if (name === 'settled' && activity.value === 'rug') { busy.value = false; activity.value = 'idle'; speak('这边空出来了。你要不要也坐下？') }
  if (name === 'played' && activity.value === 'play') { busy.value = false; activity.value = 'idle'; speak('好吧，今天算纸团赢。') }
  if (name === 'stretched' && activity.value === 'stretch') { busy.value = false; activity.value = 'idle'; speak('呼。你也可以松松肩膀。') }
}
function dragStart(e) { if (!ready.value || rug.value) return; dragging.value = true; startX = e.clientX; startY = e.clientY; e.currentTarget.setPointerCapture(e.pointerId) }
function dragMove(e) { if (dragging.value) { dragX.value = e.clientX - startX; dragY.value = e.clientY - startY } }
function dragCancel() { dragging.value = false; dragX.value = 0; dragY.value = 0 }
function dragEnd(e) {
  if (!dragging.value) return
  const distance = Math.hypot(dragX.value, dragY.value), r = stage.value.getBoundingClientRect()
  const inside = e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom
  dragCancel(); if (distance < 8 || inside) layRug(); else speak('放到房间里，我就能踩到了。')
}
watch(() => props.message, value => { if (value) { busy.value = false; activity.value = 'idle'; scene?.act('idle'); speak(value, 45000) } })
onMounted(async () => {
  try {
    const { createMomoScene } = await import('../scene/momoScene')
    if (disposed) return
    const instance = await createMomoScene(stage.value, { rug: rug.value, dim: dim.value }, sceneAction)
    if (disposed) { instance.dispose(); return }
    scene = instance; ready.value = true; scene.act('wave')
    let count = 0
    idleTimer = setInterval(() => {
      if (busy.value || document.hidden || Date.now() < protectedUntil) return
      const lines = dim.value && rug.value ? ['我的耳朵先睡着了。', '心里那盏小灯，还亮着。'] : ['我在研究，影子为什么总跟着我。', '窗外刚刚那朵云，有一点像面包。', '如果月亮来做客，我们给它搬个凳子。', '不用找话说。待在这里就好。']
      speak(lines[count++ % lines.length])
      if (!dim.value && count % 3 === 0) scene.act('stretch')
    }, 21000)
  } catch (error) { failed.value = true; console.error('Momo 3D scene could not start', error) }
})
onBeforeUnmount(() => { disposed = true; clearInterval(idleTimer); scene?.dispose() })
</script>

<template>
  <section class="momo-living" :class="{ 'lights-low': dim }">
    <div class="living-title">
      <span class="living-eyebrow"><i></i>慢一点，也没关系</span>
      <h1>{{ rug ? '这里，有一个位置属于你。' : '和小云朵，待一会儿。' }}</h1>
      <p>{{ rug ? '没有需要完成的事。你来，就很好。' : '不用找话说，也不用急着变好。' }}</p>
    </div>
    <div class="room-stage-wrap" :class="{ 'drop-invitation': dragging }">
      <div ref="stage" class="three-stage" data-testid="momo-3d-scene"></div>
      <div v-if="!ready && !failed" class="scene-loading"><span></span><p>小屋正在慢慢亮起来</p><small>给 Momo 一点点时间</small></div>
      <div v-if="failed" class="scene-error"><p>这次没能打开立体小屋。</p><small>请检查浏览器是否启用了硬件加速，刷新后再试。</small><button @click="emit('whisper')">先和 Momo 说句话</button></div>
      <div v-if="ready" class="momo-caption" aria-live="polite"><span class="caption-heart" aria-hidden="true">♡</span><Transition name="thought" mode="out-in"><p :key="line">{{ line }}</p></Transition></div>
      <span v-if="dragging" class="drop-label">放在这里，给它一点柔软</span>
      <div v-if="ready" class="room-weather"><svg v-if="!dim" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></svg><svg v-else viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M20.4 14.1A8.5 8.5 0 0 1 9.9 3.6a8.5 8.5 0 1 0 10.5 10.5Z"/></svg><span>{{ dim ? '月亮值班中' : '今天的小屋，晴' }}</span></div>
      <button v-if="ready && rug && !busy" class="sit-button" :class="{ together: seated }" :aria-pressed="seated" @click="sit"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M7 14c-2.3-1.9-4-3.5-4-5.6A3.4 3.4 0 0 1 9 6.2a3.4 3.4 0 0 1 6 2.2c0 2.1-1.7 3.7-4 5.6L9 15.6 7 14Z"/><path d="M16 13a2.6 2.6 0 0 1 4.5 1.8c0 1.7-1.6 3.1-4.5 5.2-2.9-2.1-4.5-3.5-4.5-5.2"/></svg>{{ seated ? '就这样，坐一会儿' : '坐在它旁边' }}<span v-if="!seated" aria-hidden="true">↗</span></button>
    </div>
    <p class="interaction-hint">{{ ready ? hint : '你的那一半位置，一直留着。' }}</p>
    <nav class="room-actions" aria-label="和 Momo 待一会儿">
      <button v-if="!rug" class="primary-action rug-gift" :class="{ 'is-dragging': dragging }" :disabled="!ready" :style="{ transform: `translate(${dragX}px, ${dragY}px)` }" @pointerdown="dragStart" @pointermove="dragMove" @pointerup="dragEnd" @pointercancel="dragCancel" @keydown.enter.prevent="layRug" @keydown.space.prevent="layRug"><span class="mini-rug" aria-hidden="true"></span><span class="action-copy">铺一块软地毯<small>轻点，或者拖进小屋</small></span><span class="action-arrow" aria-hidden="true">↗</span></button>
      <button v-else class="primary-action" :disabled="!ready || busy" @click="play"><svg class="paper-icon" viewBox="0 0 32 32" fill="none" aria-hidden="true"><path d="m8 5 12-1 8 8-2 13-12 4-10-9Z"/><path d="m8 5 4 10 8-11m-8 11 14 10M4 20l8-5 3 14m-3-14 16-3"/></svg><span class="action-copy">一起玩一会儿<small>给它滚一只小纸团</small></span><span class="action-arrow" aria-hidden="true">↗</span></button>
      <span class="dock-divider" aria-hidden="true"></span>
      <button class="little-action" :disabled="!ready || busy" @click="pet"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M12 20S3 14.7 3 8.7a4.3 4.3 0 0 1 9-1 4.3 4.3 0 0 1 9 1c0 6-9 11.3-9 11.3Z"/></svg><span>摸摸头</span></button>
      <button class="little-action" :disabled="!ready || busy" @click="stretch"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="5" r="2"/><path d="M5 7c2 3 4 4 7 4s5-1 7-4M12 11v6m0 0-4 4m4-4 4 4"/></svg><span>伸懒腰</span></button>
      <button class="little-action" :class="{ selected: dim }" :disabled="!ready" :aria-pressed="dim" @click="lamp"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="m8 3-4 11h16L16 3H8Zm4 11v7m-4 0h8"/><path d="M17 14v4"/></svg><span>{{ dim ? '亮一点' : '暗一点' }}</span></button>
      <button class="little-action whisper-action" @click="emit('whisper')"><svg viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M21 11.2c0 4.5-4 8.2-9 8.2-1.1 0-2.3-.2-3.3-.5L4 21v-4.1a7.7 7.7 0 0 1-1-11A9.6 9.6 0 0 1 12 3c5 0 9 3.7 9 8.2Z"/><path d="M8 11h.01M12 11h.01M16 11h.01"/></svg><span>说句话</span></button>
    </nav>
    <div class="room-small-links"><button @click="emit('memories')">我们的小回忆 <span aria-hidden="true">↗</span></button><span v-if="rug" aria-hidden="true">·</span><button v-if="rug" :disabled="busy" @click="foldRug">收起地毯</button></div>
  </section>
</template>

<style scoped>
.momo-living {
  --room-ink: #374a43;
  --room-muted: #738278;
  --room-accent: #b17354;
  max-width: 1260px;
  margin: auto;
  color: var(--room-ink);
}
.living-title { position: relative; z-index: 2; display: grid; grid-template-columns: 1fr auto; text-align: left; padding: 22px 4% 0; pointer-events: none; }
.living-eyebrow { grid-column: 1; display: inline-flex; align-items: center; gap: 9px; color: var(--room-muted); font-size: 11px; letter-spacing: 2.4px; }
.living-eyebrow i { width: 5px; height: 5px; border-radius: 50%; background: #8caa86; box-shadow: 0 0 0 4px #8caa8615; }
.living-title h1 { grid-column: 1; margin: 12px 0 7px; font-family: "Songti SC", "STSong", "Noto Serif SC", serif; font-size: clamp(27px, 2.7vw, 36px); font-weight: 500; line-height: 1.4; letter-spacing: 1.6px; }
.living-title p { grid-column: 2; grid-row: 2; align-self: center; max-width: 200px; margin: 0 0 0 28px; padding-left: 20px; border-left: 1px solid #7f968435; font-size: 12px; line-height: 1.9; color: var(--room-muted); letter-spacing: .5px; }
.room-stage-wrap { position: relative; width: 100%; height: clamp(340px, calc(100svh - 358px), 620px); max-width: 1180px; margin: -8px auto 0; transition: filter .8s; }
.room-stage-wrap::before { content: ""; position: absolute; z-index: -1; inset: 1% 6% 0; border-radius: 50%; background: radial-gradient(ellipse, #bed0bb75 0%, #c8d7c343 46%, transparent 72%); pointer-events: none; transition: background 1s; }
.three-stage { width: 100%; height: 100%; touch-action: pan-y; }
.three-stage :deep(canvas) { display: block; width: 100%; height: 100%; outline: 0; mask-image: linear-gradient(to bottom, transparent, #000 10%, #000 90%, transparent); }
.momo-caption { position: absolute; z-index: 3; bottom: 12px; left: 50%; transform: translateX(-50%); display: flex; align-items: center; justify-content: center; gap: 11px; width: max-content; max-width: min(560px, 88%); min-height: 50px; padding: 11px 23px; color: #405047; background: #fffdf3e6; border: 1px solid #fffdf6; border-radius: 22px 22px 22px 6px; box-shadow: 0 6px 25px #4658440a; backdrop-filter: blur(12px); font-size: 14px; line-height: 1.8; pointer-events: none; }
.momo-caption p { margin: 0; }
.caption-heart { flex: 0 0 auto; color: #ba8853; font: 22px/1 Georgia, serif; }
.room-weather { position: absolute; left: 7%; top: 12%; display: flex; align-items: center; gap: 9px; color: #738476; font-size: 11px; letter-spacing: .8px; pointer-events: none; }
.room-weather svg { width: 21px; height: 21px; stroke: #8d9d79; stroke-width: 1.2; stroke-linecap: round; stroke-linejoin: round; }
.sit-button { position: absolute; bottom: 19%; right: 14%; display: inline-flex; align-items: center; gap: 8px; min-height: 44px; padding: 10px 16px; color: #52604d; background: #f8faeee0; border: 1px solid #fffef0; border-radius: 30px; backdrop-filter: blur(10px); box-shadow: 0 8px 30px #4a624909; font-size: 12px; transition: transform .2s, background .2s; }
.sit-button svg { width: 21px; height: 21px; stroke: #8b9a76; stroke-width: 1.3; stroke-linecap: round; stroke-linejoin: round; }
.sit-button:hover { transform: translateY(-2px); background: #fffef5; }
.sit-button.together { color: #68784c; background: #ecf1dfe6; }
.interaction-hint { position: relative; margin: 6px 0 18px; color: #7d8777; font-size: 12px; text-align: center; line-height: 1.7; letter-spacing: .4px; pointer-events: none; }
.room-actions { position: relative; z-index: 12; display: flex; align-items: center; justify-content: center; gap: 8px; width: max-content; max-width: 100%; margin: auto; padding: 4px 0; }
.room-actions button { display: flex; align-items: center; justify-content: center; gap: 9px; min-height: 68px; border: 1px solid #eef1e980; background: #e3e9dc66; border-radius: 18px; color: #586650; font-size: 12px; transition: background .2s, box-shadow .2s, color .2s; -webkit-tap-highlight-color: transparent; }
.room-actions button:hover:enabled { color: #344c3a; background: #dde6d5d9; box-shadow: 0 5px 15px #5c765409; }
.room-actions button:active:enabled { background: #d1ddcbbb; }
.room-actions button:disabled { opacity: .38; cursor: default; }
.room-actions .little-action { min-width: 78px; padding: 8px 12px; flex-direction: column; gap: 7px; }
.little-action svg { width: 22px; height: 22px; stroke: #788c67; stroke-width: 1.3; stroke-linecap: round; stroke-linejoin: round; }
.little-action.whisper-action svg { stroke-width: 1.15; }
.little-action.whisper-action svg path:last-child { stroke-width: 2.5; }
.little-action.selected { background: #dce4e5b3; color: #526a70; }
.little-action.selected svg { stroke: #638088; }
.room-actions .primary-action { min-width: 242px; min-height: 72px; padding: 11px 18px; justify-content: flex-start; gap: 16px; background: linear-gradient(125deg, #966047, #a06f4d); border: 1px solid #98664477; box-shadow: 0 6px 18px #856a4417, inset 0 1px 0 #f5e0b930; }
.room-actions .primary-action:hover:enabled { background: linear-gradient(125deg, #89563e, #956343); box-shadow: 0 8px 24px #856a4429; }
.action-copy { text-align: left; color: #fff9e9; font-size: 14px; white-space: nowrap; }
.action-copy small { display: block; margin-top: 5px; color: #fff2da; font-size: 11px; }
.action-arrow { margin-left: auto; color: #fff0d1b5; font-size: 17px; }
.dock-divider { width: 1px; height: 30px; margin: 0 4px; background: #80927626; }
.rug-gift { touch-action: none; cursor: grab; }
.rug-gift.is-dragging { z-index: 15; cursor: grabbing; box-shadow: 0 15px 38px #9a765240; }
.mini-rug { position: relative; flex: 0 0 auto; display: block; width: 40px; height: 29px; border-radius: 50%; background: repeating-radial-gradient(ellipse, #ceb095 0 1.5px, #ebd4b9 2px 3px); transform: rotate(-18deg); box-shadow: 0 3px 4px #94735524, inset 0 0 0 1px #bf9a792d; }
.paper-icon { flex: 0 0 auto; width: 37px; height: 37px; padding: 3px; stroke: #aa7b4f; fill: #fff3d4; stroke-width: 1; stroke-linejoin: round; transform: rotate(-12deg); }
.room-small-links { display: flex; align-items: center; justify-content: center; gap: 9px; margin: 15px 0 0; color: #7d8876; font-size: 12px; }
.room-small-links button { min-height: 38px; border: 0; background: none; color: inherit; padding: 8px; font-size: 12px; transition: color .2s; }
.room-small-links button span { display: inline-block; margin-left: 4px; opacity: .7; }
.room-small-links button:hover { color: #3f5841; }
.room-small-links button:disabled { opacity: .4; cursor: default; }
.drop-invitation { filter: drop-shadow(0 0 30px #ffe8b5aa); }
.drop-label { position: absolute; bottom: 24%; left: 50%; transform: translateX(-50%); padding: 14px 22px; background: #fff8e6eb; border: 1px solid white; border-radius: 24px; color: #917052; font-size: 13px; pointer-events: none; }
.scene-loading, .scene-error { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 14px; color: #71816b; font-size: 14px; text-align: center; }
.scene-loading p, .scene-error p { margin: 0; }
.scene-loading small, .scene-error small { color: #8e9a84; font-size: 12px; line-height: 1.6; }
.scene-loading > span { width: 30px; height: 30px; margin-bottom: 7px; border: 2px solid #ceb29935; border-top-color: #bf9a7d; border-radius: 50%; animation: spin 1.5s linear infinite; }
.scene-error button { padding: 12px 20px; border: 0; border-radius: 16px; background: #f8eddf; color: #8a685c; }
.lights-low { --room-ink: #405963; --room-muted: #7d9495; }
.lights-low .room-stage-wrap::before { background: radial-gradient(ellipse, #88aaa76b 0%, #b8cfc33d 46%, transparent 72%); }
.lights-low .momo-caption { background: #eef4eadf; color: #435f62; border-color: #f9fff6; }
.lights-low .room-weather { color: #728e92; }
.lights-low .room-weather svg { stroke: #75999e; }
button:focus-visible { outline: 3px solid #71865c; outline-offset: 4px; }
.thought-enter-active, .thought-leave-active { transition: opacity .3s, transform .3s; }
.thought-enter-from { opacity: 0; transform: translateY(4px); }
.thought-leave-to { opacity: 0; transform: translateY(-4px); }
@keyframes spin { to { transform: rotate(360deg); } }
@media (min-width: 1500px) and (min-height: 950px) {
  .living-title { padding-top: 43px; }
  .room-stage-wrap { height: 60vh; }
}
@media (max-width: 900px) {
  .living-title { padding-top: 24px; }
  .living-title p { max-width: 160px; font-size: 11px; }
  .room-stage-wrap { margin-top: 0; }
  .room-weather { left: 5%; top: 12%; }
  .sit-button { right: 7%; bottom: 18%; }
  .room-actions { gap: 6px; padding: 4px 0; }
  .room-actions .primary-action { min-width: 218px; padding: 11px 14px; gap: 11px; }
  .room-actions .little-action { min-width: 69px; padding: 7px 9px; }
  .dock-divider { margin: 0 3px; }
}
@media (max-width: 620px) {
  .living-title { display: block; padding: 19px 5px 0; }
  .living-eyebrow { font-size: 11px; letter-spacing: 1px; }
  .living-title h1 { font-size: 25px; margin: 10px 0 8px; letter-spacing: .3px; }
  .living-title p { max-width: none; margin: 0; padding: 0; border: 0; font-size: 12px; }
  .room-stage-wrap { width: calc(100% + 32px); height: clamp(330px, calc(100svh - 400px), 420px); margin: 8px -16px 0; }
  .momo-caption { bottom: 2px; max-width: 90%; min-height: 44px; padding: 9px 15px; gap: 8px; font-size: 12px; }
  .caption-heart { font-size: 20px; }
  .room-weather { top: 6%; left: 7%; font-size: 10px; gap: 6px; }
  .room-weather svg { width: 18px; height: 18px; }
  .sit-button { right: 5%; bottom: 18%; min-height: 40px; font-size: 11px; padding: 8px 12px; gap: 5px; }
  .sit-button svg { width: 18px; height: 18px; }
  .interaction-hint { margin: 10px 0 16px; font-size: 11px; }
  .room-actions { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 7px; width: min(100%, 420px); padding: 0; }
  .room-actions .primary-action { grid-column: 1 / -1; min-height: 62px; width: 100%; margin-bottom: 2px; padding: 10px 21px; gap: 17px; border-radius: 18px; }
  .room-actions .little-action { min-width: 0; min-height: 63px; padding: 8px 3px; font-size: 12px; gap: 7px; }
  .little-action svg { width: 22px; height: 22px; }
  .dock-divider { display: none; }
  .action-copy { font-size: 14px; }
  .action-copy small { font-size: 11px; }
  .room-small-links { margin-top: 12px; }
  .room-small-links button { font-size: 11px; }
  .drop-label { width: max-content; max-width: 90%; bottom: 24%; font-size: 12px; }
}
@media (max-width: 370px) { .living-title h1 { font-size: 22px; } .room-stage-wrap { height: 330px; } }
@media (prefers-reduced-motion: reduce) { *, *::before { transition: none !important; animation: none !important; } }
</style>
