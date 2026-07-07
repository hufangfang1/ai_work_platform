export const taskStatuses = [
  { value: 'created', label: '已创建', type: 'info' },
  { value: 'branch_generated', label: '分支已生成', type: 'primary' },
  { value: 'plan_generated', label: '计划已生成', type: 'primary' },
  { value: 'plan_confirmed', label: '计划已确认', type: 'success' },
  { value: 'coding', label: 'AI 开发中', type: 'warning' },
  { value: 'code_changed', label: '待 Review', type: 'warning' },
  { value: 'reviewing', label: 'Review 中', type: 'warning' },
  { value: 'review_passed', label: 'Review 通过', type: 'success' },
  { value: 'review_failed', label: 'Review 未通过', type: 'danger' },
  { value: 'fixing', label: '继续修改中', type: 'warning' },
  { value: 'ready_to_commit', label: '待提交', type: 'primary' },
  { value: 'committing', label: '提交中', type: 'warning' },
  { value: 'committed', label: '已提交', type: 'success' },
  { value: 'retrospected', label: '已复盘', type: 'success' },
  { value: 'failed', label: '执行失败', type: 'danger' },
  { value: 'terminated', label: '已终止', type: 'info' },
]

export const requirementStatuses = [
  { value: 'draft', label: '草稿', type: 'info' },
  { value: 'active', label: '进行中', type: 'primary' },
  { value: 'closed', label: '已关闭', type: 'success' },
]

// 运行中的过渡态,前端需要轮询并展示脉冲动画
export const runningStatuses = new Set(['coding', 'reviewing', 'committing', 'fixing'])

export function getStatusMeta(status) {
  return (
    taskStatuses.find((item) => item.value === status)
    || requirementStatuses.find((item) => item.value === status)
    || { value: status, label: status || '未知', type: 'info' }
  )
}

export function canTerminate(status) {
  return !['committed', 'retrospected', 'terminated'].includes(status)
}
