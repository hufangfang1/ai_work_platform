import { ElMessage } from 'element-plus'

const BASE = '/api/ai-dev'

async function request(method, path, body, { silent = false } = {}) {
  let res
  try {
    res = await fetch(BASE + path, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: body === undefined ? undefined : JSON.stringify(body),
    })
  } catch (error) {
    if (!silent) ElMessage.error('无法连接后端服务')
    throw error
  }
  const json = await res.json().catch(() => ({ code: res.status || -1, message: res.statusText }))
  if (json.code !== 0) {
    if (!silent) ElMessage.error(json.message || '请求失败')
    const err = new Error(json.message || '请求失败')
    err.code = json.code
    throw err
  }
  return json.data
}

function qs(params = {}) {
  const search = new URLSearchParams()
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') search.set(key, value)
  })
  const str = search.toString()
  return str ? `?${str}` : ''
}

export const api = {
  requirements: {
    list: () => request('GET', '/requirements'),
    create: (body) => request('POST', '/requirements', body),
    detail: (id) => request('GET', `/requirements/${id}`),
    update: (id, body) => request('PUT', `/requirements/${id}`, body),
    close: (id) => request('POST', `/requirements/${id}/close`),
    loadDoc: (id, body) => request('POST', `/requirements/${id}/load-doc`, body),
    generateBreakdown: (id, body) => request('POST', `/requirements/${id}/generate-breakdown`, body),
    saveBreakdown: (id, body) => request('PUT', `/requirements/${id}/breakdown`, body),
    confirmBreakdown: (id) => request('POST', `/requirements/${id}/confirm-breakdown`),
    generateBranch: (id, model = '', draft = false) =>
      request('POST', `/requirements/${id}/generate-branch`, { model, draft: draft ? 1 : 0 }),
    saveBranch: (id, finalBranchName) =>
      request('PUT', `/requirements/${id}/branch`, { final_branch_name: finalBranchName }),
    checkBranch: (id, finalBranchName) =>
      request('POST', `/requirements/${id}/check-branch`, { final_branch_name: finalBranchName }),
    tasks: (id) => request('GET', `/requirements/${id}/tasks`),
  },

  tasks: {
    list: (params) => request('GET', `/tasks${qs(params)}`),
    detail: (id, options) => request('GET', `/tasks/${id}`, undefined, options),
    create: (body) => request('POST', '/tasks', body),
    update: (id, body) => request('PUT', `/tasks/${id}`, body),
    terminate: (id) => request('POST', `/tasks/${id}/terminate`),
    cleanupWorktree: (id) => request('POST', `/tasks/${id}/cleanup-worktree`),
    generateBranch: (id, model = '', draft = false) =>
      request('POST', `/tasks/${id}/generate-branch`, { model, draft: draft ? 1 : 0 }),
    checkBranch: (id, finalBranchName) =>
      request('POST', `/tasks/${id}/check-branch`, { final_branch_name: finalBranchName }),
    generatePlan: (id, model = '', draft = false) =>
      request('POST', `/tasks/${id}/generate-plan`, { model, draft: draft ? 1 : 0 }),
    savePlan: (id, planContent) => request('PUT', `/tasks/${id}/plan`, { plan_content: planContent }),
    confirmPlan: (id) => request('POST', `/tasks/${id}/confirm-plan`),
    execute: (id, model = '', draft = false) =>
      request('POST', `/tasks/${id}/execute`, { model, draft: draft ? 1 : 0 }),
    review: (id) => request('POST', `/tasks/${id}/review`),
    aiReview: (id, model = '', draft = false) =>
      request('POST', `/tasks/${id}/ai-review`, { model, draft: draft ? 1 : 0 }),
    approveReview: (id) => request('POST', `/tasks/${id}/approve-review`),
    rejectReview: (id, feedback) => request('POST', `/tasks/${id}/reject-review`, { feedback }),
    fix: (id, feedback, model = '', draft = false) =>
      request('POST', `/tasks/${id}/fix`, { feedback, model, draft: draft ? 1 : 0 }),
    generateCommitMessage: (id, model = '', draft = false) =>
      request('POST', `/tasks/${id}/generate-commit-message`, { model, draft: draft ? 1 : 0 }),
    commit: (id, commitMessage) => request('POST', `/tasks/${id}/commit`, { commit_message: commitMessage }),
    push: (id) => request('POST', `/tasks/${id}/push`),
    retrospect: (id) => request('POST', `/tasks/${id}/retrospect`),
    getRetrospective: (id) => request('GET', `/tasks/${id}/retrospective`),
    saveRetrospective: (id, content) => request('PUT', `/tasks/${id}/retrospective`, { content }),
    runs: (id) => request('GET', `/tasks/${id}/runs`),
  },

  runs: {
    detail: (runId, options) => request('GET', `/runs/${runId}`, undefined, options),
    logs: (runId, afterSeq = 0, options) =>
      request('GET', `/runs/${runId}/logs${qs({ after_seq: afterSeq })}`, undefined, options),
    cancel: (runId) => request('POST', `/runs/${runId}/cancel`),
    retry: (runId, model) =>
      request('POST', `/runs/${runId}/retry`, model !== undefined ? { model } : undefined),
    updatePrompt: (runId, prompt) => request('PUT', `/runs/${runId}/prompt`, { prompt }),
    execute: (runId) => request('POST', `/runs/${runId}/execute`),
    discard: (runId) => request('POST', `/runs/${runId}/discard`),
  },

  projects: {
    list: () => request('GET', '/projects'),
    save: (body) => request('POST', '/projects', body),
    update: (id, body) => request('PUT', `/projects/${id}`, body),
    remove: (id) => request('DELETE', `/projects/${id}`),
    scan: () => request('POST', '/projects/scan'),
    describe: (path, model = '', draft = false) =>
      request('POST', '/projects/describe', { path, model, draft: draft ? 1 : 0 }),
  },

  config: {
    workspace: () => request('GET', '/workspace-config'),
    browseWorkspace: (path) => request('GET', `/workspace-browse${qs({ path })}`),
    saveWorkspace: (roots) => request('PUT', '/workspace-config', { roots }),
    model: () => request('GET', '/model-config'),
    modelProfiles: () => request('GET', '/model-profiles'),
    modelOptions: () => request('GET', '/model-options'),
    saveModel: (body) => request('PUT', '/model-config', body),
    saveModelProfiles: (profiles) => request('PUT', '/model-profiles', { profiles }),
    refreshModelProfiles: () => request('POST', '/model-profiles/refresh'),
    securityRules: () => request('GET', '/security-rules'),
    saveSecurityRules: (rules) => request('PUT', '/security-rules', { rules }),
    exportConfig: () => request('GET', '/config-export'),
    importConfig: (body) => request('POST', '/config-import', body),
    migrationStatus: () => request('GET', '/migration-status'),
    migrate: () => request('POST', '/migrate'),
  },
}
