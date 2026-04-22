<template>
  <div class="approvals p-6 space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold text-gray-900">Approvals</h1>
        <p class="text-sm text-gray-500 mt-1">
          Review and manage pending approval requests
        </p>
      </div>
      <div class="flex items-center space-x-3">
        <span
          v-if="approvalsStore.pendingCount > 0"
          class="px-3 py-1 text-sm font-medium bg-yellow-100 text-yellow-800 rounded-full"
        >
          {{ approvalsStore.pendingCount }} pending
        </span>
        <button
          @click="refreshApprovals"
          class="p-2 text-gray-400 hover:text-gray-600 rounded-lg hover:bg-gray-100 transition-colors"
          title="Refresh"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Filter Tabs -->
    <div class="border-b border-gray-200">
      <nav class="flex space-x-8">
        <button
          v-for="tab in filterTabs"
          :key="tab.value"
          @click="activeFilter = tab.value"
          class="pb-3 px-1 text-sm font-medium border-b-2 transition-colors"
          :class="activeFilter === tab.value
            ? 'border-indigo-600 text-indigo-600'
            : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'"
        >
          {{ tab.label }}
          <span
            v-if="tab.count > 0"
            class="ml-2 px-2 py-0.5 text-xs rounded-full"
            :class="activeFilter === tab.value ? 'bg-indigo-100 text-indigo-600' : 'bg-gray-100 text-gray-500'"
          >
            {{ tab.count }}
          </span>
        </button>
      </nav>
    </div>

    <!-- Loading -->
    <div v-if="approvalsStore.isLoading" class="text-center py-12">
      <div class="animate-spin w-8 h-8 border-4 border-indigo-600 border-t-transparent rounded-full mx-auto" />
      <p class="mt-4 text-gray-500">Loading approvals...</p>
    </div>

    <!-- Empty State -->
    <div v-else-if="filteredApprovals.length === 0" class="text-center py-12 bg-white rounded-lg border border-gray-200">
      <svg class="w-16 h-16 text-gray-300 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <h3 class="text-lg font-medium text-gray-900">No approvals found</h3>
      <p class="text-gray-500 mt-1">
        {{ activeFilter === 'all' ? 'No approval requests yet' : `No ${activeFilter} approvals` }}
      </p>
    </div>

    <!-- Approval Cards -->
    <div v-else class="space-y-4">
      <div
        v-for="approval in filteredApprovals"
        :key="approval.id"
        class="bg-white rounded-lg border border-gray-200 shadow-sm overflow-hidden"
      >
        <div class="p-5">
          <div class="flex items-start justify-between">
            <div class="flex items-start space-x-4">
              <!-- Type Badge -->
              <span
                class="flex-shrink-0 px-2.5 py-1 text-xs font-medium rounded-full"
                :class="typeBadgeClass(approval.type)"
              >
                {{ approval.type }}
              </span>

              <div class="min-w-0">
                <!-- Resource Info -->
                <h4 class="text-sm font-semibold text-gray-900">
                  {{ approval.resource_name || approval.title || 'Unnamed Resource' }}
                </h4>
                <p v-if="approval.resource_type" class="text-xs text-gray-500 mt-0.5">
                  {{ approval.resource_type }} {{ approval.resource_id ? `#${approval.resource_id}` : '' }}
                </p>

                <!-- Reason -->
                <p v-if="approval.reason" class="text-sm text-gray-600 mt-2">
                  {{ approval.reason }}
                </p>

                <!-- Metadata -->
                <div class="flex items-center space-x-4 mt-3 text-xs text-gray-400">
                  <span v-if="approval.requested_by" class="flex items-center space-x-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                    </svg>
                    <span>{{ approval.requested_by }}</span>
                  </span>
                  <span class="flex items-center space-x-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span>{{ formatTime(approval.created_at) }}</span>
                  </span>
                </div>
              </div>
            </div>

            <!-- Status Badge -->
            <span
              class="flex-shrink-0 px-2.5 py-1 text-xs font-medium rounded-full"
              :class="statusBadgeClass(approval.status)"
            >
              {{ approval.status }}
            </span>
          </div>

          <!-- Action buttons for pending approvals -->
          <div v-if="approval.status === 'pending'" class="flex items-center space-x-3 mt-4 pt-4 border-t border-gray-100">
            <button
              @click="openApproveModal(approval)"
              class="px-4 py-2 text-sm font-medium bg-green-600 text-white rounded-lg hover:bg-green-700 transition-colors"
            >
              Approve
            </button>
            <button
              @click="openRejectModal(approval)"
              class="px-4 py-2 text-sm font-medium bg-white text-red-600 border border-red-300 rounded-lg hover:bg-red-50 transition-colors"
            >
              Reject
            </button>
          </div>

          <!-- Review comment for processed approvals -->
          <div v-if="approval.review_comment" class="mt-4 pt-4 border-t border-gray-100">
            <p class="text-xs text-gray-500 mb-1">Review Comment:</p>
            <p class="text-sm text-gray-700 bg-gray-50 rounded-lg p-3">{{ approval.review_comment }}</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Approve/Reject Confirmation Modal -->
    <div v-if="modalApproval" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4">
        <div class="p-6">
          <h2 class="text-lg font-semibold text-gray-900 mb-1">
            {{ modalAction === 'approve' ? 'Approve Request' : 'Reject Request' }}
          </h2>
          <p class="text-sm text-gray-500 mb-4">
            {{ modalApproval.resource_name || modalApproval.title }}
          </p>

          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-1">
              {{ modalAction === 'approve' ? 'Comment (optional)' : 'Reason for rejection' }}
            </label>
            <textarea
              v-model="modalComment"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500 text-sm"
              :placeholder="modalAction === 'approve' ? 'Add a comment...' : 'Explain why this was rejected...'"
            />
          </div>

          <div class="flex justify-end space-x-3">
            <button
              @click="closeModal"
              class="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg transition-colors"
            >
              Cancel
            </button>
            <button
              @click="confirmAction"
              :disabled="isProcessing || (modalAction === 'reject' && !modalComment.trim())"
              class="px-4 py-2 text-sm font-medium text-white rounded-lg transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              :class="modalAction === 'approve' ? 'bg-green-600 hover:bg-green-700' : 'bg-red-600 hover:bg-red-700'"
            >
              {{ isProcessing ? 'Processing...' : (modalAction === 'approve' ? 'Confirm Approve' : 'Confirm Reject') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useApprovalsStore } from '../stores/approvals.js'

const approvalsStore = useApprovalsStore()

const activeFilter = ref('all')
const modalApproval = ref(null)
const modalAction = ref('')
const modalComment = ref('')
const isProcessing = ref(false)

const filterTabs = computed(() => [
  { label: 'All', value: 'all', count: approvalsStore.approvals.length },
  { label: 'Pending', value: 'pending', count: approvalsStore.pendingApprovals.length },
  { label: 'Approved', value: 'approved', count: approvalsStore.approvedApprovals.length },
  { label: 'Rejected', value: 'rejected', count: approvalsStore.rejectedApprovals.length }
])

const filteredApprovals = computed(() => {
  switch (activeFilter.value) {
    case 'pending': return approvalsStore.pendingApprovals
    case 'approved': return approvalsStore.approvedApprovals
    case 'rejected': return approvalsStore.rejectedApprovals
    default: return approvalsStore.approvals
  }
})

onMounted(() => {
  approvalsStore.fetchApprovals()
})

function refreshApprovals() {
  approvalsStore.fetchApprovals()
}

function openApproveModal(approval) {
  modalApproval.value = approval
  modalAction.value = 'approve'
  modalComment.value = ''
}

function openRejectModal(approval) {
  modalApproval.value = approval
  modalAction.value = 'reject'
  modalComment.value = ''
}

function closeModal() {
  modalApproval.value = null
  modalAction.value = ''
  modalComment.value = ''
}

async function confirmAction() {
  if (!modalApproval.value) return
  isProcessing.value = true

  try {
    if (modalAction.value === 'approve') {
      await approvalsStore.approveItem(modalApproval.value.id, modalComment.value)
    } else {
      await approvalsStore.rejectItem(modalApproval.value.id, modalComment.value)
    }
    closeModal()
  } finally {
    isProcessing.value = false
  }
}

function typeBadgeClass(type) {
  const map = {
    deployment: 'bg-purple-100 text-purple-700',
    flow: 'bg-blue-100 text-blue-700',
    agent: 'bg-green-100 text-green-700',
    budget: 'bg-yellow-100 text-yellow-700',
    access: 'bg-orange-100 text-orange-700'
  }
  return map[type] || 'bg-gray-100 text-gray-700'
}

function statusBadgeClass(status) {
  const map = {
    pending: 'bg-yellow-100 text-yellow-700',
    approved: 'bg-green-100 text-green-700',
    rejected: 'bg-red-100 text-red-700'
  }
  return map[status] || 'bg-gray-100 text-gray-700'
}

function formatTime(timestamp) {
  if (!timestamp) return ''
  const date = new Date(timestamp)
  const now = new Date()
  const diff = Math.floor((now - date) / 1000)

  if (diff < 60) return 'just now'
  if (diff < 3600) return `${Math.floor(diff / 60)}m ago`
  if (diff < 86400) return `${Math.floor(diff / 3600)}h ago`
  return date.toLocaleDateString()
}
</script>
