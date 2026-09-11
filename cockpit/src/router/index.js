import { createRouter, createWebHashHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth.js'

// ---------------------------------------------------------------------------
// Eagerly-loaded user views (existing)
// ---------------------------------------------------------------------------
import Dashboard    from '../views/Dashboard.vue'
import Agents       from '../views/Agents.vue'
import Flows        from '../views/Flows.vue'
import FlowBuilder  from '../views/FlowBuilder.vue'
import Memory       from '../views/Memory.vue'
import Usage        from '../views/Usage.vue'
import Settings     from '../views/Settings.vue'
import Login        from '../views/Login.vue'
import Atlas        from '../views/Atlas.vue'
import Approvals    from '../views/Approvals.vue'
import Traces       from '../views/Traces.vue'
import Intelligence from '../views/Intelligence.vue'

// Lazy user views
const AgentBuilder  = () => import('../views/AgentBuilder.vue')
const Billing       = () => import('../views/Billing.vue')
const Outcomes      = () => import('../views/Outcomes.vue')
const Communications = () => import('../views/Communications.vue')
const FeaturePacks  = () => import('../views/FeaturePacks.vue')
const Connectors    = () => import('../views/Connectors.vue')
const OpsFirstWin     = () => import('../views/operate/OpsFirstWin.vue')
const SalesHome       = () => import('../views/sales/SalesHome.vue')
const SalesLeads      = () => import('../views/sales/Leads.vue')
const FunnelSetup     = () => import('../views/sales/FunnelSetup.vue')
const ScriptStudio    = () => import('../views/sales/ScriptStudio.vue')
const SalesInbox      = () => import('../views/sales/Inbox.vue')
const Partners        = () => import('../views/sales/Partners.vue')
const PartnerDmQueue  = () => import('../views/sales/PartnerDmQueue.vue')
const PartnerSettings = () => import('../views/sales/PartnerSettings.vue')
const PartnerThread   = () => import('../views/sales/PartnerThread.vue')
const ComplianceHome  = () => import('../views/compliance/ComplianceHome.vue')
const Operating       = () => import('../views/operating/Operating.vue')

// Lazy financial views
const FinancialDashboard = () => import('../views/financial/FinancialDashboard.vue')
const FinancialLedger    = () => import('../views/financial/FinancialLedger.vue')
const Invoices           = () => import('../views/financial/Invoices.vue')
const Payments           = () => import('../views/financial/Payments.vue')
const Portfolios         = () => import('../views/financial/Portfolios.vue')
const ExpenseList        = () => import('../views/financial/expenses/ExpenseList.vue')
const ExpenseNew         = () => import('../views/financial/expenses/ExpenseNew.vue')
const ExpenseDetail      = () => import('../views/financial/expenses/ExpenseDetail.vue')
const BillsInbox         = () => import('../views/financial/ap/BillsInbox.vue')
const BillDetail         = () => import('../views/financial/ap/BillDetail.vue')
const Vendors            = () => import('../views/financial/ap/Vendors.vue')
const VendorDetail       = () => import('../views/financial/ap/VendorDetail.vue')
const AccountingSettings = () => import('../views/financial/accounting/AccountingSettings.vue')
const ExportCenter       = () => import('../views/financial/accounting/ExportCenter.vue')
const SpendAnalytics     = () => import('../views/financial/spend/SpendAnalytics.vue')

// Lazy shell / shared
const Forbidden     = () => import('../views/Forbidden.vue')
const NotFound      = () => import('../views/NotFound.vue')
const Onboarding    = () => import('../views/Onboarding.vue')
const SharedTrace   = () => import('../views/SharedTrace.vue')

// Lazy admin views
const AdminDashboard = () => import('../views/admin/AdminDashboard.vue')
const AdminUsers     = () => import('../views/admin/AdminUsers.vue')
const AdminAudit     = () => import('../views/admin/AdminAudit.vue')
const AdminCopy      = () => import('../views/admin/AdminCopy.vue')

// Lazy platform (super admin) views
const PlatformOverview      = () => import('../views/platform/PlatformOverview.vue')
const PlatformFeatureFlags  = () => import('../views/platform/PlatformFeatureFlags.vue')
const PlatformRolloutUsageV2 = () => import('../views/platform/PlatformRolloutUsageV2.vue')
const PlatformStateEngine   = () => import('../views/platform/PlatformStateEngine.vue')

// ---------------------------------------------------------------------------
// Routes
// ---------------------------------------------------------------------------

const routes = [
  // Auth / public
  { path: '/login',       name: 'Login',       component: Login,      meta: { guest: true } },
  { path: '/share/trace/:token', name: 'SharedTrace', component: SharedTrace, meta: { public: true } },
  { path: '/onboarding',  name: 'Onboarding',  component: Onboarding, meta: { requiresAuth: true } },
  { path: '/403',         name: 'Forbidden',   component: Forbidden },
  { path: '/404',         name: 'NotFound',    component: NotFound },

  // ---------------- USER SPACE ----------------
  { path: '/',              name: 'Dashboard',     component: Dashboard,   meta: { requiresAuth: true } },
  { path: '/atlas',         name: 'Atlas',         component: Atlas,       meta: { requiresAuth: true } },
  { path: '/agents',        name: 'Agents',        component: Agents,      meta: { requiresAuth: true } },
  { path: '/agents/new',    name: 'CreateAgent',   component: Agents,      meta: { requiresAuth: true, createMode: true } },
  { path: '/agents/builder',             name: 'AgentBuilder',         component: AgentBuilder, meta: { requiresAuth: true } },
  { path: '/agents/builder/:templateId', name: 'AgentBuilderTemplate', component: AgentBuilder, meta: { requiresAuth: true } },
  { path: '/flows',         name: 'Flows',         component: Flows,       meta: { requiresAuth: true } },
  { path: '/flows/new',     name: 'CreateFlow',    component: FlowBuilder, meta: { requiresAuth: true } },
  { path: '/flows/:id',     name: 'FlowBuilder',   component: FlowBuilder, meta: { requiresAuth: true } },
  { path: '/approvals',     name: 'Approvals',     component: Approvals,   meta: { requiresAuth: true } },
  { path: '/traces',        name: 'Traces',        component: Traces,      meta: { requiresAuth: true } },
  { path: '/intelligence',  name: 'Intelligence',  component: Intelligence,meta: { requiresAuth: true } },
  { path: '/outcomes',      name: 'Outcomes',      component: Outcomes,      meta: { requiresAuth: true } },
  { path: '/communications', name: 'Communications', component: Communications, meta: { requiresAuth: true } },
  { path: '/feature-packs', name: 'FeaturePacks',  component: FeaturePacks,  meta: { requiresAuth: true } },
  { path: '/connectors',    name: 'Connectors',    component: Connectors,    meta: { requiresAuth: true } },
  { path: '/operate/first-win', name: 'OpsFirstWin', component: OpsFirstWin, meta: { requiresAuth: true } },
  { path: '/sales', name: 'SalesHome', component: SalesHome, meta: { requiresAuth: true } },
  { path: '/sales/leads', name: 'SalesLeads', component: SalesLeads, meta: { requiresAuth: true } },
  { path: '/sales/funnel-setup', name: 'FunnelSetup', component: FunnelSetup, meta: { requiresAuth: true } },
  { path: '/sales/script-studio', name: 'ScriptStudio', component: ScriptStudio, meta: { requiresAuth: true } },
  { path: '/sales/inbox', name: 'SalesInbox', component: SalesInbox, meta: { requiresAuth: true } },
  { path: '/sales/partners', name: 'Partners', component: Partners, meta: { requiresAuth: true } },
  { path: '/sales/partners/dm-queue', name: 'PartnerDmQueue', component: PartnerDmQueue, meta: { requiresAuth: true } },
  { path: '/sales/partners/settings', name: 'PartnerSettings', component: PartnerSettings, meta: { requiresAuth: true } },
  { path: '/sales/partners/:id', name: 'PartnerThread', component: PartnerThread, meta: { requiresAuth: true } },
  { path: '/compliance', name: 'ComplianceHome', component: ComplianceHome, meta: { requiresAuth: true } },
  { path: '/operating', name: 'Operating', component: Operating, meta: { requiresAuth: true } },
  { path: '/memory',        name: 'Memory',        component: Memory,      meta: { requiresAuth: true } },
  { path: '/usage',         name: 'Usage',         component: Usage,       meta: { requiresAuth: true } },
  { path: '/settings',      name: 'Settings',      component: Settings,    meta: { requiresAuth: true } },
  { path: '/settings/usage',name: 'UsageSettings', component: Usage,       meta: { requiresAuth: true } },
  { path: '/settings/automation-level', name: 'AutomationLevel', component: () => import('../views/settings/AutomationLevel.vue'), meta: { requiresAuth: true, capability: 'tenant.manage' } },
  { path: '/billing',       name: 'Billing',       component: Billing,     meta: { requiresAuth: true } },

  // ---------------- FINANCIAL OS ----------------
  { path: '/financial',           name: 'FinancialDashboard', component: FinancialDashboard, meta: { requiresAuth: true, capability: 'finance.view' } },
  { path: '/financial/ledger',    name: 'FinancialLedger',    component: FinancialLedger,    meta: { requiresAuth: true, capability: 'finance.view' } },
  { path: '/financial/invoices',  name: 'Invoices',           component: Invoices,           meta: { requiresAuth: true, capability: 'finance.view' } },
  { path: '/financial/payments',  name: 'Payments',           component: Payments,           meta: { requiresAuth: true, capability: 'finance.view' } },
  { path: '/financial/portfolios',name: 'Portfolios',         component: Portfolios,         meta: { requiresAuth: true, capability: 'finance.view' } },
  { path: '/financial/expenses',      name: 'Expenses',      component: ExpenseList,   meta: { requiresAuth: true, capability: 'expenses.submit' } },
  // NOTE: /new must be declared before /:id so the literal segment wins.
  { path: '/financial/expenses/new',  name: 'ExpenseNew',    component: ExpenseNew,    meta: { requiresAuth: true, capability: 'expenses.submit' } },
  { path: '/financial/expenses/:id',  name: 'ExpenseDetail', component: ExpenseDetail, meta: { requiresAuth: true, capability: 'expenses.submit' } },
  { path: '/financial/bills',         name: 'BillsInbox',    component: BillsInbox,    meta: { requiresAuth: true, capability: 'ap.view' } },
  { path: '/financial/bills/:id',     name: 'BillDetail',    component: BillDetail,    meta: { requiresAuth: true, capability: 'ap.view' } },
  { path: '/financial/vendors',       name: 'Vendors',       component: Vendors,       meta: { requiresAuth: true, capability: 'ap.view' } },
  { path: '/financial/vendors/:id',   name: 'VendorDetail',  component: VendorDetail,  meta: { requiresAuth: true, capability: 'ap.view' } },
  { path: '/financial/accounting',        name: 'AccountingSettings', component: AccountingSettings, meta: { requiresAuth: true, capability: 'accounting.manage' } },
  { path: '/financial/accounting/export', name: 'ExportCenter',       component: ExportCenter,       meta: { requiresAuth: true, capability: 'accounting.manage' } },
  { path: '/financial/spend',             name: 'SpendAnalytics',     component: SpendAnalytics,     meta: { requiresAuth: true, capability: 'finance.view' } },

  // ---------------- ADMIN SPACE ----------------
  {
    path: '/admin',
    meta: { requiresAuth: true, roles: ['admin', 'super_admin'] },
    children: [
      { path: '',        name: 'AdminDashboard', component: AdminDashboard },
      { path: 'users',   name: 'AdminUsers',     component: AdminUsers,  meta: { capability: 'users.manage' } },
      { path: 'audit',   name: 'AdminAudit',     component: AdminAudit,  meta: { capability: 'audit.view' } },
      { path: 'copy',    name: 'AdminCopy',      component: AdminCopy,   meta: { capability: 'copy.manage' } },
      { path: 'budget',  name: 'AdminBudget',    component: Billing,     meta: { capability: 'budget.edit' } },
    ],
  },

  // ---------------- PLATFORM SPACE (super admin) ----------------
  {
    path: '/platform',
    meta: { requiresAuth: true, roles: ['super_admin'] },
    children: [
      { path: '',                   name: 'PlatformOverview',       component: PlatformOverview },
      { path: 'feature-flags',      name: 'PlatformFeatureFlags',   component: PlatformFeatureFlags, meta: { stepUp: true, capability: 'flag.write' } },
      { path: 'rollouts/usage-v2',  name: 'PlatformRolloutUsageV2', component: PlatformRolloutUsageV2, meta: { stepUp: true, capability: 'rollout.cutover' } },
      { path: 'ste',                name: 'PlatformStateEngine',   component: PlatformStateEngine,   meta: { capability: 'ste.view' } },
    ],
  },

  // ---------------- ENTERPRISE SPACE (signed AIOS, connectors, audit) ----------------
  {
    path: '/enterprise',
    meta: { requiresAuth: true },
    children: [
      { path: 'connectors', name: 'EnterpriseConnectors',
        component: () => import('../views/enterprise/EnterpriseConnectors.vue') },
      { path: 'aios',       name: 'EnterpriseAios',
        component: () => import('../views/enterprise/EnterpriseAiosDownloads.vue') },
      { path: 'trust',      name: 'EnterpriseTrust',
        component: () => import('../views/enterprise/EnterpriseTrust.vue') },
    ],
  },

  // Catch-all
  { path: '/:pathMatch(.*)*', redirect: '/404' },
]

const router = createRouter({
  history: createWebHashHistory(),
  routes,
})

// ---------------------------------------------------------------------------
// Guards
// ---------------------------------------------------------------------------

router.beforeEach((to, _from, next) => {
  const auth = useAuthStore()

  // Truly public routes (e.g. /share/trace/:token) bypass every gate.
  if (to.meta.public) return next()

  // Guest auth routes redirect to landing sign-in shell
  if (to.meta.guest) {
    if (auth.isAuthenticated) return next('/')
    let hashPath = '#/'
    if (typeof to.query.return_to === 'string' && to.query.return_to) {
      const raw = to.query.return_to
      hashPath = raw.startsWith('#') ? raw : `#${raw.startsWith('/') ? raw : `/${raw}`}`
    }
    window.location.assign(`/sign-in?return_to=${encodeURIComponent(`/cockpit/${hashPath}`)}`)
    return false
  }

  // Auth requirement — use landing sign-in UX (not in-cockpit Laravel-style login)
  if (to.meta.requiresAuth && !auth.isAuthenticated) {
    const returnTo = `/cockpit/#${to.fullPath}`
    window.location.assign(`/sign-in?return_to=${encodeURIComponent(returnTo)}`)
    return false
  }

  // Phase 1: Onboarding gate — redirect to onboarding if incomplete
  // Skip check for onboarding route itself and public routes
  if (auth.requiresOnboarding() && to.path !== '/onboarding' && !to.meta.guest) {
    return next('/onboarding')
  }

  // If onboarding is complete, don't allow returning to onboarding
  if (auth.isOnboardingComplete && to.path === '/onboarding') {
    return next('/atlas')
  }

  // Role check
  if (to.meta.roles && !to.meta.roles.includes(auth.role)) {
    return next({ path: '/403', query: { required_roles: to.meta.roles.join(',') } })
  }

  // Capability check
  if (to.meta.capability && !auth.has(to.meta.capability)) {
    return next({ path: '/403', query: { required_capability: to.meta.capability } })
  }

  // Step-up freshness for sensitive routes. The destination page's
  // StepUpGuard will trigger an interactive MFA prompt; here we just
  // surface the requirement so the UI can branch.
  return next()
})

export default router
