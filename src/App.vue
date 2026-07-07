<template>
  <div class="app-shell">
    <aside class="sidebar">
      <div class="brand">
        <div class="brand-mark">AI</div>
        <div>
          <div class="brand-name">开发工单台</div>
          <div class="brand-subtitle">AI Dev Workbench</div>
        </div>
      </div>
      <nav class="nav">
        <RouterLink class="nav-link" to="/requirements">
          <el-icon><Document /></el-icon>
          <span>需求</span>
        </RouterLink>
        <RouterLink class="nav-link" to="/tasks">
          <el-icon><Tickets /></el-icon>
          <span>工单</span>
        </RouterLink>
        <RouterLink class="nav-link" to="/projects">
          <el-icon><FolderOpened /></el-icon>
          <span>项目</span>
        </RouterLink>
        <RouterLink class="nav-link" to="/settings">
          <el-icon><Setting /></el-icon>
          <span>设置</span>
        </RouterLink>
      </nav>
    </aside>
    <main class="main">
      <RouterView />
    </main>
  </div>
</template>
