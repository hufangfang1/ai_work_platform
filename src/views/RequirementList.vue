<template>
  <section class="page">
    <div class="page-heading">
      <div>
        <h1>需求</h1>
        <p>一个需求可以关联多个项目，拆解后为每个项目生成独立开发任务。</p>
      </div>
      <div class="toolbar">
        <el-button type="primary" @click="createVisible = true">
          <el-icon><Plus /></el-icon>
          新建需求
        </el-button>
      </div>
    </div>

    <div class="panel" v-loading="loading">
      <el-table v-if="requirements.length" :data="requirements">
        <el-table-column label="需求标题" min-width="240">
          <template #default="{ row }">
            <el-link type="primary" @click="$router.push(`/requirements/${row.id}`)">
              {{ row.title }}
            </el-link>
            <div class="muted" style="font-size: 12px">
              {{ row.doc_url || '手动录入需求' }}
            </div>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="110">
          <template #default="{ row }"><StatusTag :status="row.status" /></template>
        </el-table-column>
        <el-table-column label="涉及项目" min-width="180">
          <template #default="{ row }">
            <template v-if="row.project_names">
              <span v-for="name in row.project_names.split(',')" :key="name" class="chip chip--brand">
                {{ name }}
              </span>
            </template>
            <span v-else class="muted">未拆解</span>
          </template>
        </el-table-column>
        <el-table-column label="任务进度" width="170">
          <template #default="{ row }">
            <template v-if="row.task_total > 0">
              <div class="muted" style="font-size: 12px; margin-bottom: 4px">
                {{ row.task_committed }}/{{ row.task_total }} 已提交
              </div>
              <el-progress
                :percentage="Math.round((row.task_committed / row.task_total) * 100)"
                :stroke-width="5"
                :show-text="false"
              />
            </template>
            <span v-else class="muted">未拆解</span>
          </template>
        </el-table-column>
        <el-table-column label="快照" width="80">
          <template #default="{ row }">
            <span v-if="row.latest_doc_version" class="mono">v{{ row.latest_doc_version }}</span>
            <span v-else class="muted">无</span>
          </template>
        </el-table-column>
        <el-table-column label="最后更新" width="170">
          <template #default="{ row }">{{ formatTime(row.updated_at) }}</template>
        </el-table-column>
      </el-table>
      <div v-else-if="!loading" class="empty-state">还没有需求,点击右上角"新建需求"开始</div>
    </div>

    <el-dialog v-model="createVisible" title="新建需求" width="520px">
      <el-form :model="createForm" label-position="top">
        <el-form-item label="需求标题" required>
          <el-input v-model="createForm.title" placeholder="例如:SPA 2.0 知识图谱" />
        </el-form-item>
        <el-form-item label="需求文档地址(选填)">
          <el-input v-model="createForm.doc_url" placeholder="https://xxx.feishu.cn/wiki/..." />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="createVisible = false">取消</el-button>
        <el-button type="primary" :loading="creating" @click="createRequirement">创建</el-button>
      </template>
    </el-dialog>
  </section>
</template>

<script setup>
import { onMounted, reactive, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useRouter } from 'vue-router'
import StatusTag from '../components/StatusTag.vue'
import { api } from '../services/api'

const router = useRouter()
const requirements = ref([])
const loading = ref(false)
const createVisible = ref(false)
const creating = ref(false)
const createForm = reactive({ title: '', doc_url: '' })

function formatTime(value) {
  if (!value) return '-'
  return new Date(value.replace(' ', 'T')).toLocaleString('zh-CN', { hour12: false })
}

async function load() {
  loading.value = true
  try {
    requirements.value = await api.requirements.list()
  } finally {
    loading.value = false
  }
}

async function createRequirement() {
  if (!createForm.title.trim()) {
    ElMessage.warning('请填写需求标题')
    return
  }
  creating.value = true
  try {
    const requirement = await api.requirements.create({ ...createForm })
    createVisible.value = false
    router.push(`/requirements/${requirement.id}`)
  } finally {
    creating.value = false
  }
}

onMounted(load)
</script>
