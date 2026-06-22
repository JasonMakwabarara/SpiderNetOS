<template>
  <div class="billing p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <!-- Header -->
    <div>
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Billing & Plans</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">
        Manage your subscription, view usage, and configure payment
      </p>
    </div>

    <!-- ═══ Current Plan Card ═══ -->
    <div class="dct-card p-6">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex items-center gap-4">
          <div
            class="w-14 h-14 rounded-2xl flex items-center justify-center"
            :style="{ background: currentPlanAccent }"
          >
            <svg class="w-7 h-7" :style="{ color: currentPlanIcon }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
          </div>
          <div>
            <p class="text-sm font-medium" :style="{ color: 'var(--text-muted)' }">Current Plan</p>
            <h2 class="text-xl font-bold" :style="{ color: 'var(--text-primary)' }">
              {{ currentPlan.name }}
              <span
                class="ml-2 text-sm font-semibold"
                :class="currentPlan.id === 'starter' ? 'dct-pill-cyan' : currentPlan.id === 'growth' ? 'dct-pill-lime' : 'dct-pill-pink'"
              >
                {{ currentPlan.id === 'starter' ? 'Free' : currentPlan.price }}
              </span>
            </h2>
          </div>
        </div>
        <div v-if="currentPlan.id !== 'enterprise'" class="flex-shrink-0">
          <button @click="scrollToPlans" class="dct-btn-primary px-6 py-2.5">
            Upgrade Plan
          </button>
        </div>
      </div>

      <!-- Current plan features -->
      <div class="mt-6 grid grid-cols-1 md:grid-cols-3 gap-4">
        <div
          v-for="feature in currentPlan.highlights"
          :key="feature.label"
          class="flex items-center gap-3 px-4 py-3 rounded-xl"
          :style="{ background: 'var(--surface-low)' }"
        >
          <svg class="w-5 h-5 flex-shrink-0" :style="{ color: 'var(--charge-vivid)' }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
          </svg>
          <span class="text-sm" :style="{ color: 'var(--text-primary)' }">{{ feature.label }}</span>
        </div>
      </div>
    </div>

    <!-- ═══ Usage Overview ═══ -->
    <div class="dct-card p-6 space-y-5">
      <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Usage Overview</h2>

      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- Daily Spend -->
        <div class="rounded-xl p-4" :style="{ background: 'var(--surface-low)' }">
          <p class="text-xs font-medium uppercase tracking-wider mb-1" :style="{ color: 'var(--text-muted)' }">Daily Spend</p>
          <p class="text-xl font-bold" :style="{ color: 'var(--text-primary)' }">
            ${{ usageStore.currentSpend.daily.toFixed(2) }}
          </p>
          <div class="mt-2 h-1.5 rounded-full overflow-hidden" :style="{ background: 'var(--border)' }">
            <div
              class="h-full rounded-full transition-all"
              :style="{
                width: `${Math.min(usageStore.dailyPercentUsed, 100)}%`,
                background: dailyBarColor
              }"
            />
          </div>
          <p class="mt-1 text-xs" :style="{ color: 'var(--text-muted)' }">
            of ${{ usageStore.budget?.daily_limit?.toFixed(2) || '10.00' }} limit
          </p>
        </div>

        <!-- Monthly Spend -->
        <div class="rounded-xl p-4" :style="{ background: 'var(--surface-low)' }">
          <p class="text-xs font-medium uppercase tracking-wider mb-1" :style="{ color: 'var(--text-muted)' }">Monthly Spend</p>
          <p class="text-xl font-bold" :style="{ color: 'var(--text-primary)' }">
            ${{ usageStore.currentSpend.monthly.toFixed(2) }}
          </p>
          <div class="mt-2 h-1.5 rounded-full overflow-hidden" :style="{ background: 'var(--border)' }">
            <div
              class="h-full rounded-full transition-all"
              :style="{
                width: `${Math.min(usageStore.monthlyPercentUsed, 100)}%`,
                background: monthlyBarColor
              }"
            />
          </div>
          <p class="mt-1 text-xs" :style="{ color: 'var(--text-muted)' }">
            of ${{ usageStore.budget?.monthly_limit?.toFixed(2) || '100.00' }} limit
          </p>
        </div>

        <!-- Atlas inference (background) -->
        <div class="rounded-xl p-4" :style="{ background: 'var(--surface-low)' }">
          <p class="text-xs font-medium uppercase tracking-wider mb-1" :style="{ color: 'var(--text-muted)' }">Atlas inference (today)</p>
          <p class="text-xl font-bold" :style="{ color: 'var(--text-primary)' }">
            ${{ atlasInferenceDaily.toFixed(4) }}
          </p>
          <p class="mt-1 text-xs" :style="{ color: 'var(--text-muted)' }">
            ${{ atlasInferenceMonthly.toFixed(4) }} this month
          </p>
        </div>

        <!-- Agents Active -->
        <div class="rounded-xl p-4" :style="{ background: 'var(--surface-low)' }">
          <p class="text-xs font-medium uppercase tracking-wider mb-1" :style="{ color: 'var(--text-muted)' }">Active Agents</p>
          <p class="text-xl font-bold" :style="{ color: 'var(--text-primary)' }">
            {{ agentsStore.activeAgents.length }}
          </p>
          <p class="mt-1 text-xs" :style="{ color: 'var(--text-muted)' }">
            of {{ currentPlan.limits.agents }} allowed
          </p>
        </div>

        <!-- Flows -->
        <div class="rounded-xl p-4" :style="{ background: 'var(--surface-low)' }">
          <p class="text-xs font-medium uppercase tracking-wider mb-1" :style="{ color: 'var(--text-muted)' }">Budget Status</p>
          <p class="text-xl font-bold" :style="{ color: 'var(--text-primary)' }">
            {{ usageStore.isNearLimit ? 'Near Limit' : usageStore.isDegraded ? 'Degraded' : 'Healthy' }}
          </p>
          <p class="mt-1 text-xs" :style="{ color: 'var(--text-muted)' }">
            {{ usageStore.budget?.action_at_limit || 'degrade' }} at limit
          </p>
        </div>
      </div>
    </div>

    <!-- ═══ Plan Comparison ═══ -->
    <div ref="plansSection" class="space-y-4">
      <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Choose Your Plan</h2>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div
          v-for="plan in plans"
          :key="plan.id"
          class="dct-card p-6 flex flex-col relative"
          :class="{ 'ring-2': plan.id === activePlanId }"
          :style="plan.id === activePlanId ? { '--tw-ring-color': 'var(--dusk-vivid)' } : {}"
        >
          <!-- Popular badge -->
          <div
            v-if="plan.popular"
            class="absolute -top-3 left-1/2 -translate-x-1/2"
          >
            <span class="dct-pill-lime px-3 py-1 text-xs font-bold uppercase tracking-wider">
              Most Popular
            </span>
          </div>

          <!-- Current badge -->
          <div
            v-if="plan.id === activePlanId"
            class="absolute -top-3 right-4"
          >
            <span class="dct-pill-pink px-3 py-1 text-xs font-bold uppercase tracking-wider">
              Current
            </span>
          </div>

          <!-- Plan Header -->
          <div class="mb-6">
            <h3 class="text-xl font-bold" :style="{ color: 'var(--text-primary)' }">{{ plan.name }}</h3>
            <div class="mt-2 flex items-baseline gap-1">
              <span class="text-3xl font-extrabold dct-grad-text">{{ plan.price }}</span>
              <span v-if="plan.period" class="text-sm" :style="{ color: 'var(--text-muted)' }">{{ plan.period }}</span>
            </div>
            <p class="mt-2 text-sm" :style="{ color: 'var(--text-secondary)' }">{{ plan.tagline }}</p>
          </div>

          <!-- Features -->
          <ul class="space-y-3 mb-6 flex-1">
            <li
              v-for="(feature, fi) in plan.features"
              :key="fi"
              class="flex items-start gap-2"
            >
              <svg
                class="w-5 h-5 flex-shrink-0 mt-0.5"
                :style="{ color: feature.included ? 'var(--charge-vivid)' : 'var(--text-muted)' }"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path
                  v-if="feature.included"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M5 13l4 4L19 7"
                />
                <path
                  v-else
                  stroke-linecap="round"
                  stroke-linejoin="round"
                  stroke-width="2"
                  d="M6 18L18 6M6 6l12 12"
                />
              </svg>
              <span
                class="text-sm"
                :style="{ color: feature.included ? 'var(--text-primary)' : 'var(--text-muted)' }"
              >
                {{ feature.text }}
              </span>
            </li>
          </ul>

          <!-- CTA Button -->
          <div class="mt-auto">
            <button
              v-if="plan.id === activePlanId"
              disabled
              class="w-full py-2.5 rounded-xl font-semibold text-sm border"
              :style="{
                borderColor: 'var(--border-active)',
                color: 'var(--text-muted)',
                background: 'var(--surface-low)'
              }"
            >
              Current Plan
            </button>
            <button
              v-else-if="getPlanRank(plan.id) > getPlanRank(activePlanId)"
              @click="handleUpgrade(plan)"
              class="dct-btn-primary w-full py-2.5 text-sm text-center"
            >
              Upgrade to {{ plan.name }}
            </button>
            <button
              v-else
              @click="handleDowngrade(plan)"
              class="w-full py-2.5 rounded-xl font-semibold text-sm border transition-colors"
              :style="{
                borderColor: 'var(--border)',
                color: 'var(--text-secondary)',
                background: 'var(--surface-low)'
              }"
            >
              Downgrade
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ Payment Section ═══ -->
    <div class="dct-card p-6 space-y-4">
      <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Payment Method</h2>

      <div
        class="flex flex-col items-center justify-center py-12 rounded-xl border-2 border-dashed"
        :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)' }"
      >
        <svg class="w-16 h-16 mb-4" :style="{ color: 'var(--text-muted)' }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" />
        </svg>
        <h3 class="text-lg font-semibold mb-1" :style="{ color: 'var(--text-primary)' }">
          Coming Soon
        </h3>
        <p class="text-sm text-center max-w-md" :style="{ color: 'var(--text-muted)' }">
          Payment integration with <strong>Flutterwave</strong> and <strong>PayPal</strong> is coming in Sprint 9.
          You'll be able to manage cards, view invoices, and set up auto-billing.
        </p>

        <div class="flex items-center gap-4 mt-6">
          <div
            class="flex items-center gap-2 px-4 py-2 rounded-xl"
            :style="{ background: 'color-mix(in srgb, var(--charge-vivid) 10%, transparent)' }"
          >
            <svg class="w-5 h-5" :style="{ color: 'var(--charge-vivid)' }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            <span class="text-sm font-semibold" :style="{ color: 'var(--charge-dark)' }">Flutterwave</span>
          </div>
          <div
            class="flex items-center gap-2 px-4 py-2 rounded-xl"
            :style="{ background: 'color-mix(in srgb, var(--tealime-vivid) 10%, transparent)' }"
          >
            <svg class="w-5 h-5" :style="{ color: 'var(--tealime-vivid)' }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span class="text-sm font-semibold" :style="{ color: 'var(--tealime-dark)' }">PayPal</span>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ Billing History (Placeholder) ═══ -->
    <div class="dct-card p-6 space-y-4">
      <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Billing History</h2>

      <div class="overflow-x-auto">
        <table class="min-w-full">
          <thead>
            <tr :style="{ borderBottom: '1px solid var(--border)' }">
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Date</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Description</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Amount</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="billingHistory.length === 0">
              <td colspan="4" class="px-4 py-8 text-center text-sm" :style="{ color: 'var(--text-muted)' }">
                No billing history yet. Invoices will appear here once payment is configured.
              </td>
            </tr>
            <tr
              v-for="record in billingHistory"
              :key="record.id"
              :style="{ borderBottom: '1px solid var(--border)' }"
            >
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-primary)' }">{{ record.date }}</td>
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-secondary)' }">{{ record.description }}</td>
              <td class="px-4 py-3 text-sm font-semibold" :style="{ color: 'var(--text-primary)' }">{{ record.amount }}</td>
              <td class="px-4 py-3">
                <span
                  :class="{
                    'dct-pill-lime': record.status === 'paid',
                    'dct-pill-pink': record.status === 'failed',
                    'dct-pill-cyan': record.status === 'pending'
                  }"
                >
                  {{ record.status }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useUsageStore } from '../stores/usage.js'
import { useAgentsStore } from '../stores/agents.js'
import api from '../services/api.js'

const usageStore = useUsageStore()
const agentsStore = useAgentsStore()

// ─── Plan definitions ───────────────────────────────────────
const plans = [
  {
    id: 'starter',
    name: 'Starter',
    price: 'Free',
    period: '',
    tagline: 'For individuals exploring AI agents and automation.',
    popular: false,
    limits: { agents: 3, flows: 5, dailyBudget: 1 },
    features: [
      { text: 'Up to 3 active agents', included: true },
      { text: '5 published flows', included: true },
      { text: '$1/day usage budget', included: true },
      { text: 'Community support', included: true },
      { text: 'Basic usage analytics', included: true },
      { text: 'Custom model endpoints', included: false },
      { text: 'Team collaboration', included: false },
      { text: 'Priority support', included: false }
    ],
    highlights: [
      { label: '3 agents included' },
      { label: '5 flows max' },
      { label: 'Community support' }
    ]
  },
  {
    id: 'growth',
    name: 'Growth',
    price: '$29',
    period: '/mo',
    tagline: 'For teams building production agent workflows.',
    popular: true,
    limits: { agents: 25, flows: 50, dailyBudget: 10 },
    features: [
      { text: 'Up to 25 active agents', included: true },
      { text: '50 published flows', included: true },
      { text: '$10/day usage budget', included: true },
      { text: 'Email support (24h SLA)', included: true },
      { text: 'Advanced analytics & traces', included: true },
      { text: 'Custom model endpoints', included: true },
      { text: 'Team collaboration (5 seats)', included: true },
      { text: 'Priority support', included: false }
    ],
    highlights: [
      { label: '25 agents included' },
      { label: '50 flows, advanced traces' },
      { label: '5 team seats' }
    ]
  },
  {
    id: 'enterprise',
    name: 'Enterprise',
    price: '$99',
    period: '/mo',
    tagline: 'For organizations running mission-critical agent fleets.',
    popular: false,
    limits: { agents: 999, flows: 999, dailyBudget: 100 },
    features: [
      { text: 'Unlimited agents', included: true },
      { text: 'Unlimited flows', included: true },
      { text: '$100/day usage budget (configurable)', included: true },
      { text: 'Priority support (4h SLA)', included: true },
      { text: 'Full observability & intelligence', included: true },
      { text: 'Custom model endpoints', included: true },
      { text: 'Unlimited team seats', included: true },
      { text: 'SSO & audit logs', included: true }
    ],
    highlights: [
      { label: 'Unlimited agents & flows' },
      { label: 'SSO & audit logs' },
      { label: 'Priority support (4h SLA)' }
    ]
  }
]

// ─── State ──────────────────────────────────────────────────
const activePlanId = ref('starter')
const plansSection = ref(null)
const billingHistory = ref([])
const atlasInferenceDaily = ref(0)
const atlasInferenceMonthly = ref(0)

// ─── Computed ───────────────────────────────────────────────
const currentPlan = computed(() => {
  return plans.find(p => p.id === activePlanId.value) || plans[0]
})

const currentPlanAccent = computed(() => {
  const accents = {
    starter: 'color-mix(in srgb, var(--tealime-vivid) 15%, transparent)',
    growth: 'color-mix(in srgb, var(--charge-vivid) 15%, transparent)',
    enterprise: 'color-mix(in srgb, var(--dusk-vivid) 15%, transparent)'
  }
  return accents[activePlanId.value] || accents.starter
})

const currentPlanIcon = computed(() => {
  const icons = {
    starter: 'var(--tealime-vivid)',
    growth: 'var(--charge-vivid)',
    enterprise: 'var(--dusk-vivid)'
  }
  return icons[activePlanId.value] || icons.starter
})

const dailyBarColor = computed(() => {
  const pct = usageStore.dailyPercentUsed
  if (pct >= 90) return 'var(--dusk-vivid)'
  if (pct >= 70) return '#FFAA00'
  return 'var(--charge-vivid)'
})

const monthlyBarColor = computed(() => {
  const pct = usageStore.monthlyPercentUsed
  if (pct >= 90) return 'var(--dusk-vivid)'
  if (pct >= 70) return '#FFAA00'
  return 'var(--charge-vivid)'
})

// ─── Lifecycle ──────────────────────────────────────────────
onMounted(async () => {
  usageStore.fetchBudget()
  usageStore.fetchCurrentSpend()
  agentsStore.fetchAgents()

  try {
    const { data } = await api.get('/api/billing/summary')
    const plan = data.data?.plan?.id
    if (plan) activePlanId.value = plan === 'free' ? 'starter' : plan
    atlasInferenceDaily.value = data.data?.spend?.atlas_inference_daily_usd ?? 0
    atlasInferenceMonthly.value = data.data?.spend?.atlas_inference_monthly_usd ?? 0
  } catch { /* usage store fallback */ }

  if (usageStore.budget?.plan) {
    activePlanId.value = usageStore.budget.plan
  }
})

// ─── Methods ────────────────────────────────────────────────
function getPlanRank(planId) {
  const ranks = { starter: 0, growth: 1, enterprise: 2 }
  return ranks[planId] ?? 0
}

function scrollToPlans() {
  plansSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

function handleUpgrade(plan) {
  // Placeholder — will integrate with Flutterwave/PayPal in Sprint 9
  alert(`Upgrade to ${plan.name} (${plan.price}${plan.period}) — Payment integration coming soon!`)
}

function handleDowngrade(plan) {
  if (confirm(`Are you sure you want to downgrade to ${plan.name}? This may reduce your agent and flow limits.`)) {
    // Placeholder
    alert(`Downgrade to ${plan.name} requested. This will take effect at the end of your current billing cycle.`)
  }
}
</script>
