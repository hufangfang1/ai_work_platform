import { taskStatuses } from './status'

const STORAGE_KEY = 'ai-dev-workbench-state-v1'

const now = () => new Date().toISOString()

function uid(prefix) {
  return `${prefix}_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 8)}`
}

function defaultState() {
  return {
    projects: [
      {
        id: 'project_opcenterapi',
        name: 'opcenterapi',
        repoUrl: 'git@gitlab.xxx.com:xxx/opcenterapi.git',
        localPath: '/data/repos/opcenterapi',
        defaultBaseBranch: 'develop',
        defaultBranchPrefix: 'future/',
        testCommand: 'php think test',
        lintCommand: 'find app -name "*.php" -print0 | xargs -0 -n1 php -l',
        buildCommand: '',
        allowAutoCommit: true,
        allowAutoPush: false,
        status: 1,
        createdAt: now(),
        updatedAt: now(),
      },
    ],
    tasks: [],
    plans: [],
    runs: [],
    runLogs: [],
    changes: [],
    reviews: [],
    retrospectives: [],
    branches: ['develop', 'main', 'future/existing-demo-branch'],
    modelConfig: {
      provider: 'Claude',
      modelName: 'claude-sonnet-4-20250514',
      apiBase: 'https://api.anthropic.com',
      apiKeyRef: 'ANTHROPIC_API_KEY',
      contextLength: 200000,
      timeoutSeconds: 1800,
    },
    securityRules: [
      { id: uid('rule'), pattern: 'password\\s*=\\s*[^\\n]+', replacement: 'PASSWORD = "***"', enabled: true },
      { id: uid('rule'), pattern: 'token\\s*=\\s*[^\\n]+', replacement: 'TOKEN = "***"', enabled: true },
      { id: uid('rule'), pattern: 'secret\\s*=\\s*[^\\n]+', replacement: 'SECRET = "***"', enabled: true },
      { id: uid('rule'), pattern: 'api_key\\s*=\\s*[^\\n]+', replacement: 'API_KEY = "***"', enabled: true },
      { id: uid('rule'), pattern: 'Authorization:\\s*Bearer\\s+[^\\n]+', replacement: 'Authorization: Bearer ***', enabled: true },
    ],
  }
}

function loadState() {
  try {
    const raw = localStorage.getItem(STORAGE_KEY)
    if (!raw) return defaultState()
    return { ...defaultState(), ...JSON.parse(raw) }
  } catch (error) {
    console.warn('Failed to load local state', error)
    return defaultState()
  }
}

function saveState(state) {
  localStorage.setItem(STORAGE_KEY, JSON.stringify(state))
}

function mutate(mutator) {
  const state = loadState()
  const result = mutator(state)
  saveState(state)
  return result
}

function read(selector) {
  return selector(loadState())
}

function clone(value) {
  return JSON.parse(JSON.stringify(value))
}

function maskContent(content, rules) {
  return rules.reduce((text, rule) => {
    if (!rule.enabled || !rule.pattern) return text
    try {
      return text.replace(new RegExp(rule.pattern, 'gi'), rule.replacement || '***')
    } catch (error) {
      return text
    }
  }, content || '')
}

function normalizeSlug(text) {
  const lower = (text || '').toLowerCase().replace(/https?:\/\/\S+/g, ' ')
  if (/(spa\s*2(\.0)?|spa2)/i.test(lower) && /(知识图谱|knowledge[-\s]?graph)/i.test(lower)) {
    return 'spa2-knowledge-graph'
  }
  const known = [
    ['spa 2.0', 'spa2'],
    ['spa2', 'spa2'],
    ['知识图谱', 'knowledge-graph'],
    ['knowledge graph', 'knowledge-graph'],
    ['工单', 'task'],
    ['review', 'review'],
    ['复盘', 'retrospective'],
    ['提交', 'commit'],
    ['分支', 'branch'],
  ]
  const tokens = []
  known.forEach(([keyword, slug]) => {
    if (lower.includes(keyword.toLowerCase()) && !tokens.includes(slug)) tokens.push(slug)
  })
  const asciiWords = lower.match(/[a-z0-9]+/g) || []
  asciiWords.forEach((word) => {
    const alreadyCovered = tokens.some((token) => token.split('-').includes(word))
    if (word.length > 2 && !alreadyCovered && !['the', 'and', 'for', 'with', 'http', 'https'].includes(word)) {
      tokens.push(word)
    }
  })
  return (tokens.length ? tokens : ['ai', 'dev', 'task'])
    .join('-')
    .replace(/-+/g, '-')
    .slice(0, 60)
    .replace(/^-|-$/g, '')
}

function buildPlan({ docContent, project }) {
  const projectName = project?.name || '未选择项目'
  return `## 需求理解

本工单需要基于需求文档完成 ${projectName} 的代码变更，并保留需求、计划、执行、Review、提交和复盘记录。

## 涉及项目

- ${projectName}

## 可能涉及模块

- 工单状态机与详情展示
- 分支生成与校验
- 开发计划版本管理
- Agent 执行日志、diff 与测试结果采集
- Review、commit message 与复盘内容生成

## 实施步骤

1. 读取并脱敏需求文档，保存快照。
2. 依据需求语义生成可编辑分支名，并检查目标分支是否已存在。
3. 生成开发计划，人工编辑后保存为新版本。
4. 人工确认计划后，创建独立 worktree 并触发编码代理。
5. 持续记录执行日志，完成后保存变更摘要、文件列表和 git diff。
6. 发起 AI Review，结合测试命令结果给出 pass / warning / failed 结论。
7. Review 通过后生成人工可编辑的 commit message，并确认提交。
8. 根据需求、计划、diff、review 和提交信息生成复盘 Markdown。

## 配置变更

- 暂未发现必须配置变更；如实现过程中新增环境变量，需要在 Review 阶段标记风险。

## SQL 变更

- 暂未发现必须 SQL 变更；如新增表结构，需要补充迁移脚本和回滚说明。

## 脚本变更

- 暂未发现必须脚本或定时任务变更。

## 验证计划

- 运行项目配置中的语法检查命令。
- 运行项目配置中的测试命令。
- 核对状态流转、diff 展示、Review 结论和提交记录是否完整。

## 风险点

- AI 可能修改计划外文件，需通过 diff 和 Review 阶段拦截。
- 需求文档可能包含敏感信息，进入模型前必须脱敏。
- worktree 内存在未提交改动时必须阻断执行，避免覆盖人工改动。

<!-- 摘要来源：${(docContent || '').slice(0, 80).replace(/\n/g, ' ')} -->`
}

function buildDiff(task) {
  const branch = task.finalBranchName || `${task.branchPrefix}${task.branchName}`
  return `diff --git a/app/Services/AiDevTaskService.php b/app/Services/AiDevTaskService.php
new file mode 100644
index 0000000..7f4ac10
--- /dev/null
+++ b/app/Services/AiDevTaskService.php
@@ -0,0 +1,35 @@
+<?php
+
+namespace app\\Services;
+
+class AiDevTaskService
+{
+    public function buildBranchName(string $prefix, string $slug): string
+    {
+        return rtrim($prefix, '/') . '/' . $slug;
+    }
+
+    public function nextStatus(string $status, string $event): string
+    {
+        return match ($event) {
+            'confirm_plan' => 'plan_confirmed',
+            'code_changed' => 'code_changed',
+            'review_passed' => 'ready_to_commit',
+            'commit_done' => 'committed',
+            default => $status,
+        };
+    }
+}
diff --git a/config/ai_dev.php b/config/ai_dev.php
new file mode 100644
index 0000000..9d73347
--- /dev/null
+++ b/config/ai_dev.php
@@ -0,0 +1,13 @@
+<?php
+
+return [
+    'branch' => '${branch}',
+    'agent' => [
+        'driver' => 'claude_code',
+        'output_format' => 'stream-json',
+        'max_turns' => 50,
+    ],
+    'safety' => [
+        'allow_push' => false,
+    ],
+];`
}

export const api = {
  getTaskStatuses() {
    return taskStatuses
  },

  listTasks(filters = {}) {
    return read((state) => {
      let tasks = state.tasks.map((task) => ({
        ...task,
        project: state.projects.find((project) => project.id === task.projectId),
        latestPlan: state.plans.filter((plan) => plan.taskId === task.id).at(-1),
        latestChange: state.changes.filter((change) => change.taskId === task.id).at(-1),
      }))
      if (filters.status) tasks = tasks.filter((task) => task.status === filters.status)
      if (filters.projectId) tasks = tasks.filter((task) => task.projectId === filters.projectId)
      if (filters.createdBy) tasks = tasks.filter((task) => task.createdByName.includes(filters.createdBy))
      if (filters.submitted === true) tasks = tasks.filter((task) => !!task.commitHash)
      if (filters.submitted === false) tasks = tasks.filter((task) => !task.commitHash)
      return clone(tasks.sort((a, b) => b.updatedAt.localeCompare(a.updatedAt)))
    })
  },

  getTask(id) {
    return read((state) => {
      const task = state.tasks.find((item) => item.id === id)
      if (!task) return null
      return clone({
        ...task,
        project: state.projects.find((project) => project.id === task.projectId),
        plans: state.plans.filter((plan) => plan.taskId === id).sort((a, b) => a.version - b.version),
        runs: state.runs.filter((run) => run.taskId === id).sort((a, b) => b.createdAt.localeCompare(a.createdAt)),
        changes: state.changes.filter((change) => change.taskId === id).sort((a, b) => b.createdAt.localeCompare(a.createdAt)),
        reviews: state.reviews.filter((review) => review.taskId === id).sort((a, b) => b.createdAt.localeCompare(a.createdAt)),
        retrospective: state.retrospectives.find((retro) => retro.taskId === id),
      })
    })
  },

  createTask(input) {
    return mutate((state) => {
      const project = state.projects.find((item) => item.id === input.projectId)
      const id = uid('task')
      const maskedDoc = maskContent(input.docContent || '', state.securityRules)
      const branchName = input.branchName || normalizeSlug(maskedDoc || input.title || input.docUrl)
      const finalBranchName = input.finalBranchName || `${input.branchPrefix || project?.defaultBranchPrefix || 'future/'}${branchName}`
      const task = {
        id,
        title: input.title || branchName,
        docUrl: input.docUrl || '',
        docContentSnapshot: maskedDoc,
        projectId: input.projectId,
        repoName: project?.name || '',
        baseBranch: input.baseBranch || project?.defaultBaseBranch || 'develop',
        branchPrefix: input.branchPrefix || project?.defaultBranchPrefix || 'future/',
        branchName,
        finalBranchName,
        status: input.planContent ? 'plan_generated' : finalBranchName ? 'branch_generated' : maskedDoc ? 'doc_loaded' : 'created',
        createdBy: 1,
        createdByName: input.createdByName || '当前用户',
        commitHash: '',
        commitMessage: '',
        pushed: false,
        prUrl: '',
        createdAt: now(),
        updatedAt: now(),
      }
      state.tasks.push(task)
      if (input.planContent) {
        state.plans.push({
          id: uid('plan'),
          taskId: id,
          version: 1,
          planContent: input.planContent,
          source: 'ai',
          modelName: state.modelConfig.modelName,
          confirmedBy: 0,
          confirmedAt: null,
          createdAt: now(),
        })
      }
      return clone(task)
    })
  },

  updateTask(id, patch) {
    return mutate((state) => {
      const task = state.tasks.find((item) => item.id === id)
      if (!task) return null
      Object.assign(task, patch, { updatedAt: now() })
      return clone(task)
    })
  },

  loadDoc(content) {
    return read((state) => ({
      docContent: maskContent(content || '', state.securityRules),
      masked: true,
    }))
  },

  generateBranch({ docContent, title }) {
    const branchName = normalizeSlug(`${title || ''}\n${docContent || ''}`)
    return {
      branchName,
      reason: branchName.includes('knowledge-graph')
        ? '需求涉及知识图谱能力，分支名保留核心语义'
        : '分支名根据需求关键词生成，并符合小写英文、数字和短横线约束',
    }
  },

  validateBranch(finalBranchName) {
    const normalized = finalBranchName || ''
    const validFormat = /^(?!\/)(?!.*\/\/)[a-z0-9._/-]+$/.test(normalized)
      && !normalized.includes(' ')
      && !normalized.endsWith('/')
      && normalized.length <= 120
    const exists = read((state) => state.branches.includes(normalized))
    return {
      valid: validFormat && !exists,
      validFormat,
      exists,
      message: !validFormat ? '分支名只能包含小写英文、数字、点、下划线、斜线和短横线' : exists ? '远程仓库已存在该分支' : '分支可用',
    }
  },

  generatePlan({ docContent, projectId }) {
    return read((state) => {
      const project = state.projects.find((item) => item.id === projectId)
      return buildPlan({ docContent, project })
    })
  },

  savePlan(taskId, planContent, source = 'human') {
    return mutate((state) => {
      const plans = state.plans.filter((plan) => plan.taskId === taskId)
      const version = plans.length ? Math.max(...plans.map((plan) => plan.version)) + 1 : 1
      const plan = {
        id: uid('plan'),
        taskId,
        version,
        planContent,
        source,
        modelName: source === 'ai' ? state.modelConfig.modelName : '',
        confirmedBy: 0,
        confirmedAt: null,
        createdAt: now(),
      }
      state.plans.push(plan)
      const task = state.tasks.find((item) => item.id === taskId)
      if (task && task.status !== 'plan_confirmed') {
        task.status = 'plan_generated'
        task.updatedAt = now()
      }
      return clone(plan)
    })
  },

  confirmPlan(taskId) {
    return mutate((state) => {
      const task = state.tasks.find((item) => item.id === taskId)
      const plans = state.plans.filter((plan) => plan.taskId === taskId)
      const latest = plans.at(-1)
      if (task && latest) {
        latest.confirmedBy = 1
        latest.confirmedAt = now()
        task.status = 'plan_confirmed'
        task.updatedAt = now()
      }
      return clone(latest)
    })
  },

  startRun(taskId, runType = 'coding', input = '') {
    return mutate((state) => {
      const task = state.tasks.find((item) => item.id === taskId)
      const run = {
        id: uid('run'),
        taskId,
        runType,
        status: 'running',
        modelName: state.modelConfig.modelName,
        agentSessionId: uid('claude_session'),
        pid: Math.floor(20000 + Math.random() * 40000),
        input,
        output: '',
        error: '',
        startedAt: now(),
        finishedAt: null,
        createdAt: now(),
      }
      state.runs.push(run)
      if (task) {
        task.status = runType === 'fix' ? 'fixing' : 'coding'
        task.updatedAt = now()
      }
      return clone(run)
    })
  },

  appendRunLog(runId, eventType, content) {
    return mutate((state) => {
      const logs = state.runLogs.filter((log) => log.runId === runId)
      const log = {
        id: uid('log'),
        runId,
        seq: logs.length ? Math.max(...logs.map((item) => item.seq)) + 1 : 1,
        eventType,
        content,
        createdAt: now(),
      }
      state.runLogs.push(log)
      return clone(log)
    })
  },

  finishRun(runId, { status = 'succeeded', output = '', error = '' } = {}) {
    return mutate((state) => {
      const run = state.runs.find((item) => item.id === runId)
      if (!run) return null
      run.status = status
      run.output = output
      run.error = error
      run.finishedAt = now()
      const task = state.tasks.find((item) => item.id === run.taskId)
      if (task) {
        if (status === 'succeeded') {
          const project = state.projects.find((item) => item.id === task.projectId)
          const diff = buildDiff(task)
          const change = {
            id: uid('change'),
            taskId: task.id,
            runId,
            diffSummary: '新增 AI 开发工单服务和配置示例，覆盖分支生成、状态流转、Agent 参数和安全默认值。',
            changedFiles: JSON.stringify(['app/Services/AiDevTaskService.php', 'config/ai_dev.php']),
            gitDiffSnapshot: diff,
            testResult: `命令：${project?.testCommand || 'php think test'}\n结果：模拟通过\n耗时：12.4s`,
            commitHash: '',
            createdAt: now(),
          }
          state.changes.push(change)
          task.status = 'code_changed'
        } else {
          task.status = 'failed'
        }
        task.updatedAt = now()
      }
      return clone(run)
    })
  },

  listRunLogs(runId, afterSeq = 0) {
    return read((state) => clone(
      state.runLogs
        .filter((log) => log.runId === runId && log.seq > afterSeq)
        .sort((a, b) => a.seq - b.seq),
    ))
  },

  cancelRun(runId) {
    return mutate((state) => {
      const run = state.runs.find((item) => item.id === runId)
      if (!run) return null
      run.status = 'cancelled'
      run.error = '人工取消执行'
      run.finishedAt = now()
      const task = state.tasks.find((item) => item.id === run.taskId)
      if (task) {
        task.status = 'plan_confirmed'
        task.updatedAt = now()
      }
      return clone(run)
    })
  },

  createReview(taskId, feedback = '') {
    return mutate((state) => {
      const task = state.tasks.find((item) => item.id === taskId)
      const change = state.changes.filter((item) => item.taskId === taskId).at(-1)
      const failed = /失败|不通过|缺少|风险高|must fix/i.test(feedback)
      const review = {
        id: uid('review'),
        taskId,
        runId: change?.runId || '',
        status: failed ? 'failed' : 'pass',
        riskLevel: failed ? 'high' : 'low',
        reviewResult: JSON.stringify({
          status: failed ? 'failed' : 'pass',
          risk_level: failed ? 'high' : 'low',
          blocking_issues: failed ? ['Review 反馈中存在必须修复项，需要继续修改'] : [],
          warnings: failed ? ['修复后需重新运行 Review'] : ['当前为模拟 Review，真实环境需接入模型和测试命令'],
          suggestions: ['确认 diff 是否严格限定在开发计划范围内', '提交前再次检查敏感信息和配置变更'],
          summary: failed ? 'Review 未通过，请补充修改要求后继续执行 fix 轮次。' : 'Review 通过，代码改动与计划一致，可进入提交确认。',
        }, null, 2),
        testResult: change?.testResult || '未采集测试结果',
        createdAt: now(),
      }
      state.reviews.push(review)
      if (task) {
        task.status = failed ? 'review_failed' : 'ready_to_commit'
        task.updatedAt = now()
      }
      return clone(review)
    })
  },

  generateCommitMessage(taskId) {
    return read((state) => {
      const task = state.tasks.find((item) => item.id === taskId)
      return `feat(${task?.repoName || 'ai-dev'}): ${task?.title || '完成 AI 开发工单'}\n\n- 保存需求快照、开发计划和执行记录\n- 补充 Review、diff 与提交确认链路\n- 保留复盘内容，便于后续追踪`
    })
  },

  commitTask(taskId, message) {
    return mutate((state) => {
      const task = state.tasks.find((item) => item.id === taskId)
      if (!task) return null
      const hash = Math.random().toString(16).slice(2, 10)
      task.status = 'committed'
      task.commitHash = hash
      task.commitMessage = message
      task.updatedAt = now()
      const change = state.changes.filter((item) => item.taskId === taskId).at(-1)
      if (change) change.commitHash = hash
      state.branches.push(task.finalBranchName)
      return clone(task)
    })
  },

  generateRetrospective(taskId) {
    return read((state) => {
      const task = state.tasks.find((item) => item.id === taskId)
      const change = state.changes.filter((item) => item.taskId === taskId).at(-1)
      const review = state.reviews.filter((item) => item.taskId === taskId).at(-1)
      return `# ${task?.title || 'AI 开发工单'} 复盘

## 需求背景

需求来源：${task?.docUrl || '手动录入需求'}。

## 实现内容

${change?.diffSummary || '暂无改动摘要'}。

## 涉及文件

${JSON.parse(change?.changedFiles || '[]').map((file) => `- ${file}`).join('\n') || '- 暂无'}

## 验证情况

${review?.testResult || '暂无测试结果'}。

## 遇到的问题

- 第一版以模拟 Agent 跑通流程；真实环境需接入任务队列和 Claude Code headless。

## 风险与遗留项

- 需在后端落库并增加 worktree 隔离、进程超时、Redis 锁和命令白名单。

## 后续建议

- 接入飞书 Wiki 自动读取。
- 接入真实 git diff、测试命令、commit 和 Review 模型调用。`
    })
  },

  saveRetrospective(taskId, content) {
    return mutate((state) => {
      let retrospective = state.retrospectives.find((item) => item.taskId === taskId)
      if (!retrospective) {
        retrospective = {
          id: uid('retro'),
          taskId,
          content,
          createdBy: 1,
          createdAt: now(),
          updatedAt: now(),
        }
        state.retrospectives.push(retrospective)
      } else {
        retrospective.content = content
        retrospective.updatedAt = now()
      }
      const task = state.tasks.find((item) => item.id === taskId)
      if (task) {
        task.status = 'retrospected'
        task.updatedAt = now()
      }
      return clone(retrospective)
    })
  },

  terminateTask(taskId) {
    return mutate((state) => {
      const task = state.tasks.find((item) => item.id === taskId)
      if (!task) return null
      task.status = 'terminated'
      task.updatedAt = now()
      return clone(task)
    })
  },

  listProjects() {
    return read((state) => clone(state.projects))
  },

  saveProject(input) {
    return mutate((state) => {
      if (input.id) {
        const project = state.projects.find((item) => item.id === input.id)
        Object.assign(project, input, { updatedAt: now() })
        return clone(project)
      }
      const project = { ...input, id: uid('project'), createdAt: now(), updatedAt: now(), status: 1 }
      state.projects.push(project)
      return clone(project)
    })
  },

  deleteProject(id) {
    return mutate((state) => {
      state.projects = state.projects.filter((project) => project.id !== id)
      return true
    })
  },

  getModelConfig() {
    return read((state) => clone(state.modelConfig))
  },

  saveModelConfig(config) {
    return mutate((state) => {
      state.modelConfig = { ...state.modelConfig, ...config }
      return clone(state.modelConfig)
    })
  },

  listSecurityRules() {
    return read((state) => clone(state.securityRules))
  },

  saveSecurityRules(rules) {
    return mutate((state) => {
      state.securityRules = rules.map((rule) => ({ ...rule, id: rule.id || uid('rule') }))
      return clone(state.securityRules)
    })
  },

  resetDemoData() {
    const state = defaultState()
    saveState(state)
    return clone(state)
  },
}
