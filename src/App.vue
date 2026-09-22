<template>
  <main class="momo-home" :class="[`world-${worldWeather}`, { night: isNight, breathing, touched, 'living-mode': !exploring }]">
    <div class="sky-glow"></div>
    <div v-if="exploring && worldWeather === 'rain'" class="rain-layer"><i v-for="n in 28" :key="n" :style="rainStyle(n)"></i></div>
    <div class="grain"></div>

    <header>
      <button class="momo-logo" type="button" aria-label="回到 Momo 身边" @click="exploring = false"><span>✦</span>momo</button>
      <div class="day-note"><i></i>一间小屋，两个位置</div>
      <button class="memory-button" type="button" :aria-label="`打开小回忆，已有 ${memories.length} 条`" @click="showMemories = true"><el-icon><Star /></el-icon><span>{{ memories.length }}</span></button>
    </header>

    <LivingRoom v-if="!exploring" :message="companionReply" @whisper="openWhisper('')" @memories="showMemories = true" />
    <button class="explore-toggle" @click="exploring = !exploring">{{ exploring ? '← 回到 Momo 身边' : '去看看房间里的故事 →' }}</button>
    <section v-if="exploring" class="room">
      <div class="window-scene"><div class="sun"></div><div class="cloud cloud-a"></div><div class="cloud cloud-b"></div><span class="window-bar"></span></div>
      <div class="plant"><i></i><i></i><i></i><span></span></div>
      <div class="shelf"><span class="book a"></span><span class="book b"></span><span class="book c"></span><i></i></div>
      <div class="world-objects" aria-label="由你的选择和经历长出的物品">
        <button v-for="(item, index) in worldItems" :key="item.id" type="button" class="world-object" :class="[`type-${item.type}`, { newborn: newBornId === item.id }]" :style="objectPosition(index)" :title="item.name" @click="activeRelic = item">
          <img class="relic-image" :src="relicAsset(item.type)" :alt="item.name" /><small>{{ item.name }}</small>
        </button>
      </div>

      <aside class="story-card">
        <div class="story-topline"><span class="overline">今天，世界先来找你</span><span>第 {{ eventHistory.length + (eventResult ? 0 : 1) }} 幕</span></div>
        <div class="event-mark" :class="[`mark-${currentEvent.tone}`, { 'has-event-image': currentEvent.image }]">
          <img v-if="currentEvent.image" :src="currentEvent.image" :alt="currentEvent.title" />
          <span v-else>{{ currentEvent.symbol }}</span>
        </div>
        <h1>{{ currentEvent.title }}</h1>
        <p>{{ currentEvent.scene }}</p>
        <div class="story-foot"><span></span>{{ eventResult ? '这个选择已经被房间记住了' : '不用解释，选一个现在做得到的就好' }}</div>
      </aside>

      <section class="companion">
        <transition name="bubble" mode="out-in"><button :key="speech" class="speech" type="button" @click="nextThought">{{ speech }}</button></transition>
        <div class="pet-stage" @click="petMomo">
          <div class="aura"></div>
          <img src="/momo.png" alt="Momo，一只胸口发光的云朵小兽" draggable="false" />
          <span v-for="heart in hearts" :key="heart.id" class="floating-heart" :style="{ '--x': `${heart.x}px`, '--r': `${heart.rotate}deg` }">♥</span>
          <div v-if="breathing" class="breath-guide"><strong>{{ breathWord }}</strong><small>{{ breathCount }}</small></div>
        </div>
        <p class="pet-hint"><span></span>{{ breathing ? '跟着 Momo 慢慢来' : '不想做选择，也可以只摸摸 Momo' }}</p>
      </section>

      <aside v-if="!eventResult" class="choice-card">
        <span class="overline">MOMO 等你决定</span>
        <h2>{{ currentEvent.question }}</h2>
        <div class="choice-list">
          <button v-for="(choice, index) in currentEvent.choices" :key="choice.label" type="button" @click="chooseEvent(choice)">
            <span class="choice-number">0{{ index + 1 }}</span>
            <p><strong>{{ choice.label }}</strong><small>{{ choice.detail }}</small></p>
            <el-icon><Right /></el-icon>
          </button>
        </div>
        <button class="quiet-choice" type="button" @click="chooseQuietly">今天什么都不想处理</button>
      </aside>

      <aside v-else class="choice-card result-card">
        <span class="overline">房间记住了</span>
        <div class="result-relic"><img :src="relicAsset(eventResult.type)" :alt="eventResult.name" /></div>
        <h2>{{ eventResult.name }} 留了下来</h2>
        <p class="result-copy">{{ eventResult.reply }}</p>
        <button class="gentle-primary" type="button" @click="openWhisper(eventResult.prompt)">我想多说一点</button>
        <button class="next-event" type="button" @click="nextEvent">今天还想再走一步 <el-icon><Right /></el-icon></button>
        <small class="permission-copy">到这里也已经足够了。明天回来，故事会继续。</small>
      </aside>
    </section>

    <nav v-if="exploring" class="care-dock" aria-label="随时可用的小动作">
      <button type="button" @click="startBreathing"><span>◌</span>{{ breathing ? '结束呼吸' : '一起呼吸' }}</button><i></i>
      <button type="button" @click="openWhisper('')"><span>〰</span>我有话想说</button><i></i>
      <button type="button" @click="giveTreat"><span>♡</span>给 Momo 一颗软糖</button>
    </nav>
    <footer><span>离开多久都没关系，这里会保持你喜欢的样子。</span></footer>

    <transition name="sheet">
      <div v-if="whispering" class="sheet-veil" @click.self="whispering = false">
        <section class="whisper-sheet">
          <button class="close" type="button" aria-label="关闭悄悄话" @click="whispering = false"><el-icon><Close /></el-icon></button>
          <div class="mini-momo"><img src="/momo.png" alt="" /></div>
          <span class="overline">只有你愿意时</span><h2>不必写完整，<br />一句话也可以。</h2>
          <p class="world-explain">这不是任务。你说的东西会在房间里留下形状；今天不想说，也完全没关系。</p>
          <div v-if="whisperPrompt" class="soft-prompt">{{ whisperPrompt }}</div>
          <textarea ref="whisperInput" v-model="whisper" rows="4" placeholder="想到什么，就从哪里开始……"></textarea>
          <div class="whisper-actions"><small>{{ aiConfigured ? `由 ${aiModel} 生成专属意象` : '使用本地意象规则生成' }}</small><button type="button" :disabled="!whisper.trim() || replying" @click="sendWhisper">{{ replying ? '正在长出来…' : '留在房间里' }} <el-icon><Promotion /></el-icon></button></div>
          <button class="ai-link" type="button" @click="openAISettings">{{ aiConfigured ? 'AI 已连接 · 更换设置' : '连接 DeepSeek，让回应更懂你' }}</button>
        </section>
      </div>
    </transition>

    <transition name="sheet">
      <div v-if="showMemories" class="sheet-veil" @click.self="showMemories = false">
        <section class="memory-sheet">
          <button class="close" type="button" aria-label="关闭小回忆" @click="showMemories = false"><el-icon><Close /></el-icon></button>
          <span class="overline">这个世界记得</span><h2>你曾经怎样<br />一点点照顾自己</h2>
          <div v-if="memories.length" class="memory-list"><article v-for="memory in memories" :key="memory.id"><span>✦</span><div><small>{{ memory.date }}</small><p>{{ memory.text }}</p><em v-if="memory.reply">Momo：{{ memory.reply }}</em></div></article></div>
          <div v-else class="memory-empty">不需要先写些什么。完成今天的小选择，第一颗星星就会亮起来。</div>
        </section>
      </div>
    </transition>

    <transition name="relic">
      <div v-if="activeRelic" class="relic-veil" @click.self="activeRelic = null">
        <article class="relic-card" :class="[`relic-${activeRelic.type}`, `type-${activeRelic.type}`]">
          <button class="close" type="button" aria-label="关闭物品故事" @click="activeRelic = null"><el-icon><Close /></el-icon></button>
          <div class="relic-art"><img class="relic-image" :src="relicAsset(activeRelic.type)" :alt="activeRelic.name" /></div>
          <span class="overline">{{ activeRelic.date }} 留下的</span><h2>{{ activeRelic.name }}</h2>
          <blockquote>“{{ activeRelic.source }}”</blockquote><p>{{ activeRelic.story }}</p>
          <footer>它已经在这里陪了你 {{ relicDays(activeRelic) }} 天。</footer>
        </article>
      </div>
    </transition>

    <el-dialog v-model="showAISettings" title="让 Momo 更懂你" width="min(440px, calc(100vw - 32px))" class="ai-settings-dialog">
      <div class="ai-settings"><div class="security-note"><el-icon><Lock /></el-icon><p><strong>Key 只保存在这台电脑</strong><span>写入本项目的 .env.local，不进入浏览器存储。</span></p></div><label><span>DeepSeek API Key</span><input v-model="apiKeyDraft" type="password" autocomplete="off" placeholder="sk-••••••••••••••••" /></label><label><span>模型</span><select v-model="modelDraft"><option value="deepseek-chat">deepseek-chat · 温柔自然</option><option value="deepseek-reasoner">deepseek-reasoner · 更深入</option></select></label><p v-if="settingsError" class="settings-error">{{ settingsError }}</p><button class="save-settings" type="button" :disabled="savingSettings || !apiKeyDraft.trim()" @click="saveAISettings">{{ savingSettings ? '正在连接…' : '保存并连接' }}</button></div>
    </el-dialog>
  </main>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import LivingRoom from './components/LivingRoom.vue'
const exploring = ref(false)
const companionReply = ref('')

const storageKey = 'momo:companion:v1'
const eventLibrary = [
  {
    id: 'rain-box', symbol: '□', image: '/events/rain-box.png', tone: 'rain', title: '门外有一只淋湿的纸箱',
    scene: 'Momo 没有擅自打开。它拖来一条小毯子，坐在旁边等你。箱子里很安静，像一件放了很久、还没有准备好面对的事。', question: '要怎么安放它？',
    choices: [
      { label: '打开一条小缝', detail: '不用一次看完', type: 'starjar', weather: 'mist', name: '透气的旧箱子', story: '你没有逼自己彻底打开，只留了一条能透气的缝。有些事情不是被攻克的，是在安全的时候一点点被看见的。', reply: '我会陪你守着这条小缝，不让里面的东西一下子涌出来。', prompt: '箱子让你想起了什么？只写一个词也可以。' },
      { label: '先给它盖上毯子', detail: '照顾不等于立刻处理', type: 'umbrella', weather: 'rain', name: '守在门口的毯子', story: '你没有打开箱子，却先让它不再继续淋雨。原来照顾自己，也可以从“不再受冻”开始。', reply: '我们今天不打开。先让它暖一点，也让你暖一点。', prompt: '如果愿意，你可以说说，最近有什么事一直在淋雨。' },
      { label: '把它搬进屋檐下', detail: '先放到安全的地方', type: 'paperboat', weather: 'calm', name: '屋檐下的纸船', story: '箱子仍然没有打开，但它已经离开了雨里。你不必立刻解决过去，先让现在的自己站到屋檐下。', reply: '好了，雨暂时碰不到它了。剩下的事，我们以后再说。', prompt: '此刻什么会让你多一点安全感？' },
    ],
  },
  {
    id: 'closed-window', symbol: '⌗', image: '/events/closed-window.png', tone: 'sun', title: '窗子今天透进一小束光',
    scene: '那扇窗一直关得很紧。Momo 发现窗沿上落满了灰，但光还是从缝里钻了进来，在地板上照出一块很小的、暖暖的地方。', question: '你想对这束光做什么？',
    choices: [
      { label: '拉开一厘米', detail: '只让一点风进来', type: 'sprout', weather: 'sun', name: '一厘米的春天', story: '窗没有完全打开，风却已经知道了回来的路。改变不一定轰轰烈烈，一厘米也足以让房间开始呼吸。', reply: '一厘米刚刚好。我们不需要向任何人证明勇敢。', prompt: '生活里，有没有一件你愿意只尝试“一厘米”的事？' },
      { label: '擦亮这小块玻璃', detail: '不必动整扇窗', type: 'lantern', weather: 'calm', name: '被擦亮的光斑', story: '你只擦了一小块，却让光变得清楚了。你开始分得清：有些阴暗来自天气，有些只是很久没人照顾的灰尘。', reply: '看，它变亮了。不是世界突然变好，是你替自己擦出了一点位置。', prompt: '最近有没有一个很小、但真实的好消息？' },
      { label: '和 Momo 坐进光里', detail: '什么都不改变', type: 'flower', weather: 'sun', name: '晒过太阳的坐垫', story: '你们没有讨论过去，也没有计划未来，只是在光里坐了一会儿。休息不是耽误恢复，它本身就是恢复。', reply: '今天的光不用拿来成长。拿来晒晒我们就好。', prompt: '如果现在可以休息十分钟，你最想怎么度过？' },
    ],
  },
  {
    id: 'small-chair', symbol: '⌁', image: '/events/small-chair.png', tone: 'heart', title: '角落里多了一把很小的椅子',
    scene: 'Momo 说，它昨晚梦见了小时候的你。那个孩子没有哭，只是很懂事地坐着，像在等一个终于不会责怪自己的人。', question: '你想为那个孩子做什么？',
    choices: [
      { label: '在旁边坐下来', detail: '先不问发生了什么', type: 'lantern', weather: 'calm', name: '两个人的小夜灯', story: '你没有追问，也没有劝那个孩子坚强。你只是坐在旁边，让沉默第一次不是惩罚，而是陪伴。', reply: '我们可以一句话都不说。有人留下来，就已经和从前不一样了。', prompt: '小时候的你，最希望有人怎样陪着？' },
      { label: '问问他饿不饿', detail: '从最普通的需要开始', type: 'flower', weather: 'sun', name: '不会被收走的点心', story: '你没有讲大道理，只先问了一个孩子有没有吃饱。那些曾被忽略的需要，现在终于有人认真对待。', reply: '想吃什么都可以说。需要什么，从来不是一件丢脸的事。', prompt: '今天的你，有哪个很普通的需要还没被照顾？' },
      { label: '给门装一把锁', detail: '安全也包括说“不”', type: 'umbrella', weather: 'mist', name: '只属于你的钥匙', story: '门终于可以从里面锁上。爱不只意味着敞开，也意味着你有权决定谁能进来、什么时候进来。', reply: '钥匙交给你。拒绝别人，不会让你变成坏孩子。', prompt: '最近有没有一件事，你其实很想说“不”？' },
    ],
  },
  {
    id: 'old-letter', symbol: '◇', image: '/events/old-letter.png', tone: 'dusk', title: '抽屉里的旧信少了一个字',
    scene: '那封没有寄出的信原本写着“没有你，我就什么都没有”。今天醒来，“没有”两个字淡了一点。Momo 说，也许有些话正在自己松开。', question: '这封信该放在哪里？',
    choices: [
      { label: '折好，留在抽屉里', detail: '记得，不等于回去', type: 'paperboat', weather: 'calm', name: '折了四次的信', story: '你没有烧掉它，也没有寄出去。它成为过去的一部分，而不是今天必须执行的命令。', reply: '留下也可以。真正的放下，不需要假装从未发生。', prompt: '这段关系里，有什么你想留下，但不想再追回？' },
      { label: '在背面写一句给自己的话', detail: '把自己也写进故事里', type: 'starjar', weather: 'sun', name: '写给自己的背面', story: '这封信第一次不再只关于另一个人。纸张的背面出现了你的名字，也出现了一个不依赖谁来完成的以后。', reply: '这一次，信里终于也有你了。', prompt: '如果只写一句，你现在想对自己说什么？' },
      { label: '暂时不读', detail: '今天的平静更重要', type: 'sprout', weather: 'mist', name: '合上的抽屉', story: '你把抽屉轻轻合上。逃避会让人越来越害怕，而有意识地选择“不是今天”，是在保护自己的节奏。', reply: '不是今天，也不代表永远不行。今天先过今天。', prompt: '现在有什么比回忆更值得你照顾？' },
    ],
  },
]
const thoughts = ['我在呢。', '今天不想说话也没关系。', '这个房间不会催你。', '你来看看我，就已经够了。', '有些门，可以等准备好了再开。']
const fallbackReplies = ['听起来，你今天真的承受了不少。先不用急着想办法，我陪你坐一会儿。', '这件事放在心里一定有点沉。谢谢你愿意告诉我。', '我不一定能替你解决，但我可以认真记住。你不是一个人在扛。']

let initial = {}
try { initial = JSON.parse(localStorage.getItem(storageKey) || '{}') } catch { initial = {} }
const memories = ref(initial.memories || []), treats = ref(initial.treats || 3), worldItems = ref(initial.worldItems || []), eventHistory = ref(initial.eventHistory || [])
const firstMet = ref(initial.firstMet || new Date().toISOString()), speech = ref('我在门口发现了一件东西。'), hearts = ref([]), touched = ref(false), breathing = ref(false), breathWord = ref('吸气'), breathCount = ref(4), soundOn = ref(false)
const eventIndex = ref(eventHistory.value.length % eventLibrary.length), eventResult = ref(null)
const whispering = ref(false), whisperPrompt = ref(''), showMemories = ref(false), whisper = ref(''), whisperInput = ref(null), replying = ref(false)
const activeRelic = ref(null), newBornId = ref(null)
const showAISettings = ref(false), apiKeyDraft = ref(''), modelDraft = ref('deepseek-chat'), savingSettings = ref(false), settingsError = ref(''), aiConfigured = ref(false), aiModel = ref('deepseek-chat')
let breathTimer = null, speechTimer = null, heartId = 0

const hour = new Date().getHours(), isNight = hour < 6 || hour >= 19
const greeting = hour < 6 ? '夜深了' : hour < 11 ? '早上好' : hour < 14 ? '中午好' : hour < 18 ? '下午好' : '晚上好'
const todayKey = new Date().toLocaleDateString('sv-SE')
const todaysRecord = [...eventHistory.value].reverse().find(item => item.dayKey === todayKey)
if (todaysRecord) { const foundIndex = eventLibrary.findIndex(item => item.id === todaysRecord.eventId); if (foundIndex >= 0) eventIndex.value = foundIndex; eventResult.value = todaysRecord }
const currentEvent = computed(() => eventLibrary[eventIndex.value % eventLibrary.length])
const knownDays = computed(() => Math.max(1, Math.floor((Date.now() - new Date(firstMet.value).getTime()) / 86400000) + 1))
const worldWeather = computed(() => worldItems.value[0]?.weather || 'calm')
watch(() => memories.value[0]?.id, () => { companionReply.value = memories.value[0]?.reply || '' })

function persist() { localStorage.setItem(storageKey, JSON.stringify({ memories: memories.value, treats: treats.value, firstMet: firstMet.value, worldItems: worldItems.value, eventHistory: eventHistory.value })) }
function say(text, duration = 5200) { speech.value = text; window.clearTimeout(speechTimer); speechTimer = window.setTimeout(() => { speech.value = thoughts[Math.floor(Math.random() * thoughts.length)] }, duration) }
function nextThought() { say(thoughts[(thoughts.indexOf(speech.value) + 1 + thoughts.length) % thoughts.length]) }
function petMomo() { if (breathing.value) return; touched.value = false; requestAnimationFrame(() => { touched.value = true }); const id = ++heartId; hearts.value.push({ id, x: Math.round(Math.random() * 90 - 45), rotate: Math.round(Math.random() * 36 - 18) }); window.setTimeout(() => { hearts.value = hearts.value.filter(item => item.id !== id) }, 1300); say(['不做选择也可以，我陪你。', '你的手好暖。', '嗯，我知道你来了。', '胸口亮了一点点。'][Math.floor(Math.random() * 4)]); window.setTimeout(() => { touched.value = false }, 500) }
function chooseEvent(choice) { const now = Date.now(), date = new Intl.DateTimeFormat('zh-CN', { month: 'long', day: 'numeric' }).format(new Date()); const record = { id: now, eventId: currentEvent.value.id, dayKey: todayKey, date, source: `在「${currentEvent.value.title}」里，你选择了：${choice.label}`, createdAt: new Date().toISOString(), ...choice }; eventResult.value = record; eventHistory.value.push(record); worldItems.value.unshift(record); worldItems.value = worldItems.value.slice(0, 18); memories.value.unshift({ id: now, date, text: record.source, reply: choice.reply }); memories.value = memories.value.slice(0, 30); newBornId.value = now; say(choice.reply, 9000); persist(); window.setTimeout(() => { newBornId.value = null }, 2600) }
function chooseQuietly() { chooseEvent({ label: '今天什么都不处理', detail: '', type: 'lantern', weather: 'calm', name: '没有任务的夜灯', story: '你没有完成任何任务，也没有解释。房间还是为你亮了一盏灯。原来被允许停下来，本身就是一种新的经验。', reply: '好，那我们什么都不处理。你不需要靠表现来换我留下。', prompt: '如果后来想说点什么，我一直在。' }) }
function nextEvent() { eventIndex.value = (eventIndex.value + 1) % eventLibrary.length; eventResult.value = null; say('好。我们慢慢走到房间的另一边。') }
function giveTreat() { treats.value += 1; persist(); petMomo(); say(treats.value > 8 ? '已经藏不下啦，但我还是想要。' : '唔！是软软甜甜的。') }
function startBreathing() { if (breathing.value) { stopBreathing(); return } breathing.value = true; let phase = 0, remaining = 4; breathWord.value = '吸气'; breathCount.value = remaining; say('跟着我，什么都不用想。', 10000); breathTimer = window.setInterval(() => { remaining -= 1; if (remaining <= 0) { phase += 1; if (phase >= 8) { stopBreathing(); say('做得很好。现在有没有松一点点？', 6500); return } breathWord.value = phase % 2 === 0 ? '吸气' : '呼气'; remaining = phase % 2 === 0 ? 4 : 6 } breathCount.value = remaining }, 1000) }
function stopBreathing() { breathing.value = false; window.clearInterval(breathTimer) }
function openWhisper(prompt = '') { whisperPrompt.value = prompt; whispering.value = true; nextTick(() => whisperInput.value?.focus()) }
async function sendWhisper() { const text = whisper.value.trim(); if (!text) return; replying.value = true; let result; try { result = aiConfigured.value ? parseWorldResult((await callAI(text)).content, text) : fallbackWorld(text) } catch { result = fallbackWorld(text) } const id = Date.now(), date = new Intl.DateTimeFormat('zh-CN', { month: 'long', day: 'numeric' }).format(new Date()); const relic = { id, date, createdAt: new Date().toISOString(), source: text, ...result }; worldItems.value.unshift(relic); worldItems.value = worldItems.value.slice(0, 18); memories.value.unshift({ id, date, text, reply: result.reply }); memories.value = memories.value.slice(0, 30); whisper.value = ''; whispering.value = false; replying.value = false; newBornId.value = id; say(`${result.name}从你的话里留了下来。`, 8000); persist(); window.setTimeout(() => { newBornId.value = null }, 2600) }
async function callAI(text) { const response = await fetch('/api/deepseek', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ mode: 'companion_world', message: text }) }); const data = await response.json(); if (!response.ok) throw new Error(data.error); return data }
function parseWorldResult(content, source) { const clean = content.replace(/^```(?:json)?\s*/i, '').replace(/\s*```$/, '').trim(); try { return normalizeWorld(JSON.parse(clean), source) } catch { return fallbackWorld(source) } }
function normalizeWorld(data) { const types = ['paperboat', 'sprout', 'lantern', 'starjar', 'flower', 'umbrella']; const weathers = ['calm', 'rain', 'sun', 'mist']; return { type: types.includes(data.type) ? data.type : 'starjar', weather: weathers.includes(data.weather) ? data.weather : 'calm', name: String(data.name || '一颗记忆星').slice(0, 12), story: String(data.story || 'Momo 把这件事轻轻收了起来。').slice(0, 180), reply: String(data.reply || fallbackReplies[0]).slice(0, 100) } }
function fallbackWorld(text) { const tired = /累|困|疲惫|加班/.test(text), angry = /烦|气|改|吵|讨厌/.test(text), happy = /开心|完成|通过|顺利|喜欢|好/.test(text), sad = /难过|低落|哭|失望|想他|分手/.test(text); if (angry) return { type: 'paperboat', weather: 'rain', name: '不沉的纸船', story: 'Momo 把那些反复改变、让人心烦的部分折成了一艘船。雨会落下来，但它决定替你浮着。', reply: '真的很烦。先不用讲道理，我把这些乱糟糟的东西折起来。' }; if (tired) return { type: 'lantern', weather: 'mist', name: '晚归的小灯', story: '这盏灯只在你很累的时候亮。它不催你赶路，只负责让回去的方向柔和一点。', reply: '辛苦啦。今天不用再证明什么，我给你把灯留着。' }; if (happy) return { type: 'flower', weather: 'sun', name: '偷偷得意花', story: '有些开心不需要很大声。Momo 把它种在窗边，每次看见都会替你偷偷得意一次。', reply: '嘿嘿，我就知道你可以。让我也跟着高兴一会儿。' }; if (sad) return { type: 'umbrella', weather: 'rain', name: '慢一点的伞', story: '它挡不住所有雨，但会让雨落到你身上之前，先慢下来一点。Momo 会一直举着。', reply: '我听见了。你不需要马上好起来，我在伞下面给你留了位置。' }; return { type: 'sprout', weather: 'calm', name: '今天的小芽', story: '这件看似普通的小事被 Momo 埋进土里。也许过几天再看，它会告诉你当时没有注意到的东西。', reply: '我好好收下了。看，它已经冒出一点点芽。' } }
function objectPosition(index) { const spots = [[58,78],[42,78],[72,63],[27,66],[79,43],[20,45],[66,35],[34,35]]; const spot = spots[index % spots.length]; return { '--left': `${spot[0]}%`, '--top': `${spot[1]}%`, '--delay': `${(index % 5) * -.4}s` } }
function relicAsset(type) { return `/relics/${['paperboat','sprout','lantern','starjar','flower','umbrella'].includes(type) ? type : 'starjar'}.png` }
function rainStyle(n) { return { left: `${(n * 37) % 100}%`, opacity: .18 + (n % 4) * .08, animationDuration: `${1.3 + (n % 6) * .17}s`, animationDelay: `${(n % 8) * -.25}s` } }
function relicDays(item) { return Math.max(1, Math.floor((Date.now() - new Date(item.createdAt).getTime()) / 86400000) + 1) }
function toggleSound() { soundOn.value = !soundOn.value; say(soundOn.value ? '想象窗外正下着很轻的雨。' : '那我们就安安静静待着。') }
async function checkAI() { try { const response = await fetch('/api/deepseek/status'); const data = await response.json(); aiConfigured.value = Boolean(data.configured); aiModel.value = data.model || 'deepseek-chat' } catch { aiConfigured.value = false } }
function openAISettings() { whispering.value = false; apiKeyDraft.value = ''; modelDraft.value = aiModel.value; settingsError.value = ''; showAISettings.value = true }
async function saveAISettings() { savingSettings.value = true; settingsError.value = ''; try { const response = await fetch('/api/deepseek/settings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ apiKey: apiKeyDraft.value.trim(), model: modelDraft.value }) }); const data = await response.json(); if (!response.ok) throw new Error(data.error || '连接失败'); aiConfigured.value = true; aiModel.value = data.model; apiKeyDraft.value = ''; showAISettings.value = false; say('以后你说的话，我会更认真地听懂。') } catch (error) { settingsError.value = error.message } finally { savingSettings.value = false } }
onMounted(() => { if (!initial.firstMet) persist(); checkAI(); window.setTimeout(() => say(todaysRecord ? '我还记得你今天做的选择。' : currentEvent.value.scene, 9000), 700) })
onBeforeUnmount(() => { window.clearInterval(breathTimer); window.clearTimeout(speechTimer) })
</script>
