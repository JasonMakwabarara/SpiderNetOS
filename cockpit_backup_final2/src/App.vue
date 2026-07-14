<template>
  <div id="app" class="app-layout">
    <Sidebar />
    <main class="app-main">
      <router-view />
    </main>
  </div>
</template>

<script>
import Sidebar from './components/Sidebar.vue'

export default {
  name: 'App',
  components: {
    Sidebar
  }
}
</script>

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

:root {
  --bg: #0a0a0f;
  --bg-card: #14142a;
  --bg-elevated: #1a1a3e;
  --text-primary: #ffffff;
  --text-secondary: #94a3b8;
  --border: #2a2a4a;
  --accent: #FF6B2C;
  --accent-hover: #FF8C42;
}

body {
  background: var(--bg);
  color: var(--text-primary);
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.app-layout {
  display: flex;
  min-height: 100vh;
}

.app-main {
  flex: 1;
  padding: 0;
  background: var(--bg);
  overflow-y: auto;
}
</style>
