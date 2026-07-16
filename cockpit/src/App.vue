<template>
  <div class="app-container">
    <!-- Sidebar -->
    <aside class="sidebar">
      <div class="sidebar-brand">SpiderNetOS</div>
      <nav class="sidebar-nav">
        <a href="#/dashboard" class="nav-link active">Dashboard</a>
        <a href="#/atlas" class="nav-link">Atlas</a>
        <a href="#/approvals" class="nav-link">Approvals</a>
        <a href="#/traces" class="nav-link">Traces</a>
        <hr>
        <a href="#/agents" class="nav-link">Agents</a>
        <a href="#/flows" class="nav-link">Flows</a>
        <a href="#/memory" class="nav-link">Memory</a>
        <a href="#/intelligence" class="nav-link">Intelligence</a>
        <hr>
        <a href="#/usage" class="nav-link">Usage</a>
        <a href="#/analytics" class="nav-link">Analytics</a>
        <a href="#/reports" class="nav-link">Reports</a>
        <hr>
        <a href="#/settings" class="nav-link">Settings</a>
        <a href="#/billing" class="nav-link">Billing</a>
        <a href="#/security" class="nav-link">Security</a>
        <hr>
        <a href="#/tickets" class="nav-link">Tickets</a>
        <a href="#/crm" class="nav-link">CRM</a>
        <a href="#/feature-packs" class="nav-link">Feature Packs</a>
      </nav>
      <div class="sidebar-footer">
        <span class="user-label">Admin</span>
        <a href="#/login" class="logout-link">Logout</a>
      </div>
    </aside>

    <!-- Main Content - EXACTLY as GitHub -->
    <main class="main-content">
      <div id="app">
        <router-view />
      </div>
    </main>
  </div>
</template>

<script>
export default {
  name: 'App'
}
</script>

<style>
* {
  margin: 0;
  padding: 0;
  box-sizing: border-box;
}

body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
  background: #f5f7fa;
  color: #111827;
}

.app-container {
  display: flex;
  min-height: 100vh;
}

.sidebar {
  width: 220px;
  background: #1a1a2e;
  color: #a0aec0;
  display: flex;
  flex-direction: column;
  min-height: 100vh;
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
  flex-shrink: 0;
}

.sidebar-brand {
  padding: 20px 16px;
  font-size: 18px;
  font-weight: 700;
  color: #ffffff;
  border-bottom: 1px solid #2d3748;
}

.sidebar-nav {
  flex: 1;
  padding: 12px 8px;
  overflow-y: auto;
}

.nav-link {
  display: block;
  padding: 8px 14px;
  color: #a0aec0;
  text-decoration: none;
  border-radius: 6px;
  font-size: 14px;
  margin-bottom: 2px;
}

.nav-link:hover {
  background: #2d3748;
  color: #ffffff;
}

.nav-link.active {
  background: #2d3748;
  color: #ffffff;
}

hr {
  border: none;
  border-top: 1px solid #2d3748;
  margin: 8px 0;
}

.sidebar-footer {
  padding: 16px;
  border-top: 1px solid #2d3748;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 14px;
}

.user-label {
  color: #a0aec0;
}

.logout-link {
  color: #fc8181;
  text-decoration: none;
}

.logout-link:hover {
  text-decoration: underline;
}

.main-content {
  flex: 1;
  padding: 0;
  background: #f5f7fa;
  min-height: 100vh;
}

.sidebar::-webkit-scrollbar {
  width: 4px;
}

.sidebar::-webkit-scrollbar-track {
  background: transparent;
}

.sidebar::-webkit-scrollbar-thumb {
  background: rgba(255,255,255,0.15);
  border-radius: 4px;
}

/* Original GitHub styles */
#app {
  min-height: 100vh;
  background: var(--bg);
  color: var(--text-primary);
}
</style>
