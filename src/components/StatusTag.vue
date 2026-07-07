<template>
  <span class="status-tag" :class="`status-tag--${meta.type}`">
    <i class="status-dot" :class="{ 'status-dot--pulse': running }" />
    {{ meta.label }}
  </span>
</template>

<script setup>
import { computed } from 'vue'
import { getStatusMeta, runningStatuses } from '../services/status'

const props = defineProps({
  status: {
    type: String,
    required: true,
  },
})

const meta = computed(() => getStatusMeta(props.status))
const running = computed(() => runningStatuses.has(props.status))
</script>
