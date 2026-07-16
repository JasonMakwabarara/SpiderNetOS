import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'

// Views
import Dashboard from '../views/Dashboard.vue'
import Agents from '../views/Agents.vue'
import Flows from '../views/Flows.vue'
import FlowBuilder from '../views/FlowBuilder.vue'
import Memory from '../views/Memory.vue'
import Usage from '../views/Usage.vue'
import Settings from '../views/Settings.vue'
import Login from '../views/Login.vue'
import Atlas from '../views/Atlas.vue'
import Approvals from '../views/Approvals.vue'
import Traces from '../views/Traces.vue'
import Intelligence from '../views/Intelligence.vue'
import Analytics from '../views/Analytics.vue'
import Reports from '../views/Reports.vue'
import Security from '../views/Security.vue'
import Tickets from '../views/Tickets.vue'
import CRM from '../views/CRM.vue'
import Billing from '../views/Billing.vue'
import Onboarding from '../views/Onboarding.vue'
import Forbidden from '../views/Forbidden.vue'
import NotFound from '../views/NotFound.vue'
import AgentChat from '../views/AgentChat.vue'
import EditAgent from '../views/EditAgent.vue'
import EditFlow from '../views/EditFlow.vue'

const routes = [
  { path: '/', redirect: '/login' },
  { path: '/login', component: Login, meta: { guest: true } },
  { path: '/sign-in', redirect: '/login' },
  { path: '/dashboard', component: Dashboard, meta: { requiresAuth: true } },
  { path: '/atlas', component: Atlas, meta: { requiresAuth: true } },
  { path: '/agents', component: Agents, meta: { requiresAuth: true } },
  { path: '/agents/:id/chat', component: AgentChat, meta: { requiresAuth: true } },
  { path: '/agents/:id/edit', component: EditAgent, meta: { requiresAuth: true } },
  { path: '/flows', component: Flows, meta: { requiresAuth: true } },
  { path: '/flows/new', component: FlowBuilder, meta: { requiresAuth: true } },
  { path: '/flows/:id/edit', component: EditFlow, meta: { requiresAuth: true } },
  { path: '/memory', component: Memory, meta: { requiresAuth: true } },
  { path: '/usage', component: Usage, meta: { requiresAuth: true } },
  { path: '/settings', component: Settings, meta: { requiresAuth: true } },
  { path: '/approvals', component: Approvals, meta: { requiresAuth: true } },
  { path: '/traces', component: Traces, meta: { requiresAuth: true } },
  { path: '/intelligence', component: Intelligence, meta: { requiresAuth: true } },
  { path: '/analytics', component: Analytics, meta: { requiresAuth: true } },
  { path: '/reports', component: Reports, meta: { requiresAuth: true } },
  { path: '/security', component: Security, meta: { requiresAuth: true } },
  { path: '/tickets', component: Tickets, meta: { requiresAuth: true } },
  { path: '/crm', component: CRM, meta: { requiresAuth: true } },
  { path: '/billing', component: Billing, meta: { requiresAuth: true } },
  { path: '/onboarding', component: Onboarding, meta: { guest: true } },
  { path: '/403', component: Forbidden, meta: { requiresAuth: true } },
  { path: '/:pathMatch(.*)*', component: NotFound },
]

const router = createRouter({
  history: createWebHashHistory('/cockpit/'),
  routes,
})

router.beforeEach((to, from, next) => {
  const auth = useAuthStore()
  
  if (to.meta.guest) {
    if (auth.isAuthenticated) return next('/dashboard')
    return next()
  }
  
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return next('/login')
  }
  
  next()
})

export default router
