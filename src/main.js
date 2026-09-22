import { createApp } from 'vue'
import { ElDialog } from 'element-plus/es/components/dialog/index.mjs'
import { ElIcon } from 'element-plus/es/components/icon/index.mjs'
import 'element-plus/es/components/dialog/style/css.mjs'
import 'element-plus/es/components/icon/style/css.mjs'
import { Star, Right, Promotion, Close, Lock } from '@element-plus/icons-vue'
import App from './HomeApp.vue'
import './assets/styles.css'
import './assets/world.css'

const app = createApp(App)

Object.entries({ ElDialog, ElIcon, Star, Right, Promotion, Close, Lock }).forEach(([key, component]) => {
  app.component(key, component)
})

app.mount('#app')
