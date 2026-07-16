<template>
  <aside class="sidebar">
    <div class="sidebar-brand">
      <h2>SpiderNetOS</h2>
    </div>
    <nav class="sidebar-nav">
      <router-link 
        v-for="item in navItems" 
        :key="item.path" 
        :to="item.path" 
        class="nav-item"
        active-class="active"
      >
        <span class="nav-icon">{{ item.icon }}</span>
        <span class="nav-label">{{ item.label }}</span>
      </router-link>
    </nav>
  </aside>
</template>

<script>
export default {
  name: 'Sidebar',
  data() {
    return {
      navItems: [
        { icon: '📊', label: 'Dashboard', path: '/dashboard' },
        { icon: '🗣️', label: 'Atlas', path: '/atlas' },
        { icon: '🤖', label: 'Agents', path: '/agents' },
        { icon: '⚡', label: 'Flows', path: '/flows' },
        { icon: '📊', label: 'Analytics', path: '/analytics' },
        { icon: '🤖', label: 'Orchestration', path: '/orchestration' },
        { icon: '📄', label: 'Reports', path: '/reports' },
        { icon: '🔒', label: 'Security', path: '/security' },
      ]
    }
  }
}
</script>

<style scoped>
.sidebar {
  width: 240px;
  height: 100vh;
  background: var(--bg-card);
  border-right: 1px solid var(--border);
  padding: 1rem;
  position: sticky;
  top: 0;
  overflow-y: auto;
}
.sidebar-brand {
  padding: 1rem 0 2rem 0;
  border-bottom: 1px solid var(--border);
  margin-bottom: 1rem;
}
.sidebar-brand h2 {
  font-size: 1.2rem;
  font-weight: 700;
  color: var(--text-primary);
}
.sidebar-nav {
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}
.nav-item {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  padding: 0.6rem 0.8rem;
  border-radius: 8px;
  color: var(--text-secondary);
  text-decoration: none;
  transition: all 0.2s;
}
.nav-item:hover {
  background: var(--bg-elevated);
  color: var(--text-primary);
}
.nav-item.active {
  background: var(--accent);
  color: white;
}
.nav-icon {
  font-size: 1.2rem;
}
.nav-label {
  font-size: 0.9rem;
}
</style>
