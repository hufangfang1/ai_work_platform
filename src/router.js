import { createRouter, createWebHashHistory } from 'vue-router'
import RequirementList from './views/RequirementList.vue'
import RequirementDetail from './views/RequirementDetail.vue'
import TaskList from './views/TaskList.vue'
import TaskDetail from './views/TaskDetail.vue'
import ProjectConfig from './views/ProjectConfig.vue'
import Settings from './views/Settings.vue'

const routes = [
  { path: '/', redirect: '/requirements' },
  { path: '/requirements', name: 'requirements', component: RequirementList },
  { path: '/requirements/:id', name: 'requirement-detail', component: RequirementDetail, props: true },
  { path: '/tasks', name: 'tasks', component: TaskList },
  { path: '/tasks/:id', name: 'task-detail', component: TaskDetail, props: true },
  { path: '/projects', name: 'projects', component: ProjectConfig },
  { path: '/settings', name: 'settings', component: Settings },
]

export default createRouter({
  history: createWebHashHistory(),
  routes,
})
