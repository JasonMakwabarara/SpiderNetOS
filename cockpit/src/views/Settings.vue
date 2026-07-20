<template>
  <div class="settings p-6 space-y-6">
    <!-- Header -->
    <div>
      <h1 class="text-2xl font-bold text-gray-900">Settings</h1>
      <p class="text-sm text-gray-500 mt-1">
        Configure your SpiderNet OS instance
      </p>
    </div>

    <!-- Settings Tabs -->
    <div class="bg-white rounded-lg shadow-sm border border-gray-200">
      <div class="border-b border-gray-200">
        <nav class="flex -mb-px">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            @click="activeTab = tab.id"
            class="px-6 py-4 text-sm font-medium border-b-2 transition-colors"
            :class="activeTab === tab.id ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700'"
          >
            {{ tab.name }}
          </button>
        </nav>
      </div>

      <div class="p-6">
        <!-- General Settings -->
        <div v-if="activeTab === 'general'" class="space-y-6 max-w-lg">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Organization Name</label>
            <input
              v-model="settings.orgName"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Timezone</label>
            <select
              v-model="settings.timezone"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            >
              <option value="UTC">UTC</option>
              <option value="America/New_York">Eastern Time</option>
              <option value="America/Chicago">Central Time</option>
              <option value="America/Denver">Mountain Time</option>
              <option value="America/Los_Angeles">Pacific Time</option>
              <option value="Europe/London">London</option>
              <option value="Europe/Paris">Paris</option>
              <option value="Asia/Tokyo">Tokyo</option>
            </select>
          </div>

          <div class="flex items-center space-x-2">
            <input
              v-model="settings.autoSave"
              type="checkbox"
              id="autoSave"
              class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
            />
            <label for="autoSave" class="text-sm text-gray-700">Auto-save flows</label>
          </div>
        </div>

        <!-- Notifications -->
        <div v-else-if="activeTab === 'notifications'" class="space-y-4 max-w-lg">
          <div class="flex items-center justify-between py-3 border-b border-gray-100">
            <div>
              <p class="font-medium text-gray-900">Email Notifications</p>
              <p class="text-sm text-gray-500">Receive updates about system events</p>
            </div>
            <input
              v-model="settings.emailNotifications"
              type="checkbox"
              class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
            />
          </div>

          <div class="flex items-center justify-between py-3 border-b border-gray-100">
            <div>
              <p class="font-medium text-gray-900">Budget Alerts</p>
              <p class="text-sm text-gray-500">Notify when approaching usage limits</p>
            </div>
            <input
              v-model="settings.budgetAlerts"
              type="checkbox"
              class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
            />
          </div>

          <div class="flex items-center justify-between py-3">
            <div>
              <p class="font-medium text-gray-900">Anomaly Detection</p>
              <p class="text-sm text-gray-500">Alert on unusual system behavior</p>
            </div>
            <input
              v-model="settings.anomalyAlerts"
              type="checkbox"
              class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-500"
            />
          </div>
        </div>

        <!-- Security -->
        <div v-else-if="activeTab === 'security'" class="space-y-6 max-w-lg">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Current Password</label>
            <input
              type="password"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>
          
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">New Password</label>
            <input
              type="password"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Confirm New Password</label>
            <input
              type="password"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>

          <button class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700">
            Change Password
          </button>

          <MfaSettings />
        </div>

        <!-- API Keys -->
        <div v-else-if="activeTab === 'api'" class="space-y-6 max-w-lg">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">API Key</label>
            <div class="flex space-x-2">
              <input
                :value="'spider_' + '•'.repeat(32)"
                type="text"
                readonly
                class="flex-1 px-3 py-2 border border-gray-300 rounded-lg bg-gray-50"
              />
              <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                Copy
              </button>
              <button class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200">
                Regenerate
              </button>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Webhook URL</label>
            <input
              v-model="settings.webhookUrl"
              type="url"
              placeholder="https://your-app.com/webhooks/spidernet"
              class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-indigo-500 focus:border-indigo-500"
            />
          </div>
        </div>
      </div>

      <!-- Save Button -->
      <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 rounded-b-lg">
        <div class="flex items-center justify-between">
          <span v-if="saveMessage" class="text-sm" :class="saveMessage.type === 'error' ? 'text-red-600' : 'text-green-600'">
            {{ saveMessage.text }}
          </span>
          <div class="flex space-x-3 ml-auto">
            <button
              @click="resetSettings"
              class="px-4 py-2 text-gray-700 hover:bg-gray-100 rounded-lg"
            >
              Reset
            </button>
            <button
              @click="saveSettings"
              :disabled="isSaving"
              class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50"
            >
              {{ isSaving ? 'Saving...' : 'Save Changes' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive } from 'vue'
import MfaSettings from '../components/security/MfaSettings.vue'

const tabs = [
  { id: 'general', name: 'General' },
  { id: 'notifications', name: 'Notifications' },
  { id: 'security', name: 'Security' },
  { id: 'api', name: 'API & Webhooks' }
]

const activeTab = ref('general')
const isSaving = ref(false)
const saveMessage = ref(null)

const settings = reactive({
  orgName: 'My Organization',
  timezone: 'UTC',
  autoSave: true,
  emailNotifications: true,
  budgetAlerts: true,
  anomalyAlerts: true,
  webhookUrl: ''
})

function saveSettings() {
  isSaving.value = true
  setTimeout(() => {
    isSaving.value = false
    saveMessage.value = { type: 'success', text: 'Settings saved successfully!' }
    setTimeout(() => saveMessage.value = null, 3000)
  }, 500)
}

function resetSettings() {
  settings.orgName = 'My Organization'
  settings.timezone = 'UTC'
  settings.autoSave = true
  settings.emailNotifications = true
  settings.budgetAlerts = true
  settings.anomalyAlerts = true
  settings.webhookUrl = ''
}
</script>
