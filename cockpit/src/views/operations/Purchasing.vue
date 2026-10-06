<template>
  <div class="p-6 space-y-6" :style="{ background: 'var(--bg)' }" data-testid="ops-purchasing">
    <router-link to="/operations" class="text-sm" :style="{ color: 'var(--charge-vivid)' }">Operations</router-link>
    <div>
      <h1 class="text-2xl font-bold" :style="{ color: 'var(--text-primary)' }">Purchasing</h1>
      <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }">
        A submitted requisition waits for approval. Approval authorises procurement. It does not create a purchase order.
      </p>
    </div>
    <p v-if="message" class="text-sm" :style="{ color: 'var(--text-secondary)' }" data-testid="purchasing-message">{{ message }}</p>

    <form class="dct-card p-4 grid gap-3 md:grid-cols-2" data-testid="requisition-form" @submit.prevent="create">
      <label class="text-sm space-y-1 md:col-span-2" :style="{ color: 'var(--text-secondary)' }">
        Title
        <input v-model="form.title" required class="w-full px-3 py-2 rounded border" :style="field" data-testid="requisition-title" />
      </label>
      <label class="text-sm space-y-1 md:col-span-2" :style="{ color: 'var(--text-secondary)' }">
        Line
        <input v-model="form.description" required class="w-full px-3 py-2 rounded border" :style="field" data-testid="requisition-line" />
      </label>
      <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">
        Quantity
        <input v-model="form.quantity" required type="number" min="1" step="1" class="w-full px-3 py-2 rounded border" :style="field" data-testid="requisition-quantity" />
      </label>
      <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">
        Unit price
        <input v-model="form.unit_price" required type="number" min="0" step="0.01" class="w-full px-3 py-2 rounded border" :style="field" data-testid="requisition-price" />
      </label>
      <button class="dct-btn-primary px-4 py-2 text-sm md:col-span-2 w-fit" data-testid="requisition-create">Create requisition</button>
    </form>

    <section v-if="requisitions.length" class="dct-card p-4 space-y-2" data-testid="requisition-list">
      <h2 class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">Requisitions</h2>
      <button
        v-for="row in requisitions"
        :key="row.id"
        type="button"
        class="block w-full text-left text-sm px-2 py-1 rounded"
        :style="{ color: 'var(--text-secondary)' }"
        :data-testid="`requisition-row-${row.id}`"
        @click="openRequisition(row.id)"
      >
        {{ row.requisition_number }} · {{ label(row.status) }} · {{ row.title }}
      </button>
    </section>

    <article v-if="current" class="dct-card p-4 space-y-4" data-testid="requisition-current" :style="{ background: 'var(--surface-low)' }">
      <div>
        <p class="font-medium" :style="{ color: 'var(--text-primary)' }" data-testid="requisition-number">{{ current.requisition_number }}</p>
        <p class="text-sm" :style="{ color: 'var(--text-secondary)' }">{{ current.title }}</p>
        <p class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }" data-testid="requisition-status">Status: {{ label(current.status) }}</p>
        <p v-if="approvalLabel" class="text-sm" :style="{ color: 'var(--text-secondary)' }" data-testid="approval-status">Approval: {{ approvalLabel }}</p>
        <p v-if="anomaly" class="text-sm mt-1" :style="{ color: 'var(--text-secondary)' }" data-testid="approval-anomaly">{{ anomaly }}</p>
      </div>

      <button
        v-if="current.status === 'draft'"
        class="dct-btn-primary px-4 py-2 text-sm"
        data-testid="requisition-submit"
        @click="submit"
      >Submit</button>

      <button
        v-if="current.status === 'submitted' && approval"
        class="dct-btn-primary px-4 py-2 text-sm"
        data-testid="requisition-approve"
        :disabled="busy"
        @click="approve"
      >Approve</button>

      <form
        v-if="current.status === 'approved' && !order"
        class="grid gap-3 md:grid-cols-2"
        data-testid="purchase-order-form"
        @submit.prevent="createOrder"
      >
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">
          Vendor
          <select v-model="vendorId" required class="w-full px-3 py-2 rounded border" :style="field" data-testid="vendor-select">
            <option value="">Active vendor</option>
            <option v-for="vendor in activeVendors" :key="vendor.id" :value="vendor.id">{{ vendor.name }}</option>
          </select>
        </label>
        <label class="text-sm space-y-1" :style="{ color: 'var(--text-secondary)' }">
          New vendor
          <span class="flex gap-2">
            <input v-model="vendorName" class="w-full px-3 py-2 rounded border" :style="field" data-testid="vendor-name" placeholder="Name" />
            <button type="button" class="px-3 py-2 text-sm rounded border" :style="field" data-testid="vendor-create" @click="createVendor">Add</button>
          </span>
        </label>
        <p class="text-sm" :style="{ color: 'var(--text-secondary)' }" data-testid="po-currency">Currency: USD</p>
        <button class="dct-btn-primary px-4 py-2 text-sm w-fit" data-testid="po-create" :disabled="busy || !vendorId">Create purchase order</button>
      </form>

      <section v-if="order" class="space-y-3" data-testid="purchase-order">
        <p class="font-medium" :style="{ color: 'var(--text-primary)' }" data-testid="po-number">{{ order.po_number }}</p>
        <p class="text-sm" :style="{ color: 'var(--text-secondary)' }" data-testid="po-status">Status: {{ label(order.status) }}</p>
        <button
          v-if="order.status === 'draft'"
          class="dct-btn-primary px-4 py-2 text-sm"
          data-testid="po-issue"
          :disabled="busy"
          @click="issue"
        >Issue</button>

        <div data-testid="po-lines">
          <p class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">Lines</p>
          <p
            v-for="line in order.lines || []"
            :key="line.id"
            class="text-sm"
            :style="{ color: 'var(--text-secondary)' }"
            :data-testid="`po-line-${line.id}`"
          >
            {{ line.description }} · Ordered {{ amount(line.quantity) }} · Received {{ amount(receivedOf(line.id)) }}
          </p>
        </div>

        <div v-if="order.status === 'issued'" class="space-y-2">
          <button class="dct-btn-primary px-4 py-2 text-sm" data-testid="receipt-receive" :disabled="busy" @click="receiveFull">Receive full order</button>
          <p v-if="receiptError" class="text-sm" :style="{ color: 'var(--text-secondary)' }" data-testid="receipt-error">{{ receiptError }}</p>
        </div>

        <section v-if="order.status === 'received'" class="space-y-2" data-testid="supplier-finance">
          <p class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">Supplier invoice</p>
          <p v-if="financeError" class="text-sm" :style="{ color: 'var(--text-secondary)' }" data-testid="finance-error">{{ financeError }}</p>
          <button v-if="!supplierInvoice" class="dct-btn-primary px-4 py-2 text-sm" data-testid="invoice-create" :disabled="busy" @click="createInvoice">Create supplier invoice</button>
          <template v-else>
            <p class="text-sm" :style="{ color: 'var(--text-secondary)' }" data-testid="invoice-number">{{ supplierInvoice.invoice_number }}</p>
            <p class="text-sm" :style="{ color: 'var(--text-secondary)' }" data-testid="invoice-status">Status: {{ label(supplierInvoice.status) }}</p>
            <p v-if="matchStatus" class="text-sm" :style="{ color: 'var(--text-secondary)' }" data-testid="match-status">Match: {{ label(matchStatus) }}</p>
            <p v-if="posted" class="text-sm" :style="{ color: 'var(--text-secondary)' }" data-testid="posting-status">Ledger: Posted</p>
            <p v-if="fiscalReceipt" class="text-sm" :style="{ color: 'var(--text-secondary)' }" data-testid="fiscal-receipt">Fiscal: {{ fiscalReceipt }}</p>
            <div class="flex flex-wrap gap-2">
              <button class="px-3 py-2 text-sm rounded border" :style="field" data-testid="invoice-match" :disabled="busy" @click="matchInvoice">Match</button>
              <button class="px-3 py-2 text-sm rounded border" :style="field" data-testid="invoice-post" :disabled="busy || matchStatus !== 'matched'" @click="postInvoice">Post</button>
              <button class="px-3 py-2 text-sm rounded border" :style="field" data-testid="invoice-fiscal" :disabled="busy || !posted" @click="fiscalise">Fiscalise sandbox</button>
              <button class="px-3 py-2 text-sm rounded border" :style="field" data-testid="invoice-pay" :disabled="busy || !posted || supplierInvoice.status === 'paid'" @click="payInvoice">Pay from cash</button>
            </div>
          </template>
        </section>

        <div v-if="(order.receipts || []).length" class="space-y-2" data-testid="receipt-history">
          <p class="text-sm font-medium" :style="{ color: 'var(--text-primary)' }">Receipt</p>
          <div v-for="receipt in order.receipts" :key="receipt.id" :data-testid="`receipt-${receipt.id}`">
            <p class="text-sm" :style="{ color: 'var(--text-secondary)' }">{{ receipt.id }}</p>
            <p
              v-for="line in receipt.lines || []"
              :key="line.id"
              class="text-sm"
              :style="{ color: 'var(--text-secondary)' }"
            >
              {{ lineDescription(line.purchase_order_line_id) }} · Received {{ amount(line.quantity) }}
            </p>
          </div>
        </div>
      </section>
    </article>
  </div>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue'
import api from '../../services/api.js'

const field = { background: 'var(--surface-low)', color: 'var(--text-primary)', borderColor: 'var(--border)' }
const form = ref({ title: '', description: '', quantity: 1, unit_price: 0 })
const requisitions = ref([])
const current = ref(null)
const approval = ref(null)
const anomaly = ref('')
const vendors = ref([])
const vendorId = ref('')
const vendorName = ref('')
const order = ref(null)
const message = ref('')
const receiptError = ref('')
const financeError = ref('')
const supplierInvoice = ref(null)
const matchStatus = ref('')
const posted = ref(false)
const fiscalReceipt = ref('')
const busy = ref(false)

const activeVendors = computed(() => vendors.value.filter(vendor => vendor.status === 'active'))
const approvalLabel = computed(() => {
  if (approval.value) return 'Pending'
  if (current.value?.status === 'approved') return 'Approved'
  if (current.value?.status === 'rejected') return 'Rejected'
  return ''
})

function label(status) {
  if (!status) return ''
  return status.charAt(0).toUpperCase() + status.slice(1)
}

function amount(value) {
  const number = Number(value)
  return Number.isFinite(number) ? String(number) : String(value ?? '')
}

function receivedOf(lineId) {
  return (order.value?.receipts || []).reduce((total, receipt) => {
    const lines = (receipt.lines || []).filter(line => line.purchase_order_line_id === lineId)
    return total + lines.reduce((sum, line) => sum + Number(line.quantity), 0)
  }, 0)
}

function lineDescription(lineId) {
  return (order.value?.lines || []).find(line => line.id === lineId)?.description || ''
}

function fail(err, fallback) {
  message.value = err.response?.data?.message || err.response?.data?.error || fallback
}

async function loadRequisitions() {
  const res = await api.get('/api/enterprise/requisitions')
  requisitions.value = res.data.data || []
}

async function loadVendors() {
  const res = await api.get('/api/enterprise/vendors')
  vendors.value = res.data.data || []
}

async function loadOrder(id) {
  const res = await api.get(`/api/enterprise/purchase-orders/${id}`)
  order.value = res.data.data
  supplierInvoice.value = null
  matchStatus.value = ''
  posted.value = false
  fiscalReceipt.value = ''
  if (order.value.supplier_invoice) await loadFinance(order.value.supplier_invoice.id)
}

async function loadFinance(invoiceId) {
  const res = await api.get(`/api/enterprise/supplier-invoices/${invoiceId}`)
  supplierInvoice.value = res.data.data
  matchStatus.value = res.data.match?.status || ''
  posted.value = !!res.data.posting
  fiscalReceipt.value = res.data.fiscal?.fiscal_receipt_id || ''
}

function financeFail(err) {
  financeError.value = err.response?.data?.message || 'The finance step was refused.'
}

async function loadLinkedOrder(requisitionId) {
  const res = await api.get('/api/enterprise/purchase-orders')
  const match = (res.data.data || []).find(row => row.requisition_id === requisitionId)
  if (match) await loadOrder(match.id)
  else order.value = null
}

async function resolveApproval(requisition) {
  approval.value = null
  anomaly.value = ''
  if (!['submitted', 'approved'].includes(requisition.status)) return

  const pending = await api.get('/api/approvals', { params: { status: 'pending', per_page: 100 } })
  const match = (pending.data?.data || []).find(row => row.resource_id === requisition.id)
  if (match) {
    approval.value = match
    return
  }

  const fresh = await api.get(`/api/enterprise/requisitions/${requisition.id}`)
  current.value = fresh.data.data
  if (current.value.status === 'approved') return
  if (current.value.status === 'submitted') {
    anomaly.value = 'This requisition is submitted, but no pending approval was found.'
  }
}

async function openRequisition(id) {
  message.value = ''
  receiptError.value = ''
  const res = await api.get(`/api/enterprise/requisitions/${id}`)
  current.value = res.data.data
  await resolveApproval(current.value)
  await loadLinkedOrder(current.value.id)
}

async function create() {
  message.value = ''
  try {
    const res = await api.post('/api/enterprise/requisitions', {
      title: form.value.title,
      lines: [{
        description: form.value.description,
        quantity: Number(form.value.quantity),
        unit_price: Number(form.value.unit_price),
      }],
    })
    await loadRequisitions()
    await openRequisition(res.data.data.id)
    message.value = `${current.value.requisition_number} created.`
  } catch (err) {
    fail(err, 'Could not create the requisition.')
  }
}

async function submit() {
  message.value = ''
  try {
    await api.post(`/api/enterprise/requisitions/${current.value.id}/submit`)
    await openRequisition(current.value.id)
    await loadRequisitions()
    message.value = `${current.value.requisition_number} submitted for procurement approval.`
  } catch (err) {
    fail(err, 'Could not submit the requisition.')
  }
}

async function approve() {
  message.value = ''
  anomaly.value = ''
  busy.value = true
  try {
    await api.post(`/api/approvals/${approval.value.id}/approve`, { reason: 'Approved for procurement' })
    const fresh = await api.get(`/api/enterprise/requisitions/${current.value.id}`)
    current.value = fresh.data.data
    approval.value = null
    await loadRequisitions()
    if (current.value.status === 'submitted') {
      anomaly.value = 'This requisition is submitted, but no pending approval was found.'
    }
  } catch (err) {
    fail(err, 'Could not approve the requisition.')
  } finally {
    busy.value = false
  }
}

async function createVendor() {
  message.value = ''
  if (!vendorName.value.trim()) return
  try {
    const res = await api.post('/api/enterprise/vendors', { name: vendorName.value.trim() })
    vendorName.value = ''
    await loadVendors()
    vendorId.value = res.data.data.id
  } catch (err) {
    fail(err, 'Could not create the vendor.')
  }
}

async function createOrder() {
  if (!vendorId.value || current.value?.status !== 'approved') return
  message.value = ''
  busy.value = true
  try {
    const res = await api.post('/api/enterprise/purchase-orders', {
      vendor_id: vendorId.value,
      requisition_id: current.value.id,
      currency: 'USD',
    })
    await loadOrder(res.data.data.id)
    message.value = `${order.value.po_number} created.`
  } catch (err) {
    fail(err, 'Could not create the purchase order.')
  } finally {
    busy.value = false
  }
}

async function issue() {
  message.value = ''
  busy.value = true
  try {
    await api.post(`/api/enterprise/purchase-orders/${order.value.id}/issue`)
    await loadOrder(order.value.id)
  } catch (err) {
    fail(err, 'Could not issue the purchase order.')
  } finally {
    busy.value = false
  }
}

async function createInvoice() {
  financeError.value = ''
  busy.value = true
  try {
    const res = await api.post(`/api/enterprise/purchase-orders/${order.value.id}/invoice`)
    await loadFinance(res.data.data.id)
  } catch (err) {
    financeFail(err)
  } finally {
    busy.value = false
  }
}

async function matchInvoice() {
  financeError.value = ''
  busy.value = true
  try {
    const res = await api.post(`/api/enterprise/supplier-invoices/${supplierInvoice.value.id}/match`)
    matchStatus.value = res.data.data.status
  } catch (err) {
    financeFail(err)
  } finally {
    busy.value = false
  }
}

async function postInvoice() {
  financeError.value = ''
  busy.value = true
  try {
    await api.post(`/api/enterprise/supplier-invoices/${supplierInvoice.value.id}/post`)
    posted.value = true
  } catch (err) {
    financeFail(err)
  } finally {
    busy.value = false
  }
}

async function fiscalise() {
  financeError.value = ''
  busy.value = true
  try {
    const res = await api.post(`/api/enterprise/supplier-invoices/${supplierInvoice.value.id}/fiscalise`, { environment: 'sandbox' })
    fiscalReceipt.value = res.data.data.fiscal_receipt_id
  } catch (err) {
    financeFail(err)
  } finally {
    busy.value = false
  }
}

async function payInvoice() {
  financeError.value = ''
  busy.value = true
  try {
    const books = await api.get('/api/enterprise/cashbooks')
    let book = (books.data.data || []).find(row => row.currency === supplierInvoice.value.currency && row.status === 'active')
    if (!book) {
      const created = await api.post('/api/enterprise/cashbooks', { name: 'Cash', currency: supplierInvoice.value.currency })
      book = created.data.data
    }
    await api.post(`/api/enterprise/cashbooks/${book.id}/movements`, {
      type: 'payment',
      amount: Number(supplierInvoice.value.total_amount),
      currency: supplierInvoice.value.currency,
      movement_date: new Date().toISOString().slice(0, 10),
      invoice_id: supplierInvoice.value.id,
    })
    await loadFinance(supplierInvoice.value.id)
  } catch (err) {
    financeFail(err)
  } finally {
    busy.value = false
  }
}

async function receiveFull() {
  receiptError.value = ''
  message.value = ''
  busy.value = true
  try {
    const lines = (order.value.lines || []).map(line => ({
      purchase_order_line_id: line.id,
      quantity: Number(line.quantity),
    }))
    await api.post(`/api/enterprise/purchase-orders/${order.value.id}/receipts`, { lines })
    await loadOrder(order.value.id)
  } catch (err) {
    receiptError.value = err.response?.data?.message || 'Could not record the goods receipt.'
  } finally {
    busy.value = false
  }
}

onMounted(async () => {
  try {
    await Promise.all([loadRequisitions(), loadVendors()])
  } catch (err) {
    fail(err, 'Could not load purchasing.')
  }
})
</script>
