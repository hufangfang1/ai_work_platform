<script setup>
import { defineAsyncComponent, onBeforeUnmount, ref } from 'vue'
import ChildhoodHome from './components/ChildhoodHome.vue'
const Legacy = defineAsyncComponent(() => import('./App.vue'))
const legacy = ref(location.hash.startsWith('#/momo'))
const update = () => { legacy.value = location.hash.startsWith('#/momo') }
window.addEventListener('hashchange', update)
onBeforeUnmount(() => window.removeEventListener('hashchange', update))
</script>
<template><Legacy v-if="legacy"/><ChildhoodHome v-else/></template>
