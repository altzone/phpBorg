<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900">Backup Jobs</h1>
        <p class="mt-2 text-gray-600">Schedule automatic backups for your repositories</p>
      </div>
      <button @click="openJobModal()" class="btn btn-primary">
        <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Create Backup Job
      </button>
    </div>

    <!-- Error Message -->
    <div v-if="error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
      <div class="flex justify-between items-start">
        <p class="text-sm text-red-800">{{ error }}</p>
        <button @click="error = null" class="text-red-500 hover:text-red-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Info Card -->
    <div class="card mb-6 bg-blue-50 border-blue-200">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3">
          <h3 class="text-sm font-medium text-blue-800">About Backup Jobs</h3>
          <div class="mt-2 text-sm text-blue-700">
            <p>Backup jobs allow you to schedule automatic backups for your repositories. Configure when and how often backups should run.</p>
            <ul class="list-disc pl-5 mt-2 space-y-1">
              <li><strong>Manual:</strong> Backups are triggered manually only</li>
              <li><strong>Daily:</strong> Runs every day at a specified time</li>
              <li><strong>Weekly:</strong> Runs once per week on a specific day</li>
              <li><strong>Monthly:</strong> Runs once per month on a specific day</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600">Loading backup jobs...</p>
      </div>
    </div>

    <!-- Backup Jobs List -->
    <div v-else-if="backupJobs.length > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div v-for="job in backupJobs" :key="job.id" class="card">
        <div class="flex justify-between items-start mb-4">
          <div class="flex items-center gap-2 flex-1">
            <h3 class="text-lg font-semibold text-gray-900">{{ job.name }}</h3>
            <span
              :class="[
                'px-2 py-1 text-xs font-semibold rounded',
                job.enabled ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
              ]"
            >
              {{ job.enabled ? 'Enabled' : 'Disabled' }}
            </span>
            <span
              v-if="job.schedule_type !== 'manual'"
              class="px-2 py-1 text-xs font-semibold bg-blue-100 text-blue-800 rounded"
            >
              Scheduled
            </span>
          </div>
        </div>

        <!-- Repository Info -->
        <div v-if="job.repository" class="mb-3 p-2 bg-gray-50 rounded text-sm">
          <div class="font-medium text-gray-700">Repository:</div>
          <div class="text-gray-600">{{ job.repository.type || 'Backup' }} @ {{ job.repository.repo_path }}</div>
        </div>

        <!-- Schedule Info -->
        <div class="space-y-2 mb-4">
          <div class="flex justify-between text-sm">
            <span class="text-gray-600">Schedule:</span>
            <span class="font-semibold text-gray-900">{{ job.schedule_description }}</span>
          </div>

          <div v-if="job.next_run_at" class="flex justify-between text-sm">
            <span class="text-gray-600">Next Run:</span>
            <span class="font-semibold text-blue-700">{{ formatDateTime(job.next_run_at) }}</span>
          </div>

          <div v-if="job.last_run_at" class="flex justify-between text-sm">
            <span class="text-gray-600">Last Run:</span>
            <span :class="[
              'font-semibold',
              job.last_status === 'success' ? 'text-green-700' :
              job.last_status === 'failure' ? 'text-red-700' :
              job.last_status === 'running' ? 'text-blue-700' : 'text-gray-700'
            ]">
              {{ formatDateTime(job.last_run_at) }}
              <span v-if="job.last_status === 'success'">✓</span>
              <span v-else-if="job.last_status === 'failure'">✗</span>
              <span v-else-if="job.last_status === 'running'">⏳</span>
            </span>
          </div>

          <!-- Notifications -->
          <div class="flex gap-4 text-xs text-gray-600 mt-2">
            <span v-if="job.notify_on_success" class="flex items-center gap-1">
              <svg class="w-4 h-4 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
              </svg>
              Notify on success
            </span>
            <span v-if="job.notify_on_failure" class="flex items-center gap-1">
              <svg class="w-4 h-4 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
              </svg>
              Notify on failure
            </span>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex gap-2 pt-4 border-t">
          <button
            @click="toggleJob(job)"
            :class="[
              'btn flex-1 text-sm',
              job.enabled ? 'btn-secondary' : 'btn-primary'
            ]"
          >
            {{ job.enabled ? 'Disable' : 'Enable' }}
          </button>
          <button @click="openJobModal(job)" class="btn btn-secondary flex-1 text-sm">
            Edit
          </button>
          <button
            @click="deleteJob(job)"
            class="btn btn-secondary flex-1 text-sm text-red-600 hover:bg-red-50"
          >
            Delete
          </button>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="card text-center py-12">
      <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <h3 class="mt-2 text-sm font-medium text-gray-900">No backup jobs</h3>
      <p class="mt-1 text-sm text-gray-500">Get started by creating a new backup job.</p>
      <div class="mt-6">
        <button @click="openJobModal()" class="btn btn-primary">
          Create Backup Job
        </button>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <div v-if="showJobModal" class="fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 p-4">
      <div class="bg-white rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6">
          <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-900">
              {{ editingJob ? 'Edit Backup Job' : 'Create Backup Job' }}
            </h2>
            <button @click="closeJobModal" class="text-gray-400 hover:text-gray-600">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <form @submit.prevent="saveJob" class="space-y-4">
            <!-- Job Name -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Job Name *</label>
              <input v-model="jobForm.name" type="text" required class="input w-full" placeholder="e.g., MySQL Daily Backup" />
            </div>

            <!-- Repository Selection -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Repository *</label>
              <select v-model="jobForm.repository_id" required class="input w-full" :disabled="editingJob">
                <option value="">Select a repository</option>
                <option v-for="repo in repositories" :key="repo.id" :value="repo.id">
                  {{ repo.type || 'Backup' }} - {{ repo.repo_path }}
                </option>
              </select>
              <p v-if="editingJob" class="text-xs text-gray-500 mt-1">Repository cannot be changed after creation</p>
            </div>

            <!-- Schedule Type -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Schedule Type *</label>
              <select v-model="jobForm.schedule_type" required class="input w-full">
                <option value="manual">Manual (run on demand)</option>
                <option value="daily">Daily</option>
                <option value="weekly">Weekly</option>
                <option value="monthly">Monthly</option>
              </select>
            </div>

            <!-- Time (for all scheduled types) -->
            <div v-if="jobForm.schedule_type !== 'manual'">
              <label class="block text-sm font-medium text-gray-700 mb-2">Time *</label>
              <input v-model="jobForm.schedule_time" type="time" required class="input w-full" />
            </div>

            <!-- Days of Week (for weekly) - Multi-select -->
            <div v-if="jobForm.schedule_type === 'weekly'">
              <label class="block text-sm font-medium text-gray-700 mb-2">Days of Week *</label>
              <div class="grid grid-cols-7 gap-2">
                <label v-for="(day, index) in weekDays" :key="index" 
                       class="flex items-center justify-center p-2 border rounded cursor-pointer hover:bg-blue-50"
                       :class="{ 'bg-blue-100 border-blue-500': jobForm.selected_weekdays.includes(index + 1) }">
                  <input type="checkbox" 
                         :value="index + 1" 
                         v-model="jobForm.selected_weekdays"
                         class="sr-only" />
                  <span class="text-xs font-medium">{{ day.short }}</span>
                </label>
              </div>
              <p class="text-xs text-gray-500 mt-1">Select one or more days for backup</p>
            </div>

            <!-- Days of Month (for monthly) - Multi-select -->
            <div v-if="jobForm.schedule_type === 'monthly'">
              <label class="block text-sm font-medium text-gray-700 mb-2">Days of Month *</label>
              <div class="grid grid-cols-7 gap-2 max-h-48 overflow-y-auto">
                <label v-for="day in 31" :key="day"
                       class="flex items-center justify-center p-2 border rounded cursor-pointer hover:bg-blue-50 min-w-[40px]"
                       :class="{ 'bg-blue-100 border-blue-500': jobForm.selected_monthdays.includes(day) }">
                  <input type="checkbox" 
                         :value="day" 
                         v-model="jobForm.selected_monthdays"
                         class="sr-only" />
                  <span class="text-xs font-medium">{{ day }}</span>
                </label>
              </div>
              <p class="text-xs text-gray-500 mt-1">Select one or more days. If day doesn't exist in month, last day will be used</p>
            </div>

            <!-- Notifications -->
            <div class="border-t pt-4">
              <label class="block text-sm font-medium text-gray-700 mb-3">Notifications</label>
              <div class="space-y-2">
                <div class="flex items-center">
                  <input v-model="jobForm.notify_on_success" type="checkbox" id="notify-success" class="mr-2" />
                  <label for="notify-success" class="text-sm text-gray-700">Send notification on successful backup</label>
                </div>
                <div class="flex items-center">
                  <input v-model="jobForm.notify_on_failure" type="checkbox" id="notify-failure" class="mr-2" />
                  <label for="notify-failure" class="text-sm text-gray-700">Send notification on failed backup</label>
                </div>
              </div>
            </div>

            <!-- Enabled -->
            <div class="border-t pt-4">
              <div class="flex items-center">
                <input v-model="jobForm.enabled" type="checkbox" id="job-enabled" class="mr-2" />
                <label for="job-enabled" class="text-sm font-medium text-gray-700">Enable this job</label>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-3 pt-4">
              <button type="button" @click="closeJobModal" class="btn btn-secondary flex-1">
                Cancel
              </button>
              <button type="submit" :disabled="saving" class="btn btn-primary flex-1">
                {{ saving ? 'Saving...' : (editingJob ? 'Update Job' : 'Create Job') }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { backupJobsService } from '../services/backupJobs'
import { repositoryService } from '../services/repository'

const backupJobs = ref([])
const repositories = ref([])
const loading = ref(true)
const error = ref(null)
const showJobModal = ref(false)
const editingJob = ref(null)
const saving = ref(false)

const jobForm = ref({
  name: '',
  repository_id: '',
  schedule_type: 'manual',
  schedule_time: '02:00',
  schedule_day_of_week: 1,
  schedule_day_of_month: 1,
  selected_weekdays: [1], // Array for multiple weekdays
  selected_monthdays: [1], // Array for multiple month days
  enabled: true,
  notify_on_success: false,
  notify_on_failure: true,
})

// Week days configuration
const weekDays = [
  { short: 'Mon', full: 'Monday' },
  { short: 'Tue', full: 'Tuesday' },
  { short: 'Wed', full: 'Wednesday' },
  { short: 'Thu', full: 'Thursday' },
  { short: 'Fri', full: 'Friday' },
  { short: 'Sat', full: 'Saturday' },
  { short: 'Sun', full: 'Sunday' }
]

onMounted(async () => {
  await loadBackupJobs()
  await loadRepositories()
})

async function loadBackupJobs() {
  try {
    loading.value = true
    error.value = null
    const data = await backupJobsService.getBackupJobs()
    backupJobs.value = data.backup_jobs || []
  } catch (err) {
    error.value = err.response?.data?.error?.message || 'Failed to load backup jobs'
  } finally {
    loading.value = false
  }
}

async function loadRepositories() {
  try {
    const data = await repositoryService.getRepositories()
    repositories.value = data.repositories || []
  } catch (err) {
    console.error('Failed to load repositories:', err)
  }
}

function openJobModal(job = null) {
  editingJob.value = job
  if (job) {
    // Convert single day to array for backward compatibility
    const weekdays = job.selected_weekdays || (job.schedule_day_of_week ? [job.schedule_day_of_week] : [1])
    const monthdays = job.selected_monthdays || (job.schedule_day_of_month ? [job.schedule_day_of_month] : [1])
    
    jobForm.value = {
      name: job.name,
      repository_id: job.repository_id,
      schedule_type: job.schedule_type,
      schedule_time: job.schedule_time || '02:00',
      schedule_day_of_week: job.schedule_day_of_week || 1,
      schedule_day_of_month: job.schedule_day_of_month || 1,
      selected_weekdays: weekdays,
      selected_monthdays: monthdays,
      enabled: job.enabled,
      notify_on_success: job.notify_on_success,
      notify_on_failure: job.notify_on_failure,
    }
  } else {
    jobForm.value = {
      name: '',
      repository_id: '',
      schedule_type: 'manual',
      schedule_time: '02:00',
      schedule_day_of_week: 1,
      schedule_day_of_month: 1,
      selected_weekdays: [1],
      selected_monthdays: [1],
      enabled: true,
      notify_on_success: false,
      notify_on_failure: true,
    }
  }
  showJobModal.value = true
}

function closeJobModal() {
  showJobModal.value = false
  editingJob.value = null
}

async function saveJob() {
  try {
    saving.value = true
    error.value = null

    // Validate at least one day is selected
    if (jobForm.value.schedule_type === 'weekly' && jobForm.value.selected_weekdays.length === 0) {
      error.value = 'Please select at least one weekday'
      saving.value = false
      return
    }
    if (jobForm.value.schedule_type === 'monthly' && jobForm.value.selected_monthdays.length === 0) {
      error.value = 'Please select at least one day of month'
      saving.value = false
      return
    }

    const data = {
      name: jobForm.value.name,
      repository_id: parseInt(jobForm.value.repository_id),
      schedule_type: jobForm.value.schedule_type,
      enabled: jobForm.value.enabled,
      notify_on_success: jobForm.value.notify_on_success,
      notify_on_failure: jobForm.value.notify_on_failure,
    }

    // Add schedule parameters based on type
    if (jobForm.value.schedule_type !== 'manual') {
      data.schedule_time = jobForm.value.schedule_time + ':00' // Add seconds
    }

    if (jobForm.value.schedule_type === 'weekly') {
      // Send array of selected weekdays for the new multi-day support
      data.weekdays_array = jobForm.value.selected_weekdays
      // Keep backward compatibility
      data.schedule_day_of_week = jobForm.value.selected_weekdays[0] || 1
    }

    if (jobForm.value.schedule_type === 'monthly') {
      // Send array of selected month days for the new multi-day support
      data.monthdays_array = jobForm.value.selected_monthdays
      // Keep backward compatibility
      data.schedule_day_of_month = jobForm.value.selected_monthdays[0] || 1
    }

    if (editingJob.value) {
      await backupJobsService.updateBackupJob(editingJob.value.id, data)
    } else {
      await backupJobsService.createBackupJob(data)
    }

    closeJobModal()
    await loadBackupJobs()
  } catch (err) {
    error.value = err.response?.data?.error?.message || 'Failed to save backup job'
  } finally {
    saving.value = false
  }
}

async function toggleJob(job) {
  try {
    error.value = null
    await backupJobsService.toggleBackupJob(job.id)
    await loadBackupJobs()
  } catch (err) {
    error.value = err.response?.data?.error?.message || 'Failed to toggle backup job'
  }
}

async function deleteJob(job) {
  if (!confirm(`Are you sure you want to delete "${job.name}"?`)) {
    return
  }

  try {
    error.value = null
    await backupJobsService.deleteBackupJob(job.id)
    await loadBackupJobs()
  } catch (err) {
    error.value = err.response?.data?.error?.message || 'Failed to delete backup job'
  }
}

function formatDateTime(dateString) {
  if (!dateString) return 'Never'
  const date = new Date(dateString)
  return date.toLocaleString()
}
</script>
