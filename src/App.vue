<template>
  <div class="app-shell">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-mark">AI</div>
        <div>
          <div class="brand-name">AI 开发平台</div>
          <div class="brand-subtitle">AI Dev Workbench</div>
        </div>
      </div>
      <nav class="nav">
        <RouterLink class="nav-link" to="/requirements">
          <el-icon><Document /></el-icon>
          <span>需求管理</span>
        </RouterLink>
        <RouterLink class="nav-link" to="/tasks">
          <el-icon><Tickets /></el-icon>
          <span>开发任务</span>
        </RouterLink>
        <RouterLink class="nav-link" to="/projects">
          <el-icon><FolderOpened /></el-icon>
          <span>项目配置</span>
        </RouterLink>
        <RouterLink class="nav-link" to="/settings">
          <el-icon><Setting /></el-icon>
          <span>系统设置</span>
        </RouterLink>
      </nav>
    </aside>
    <main class="main">
      <RouterView />
    </main>
  </div>
</template>
