<template>
  <section class="page">
    <div class="page-heading">
      <div>
        <h1>开发任务</h1>
        <p>开发任务由需求拆解生成，跟踪开发计划、代码实现、代码审查与代码提交；项目复盘统一在需求详情页完成。</p>
      </div>
    </div>

    <div class="panel">
      <el-form class="compact-form" :model="filters" label-position="top">
        <el-form-item label="所属需求">
          <el-select v-model="filters.requirement_id" clearable filterable placeholder="全部需求">
            <el-option
              v-for="requirement in requirements"
              :key="requirement.id"
              :label="requirement.title"
              :value="requirement.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="filters.status" clearable placeholder="全部状态">
            <el-option
              v-for="status in statuses"
              :key="status.value"
              :label="status.label"
              :value="status.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="项目">
          <el-select v-model="filters.project_id" clearable placeholder="全部项目">
            <el-option
              v-for="project in projects"
              :key="project.id"
              :label="project.name"
              :value="project.id"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="提交状态">
          <el-select v-model="filters.submitted" clearable placeholder="全部">
            <el-option label="已提交" value="1" />
            <el-option label="未提交" value="0" />
          </el-select>
        </el-form-item>
      </el-form>
    </div>

    <div class="panel" v-loading="loading">
      <el-table v-if="tasks.length" :data="tasks">
        <el-table-column label="任务标题" min-width="220">
          <template #default="{ row }">
            <el-link type="primary" @click="$router.push(`/tasks/${row.id}`)">
              {{ row.title }}
            </el-link>
          </template>
        </el-table-column>
        <el-table-column label="所属需求" min-width="180">
          <template #default="{ row }">
            <el-link
              v-if="row.requirement_id"
              class="muted"
              @click="$router.push(`/requirements/${row.requirement_id}`)"
            >
              {{ row.requirement_title || `需求 #${row.requirement_id}` }}
            </el-link>
            <span v-else class="muted">-</span>
          </template>
        </el-table-column>
        <el-table-column label="项目" width="140">
          <template #default="{ row }">
            <span class="chip chip--brand">{{ row.project_name || row.repo_name }}</span>
          </template>
        </el-table-column>
        <el-table-column label="分支" min-width="200">
          <template #default="{ row }">
            <span v-if="row.final_branch_name" class="mono">{{ row.final_branch_name }}</span>
            <span v-else class="muted">未生成</span>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="140">
          <template #default="{ row }"><StatusTag :status="row.status" /></template>
        </el-table-column>
        <el-table-column label="最后更新" width="170">
          <template #default="{ row }">{{ formatTime(row.updated_at) }}</template>
        </el-table-column>
      </el-table>
      <div v-else-if="!loading" class="empty-state">暂无开发任务，请先在需求页完成拆解</div>
    </div>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref, watch } from 'vue'
import StatusTag from '../components/StatusTag.vue'
import { taskStatuses } from '../services/status'
import { api } from '../services/api'

const filters = reactive({
  requirement_id: '',
  status: '',
  project_id: '',
  submitted: '',
})
const tasks = ref([])
const projects = ref([])
const requirements = ref([])
const loading = ref(false)
const statuses = taskStatuses

function formatTime(value) {
  if (!value) return '-'
  return new Date(value.replace(' ', 'T')).toLocaleString('zh-CN', { hour12: false })
}

async function load() {
  loading.value = true
  try {
    tasks.value = await api.tasks.list({ ...filters })
  } finally {
    loading.value = false
  }
}

watch(filters, load)

onMounted(async () => {
  load()
  projects.value = await api.projects.list()
  requirements.value = await api.requirements.list()
})
</script>
