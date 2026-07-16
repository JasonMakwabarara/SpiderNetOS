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

// New Features
import Analytics from '../views/Analytics.vue'
import Orchestration from '../views/Orchestration.vue'
import Reports from '../views/Reports.vue'
import Security from '../views/Security.vue'

// Lazy views
const AgentBuilder = () => import('../views/AgentBuilder.vue')
const Billing = () => import('../views/Billing.vue')
const Outcomes = () => import('../views/Outcomes.vue')
const Communications = () => import('../views/Communications.vue')
const FeaturePacks = () => import('../views/FeaturePacks.vue')
const OpsFirstWin = () => import('../views/operate/OpsFirstWin.vue')
const SalesHome = () => import('../views/sales/SalesHome.vue')
const ComplianceHome = () => import('../views/compliance/ComplianceHome.vue')
const FinancialDashboard = () => import('../views/financial/FinancialDashboard.vue')
const FinancialLedger = () => import('../views/financial/FinancialLedger.vue')
const Invoices = () => import('../views/financial/Invoices.vue')
const Payments = () => import('../views/financial/Payments.vue')
const Portfolios = () => import('../views/financial/Portfolios.vue')
const Forbidden = () => import('../views/Forbidden.vue')
const NotFound = () => import('../views/NotFound.vue')
const Onboarding = () => import('../views/Onboarding.vue')
const SharedTrace = () => import('../views/SharedTrace.vue')
const AdminDashboard = () => import('../views/admin/AdminDashboard.vue')
const AdminUsers = () => import('../views/admin/AdminUsers.vue')
const AdminAudit = () => import('../views/admin/AdminAudit.vue')
const AdminCopy = () => import('../views/admin/AdminCopy.vue')
const PlatformOverview = () => import('../views/platform/PlatformOverview.vue')
const PlatformFeatureFlags = () => import('../views/platform/PlatformFeatureFlags.vue')
const PlatformRolloutUsageV2 = () => import('../views/platform/PlatformRolloutUsageV2.vue')
const PlatformStateEngine = () => import('../views/platform/PlatformStateEngine.vue')

const routes = [
  // Auth
  { path: '/login', name: 'Login', component: Login, meta: { guest: true } },
  { path: '/share/trace/:token', name: 'SharedTrace', component: SharedTrace, meta: { public: true } },
  { path: '/onboarding', name: 'Onboarding', component: Onboarding, meta: { requiresAuth: true } },
  { path: '/403', name: 'Forbidden', component: Forbidden },
  { path: '/404', name: 'NotFound', component: NotFound },

  // User Space
  { path: '/', redirect: '/dashboard' },
  { path: '/dashboard', name: 'Dashboard', component: Dashboard, meta: { requiresAuth: true } },
  { path: '/atlas', name: 'Atlas', component: Atlas, meta: { requiresAuth: true } },
  { path: '/agents', name: 'Agents', component: Agents, meta: { requiresAuth: true } },
  { path: '/agents/new', name: 'CreateAgent', component: Agents, meta: { requiresAuth: true } },
  { path: '/agents/builder', name: 'AgentBuilder', component: AgentBuilder, meta: { requiresAuth: true } },
  { path: '/agents/builder/:templateId', name: 'AgentBuilderTemplate', component: AgentBuilder, meta: { requiresAuth: true } },
  { path: '/flows', name: 'Flows', component: Flows, meta: { requiresAuth: true } },
  { path: '/flows/new', name: 'CreateFlow', component: FlowBuilder, meta: { requiresAuth: true } },
  { path: '/flows/:id', name: 'FlowBuilder', component: FlowBuilder, meta: { requiresAuth: true } },
  { path: '/approvals', name: 'Approvals', component: Approvals, meta: { requiresAuth: true } },
  { path: '/traces', name: 'Traces', component: Traces, meta: { requiresAuth: true } },
  { path: '/intelligence', name: 'Intelligence', component: Intelligence, meta: { requiresAuth: true } },
  { path: '/outcomes', name: 'Outcomes', component: Outcomes, meta: { requiresAuth: true } },
  { path: '/communications', name: 'Communications', component: Communications, meta: { requiresAuth: true } },
  { path: '/feature-packs', name: 'FeaturePacks', component: FeaturePacks, meta: { requiresAuth: true } },
  { path: '/operate/first-win', name: 'OpsFirstWin', component: OpsFirstWin, meta: { requiresAuth: true } },
  { path: '/sales', name: 'SalesHome', component: SalesHome, meta: { requiresAuth: true } },
  { path: '/compliance', name: 'ComplianceHome', component: ComplianceHome, meta: { requiresAuth: true } },
  { path: '/memory', name: 'Memory', component: Memory, meta: { requiresAuth: true } },
  { path: '/usage', name: 'Usage', component: Usage, meta: { requiresAuth: true } },
  { path: '/settings', name: 'Settings', component: Settings, meta: { requiresAuth: true } },
  { path: '/settings/usage', name: 'UsageSettings', component: Usage, meta: { requiresAuth: true } },
  { path: '/settings/automation-level', name: 'AutomationLevel', component: () => import('../views/settings/AutomationLevel.vue'), meta: { requiresAuth: true } },
  { path: '/billing', name: 'Billing', component: Billing, meta: { requiresAuth: true } },

  // NEW FEATURES - ADDED HERE
  { path: '/analytics', name: 'Analytics', component: Analytics, meta: { requiresAuth: true } },
  { path: '/orchestration', name: 'Orchestration', component: Orchestration, meta: { requiresAuth: true } },
  { path: '/reports', name: 'Reports', component: Reports, meta: { requiresAuth: true } },
  { path: '/security', name: 'Security', component: Security, meta: { requiresAuth: true } },

  // Financial
  { path: '/financial', name: 'FinancialDashboard', component: FinancialDashboard, meta: { requiresAuth: true } },
  { path: '/financial/ledger', name: 'FinancialLedger', component: FinancialLedger, meta: { requiresAuth: true } },
  { path: '/financial/invoices', name: 'Invoices', component: Invoices, meta: { requiresAuth: true } },
  { path: '/financial/payments', name: 'Payments', component: Payments, meta: { requiresAuth: true } },
  { path: '/financial/portfolios', name: 'Portfolios', component: Portfolios, meta: { requiresAuth: true } },

  // Admin
  {
    path: '/admin',
    meta: { requiresAuth: true, roles: ['admin', 'super_admin'] },
    children: [
      { path: '', name: 'AdminDashboard', component: AdminDashboard },
      { path: 'users', name: 'AdminUsers', component: AdminUsers },
      { path: 'audit', name: 'AdminAudit', component: AdminAudit },
      { path: 'copy', name: 'AdminCopy', component: AdminCopy },
      { path: 'budget', name: 'AdminBudget', component: Billing },
    ],
  },

  // Platform
  {
    path: '/platform',
    meta: { requiresAuth: true, roles: ['super_admin'] },
    children: [
      { path: '', name: 'PlatformOverview', component: PlatformOverview },
      { path: 'feature-flags', name: 'PlatformFeatureFlags', component: PlatformFeatureFlags },
      { path: 'rollouts/usage-v2', name: 'PlatformRolloutUsageV2', component: PlatformRolloutUsageV2 },
      { path: 'ste', name: 'PlatformStateEngine', component: PlatformStateEngine },
    ],
  },

  // Enterprise
  {
    path: '/enterprise',
    meta: { requiresAuth: true },
    children: [
      { path: 'connectors', name: 'EnterpriseConnectors', component: () => import('../views/enterprise/EnterpriseConnectors.vue') },
      { path: 'aios', name: 'EnterpriseAios', component: () => import('../views/enterprise/EnterpriseAiosDownloads.vue') },
      { path: 'trust', name: 'EnterpriseTrust', component: () => import('../views/enterprise/EnterpriseTrust.vue') },
    ],
  },

  // Catch-all
  { path: '/:pathMatch(.*)*', redirect: '/404' },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

// SIMPLE GUARD - NO WINDOW.LOCATION
// ---------------------------------------------------------------------------
// Guards
// ---------------------------------------------------------------------------

router.beforeEach((to, _from, next) => {
  const auth = useAuthStore()

  // Public routes - always allow
  if (to.meta.public) {
    return next()
  }

  // Guest routes (login)
  if (to.meta.guest) {
    if (auth.isAuthenticated) {
      return next('/dashboard')
    }
    return next()
  }

  // Protected routes - require authentication
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    return next('/login')
  }

  return next()
})

export default router
