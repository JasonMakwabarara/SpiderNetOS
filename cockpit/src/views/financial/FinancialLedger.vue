<template>
  <div class="financial-ledger p-6 space-y-6" :style="{ background: 'var(--bg)' }">
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">General Ledger</h1>
        <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">Double-entry bookkeeping and account management</p>
      </div>
      <button @click="showNewAccount = true" class="dct-btn-primary px-4 py-2 text-sm">+ New Account</button>
    </div>

    <!-- Accounts -->
    <div class="dct-card p-6 space-y-4">
      <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Accounts</h2>
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-3">
        <div v-for="acct in accounts" :key="acct.id" class="rounded-xl p-4" :style="{ background: 'var(--surface-low)' }">
          <p class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">{{ acct.name }}</p>
          <p class="text-xs" :style="{ color: 'var(--text-muted)' }">{{ acct.type }}</p>
          <p class="text-xl font-bold mt-2" :style="{ color: parseFloat(acct.balance) >= 0 ? 'var(--charge-vivid)' : 'var(--dusk-vivid)' }">
            ${{ formatNumber(acct.balance) }}
          </p>
          <p class="text-xs mt-1" :style="{ color: 'var(--text-muted)' }">{{ acct.currency }}</p>
        </div>
      </div>
    </div>

    <!-- Ledger Entries -->
    <div class="dct-card p-6 space-y-4">
      <div class="flex items-center justify-between">
        <h2 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">Ledger Entries</h2>
        <button @click="showNewEntry = true" class="dct-btn-primary px-4 py-2 text-sm">+ Journal Entry</button>
      </div>

      <div class="overflow-x-auto">
        <table class="min-w-full">
          <thead>
            <tr :style="{ borderBottom: '1px solid var(--border)' }">
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Date</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Account</th>
              <th class="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Description</th>
              <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Debit</th>
              <th class="px-4 py-3 text-right text-xs font-semibold uppercase tracking-wider" :style="{ color: 'var(--text-muted)' }">Credit</th>
            </tr>
          </thead>
          <tbody>
            <tr v-if="entries.length === 0">
              <td colspan="5" class="px-4 py-8 text-center text-sm" :style="{ color: 'var(--text-muted)' }">
                No ledger entries yet. Create a journal entry to get started.
              </td>
            </tr>
            <tr v-for="entry in entries" :key="entry.id" :style="{ borderBottom: '1px solid var(--border)' }">
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-primary)' }">{{ formatDate(entry.posted_at) }}</td>
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-secondary)' }">{{ entry.account?.name || '—' }}</td>
              <td class="px-4 py-3 text-sm" :style="{ color: 'var(--text-secondary)' }">{{ entry.description || '—' }}</td>
              <td class="px-4 py-3 text-sm text-right" :style="{ color: entry.side === 'debit' ? 'var(--charge-vivid)' : 'var(--text-muted)' }">
                {{ entry.side === 'debit' ? '$' + formatNumber(entry.amount) : '—' }}
              </td>
              <td class="px-4 py-3 text-sm text-right" :style="{ color: entry.side === 'credit' ? 'var(--dusk-vivid)' : 'var(--text-muted)' }">
                {{ entry.side === 'credit' ? '$' + formatNumber(entry.amount) : '—' }}
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- New Account Modal -->
    <div v-if="showNewAccount" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="dct-card p-6 w-full max-w-md space-y-4" :style="{ background: 'var(--bg)' }">
        <h3 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">New Financial Account</h3>
        <input v-model="newAccount.name" placeholder="Account name" class="w-full px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
        <select v-model="newAccount.type" class="w-full px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }">
          <option value="asset">Asset</option>
          <option value="liability">Liability</option>
          <option value="equity">Equity</option>
          <option value="revenue">Revenue</option>
          <option value="expense">Expense</option>
        </select>
        <div class="flex gap-3 justify-end">
          <button @click="showNewAccount = false" class="px-4 py-2 rounded-lg text-sm" :style="{ color: 'var(--text-secondary)', background: 'var(--surface-low)' }">Cancel</button>
          <button @click="createAccount" class="dct-btn-primary px-4 py-2 text-sm">Create</button>
        </div>
      </div>
    </div>

    <!-- New Journal Entry Modal -->
    <div v-if="showNewEntry" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="dct-card p-6 w-full max-w-lg space-y-4" :style="{ background: 'var(--bg)' }">
        <h3 class="text-lg font-semibold" :style="{ color: 'var(--text-primary)' }">New Journal Entry</h3>
        <select v-model="newEntry.source" class="w-full px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }">
          <option value="">Source Account (Credit)</option>
          <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.type }})</option>
        </select>
        <select v-model="newEntry.destination" class="w-full px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }">
          <option value="">Destination Account (Debit)</option>
          <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }} ({{ a.type }})</option>
        </select>
        <input v-model="newEntry.amount" type="number" placeholder="Amount" class="w-full px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
        <input v-model="newEntry.description" placeholder="Description" class="w-full px-3 py-2 rounded-lg border" :style="{ borderColor: 'var(--border)', background: 'var(--surface-low)', color: 'var(--text-primary)' }" />
        <div class="flex gap-3 justify-end">
          <button @click="showNewEntry = false" class="px-4 py-2 rounded-lg text-sm" :style="{ color: 'var(--text-secondary)', background: 'var(--surface-low)' }">Cancel</button>
          <button @click="createJournalEntry" class="dct-btn-primary px-4 py-2 text-sm">Post Entry</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import api from '../../services/api.js'

const accounts = ref([])
const entries = ref([])
const showNewAccount = ref(false)
const showNewEntry = ref(false)
const newAccount = ref({ name: '', type: 'asset' })
const newEntry = ref({ source: '', destination: '', amount: '', description: '' })

function formatNumber(n) { return Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) }
function formatDate(d) { return d ? new Date(d).toLocaleDateString() : '—' }

async function createAccount() {
  try {
    await api.post('/api/financial/ledger/accounts', { name: newAccount.value.name, type: newAccount.value.type })
    showNewAccount.value = false
    newAccount.value = { name: '', type: 'asset' }
    loadAccounts()
  } catch (e) { console.error('Failed to create account', e) }
}

async function createJournalEntry() {
  try {
    await api.post('/api/financial/ledger/journal-entry', {
      source_account_id: newEntry.value.source,
      destination_account_id: newEntry.value.destination,
      amount: newEntry.value.amount,
      description: newEntry.value.description,
    })
    showNewEntry.value = false
    newEntry.value = { source: '', destination: '', amount: '', description: '' }
    loadEntries()
    loadAccounts()
  } catch (e) { console.error('Failed to create journal entry', e) }
}

async function loadAccounts() {
  try {
    const res = await api.get('/api/financial/ledger/accounts')
    accounts.value = res.data.data || []
  } catch (e) { console.error('Failed to load accounts', e) }
}

async function loadEntries() {
  try {
    const res = await api.get('/api/financial/ledger')
    entries.value = res.data.data?.data || []
  } catch (e) { console.error('Failed to load entries', e) }
}

onMounted(() => { loadAccounts(); loadEntries() })
</script>
