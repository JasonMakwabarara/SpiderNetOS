<template>
  <div class="billing p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <!-- Header -->
    <div>
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Billing &amp; Plans</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">
        Your SpiderNetOS platform plan and AI usage — not your business invoices or ledger.
      </p>
      <p class="text-xs mt-2" :style="{ color: 'var(--text-muted)' }">
        For invoicing, payments, and cash flow, enable
        <RouterLink to="/feature-packs" class="underline" style="color: var(--accent);">Financial OS</RouterLink>
        in Feature Packs.
      </p>
    </div>

    <!-- Return-from-checkout banner -->
    <div v-if="banner" class="dct-card p-4 flex items-center gap-3"
      :style="{ borderLeft: `3px solid ${banner.ok ? 'var(--charge-vivid)' : 'var(--dusk-vivid)'}` }">
      <span class="text-sm" :style="{ color: 'var(--text-primary)' }">{{ banner.text }}</span>
    </div>

    <!-- Step-up required notice -->
    <div v-if="stepUpNeeded" class="dct-card p-4"
      :style="{ borderLeft: '3px solid var(--dusk-vivid)' }">
      <p class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">Re-authentication required</p>
      <p class="text-xs mt-1" :style="{ color: 'var(--text-muted)' }">
        Changing your plan is a protected action. Please re-enter your password from the security prompt, then try again.
      </p>
    </div>

    <!-- ═══ Current Plan Card ═══ -->
    <div class="dct-card p-6">
      <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
        <div class="flex items-center gap-4">
          <div class="w-14 h-14 rounded-2xl flex items-center justify-center"
            :style="{ background: 'color-mix(in srgb, var(--charge-vivid) 15%, transparent)' }">
            <svg class="w-7 h-7" :style="{ color: 'var(--charge-vivid)' }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
          </div>
          <div>
            <p class="text-sm font-medium" :style="{ color: 'var(--text-muted)' }">Current Plan</p>
            <h2 class="text-xl font-bold flex items-center gap-2" :style="{ color: 'var(--text-primary)' }">
              {{ currentPlan?.name || 'No plan' }}
              <span v-if="currentPlan" class="text-sm font-semibold dct-pill-lime">
                {{ currentPlan.is_custom ? 'Custom' : money(currentPlan.monthly_fee_cents, currentPlan.currency) + '/mo' }}
              </span>
              <span v-if="subscription?.in_trial" class="text-xs dct-pill-cyan">Trial</span>
            </h2>
            <p v-if="subscription?.cancel_at_period_end" class="text-xs mt-1" :style="{ color: 'var(--dusk-vivid)' }">
              Cancels at period end{{ subscription.current_period_end ? ' · ' + fmtDate(subscription.current_period_end) : '' }}
            </p>
          </div>
        </div>
        <div class="flex-shrink-0 flex gap-2">
          <button @click="scrollToPlans" class="dct-btn-primary px-6 py-2.5">Change plan</button>
          <button v-if="subscription && !subscription.cancel_at_period_end" @click="onCancel"
            class="px-4 py-2.5 rounded-xl text-sm border"
            :style="{ borderColor: 'var(--border)', color: 'var(--text-secondary)', background: 'var(--surface-low)' }">
            Cancel
          </button>
        </div>
      </div>
    </div>

    <!-- ═══ Usage this month (allowance + overage) ═══ -->
    <div class="dct-card p-6 space-y-5">
      <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Usage this month</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="rounded-xl p-4" :style="{ background: 'var(--surface-low)' }">
          <p class="text-xs font-medium uppercase tracking-wider mb-1" :style="{ color: 'var(--text-muted)' }">Platform fee</p>
          <p class="text-xl font-bold" :style="{ color: 'var(--text-primary)' }">
            {{ allowance ? money(allowance.platform_fee_cents, cur) : '—' }}
          </p>
          <p class="mt-1 text-xs" :style="{ color: 'var(--text-muted)' }">per month</p>
        </div>
        <div class="rounded-xl p-4" :style="{ background: 'var(--surface-low)' }">
          <p class="text-xs font-medium uppercase tracking-wider mb-1" :style="{ color: 'var(--text-muted)' }">AI usage · included</p>
          <p class="text-xl font-bold" :style="{ color: 'var(--text-primary)' }">
            {{ allowance ? money(allowance.metered_usage_cents, cur) : '$' + usageStore.currentSpend.monthly.toFixed(2) }}
          </p>
          <div class="mt-2 h-1.5 rounded-full overflow-hidden" :style="{ background: 'var(--border)' }">
            <div class="h-full rounded-full transition-all" :style="{ width: allowancePct + '%', background: allowanceBarColor }" />
          </div>
          <p class="mt-1 text-xs" :style="{ color: 'var(--text-muted)' }">
            of {{ allowance ? money(allowance.included_usage_cents, cur) : '—' }} included
          </p>
        </div>
        <div class="rounded-xl p-4" :style="{ background: 'var(--surface-low)' }">
          <p class="text-xs font-medium uppercase tracking-wider mb-1" :style="{ color: 'var(--text-muted)' }">Projected overage</p>
          <p class="text-xl font-bold" :style="{ color: 'var(--text-primary)' }">
            {{ allowance ? money(allowance.projected_overage_cents, cur) : '—' }}
          </p>
          <p class="mt-1 text-xs" :style="{ color: 'var(--text-muted)' }">
            usage over allowance, +{{ allowance?.usage_margin_pct ?? 15 }}%
          </p>
        </div>
        <div class="rounded-xl p-4" :style="{ background: 'var(--surface-low)' }">
          <p class="text-xs font-medium uppercase tracking-wider mb-1" :style="{ color: 'var(--text-muted)' }">Projected total</p>
          <p class="text-xl font-bold dct-grad-text">
            {{ allowance ? money(allowance.platform_fee_cents + allowance.projected_overage_cents, cur) : '—' }}
          </p>
          <p class="mt-1 text-xs" :style="{ color: 'var(--text-muted)' }">this month, est.</p>
        </div>
      </div>
    </div>

    <!-- ═══ Plan Comparison ═══ -->
    <div ref="plansSection" class="space-y-4">
      <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Choose your plan</h2>
      <div v-if="billing.isLoading && !billing.plans.length" class="text-sm" :style="{ color: 'var(--text-muted)' }">
        Loading plans…
      </div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
        <div v-for="(plan, idx) in billing.plans" :key="plan.id"
          class="dct-card p-6 flex flex-col relative"
          :class="{ 'ring-2': plan.id === currentPlanId }"
          :style="plan.id === currentPlanId ? { '--tw-ring-color': 'var(--dusk-vivid)' } : {}">
          <div v-if="idx === 1" class="absolute -top-3 left-1/2 -translate-x-1/2">
            <span class="dct-pill-lime px-3 py-1 text-xs font-bold uppercase tracking-wider">Most Popular</span>
          </div>
          <div v-if="plan.id === currentPlanId" class="absolute -top-3 right-4">
            <span class="dct-pill-pink px-3 py-1 text-xs font-bold uppercase tracking-wider">Current</span>
          </div>

          <div class="mb-6">
            <h3 class="text-xl font-bold" :style="{ color: 'var(--text-primary)' }">{{ plan.name }}</h3>
            <div class="mt-2 flex items-baseline gap-1">
              <span class="text-3xl font-extrabold dct-grad-text">
                {{ plan.is_custom ? 'Custom' : money(plan.monthly_fee_cents, plan.currency) }}
              </span>
              <span v-if="!plan.is_custom" class="text-sm" :style="{ color: 'var(--text-muted)' }">/mo</span>
            </div>
            <p class="mt-2 text-sm" :style="{ color: 'var(--text-secondary)' }">{{ plan.tagline }}</p>
          </div>

          <ul class="space-y-3 mb-6 flex-1">
            <li v-for="(f, fi) in planFeatures(plan)" :key="fi" class="flex items-start gap-2">
              <svg class="w-5 h-5 flex-shrink-0 mt-0.5" :style="{ color: 'var(--charge-vivid)' }"
                fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
              </svg>
              <span class="text-sm" :style="{ color: 'var(--text-primary)' }">{{ f }}</span>
            </li>
          </ul>

          <div class="mt-auto">
            <button v-if="plan.id === currentPlanId" disabled
              class="w-full py-2.5 rounded-xl font-semibold text-sm border"
              :style="{ borderColor: 'var(--border-active)', color: 'var(--text-muted)', background: 'var(--surface-low)' }">
              Current Plan
            </button>
            <button v-else-if="plan.is_custom" @click="contactSales"
              class="w-full py-2.5 rounded-xl font-semibold text-sm border"
              :style="{ borderColor: 'var(--border)', color: 'var(--text-secondary)', background: 'var(--surface-low)' }">
              Contact sales
            </button>
            <button v-else @click="choosePlan(plan)" :disabled="subscribing === plan.id"
              class="dct-btn-primary w-full py-2.5 text-sm text-center">
              {{ subscribing === plan.id ? 'Starting…' : (currentPlanId ? 'Switch to ' + plan.name : 'Choose ' + plan.name) }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ Invoices ═══ -->
    <div class="dct-card p-6 space-y-4">
      <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Billing history</h2>
      <div class="overflow-x-auto">
        <table class="min-w-full">
          <thead>
            <tr :style="{ borderBottom: '1px solid var(--border)' }">
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Period</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Plan</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Fee</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Overage</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Total</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Status</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="!billing.invoices.length">
              <td colspan="6" class="px-4 py-8 text-center text-sm" :style="{ color: 'var(--text-muted)' }">
                No invoices yet. Your first invoice is generated at the end of the billing month.
              </td>
            </tr>
            <tr v-for="inv in billing.invoices" :key="inv.id" :style="{ borderBottom: '1px solid var(--border)' }">
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-primary)' }">{{ fmtDate(inv.period_start) }} – {{ fmtDate(inv.period_end) }}</td>
              <td class="px-4 py-3 text-sm capitalize" :style="{ color: 'var(--text-secondary)' }">{{ inv.plan_id }}</td>
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-secondary)' }">{{ money(inv.platform_fee_cents, inv.currency) }}</td>
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-secondary)' }">{{ money(inv.overage_cents, inv.currency) }}</td>
              <td class="px-4 py-3 text-sm font-semibold" :style="{ color: 'var(--text-primary)' }">{{ money(inv.total_cents, inv.currency) }}</td>
              <td class="px-4 py-3">
                <span :class="{ 'dct-pill-lime': inv.status === 'paid', 'dct-pill-pink': inv.status === 'void', 'dct-pill-cyan': inv.status === 'open' || inv.status === 'draft' }">
                  {{ inv.status }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- ═══ Purchased Feature Packs ═══ -->
    <div class="dct-card p-6 space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Purchased packs</h2>
        <RouterLink to="/feature-packs" class="text-sm underline" style="color: var(--accent);">Browse packs →</RouterLink>
      </div>
      <p v-if="!entitlements.length" class="text-sm" :style="{ color: 'var(--text-secondary)' }">No purchased packs yet.</p>
      <ul v-else class="divide-y" :style="{ borderColor: 'var(--border)' }">
        <li v-for="e in entitlements" :key="e.id" class="py-3 flex items-center justify-between">
          <div>
            <p class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">{{ e.pack_id }}</p>
            <p class="text-xs" :style="{ color: 'var(--text-muted)' }">
              {{ (e.amount_cents / 100).toFixed(2) }} {{ e.currency }} · {{ e.source }}
              <span v-if="e.purchased_at"> · {{ fmtDate(e.purchased_at) }}</span>
            </p>
          </div>
          <span class="text-xs px-2 py-1 rounded-full"
            :class="e.status === 'active' ? 'dct-pill-lime' : e.status === 'pending' ? 'dct-pill-cyan' : 'dct-pill-pink'">
            {{ e.status }}
          </span>
        </li>
      </ul>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useBillingStore } from '../stores/billing.js'
import { useUsageStore } from '../stores/usage.js'
import api from '../services/api.js'

const route = useRoute()
const billing = useBillingStore()
const usageStore = useUsageStore()

const plansSection = ref(null)
const entitlements = ref([])
const subscribing = ref(null)
const stepUpNeeded = ref(false)
const banner = ref(null)

const subscription = computed(() => billing.summary?.subscription || null)
const allowance = computed(() => billing.summary?.usage_allowance || null)
const cur = computed(() => allowance.value?.currency || 'USD')

const currentPlanId = computed(() => subscription.value?.plan_id || billing.summary?.plan?.id || null)
const currentPlan = computed(() => billing.plans.find(p => p.id === currentPlanId.value) || null)

const allowancePct = computed(() => {
  if (!allowance.value || !allowance.value.included_usage_cents) return 0
  return Math.min(100, (allowance.value.metered_usage_cents / allowance.value.included_usage_cents) * 100)
})
const allowanceBarColor = computed(() => {
  const p = allowancePct.value
  if (p >= 100) return 'var(--dusk-vivid)'
  if (p >= 80) return '#FFAA00'
  return 'var(--charge-vivid)'
})

function money(cents, currency = 'USD') {
  const v = (cents || 0) / 100
  const sym = currency === 'USD' ? '$' : (currency + ' ')
  return sym + (Number.isInteger(v) ? v.toFixed(0) : v.toFixed(2))
}
function fmtDate(d) {
  try { return new Date(d).toLocaleDateString() } catch { return d }
}
function ent(v, noun) {
  return v < 0 ? `Unlimited ${noun}` : `${v} ${noun}`
}
function planFeatures(plan) {
  const e = plan.entitlements || {}
  const out = [
    ent(e.agents ?? 0, 'active agents'),
    ent(e.flows ?? 0, 'flows'),
    ent(e.seats ?? 0, 'team seats'),
    (e.pack_slots < 0 ? 'All feature packs' : `${e.pack_slots ?? 0} feature pack${e.pack_slots === 1 ? '' : 's'} included`),
  ]
  if (!plan.is_custom) {
    out.push(`${money(plan.included_usage_cents, plan.currency)} AI usage included`)
    out.push(`then usage at cost + ${plan.usage_margin_pct}%`)
  } else {
    out.push('Pooled/custom usage', 'SSO & priority support')
  }
  return out
}

function scrollToPlans() {
  plansSection.value?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
function contactSales() {
  window.location.href = 'mailto:sales@apexsynchronia.com?subject=SpiderNetOS Enterprise plan'
}

async function choosePlan(plan) {
  subscribing.value = plan.id
  stepUpNeeded.value = false
  try {
    const res = await billing.subscribe(plan.id)
    if (res?.checkout_url) {
      window.location.href = res.checkout_url
      return
    }
    if (res?.changed) {
      banner.value = { ok: true, text: `Plan switched to ${plan.name}.` }
      await billing.fetchAll()
    }
  } catch (err) {
    if (err.response?.status === 428) {
      stepUpNeeded.value = true
    } else if (err.response?.status === 409) {
      banner.value = { ok: true, text: 'You are already on this plan.' }
    } else {
      banner.value = { ok: false, text: err.response?.data?.message || 'Could not start checkout.' }
    }
  } finally {
    subscribing.value = null
  }
}

async function onCancel() {
  if (!confirm('Cancel your subscription at the end of the current period?')) return
  try {
    await billing.cancel()
    banner.value = { ok: true, text: 'Subscription will cancel at period end.' }
    await billing.fetchAll()
  } catch (err) {
    if (err.response?.status === 428) stepUpNeeded.value = true
    else banner.value = { ok: false, text: err.response?.data?.message || 'Could not cancel.' }
  }
}

onMounted(async () => {
  if (route.query.subscribe === 'success') banner.value = { ok: true, text: 'Subscription confirmed — welcome aboard.' }
  else if (route.query.subscribe === 'cancelled') banner.value = { ok: false, text: 'Checkout cancelled — no changes made.' }

  usageStore.fetchBudget()
  usageStore.fetchCurrentSpend()
  await billing.fetchAll()

  try {
    const { data } = await api.get('/api/feature-packs/entitlements')
    entitlements.value = data?.data || []
  } catch { /* leave empty */ }
})
</script>
