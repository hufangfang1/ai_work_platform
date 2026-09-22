import fs from 'node:fs'
import path from 'node:path'

const API_URL = 'https://api.deepseek.com/chat/completions'

function sendJson(res, status, payload) {
  res.statusCode = status
  res.setHeader('Content-Type', 'application/json; charset=utf-8')
  res.end(JSON.stringify(payload))
}

async function readBody(req) {
  let raw = ''
  for await (const chunk of req) {
    raw += chunk
    if (raw.length > 200_000) throw new Error('请求内容过长')
  }
  return JSON.parse(raw || '{}')
}

function analyzePrompt(input) {
  return `请把下面这段工作描述整理为一张可执行的工作卡。只返回 JSON，不要 Markdown：
{
  "title": "不超过32字",
  "kind": "incident 或 feature 或 explore",
  "summary": "用两句话说明判断与推进策略",
  "chips": ["2到4个短标签"],
  "checkpoints": ["恰好4个可验证的推进节点"]
}

判断规则：线上故障、用户反馈、异常归为 incident；明确产品交付归为 feature；个人兴趣、效率工具和实验归为 explore。

工作描述：${input}`
}

function workPrompt(work, instruction) {
  const history = (work.events || []).slice(-8).map(event => `- ${event.label}：${event.text}`).join('\n') || '- 暂无'
  const steps = (work.checkpoints || []).map(step => `- [${step.done ? 'x' : ' '}] ${step.text}`).join('\n')
  return `你正在协助推进一项真实工作，请基于已有上下文给出本轮可直接执行的结果。不要说空话，不要虚构已经读过代码或运行过测试；如果缺少项目文件或证据，要明确提出最少量的问题。

标题：${work.title}
类型：${work.kind}
原始描述：${work.original}
当前整理：${work.summary}
当前下一步：${work.next}

推进节点：
${steps}

历史记录：
${history}

本轮指令：${instruction || `请围绕“${work.next}”给出分析、行动清单和需要我确认的内容。`}`
}

function companionPrompt(message) {
  return `你是用户的桌面宠物 Momo，一只温柔、俏皮、胸口会发光的云朵小兽。请回应用户刚刚说的话。

要求：
- 使用自然、简短的中文，最多 80 字
- 首先接住对方的情绪，不说教，不急于提供解决方案
- 不要自称 AI，不要列清单，不使用心理咨询套话
- 可以有一点属于小宠物的动作或想象，但不要幼稚过头
- 若涉及明确的自伤或紧急危险，温柔鼓励用户立即联系身边可信任的人和当地紧急援助

用户说：${message}`
}

function companionWorldPrompt(message) {
  return `你是桌面宠物 Momo，要把用户今天的一段真实经历变成房间里一个有象征意义的物品和一则小故事。只返回 JSON，不要 Markdown：
{
  "type": "paperboat | sprout | lantern | starjar | flower | umbrella 中选一个",
  "weather": "calm | rain | sun | mist 中选一个",
  "name": "有诗意但自然的物品名，不超过8个汉字",
  "story": "解释这个物品如何从用户经历中长出来，60到100字，具体而不说教",
  "reply": "Momo 当下对用户说的一句话，30到60字"
}
避免心理咨询套话，不要强行积极，不要虚构额外事实。用户经历：${message}`
}

export function deepseekPlugin(env) {
  let apiKey = env.DEEPSEEK_API_KEY
  let model = env.DEEPSEEK_MODEL || 'deepseek-chat'

  function saveSettings(nextKey, nextModel) {
    const envPath = path.resolve(process.cwd(), '.env.local')
    const existing = fs.existsSync(envPath) ? fs.readFileSync(envPath, 'utf8') : ''
    const kept = existing.split(/\r?\n/).filter(line => line && !line.startsWith('DEEPSEEK_API_KEY=') && !line.startsWith('DEEPSEEK_MODEL='))
    kept.push(`DEEPSEEK_API_KEY=${nextKey}`, `DEEPSEEK_MODEL=${nextModel}`)
    fs.writeFileSync(envPath, `${kept.join('\n')}\n`, { mode: 0o600 })
  }

  async function middleware(req, res, next) {
    if (!req.url?.startsWith('/api/deepseek')) return next()
    if (req.url === '/api/deepseek/status') return sendJson(res, 200, { configured: Boolean(apiKey), model })
    if (req.url === '/api/deepseek/settings' && req.method === 'POST') {
      try {
        const body = await readBody(req)
        const nextKey = String(body.apiKey || '').trim()
        const nextModel = ['deepseek-chat', 'deepseek-reasoner'].includes(body.model) ? body.model : 'deepseek-chat'
        if (!/^sk-[A-Za-z0-9_-]{10,}$/.test(nextKey)) return sendJson(res, 400, { error: 'Key 格式不正确，请检查后重试' })
        apiKey = nextKey
        model = nextModel
        saveSettings(apiKey, model)
        return sendJson(res, 200, { configured: true, model })
      } catch (error) { return sendJson(res, 500, { error: error.message || '保存失败' }) }
    }
    if (req.method !== 'POST') return sendJson(res, 405, { error: 'Method not allowed' })
    if (!apiKey) return sendJson(res, 503, { error: '尚未配置 DEEPSEEK_API_KEY' })

    try {
      const body = await readBody(req)
      const prompt = body.mode === 'analyze' ? analyzePrompt(body.input || '') : body.mode === 'companion_world' ? companionWorldPrompt(body.message || '') : body.mode === 'companion' ? companionPrompt(body.message || '') : workPrompt(body.work || {}, body.instruction || '')
      const response = await fetch(API_URL, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${apiKey}` },
        body: JSON.stringify({
          model,
          messages: [
            { role: 'system', content: '你是一个严谨、简洁的中文软件开发工作助手。你的目标是减少用户维护流程的负担。' },
            { role: 'user', content: prompt },
          ],
          temperature: body.mode === 'analyze' ? 0.2 : 0.5,
          stream: false,
        }),
      })
      const data = await response.json()
      if (!response.ok) return sendJson(res, response.status, { error: data?.error?.message || 'DeepSeek 请求失败' })
      sendJson(res, 200, { content: data?.choices?.[0]?.message?.content || '', model: data.model || model, usage: data.usage || null })
    } catch (error) {
      sendJson(res, 500, { error: error.message || 'DeepSeek 服务异常' })
    }
  }

  return {
    name: 'morrow-deepseek',
    configureServer(server) { server.middlewares.use(middleware) },
    configurePreviewServer(server) { server.middlewares.use(middleware) },
  }
}
