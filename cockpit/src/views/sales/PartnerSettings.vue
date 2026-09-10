<template>
  <div class="px-6 py-7 max-w-4xl mx-auto">
    <div class="mb-6 flex items-start justify-between gap-4">
      <div>
        <div class="sn-eyebrow">Operate · Sales · Partners</div>
        <h1 class="text-2xl font-semibold mt-1" style="color: var(--text-primary);">Outreach settings</h1>
        <p class="text-sm mt-1" style="color: var(--text-secondary);">
          The program facts every template (and later the recruiter bot) may quote, plus the sending limits.
        </p>
      </div>
      <RouterLink to="/sales/partners" class="px-4 py-2 rounded-lg text-sm border" style="border-color: var(--border); color: var(--text-secondary);">Back</RouterLink>
    </div>

    <div class="sn-card p-4 mb-4 text-sm" style="color: var(--text-secondary);">
      <p>Mailbox: <strong :style="{ color: store.settingsMeta.mailbox_connected ? '#3ddc97' : '#FF6B6B' }">{{ store.settingsMeta.mailbox_connected ? 'connected' : 'not connected' }}</strong>
        · Sending flag: <strong>{{ store.settingsMeta.flags?.sending ? 'on' : 'off' }}</strong></p>
      <p class="mt-1">Connect “Affonso” and “Zoho Mail (partner mailbox)” under <RouterLink to="/connectors" class="underline" style="color: var(--accent);">Connectors</RouterLink>.
        Sending is enabled per tenant with <code>php artisan feature set outreach.sending on --tenant=&lt;id&gt;</code>.</p>
    </div>

    <form v-if="form" class="space-y-6" @submit.prevent="save">
      <section class="sn-card p-5 space-y-3">
        <h2 class="font-medium" style="color: var(--text-primary);">Program facts</h2>
        <div class="grid md:grid-cols-2 gap-3">
          <label class="text-xs" style="color: var(--text-muted);">Brand <input v-model="form.program.brand" class="sn-input w-full" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Portal name <input v-model="form.program.portal_name" class="sn-input w-full" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Commission % <input v-model.number="form.program.commission_pct" type="number" class="sn-input w-full" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Months <input v-model.number="form.program.months" type="number" class="sn-input w-full" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Cookie days <input v-model.number="form.program.cookie_days" type="number" class="sn-input w-full" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Minimum payout (USD) <input v-model.number="form.program.min_payout_usd" type="number" class="sn-input w-full" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Hold days <input v-model.number="form.program.hold_days" type="number" class="sn-input w-full" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Operator legal name <input v-model="form.program.operator_legal_name" class="sn-input w-full" /></label>
          <label class="text-xs md:col-span-2" style="color: var(--text-muted);">Join link (Affonso group invite link) <input v-model="form.program.join_url" class="sn-input w-full" placeholder="https://yourportal.affonso.io/?group=…" /></label>
          <label class="text-xs md:col-span-2" style="color: var(--text-muted);">Terms URL <input v-model="form.program.terms_url" class="sn-input w-full" /></label>
          <label class="text-xs md:col-span-2" style="color: var(--text-muted);">Postal address (printed in every email footer) <input v-model="form.program.postal_address" class="sn-input w-full" /></label>
        </div>
      </section>

      <section class="sn-card p-5 space-y-3">
        <h2 class="font-medium" style="color: var(--text-primary);">Sending limits</h2>
        <div class="grid md:grid-cols-3 gap-3">
          <label class="text-xs" style="color: var(--text-muted);">Per tick <input v-model.number="form.sending.per_run_cap" type="number" class="sn-input w-full" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Minimum gap (seconds) <input v-model.number="form.sending.min_gap_seconds" type="number" class="sn-input w-full" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Hourly cap <input v-model.number="form.sending.hourly_burst_cap" type="number" class="sn-input w-full" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Timezone <input v-model="form.sending.timezone" class="sn-input w-full" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Quiet from <input v-model="form.sending.quiet_hours.start" class="sn-input w-full" placeholder="20:00" /></label>
          <label class="text-xs" style="color: var(--text-muted);">Quiet until <input v-model="form.sending.quiet_hours.end" class="sn-input w-full" placeholder="08:00" /></label>
        </div>
        <p class="text-xs" style="color: var(--text-muted);">Warm-up: daily cap by day since the first send</p>
        <div v-for="(rung, i) in form.sending.warmup" :key="i" class="flex gap-2 items-center">
          <span class="text-xs" style="color: var(--text-muted);">from day</span>
          <input v-model.number="rung.from_day" type="number" class="sn-input" style="width: 90px;" />
          <span class="text-xs" style="color: var(--text-muted);">cap</span>
          <input v-model.number="rung.cap" type="number" class="sn-input" style="width: 90px;" />
        </div>
      </section>

      <section class="sn-card p-5 space-y-3">
        <h2 class="font-medium" style="color: var(--text-primary);">Sequence and replies</h2>
        <div v-for="(step, i) in form.sequence" :key="i" class="flex gap-2 items-center">
          <span class="text-xs" style="color: var(--text-muted);">Step {{ i + 1 }}</span>
          <input v-model="step.template" class="sn-input" style="width: 220px;" />
          <span class="text-xs" style="color: var(--text-muted);">after</span>
          <input v-model.number="step.wait_days" type="number" class="sn-input" style="width: 80px;" />
          <span class="text-xs" style="color: var(--text-muted);">days</span>
        </div>
        <label class="text-xs block" style="color: var(--text-muted);">Reply mode
          <select v-model="form.replies.mode" class="sn-input">
            <option value="approve">approve (every bot reply needs a human)</option>
            <option value="auto">auto (send within guardrails)</option>
          </select>
        </label>
      </section>

      <div class="flex items-center gap-3">
        <button type="submit" class="sn-btn px-5 py-2 rounded-lg text-sm font-semibold" :disabled="saving">{{ saving ? 'Saving…' : 'Save settings' }}</button>
        <span v-if="saved" class="text-sm" style="color: #3ddc97;">Saved.</span>
        <span v-if="store.error" class="text-sm" style="color: #FF6B6B;">{{ store.error }}</span>
      </div>
    </form>
  </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { usePartnersStore } from '../../stores/partners.js'

const store = usePartnersStore()
const form = ref(null)
const saving = ref(false)
const saved = ref(false)

onMounted(async () => {
  const s = await store.fetchSettings()
  form.value = JSON.parse(JSON.stringify(s))
})

async function save() {
  saving.value = true
  saved.value = false
  try {
    const { program, sending, sequence, replies } = form.value
    const { started_at, ...sendingPatch } = sending
    await store.saveSettings({ program, sending: sendingPatch, sequence, replies })
    saved.value = true
  } catch { /* store.error is shown */ } finally {
    saving.value = false
  }
}
</script>
