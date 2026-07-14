<template>
  <div class="p-6 space-y-4">
    <header class="flex items-center justify-between">
      <div>
        <h1 class="text-2xl font-bold">Users</h1>
        <p class="text-sm text-gray-500">Manage teammates, roles, and access for this tenant.</p>
      </div>
      <button
        class="px-3 py-2 rounded bg-indigo-600 text-white text-sm font-medium"
        @click="inviteOpen = true"
      >
        Invite user
      </button>
    </header>

    <FilterBar v-model="filters" :fields="filterFields" @apply="load" @reset="load" />

    <DataTable
      :columns="columns"
      :rows="users"
      :loading="loading"
      caption="Tenant users"
    >
      <template #cell-role="{ row }">
        <RoleBadge :role="row.role" />
      </template>
      <template #cell-status="{ row }">
        <span :class="row.status === 'active' ? 'text-green-600' : 'text-gray-400'">
          {{ row.status }}
        </span>
      </template>
      <template #actions="{ row }">
        <button class="text-xs text-indigo-600 mr-2" @click="editRow = row">Edit</button>
        <button class="text-xs text-red-600"
          @click="confirmDeactivate(row)"
          :disabled="row.id === authStore.user?.id"
        >Deactivate</button>
      </template>
      <template #empty>
        <EmptyState title="No teammates yet" description="Invite people to join your tenant.">
          <template #actions>
            <button class="px-3 py-1.5 rounded bg-indigo-600 text-white text-sm" @click="inviteOpen = true">
              Invite user
            </button>
          </template>
        </EmptyState>
      </template>
    </DataTable>

    <!-- Invite dialog (minimal placeholder — uses native prompt) -->
    <ConfirmDialog
      v-model="inviteOpen"
      title="Invite user"
      confirmLabel="Send invite"
      @confirm="sendInvite"
    >
      <label for="invite-email" class="block text-xs font-medium mb-1">Email</label>
      <input id="invite-email" type="email" v-model="inviteEmail" class="w-full px-3 py-2 border rounded text-sm" />
      <label for="invite-role" class="block text-xs font-medium mt-3 mb-1">Role</label>
      <select id="invite-role" v-model="inviteRole" class="w-full px-3 py-2 border rounded text-sm">
        <option value="user">User</option>
        <option value="admin">Admin</option>
      </select>
    </ConfirmDialog>

    <!-- Typed-confirm for deactivation -->
    <TypedConfirmDialog
      v-model="deactivateOpen"
      :phrase="deactivateTarget?.email || ''"
      title="Deactivate user"
      confirmLabel="Deactivate"
      @confirm="doDeactivate"
    >
      <p>Deactivating this user revokes access immediately. Audit-logged.</p>
    </TypedConfirmDialog>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '../../stores/auth.js'
import DataTable         from '../../components/data/DataTable.vue'
import FilterBar         from '../../components/data/FilterBar.vue'
import EmptyState        from '../../components/data/EmptyState.vue'
import RoleBadge         from '../../components/security/RoleBadge.vue'
import ConfirmDialog     from '../../components/feedback/ConfirmDialog.vue'
import TypedConfirmDialog from '../../components/feedback/TypedConfirmDialog.vue'

const authStore = useAuthStore()

const loading = ref(false)
const users   = ref([])

const filters = ref({})
const filterFields = [
  { key: 'q',      label: 'Search',  type: 'text' },
  { key: 'role',   label: 'Role',    type: 'select',
    options: [
      { value: 'user',  label: 'User' },
      { value: 'admin', label: 'Admin' },
    ] },
  { key: 'status', label: 'Status',  type: 'select',
    options: [
      { value: 'active',   label: 'Active' },
      { value: 'disabled', label: 'Disabled' },
    ] },
]

const columns = [
  { key: 'name',   label: 'Name',   sortable: true },
  { key: 'email',  label: 'Email',  sortable: true },
  { key: 'role',   label: 'Role' },
  { key: 'status', label: 'Status' },
]

// Invite
const inviteOpen  = ref(false)
const inviteEmail = ref('')
const inviteRole  = ref('user')

// Deactivate
const deactivateOpen   = ref(false)
const deactivateTarget = ref(null)
const editRow          = ref(null)

function confirmDeactivate(row) {
  deactivateTarget.value = row
  deactivateOpen.value   = true
}

function sendInvite() {
  // Backend endpoint assumed at /api/admin/users:invite — server enforces audit + cap check.
  inviteEmail.value = ''
  inviteRole.value  = 'user'
}

function doDeactivate() {
  // Endpoint assumed at DELETE /api/admin/users/{id}; server enforces.
  const id = deactivateTarget.value?.id
  users.value = users.value.map((u) => (u.id === id ? { ...u, status: 'disabled' } : u))
  deactivateTarget.value = null
}

function load() {
  // Placeholder: server endpoint /api/admin/users with filters
}

onMounted(load)
</script>
