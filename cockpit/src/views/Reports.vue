<template>
  <div class="p-6">
    <div class="flex justify-between items-center mb-6">
      <h1 class="text-2xl font-bold">?? Reports</h1>
      <button @click="generateReport" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">+ Generate New Report</button>
    </div>

    <div class="bg-white rounded-lg shadow p-6">
      <div v-if="loading" class="text-center py-8">Loading...</div>
      <div v-else-if="reports.length === 0" class="text-center py-8 text-gray-500">
        No reports yet. Click "Generate New Report" to create one.
      </div>
      <div v-else>
        <table class="min-w-full">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-4 py-2 text-left">Title</th>
              <th class="px-4 py-2 text-left">Type</th>
              <th class="px-4 py-2 text-left">Generated</th>
              <th class="px-4 py-2 text-left">Actions</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="report in reports" :key="report.id" class="border-t">
              <td class="px-4 py-2">{{ report.title }}</td>
              <td class="px-4 py-2">{{ report.type }}</td>
              <td class="px-4 py-2 text-sm text-gray-500">{{ new Date(report.generated_at).toLocaleString() }}</td>
              <td class="px-4 py-2">
                <button @click="downloadReport(report)" class="text-blue-500 hover:text-blue-700 mr-2">? Download</button>
                <button @click="viewReport(report)" class="text-green-500 hover:text-green-700">?? View</button>
                <button @click="deleteReport(report.id)" class="text-red-500 hover:text-red-700 ml-2">??</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- View Report Modal -->
    <div v-if="showModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
      <div class="bg-white rounded-lg p-6 w-full max-w-2xl max-h-[80vh] overflow-y-auto">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-xl font-bold">{{ selectedReport?.title }}</h2>
          <button @click="showModal = false" class="text-gray-500 hover:text-gray-700">?</button>
        </div>
        <div class="bg-gray-50 p-4 rounded">
          <pre class="whitespace-pre-wrap text-sm">{{ formattedData }}</pre>
        </div>
        <div class="mt-4 flex gap-2">
          <button @click="downloadReport(selectedReport)" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">? Download</button>
          <button @click="showModal = false" class="bg-gray-300 px-4 py-2 rounded hover:bg-gray-400">Close</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import api from '../services/api.js'

export default {
  data() {
    return {
      reports: [],
      loading: false,
      showModal: false,
      selectedReport: null
    }
  },
  mounted() {
    this.fetchReports()
  },
  computed: {
    formattedData() {
      if (!this.selectedReport) return ''
      const data = this.selectedReport.data
      if (typeof data === 'string') {
        try { return JSON.stringify(JSON.parse(data), null, 2) }
        catch { return data }
      }
      return JSON.stringify(data, null, 2)
    }
  },
  methods: {
    async fetchReports() {
      this.loading = true
      try {
        const { data } = await api.get('/reports')
        this.reports = data
      } catch (error) {
        console.error('Error fetching reports:', error)
      } finally {
        this.loading = false
      }
    },
    async generateReport() {
      try {
        const { data } = await api.post('/reports/generate')
        alert('? Report generated successfully!')
        await this.fetchReports()
      } catch (error) {
        alert('? Failed to generate report: ' + error.message)
      }
    },
    async deleteReport(id) {
      if (!confirm('Delete this report?')) return
      try {
        await api.delete(`/reports/${id}`)
        await this.fetchReports()
      } catch (error) {
        alert('? Failed to delete report: ' + error.message)
      }
    },
    viewReport(report) {
      this.selectedReport = report
      this.showModal = true
    },
    downloadReport(report) {
      const json = JSON.stringify(report, null, 2)
      const blob = new Blob([json], { type: 'application/json' })
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${report.title}_${new Date().toISOString().slice(0,10)}.json`
      a.click()
      URL.revokeObjectURL(url)
    }
  }
}
</script>
