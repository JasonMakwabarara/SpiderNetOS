<template>
  <div class="space-y-4">
    <div class="bg-blue-50 rounded-lg p-4 mb-4">
      <p class="text-sm text-blue-800">
        Invite teammates to collaborate. You can skip this and add users later from Admin settings.
      </p>
    </div>

    <div>
      <label class="block text-sm font-medium text-gray-700 mb-2">Team Members</label>
      <div class="space-y-2">
        <div
          v-for="(member, index) in members"
          :key="index"
          class="flex gap-2"
        >
          <input
            v-model="member.email"
            type="email"
            class="flex-1 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            placeholder="colleague@company.com"
          />
          <select
            v-model="member.role"
            class="w-32 px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
          >
            <option value="admin">Admin</option>
            <option value="user">User</option>
          </select>
          <button
            v-if="members.length > 1"
            class="p-2 text-gray-400 hover:text-red-500 transition-colors"
            @click="removeMember(index)"
          >
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
            </svg>
          </button>
        </div>
      </div>
      <button
        class="mt-2 text-sm text-indigo-600 hover:text-indigo-700 font-medium"
        @click="addMember"
      >
        + Add another
      </button>
    </div>

    <div class="pt-4 border-t border-gray-100">
      <div class="flex items-center justify-between text-sm">
        <span class="text-gray-600">{{ validEmails.length }} valid invitation(s)</span>
        <span v-if="adminCount > 0" class="text-indigo-600">{{ adminCount }} admin(s)</span>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'

const props = defineProps({
  modelValue: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['update:modelValue'])

const members = ref([
  { email: '', role: 'user' },
])

const validEmails = computed(() => {
  return members.value.filter(m => isValidEmail(m.email))
})

const adminCount = computed(() => {
  return members.value.filter(m => m.role === 'admin' && isValidEmail(m.email)).length
})

function isValidEmail(email) {
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
}

function addMember() {
  members.value.push({ email: '', role: 'user' })
}

function removeMember(index) {
  members.value.splice(index, 1)
}

// Emit formatted data
watch(members, (newVal) => {
  const validMembers = newVal.filter(m => isValidEmail(m.email))
  emit('update:modelValue', {
    emails: validMembers.map(m => m.email),
    roles: validMembers.map(m => m.role),
    invitations: validMembers,
  })
}, { deep: true })

// Initialize from props
watch(() => props.modelValue, (newVal) => {
  if (newVal?.invitations?.length > 0) {
    members.value = newVal.invitations.map(i => ({
      email: i.email || '',
      role: i.role || 'user',
    }))
    // Ensure at least one row
    if (members.value.length === 0) {
      members.value = [{ email: '', role: 'user' }]
    }
  }
}, { immediate: true })
</script>
