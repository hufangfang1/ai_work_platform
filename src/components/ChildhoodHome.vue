<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { homePhotos, gateZ, addedRooms } from '../scene/homeLayout.js'
const mapGateY=78+gateZ*7.5,mapWingFrontY=78+addedRooms[0].bounds[2]*7.5
const boundaryPath=`M18 138V${mapGateY}H75m23 0h39v${138-mapGateY}`
const housePath=`M17 135h94V${mapWingFrontY}h24v${182-mapWingFrontY}H17Z`
const host=ref(null),ready=ref(false),entered=ref(false),error=ref(''),warning=ref(''),view=ref(2),mode=ref('walk'),gallery=ref(false),notes=ref(false),position=ref({x:0,z:3,yaw:0})
const current=computed(()=>homePhotos[view.value])
const photoIndex=ref(2),selectedPhoto=computed(()=>homePhotos[photoIndex.value])
let world,disposed=false
const drawer=ref(null)
let returnFocus=null
watch([gallery,notes],async([a,b])=>{if(a||b){returnFocus=document.activeElement;await nextTick();drawer.value?.querySelector('button')?.focus()}else{returnFocus?.focus?.()}})
const directions=[{key:'KeyW',label:'向前走',symbol:'↑'},{key:'KeyA',label:'向左走',symbol:'←'},{key:'KeyS',label:'向后走',symbol:'↓'},{key:'KeyD',label:'向右走',symbol:'→'}]
function enter(){entered.value=true;world?.enter()}
function go(index){view.value=index;mode.value='walk';gallery.value=false;notes.value=false;entered.value=true;world?.view(index)}
function toggleGallery(){gallery.value=!gallery.value;if(gallery.value)photoIndex.value=view.value;notes.value=false;world?.pause(gallery.value||!entered.value)}
function toggleNotes(){notes.value=!notes.value;gallery.value=false;world?.pause(notes.value||!entered.value)}
function overview(){entered.value=true;mode.value='overview';world?.overview()}
function keyClose(e){if(e.key==='Escape'){gallery.value=false;notes.value=false;world?.pause(!entered.value)}
  if(e.key==='Tab'&&(gallery.value||notes.value)){const items=[...(drawer.value?.querySelectorAll('button:not(:disabled),a[href]')||[])],first=items[0],last=items.at(-1);if(e.shiftKey&&document.activeElement===first){e.preventDefault();last?.focus()}else if(!e.shiftKey&&document.activeElement===last){e.preventDefault();first?.focus()}}
}
onMounted(async()=>{
  document.title='回家 · 记忆里的院子'
  window.addEventListener('keydown',keyClose)
  try{const {createChildhoodScene}=await import('../scene/childhoodScene.js');if(disposed)return
    const instance=await createChildhoodScene(host.value,change=>{if(disposed)return;if(change.position)position.value=change.position;if(change.mode)mode.value=change.mode;if(change.view!==undefined)view.value=change.view;if(change.warning)warning.value=change.warning})
    if(disposed){instance.dispose();return}world=instance;ready.value=true
  }catch(e){console.error('Courtyard scene failed',e);error.value='三维场景未能打开。可以先看原照片；请确认浏览器支持 WebGL。'}
})
onBeforeUnmount(()=>{disposed=true;window.removeEventListener('keydown',keyClose);world?.dispose()})
</script>

<template>
  <main class="home-experience" :class="{entered}">
    <div ref="host" class="home-canvas"></div>
    <div class="home-vignette" aria-hidden="true"></div>
    <header class="home-top">
      <button class="home-brand" @click="go(2)" :disabled="!ready"><span class="door-symbol">⌂</span><span>回家<small>THE PLACE WE REMEMBER</small></span></button>
      <span class="archive-status"><i></i> 私人记忆档案 <span> / </span> 001</span>
      <div class="home-top-actions"><button @click="toggleNotes">关于这次重建</button><button class="photos-toggle" @click="toggleGallery"><span aria-hidden="true">▧</span> 五张老照片</button></div>
    </header>

    <section v-if="!entered" class="home-intro">
      <div class="intro-index"><span>一处院子 · 五张照片</span><i></i></div>
      <h1>记忆里的院子，<br>再走一遍。</h1>
      <p>那扇门，那棵树，窗前的晾衣绳。<br>从你留下的照片，慢慢找回家的模样。</p>
      <button class="enter-home" :disabled="!ready" @click="enter">{{ error?'场景暂不可用':ready?'走进院子':'正在搭起院子…' }}<span aria-hidden="true">↗</span></button>
      <p v-if="error" class="home-error" role="alert">{{ error }}</p>
      <small class="honest-note">照片参考重建 · 非自动扫描 · 布局与尺寸待你校正</small>
    </section>

    <div v-if="entered&&!gallery&&!notes" class="place-caption"><span>正在这里</span><h2>{{ mode==='overview'?'院子的空间关系':current.name }}</h2><p>{{ mode==='overview'?'拖动旋转 · 滚轮缩放 · 点击下方地点回到地面':'拖动环顾 · W A S D 行走 · 也可用右下角方向键' }}</p></div>

    <nav v-if="ready" class="memory-stops" aria-label="走到照片里的位置">
      <span class="stops-label">记忆中的位置</span>
      <button v-for="(photo,i) in homePhotos.slice(0,3)" :key="photo.src" :class="{selected:view===i&&mode==='walk'}" @click="go(i)"><span>0{{i+1}}</span>{{photo.name}}<i aria-hidden="true">↗</i></button>
      <button class="overview-button" :class="{selected:mode==='overview'}" @click="overview"><span aria-hidden="true">⌑</span>俯看院子</button>
    </nav>

    <aside v-if="entered&&!gallery&&!notes" class="home-position" aria-label="院子位置示意，布局为推断">
      <svg viewBox="0 0 150 190" aria-hidden="true"><path :d="boundaryPath"/><path class="map-house" :d="housePath"/><path class="map-shed" d="M19 86h28v49H19Z"/><path d="M19 120h28M20 124h6v8h-6Z" stroke-dasharray="2 2"/><circle cx="25" cy="60" r="7"/><circle class="map-person" :cx="65+position.x*7.5" :cy="78+position.z*7.5" r="3.5"/></svg>
      <span>{{mode==='walk'?'你在院子里':'空间概览'}} · 推断布局</span>
      <div v-if="mode==='walk'" class="walk-pad" aria-label="行走控制"><button v-for="d in directions" :key="d.key" :aria-label="d.label" :class="d.key" @click="world?.step(d.key)">{{d.symbol}}</button></div>
    </aside>

    <div class="home-bottom-note"><span>2015 · 冬日参照</span><span>{{warning||'本地浏览 · 原照片未上传'}}</span></div>

    <section v-if="gallery" ref="drawer" class="photo-drawer" role="dialog" aria-modal="true" aria-label="五张老照片">
      <header><div><small>ORIGINAL PHOTOGRAPHS</small><h2>仅有的五张，<br>也是我们的起点。</h2></div><button aria-label="关闭照片" @click="toggleGallery">×</button></header>
      <img class="original-photo" :src="selectedPhoto.src" :alt="selectedPhoto.name+'，用户提供的老家原照片'">
      <div class="photo-description"><span>0{{photoIndex+1}} / 05</span><h3>{{selectedPhoto.name}}</h3><p>{{selectedPhoto.note}}</p></div>
      <div class="photo-thumbnails"><button v-for="(photo,i) in homePhotos" :key="photo.src" :class="{selected:photoIndex===i}" :aria-label="'查看原照片：'+photo.name" @click="photoIndex=i"><img :src="photo.src" :alt="photo.name"></button></div>
      <button class="photo-location" :disabled="!ready" @click="go(photoIndex)">到场景中的参考位置 <span>↗</span></button>
      <p class="photo-disclaimer">原照片保持不变。场景里看不到的部分没有可靠依据，不代表老家原貌；夏日蓝棚与照片中的人物未重建。</p>
    </section>

    <section v-if="notes" ref="drawer" class="photo-drawer reconstruction-notes" role="dialog" aria-modal="true" aria-label="重建说明">
      <header><div><small>A FIRST RECONSTRUCTION</small><h2>记得的，留下。<br>不确定的，不假装。</h2></div><button aria-label="关闭重建说明" @click="toggleNotes">×</button></header>
      <article><span>01 / 来自照片</span><h3>让你认得出的细节</h3><p>旧白墙、木格窗和铁栏，门口台阶、砖门洞、红铁门、绿篷三轮、车棚与落叶的树。窗户使用了第一张原照片的局部纹理。</p></article>
      <article><span>02 / 已确认与待校正</span><h3>双坡屋顶与连续平台</h3><p>正对院门的主屋与西边房间属于同一栋建筑，两处屋顶都是倒 V 形。走廊上方的平台左右与主屋侧墙平齐，西厢房屋顶紧接平台前沿。贴墙小棚暂按后沿与平台顶面同高衔接，后部洗手池走廊由平台覆盖，前面延伸为车棚。屋脊高度、平台厚度与具体尺寸仍为估计，未重建室内。</p></article>
      <article><span>03 / 没有擅自补齐</span><h3>先停在院子里</h3><p>室内没有照片，所以暂不开放。不同季节的物品没有混放，也没有重建照片中的人物。现在是本地几何搭建，不是 AI 自动生成世界。旧石灰墙、铁门旧漆和木纹使用通用 AI 生成材质，非实物扫描；生成时未使用原照片，浏览时不调用生成接口。</p></article>
      <button class="photo-location" @click="notes=false;overview()">俯看布局，核对记忆 <span>↗</span></button>
    </section>
  </main>
</template>

<style scoped>
.home-experience{position:fixed;inset:0;overflow:hidden;background:#c9cec9;color:#f5f2e8;font-family:"PingFang SC","Microsoft YaHei",sans-serif;isolation:isolate}
.home-canvas{position:absolute;inset:0;touch-action:none}.home-canvas :deep(canvas){display:block;width:100%;height:100%;outline:none}.home-canvas :deep(canvas:focus-visible){outline:2px solid #ddd8bd;outline-offset:-3px}
.home-vignette{position:absolute;inset:0;pointer-events:none;background:linear-gradient(90deg,#121d1bcc 0%,#1923206b 37%,transparent 70%),linear-gradient(0deg,#111a18b3 0%,transparent 28%,transparent 76%,#14201b7d 100%);transition:background .8s}
.entered .home-vignette{background:linear-gradient(0deg,#111a189c,transparent 29%,transparent 82%,#14201b80)}
.home-top{position:absolute;top:0;left:0;right:0;display:flex;align-items:center;justify-content:space-between;padding:26px 38px;gap:24px}
.home-experience button{color:inherit;font:inherit;border:0}.home-experience button:disabled{opacity:.5;cursor:wait}.home-experience button:focus-visible,.home-experience a:focus-visible{outline:2px solid #e4be83;outline-offset:5px}
.home-brand{display:flex;align-items:center;gap:12px;padding:0;background:none;text-align:left;letter-spacing:5px;font-size:23px!important}.door-symbol{font-size:32px;line-height:1}.home-brand small{display:block;font:8px/1.6 Georgia,serif;letter-spacing:1.4px;opacity:.65;margin-top:5px}.archive-status{font-size:10px;letter-spacing:2px;display:flex;align-items:center;gap:12px;opacity:.8}.archive-status i{width:5px;height:5px;border-radius:50%;background:#ced6ad}.archive-status span{opacity:.4}
.home-top-actions{display:flex;align-items:center;gap:20px}.home-top-actions button{font-size:11px;background:none;padding:10px 0}.home-top-actions .photos-toggle{border:1px solid #ffffff42;background:#15211d33;padding:10px 16px;border-radius:3px;backdrop-filter:blur(8px);display:flex;gap:9px;align-items:center}.photos-toggle span{font-size:18px}
.home-intro{position:absolute;left:7%;top:27%;max-width:520px}.intro-index{display:flex;align-items:center;gap:15px;font-size:10px;letter-spacing:3px;color:#d3d4bd}.intro-index i{width:50px;height:1px;background:#e2dfbd66}.home-intro h1{font-family:"Songti SC","STSong",serif;font-size:clamp(36px,4.2vw,62px);font-weight:400;letter-spacing:3px;line-height:1.45;margin:22px 0}.home-intro>p{font-size:13px;line-height:2.2;letter-spacing:1px;color:#e2e1d5c7;margin:0 0 28px}.home-intro .enter-home{width:208px;display:flex;align-items:center;justify-content:space-between;background:#e9e5d5;color:#25372c;min-height:54px;padding:0 22px;border-radius:2px;font-size:13px;letter-spacing:2px;transition:background .2s}.enter-home:hover:enabled{background:#fffbed}.enter-home span{font-size:23px}.honest-note{display:block;margin-top:16px;font-size:9px;line-height:1.6;color:#d8d9c5a3;letter-spacing:.6px}.home-intro .home-error{font-size:12px;color:#f1c6a9;margin-top:15px}
.place-caption{position:absolute;left:38px;top:112px;pointer-events:none;text-shadow:0 2px 14px #12251b80}.place-caption>span{font-size:9px;letter-spacing:3px;opacity:.8}.place-caption h2{font-family:"Songti SC",serif;font-size:27px;font-weight:400;letter-spacing:2px;margin:9px 0}.place-caption p{font-size:10px;opacity:.8;line-height:1.8;margin:0}
.memory-stops{position:absolute;left:38px;bottom:58px;display:flex;align-items:center;gap:8px;max-width:calc(100% - 210px)}.stops-label{font-size:9px;letter-spacing:1.5px;writing-mode:vertical-rl;line-height:1.8;margin-right:16px;opacity:.6}.memory-stops button{display:flex;align-items:center;gap:13px;min-height:48px;padding:10px 17px;font-size:11px;background:#26302b7a;border:1px solid #ffffff24;border-radius:3px;backdrop-filter:blur(12px);white-space:nowrap}.memory-stops button.selected{background:#e4e4d6;color:#2e3c32;border-color:#e4e4d6}.memory-stops button>span{font:10px Georgia,serif;opacity:.6}.memory-stops button i{font-style:normal;margin-left:7px;opacity:.6}.memory-stops .overview-button{background:transparent;border-color:transparent;opacity:.8}
.home-bottom-note{position:absolute;bottom:22px;left:38px;right:38px;display:flex;justify-content:space-between;font-size:9px;letter-spacing:1px;opacity:.6;pointer-events:none}
.home-position{position:absolute;right:32px;bottom:60px;display:flex;align-items:center;flex-direction:column;padding:13px 12px 10px;background:#20312a70;backdrop-filter:blur(10px);border:1px solid #d8ddc924;border-radius:3px}.home-position svg{width:105px;height:125px;fill:none;stroke:#e4e3d379;stroke-width:1.5}.home-position .map-house{fill:#ced7be2b}.home-position .map-shed{fill:#ced7be12}.home-position .map-person{fill:#efc994;stroke:#efc994;stroke-width:1}.home-position>span{font-size:8px;color:#dddfce;opacity:.7}.walk-pad{display:grid;grid-template-columns:repeat(3,32px);gap:3px;margin-top:12px}.walk-pad button{width:32px;height:29px;background:#ffffff15;border:1px solid #ffffff18;border-radius:2px}.walk-pad button:hover{background:#ffffff3b}.walk-pad .KeyW{grid-column:2}.walk-pad .KeyA{grid-column:1;grid-row:2}.walk-pad .KeyS{grid-column:2;grid-row:2}.walk-pad .KeyD{grid-column:3;grid-row:2}
.photo-drawer{position:absolute;z-index:10;top:0;right:0;bottom:0;width:min(490px,100%);overflow-y:auto;background:#eeeae0;color:#344139;padding:32px;box-shadow:-20px 0 70px #17211c50}.photo-drawer header{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:28px}.photo-drawer header small{font:9px Georgia,serif;letter-spacing:2px;color:#7b8172}.photo-drawer h2{font-family:"Songti SC",serif;font-size:30px;line-height:1.5;font-weight:400;margin:14px 0 0}.photo-drawer header button{font-size:27px;background:transparent;min-width:38px;min-height:38px}.original-photo{display:block;width:100%;height:auto;border:7px solid #faf7ed;box-shadow:0 5px 20px #31443715}.photo-description{position:relative;margin:25px 0 21px}.photo-description>span{position:absolute;right:0;top:3px;font:11px Georgia,serif;color:#8e9181}.photo-description h3{font-size:15px;font-weight:500;letter-spacing:1px;margin:0 0 9px}.photo-description p{font-size:10px;line-height:1.8;color:#7a8074;margin:0}.photo-thumbnails{display:grid;grid-template-columns:repeat(5,1fr);gap:7px}.photo-thumbnails button{padding:2px;background:transparent;border:1px solid transparent}.photo-thumbnails button.selected{border-color:#5f715c}.photo-thumbnails img{display:block;width:100%;aspect-ratio:4/3;object-fit:cover;opacity:.65}.photo-thumbnails button.selected img{opacity:1}.photo-drawer .photo-location{display:flex;align-items:center;justify-content:space-between;width:100%;padding:16px 18px;margin-top:27px;background:#364b3d;color:#f1efe1;font-size:12px}.photo-disclaimer{font-size:10px;line-height:1.9;color:#86887a;margin:18px 0}.reconstruction-notes article{padding:20px 0;border-top:1px solid #66795a25}.reconstruction-notes article>span{font-size:10px;color:#8b967f}.reconstruction-notes h3{font-size:16px;font-weight:500;margin:10px 0}.reconstruction-notes article p{font-size:12px;line-height:1.9;color:#737d70}
@media(max-width:900px){.archive-status{display:none}.memory-stops{left:22px;gap:5px}.stops-label{display:none}.memory-stops button{padding:10px;font-size:10px;gap:7px}.memory-stops button i{display:none}.home-top{padding:22px}.home-position{right:20px}.home-intro{left:7%;top:26%}}
@media(max-width:620px){.home-top{padding:19px 18px;gap:8px}.home-brand{font-size:19px!important;gap:7px}.home-brand small{font-size:6px;letter-spacing:.8px}.home-top-actions{gap:10px}.home-top-actions button{font-size:9px}.home-top-actions .photos-toggle{padding:8px}.home-top-actions button:first-child{display:none}.home-intro{top:25%;left:25px;right:25px}.home-intro h1{font-size:37px;letter-spacing:1px;margin:18px 0}.home-intro>p{font-size:12px}.home-intro .enter-home{min-height:51px;width:190px}.honest-note{font-size:8px}.home-vignette{background:linear-gradient(90deg,#16221baf,transparent),linear-gradient(0deg,#15211cc2,transparent 50%,#15211c70)}.memory-stops{left:18px;right:18px;bottom:50px;max-width:none;overflow-x:auto;padding:4px 0;gap:5px}.memory-stops button{min-height:43px;padding:8px 10px;font-size:10px}.memory-stops button>span{display:none}.memory-stops .overview-button{padding:8px}.home-bottom-note{left:18px;right:18px;bottom:19px;font-size:8px;letter-spacing:0}.home-position{right:18px;bottom:112px;padding:8px}.home-position svg{display:none}.home-position>span{display:none}.walk-pad{margin-top:0;grid-template-columns:repeat(3,37px)}.walk-pad button{width:37px;height:37px}.place-caption{left:20px;top:105px}.place-caption h2{font-size:23px}.place-caption p{max-width:220px;font-size:9px}.photo-drawer{padding:25px 20px}.photo-drawer h2{font-size:28px}}
@media(prefers-reduced-motion:reduce){*{transition:none!important}}
@media(max-width:620px){.home-top-actions button:first-child{display:block;max-width:50px;line-height:1.5}.home-top-actions{gap:8px}.home-brand small{max-width:90px}}
</style>
