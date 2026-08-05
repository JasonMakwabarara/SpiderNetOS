<template>
  <div class="px-8 py-6 max-w-[900px] mx-auto">
    <header class="mb-5">
      <h1 class="text-[26px] font-heading font-semibold tracking-tight" style="color: var(--text-primary);">New expense report</h1>
      <p class="text-sm mt-1" style="color: var(--text-muted);">
        Draft first: create the report, add line items with receipts, then submit for approval.
      </p>
    </header>

    <!-- Step 1: create draft -->
    <section v-if="!draft" class="sn-card p-5 space-y-4" data-testid="expense-create-panel">
      <div>
        <label for="exp-title">Title</label>
        <input
          id="exp-title"
          v-model="form.title"
          type="text"
          placeholder="e.g. Client visit — Cardiff, March"
          data-testid="expense-title-input"
        />
      </div>
      <div class="max-w-[200px]">
        <label for="exp-currency">Currency</label>
        <select id="exp-currency" v-model="form.currency" data-testid="expense-currency-select">
          <option value="USD">USD</option>
          <option value="GBP">GBP</option>
          <option value="EUR">EUR</option>
        </select>
      </div>
      <p v-if="formError" class="text-xs" style="color: var(--danger);" data-testid="expense-create-error">{{ formError }}</p>
      <div class="flex justify-end">
        <button
          class="sn-btn sn-btn-primary"
          :disabled="creating"
          data-testid="expense-create-button"
          @click="createDraft"
        >{{ creating ? 'Creating…' : 'Create draft' }}</button>
      </div>
    </section>

    <!-- Step 2: line items + receipts -->
    <template v-else>
      <section class="sn-card p-5 mb-4">
        <div class="flex items-center justify-between gap-3">
          <div class="min-w-0">
            <div class="flex items-center gap-2">
              <h2 class="font-heading font-semibold text-[17px] truncate" style="color: var(--text-primary);">{{ draft.title }}</h2>
              <span class="sn-pill text-[10px]">{{ draft.status || 'draft' }}</span>
            </div>
            <p class="text-xs mt-0.5 mono" style="color: var(--text-muted);">{{ draft.id }}</p>
          </div>
          <div class="text-right shrink-0">
            <div class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">Total</div>
            <div class="text-lg font-semibold mono" style="color: var(--text-primary);">
              {{ draft.currency || form.currency }} {{ formatAmount(totalAmount) }}
            </div>
          </div>
        </div>
      </section>

      <!-- Existing line items -->
      <section v-if="lineItems.length" class="sn-card overflow-hidden mb-4" data-testid="expense-items-list">
        <table>
          <thead>
            <tr>
              <th>Date</th><th>Category</th><th>Merchant</th><th>Description</th>
              <th class="text-right">Amount</th><th>Receipt</th><th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="item in lineItems" :key="item.id" :data-testid="`expense-item-${item.id}`">
              <td class="whitespace-nowrap">{{ item.expense_date }}</td>
              <td>{{ categoryName(item.category_id) }}</td>
              <td>{{ item.merchant }}</td>
              <td class="max-w-[220px] truncate">{{ item.description }}</td>
              <td class="text-right mono">{{ formatAmount(item.amount) }}</td>
              <td>
                <span v-if="item.has_receipt" class="sn-pill sn-pill-success text-[10px]">attached</span>
                <div v-else class="min-w-[180px]">
                  <ReceiptUpload
                    :progress="uploadProgress[item.id] || 0"
                    @upload="(file) => onUpload(item, file)"
                  />
                </div>
              </td>
              <td class="text-right">
                <button
                  class="text-xs font-medium"
                  style="color: var(--danger);"
                  :data-testid="`expense-item-remove-${item.id}`"
                  @click="removeItem(item)"
                >Remove</button>
              </td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Add line item -->
      <section class="sn-card p-5 mb-4 space-y-3" data-testid="expense-add-item-panel">
        <h3 class="text-[10px] uppercase tracking-widest font-semibold" style="color: var(--text-muted);">Add line item</h3>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label for="item-date">Date</label>
            <input id="item-date" v-model="item.expense_date" type="date" data-testid="item-date-input" />
          </div>
          <div>
            <label for="item-category">Category</label>
            <select id="item-category" v-model="item.category_id" data-testid="item-category-select">
              <option disabled value="">Select category…</option>
              <option v-for="c in expensesStore.categories" :key="c.id" :value="c.id">{{ c.name }}</option>
            </select>
          </div>
          <div>
            <label for="item-merchant">Merchant</label>
            <input id="item-merchant" v-model="item.merchant" type="text" placeholder="e.g. Great Western Railway" data-testid="item-merchant-input" />
          </div>
          <div>
            <label for="item-amount">Amount</label>
            <input id="item-amount" v-model="item.amount" type="number" min="0" step="0.01" placeholder="0.00" class="mono" data-testid="item-amount-input" />
          </div>
          <div class="md:col-span-2">
            <label for="item-description">Description</label>
            <input id="item-description" v-model="item.description" type="text" placeholder="What was this for?" data-testid="item-description-input" />
          </div>
        </div>
        <p v-if="itemError" class="text-xs" style="color: var(--danger);" data-testid="item-error">{{ itemError }}</p>
        <div class="flex justify-end">
          <button class="sn-btn" :disabled="addingItem" data-testid="item-add-button" @click="addItem">
            {{ addingItem ? 'Adding…' : '+ Add item' }}
          </button>
        </div>
      </section>

      <!-- Submit -->
      <footer class="flex items-center justify-between gap-3">
        <p class="text-xs" style="color: var(--text-muted);">
          Submitting sends the report into the approval chain. You can't edit after submitting.
        </p>
        <div class="flex items-center gap-2">
          <p v-if="submitError" class="text-xs" style="color: var(--danger);" data-testid="expense-submit-error">{{ submitError }}</p>
          <button
            class="sn-btn sn-btn-primary"
            :disabled="submitting || !lineItems.length"
            data-testid="expense-submit-button"
            @click="submit"
          >{{ submitting ? 'Submitting…' : 'Submit for approval' }}</button>
        </div>
      </footer>
    </template>
  </div>
</template>

<script setup>
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useExpensesStore } from '../../../stores/expenses.js'
import ReceiptUpload from '../../../components/financial/ReceiptUpload.vue'

const router = useRouter()
const expensesStore = useExpensesStore()

const form = reactive({ title: '', currency: 'USD' })
const item = reactive({ expense_date: '', category_id: '', merchant: '', description: '', amount: '' })

const creating = ref(false)
const addingItem = ref(false)
const submitting = ref(false)
const formError = ref('')
const itemError = ref('')
const submitError = ref('')
const uploadProgress = reactive({})

const draft = computed(() => expensesStore.currentExpense)
const lineItems = computed(() => draft.value?.line_items || [])
const totalAmount = computed(() =>
  draft.value?.total_amount ?? lineItems.value.reduce((sum, li) => sum + Number(li.amount || 0), 0)
)

function categoryName(id) {
  return expensesStore.categories.find((c) => c.id === id)?.name || '—'
}

function formatAmount(v) {
  return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: 2 })
}

async function createDraft() {
  formError.value = ''
  if (!form.title.trim()) {
    formError.value = 'Give the report a title.'
    return
  }
  creating.value = true
  const res = await expensesStore.createExpense({ title: form.title.trim(), currency: form.currency })
  creating.value = false
  if (!res.success) formError.value = res.error || 'Failed to create draft.'
}

async function addItem() {
  itemError.value = ''
  if (!item.expense_date || !item.category_id || !item.merchant.trim() || !(Number(item.amount) > 0)) {
    itemError.value = 'Date, category, merchant, and a positive amount are required.'
    return
  }
  addingItem.value = true
  const res = await expensesStore.addItem(draft.value.id, {
    expense_date: item.expense_date,
    category_id: item.category_id,
    merchant: item.merchant.trim(),
    description: item.description.trim(),
    amount: String(item.amount),
  })
  addingItem.value = false
  if (!res.success) {
    itemError.value = res.error || 'Failed to add line item.'
    return
  }
  Object.assign(item, { expense_date: '', category_id: '', merchant: '', description: '', amount: '' })
}

async function removeItem(li) {
  await expensesStore.removeItem(draft.value.id, li.id)
}

async function onUpload(li, file) {
  uploadProgress[li.id] = 1
  const res = await expensesStore.uploadReceipt(
    draft.value.id, li.id, file,
    (pct) => { uploadProgress[li.id] = pct },
  )
  uploadProgress[li.id] = 0
  if (res.success) {
    // Refresh so has_receipt + documents reflect server state.
    await expensesStore.fetchExpense(draft.value.id)
  }
}

async function submit() {
  submitError.value = ''
  submitting.value = true
  const res = await expensesStore.submitExpense(draft.value.id)
  submitting.value = false
  if (!res.success) {
    submitError.value = res.error || 'Failed to submit.'
    return
  }
  router.push(`/financial/expenses/${draft.value.id}`)
}

onMounted(() => {
  // Fresh draft flow — clear any previously viewed report.
  expensesStore.currentExpense = null
  expensesStore.fetchCategories()
})
</script>
