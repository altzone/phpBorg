<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $t('backup_jobs.title') }}</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">{{ $t('backup_jobs.subtitle') }}</p>
      </div>
      <RouterLink to="/backup-wizard" class="btn btn-primary">
        <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        {{ $t('backup_jobs.new_wizard') }}
      </RouterLink>
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
    <div class="card mb-6 bg-blue-50 dark:bg-blue-900/20 border-blue-200 dark:border-blue-800">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3">
          <h3 class="text-sm font-medium text-blue-800 dark:text-blue-300">{{ $t('backup_jobs.about_title') }}</h3>
          <div class="mt-2 text-sm text-blue-700 dark:text-blue-400">
            <p>{{ $t('backup_jobs.about_description') }}</p>
            <ul class="list-disc pl-5 mt-2 space-y-1">
              <li>{{ $t('backup_jobs.about_manual') }}</li>
              <li>{{ $t('backup_jobs.about_daily') }}</li>
              <li>{{ $t('backup_jobs.about_weekly') }}</li>
              <li>{{ $t('backup_jobs.about_monthly') }}</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400">{{ $t('backup_jobs.loading') }}</p>
      </div>
    </div>

    <!-- Backup Jobs List -->
    <div v-else-if="backupJobs.length > 0" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <div v-for="job in backupJobs" :key="job.id" class="card">
        <div class="flex justify-between items-start mb-4">
          <div class="flex items-center gap-2 flex-1">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ job.name }}</h3>
            <span
              :class="[
                'px-2 py-1 text-xs font-semibold rounded',
                job.enabled ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300' : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
              ]"
            >
              {{ job.enabled ? $t('backup_jobs.enabled') : $t('backup_jobs.disabled') }}
            </span>
            <span
              v-if="job.schedule_type !== 'manual'"
              class="px-2 py-1 text-xs font-semibold bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300 rounded"
            >
              {{ $t('backup_jobs.scheduled') }}
            </span>
          </div>
        </div>

        <!-- Repository Info -->
        <div v-if="job.repository" class="mb-3 p-2 bg-gray-50 dark:bg-gray-700 rounded text-sm">
          <div class="font-medium text-gray-700 dark:text-gray-300">{{ $t('backup_jobs.repository') }}:</div>
          <div class="text-gray-600 dark:text-gray-400">{{ job.repository.type || 'Backup' }} @ {{ job.repository.repo_path }}</div>
        </div>

        <!-- Schedule Info -->
        <div class="space-y-2 mb-4">
          <div class="flex justify-between text-sm">
            <span class="text-gray-600 dark:text-gray-400">{{ $t('backup_jobs.schedule') }}:</span>
            <span class="font-semibold text-gray-900 dark:text-gray-100">
              {{ formatScheduleDescription(job) }}
              <!-- Show indicator if multi-selection -->
              <span v-if="job.selected_weekdays && job.selected_weekdays.length > 1"
                    class="ml-1 text-xs text-blue-600 dark:text-blue-400"
                    :title="$t('backup_jobs.selected_days_tooltip', { days: job.selected_weekdays.map(d => weekDays[d-1].full).join(', ') })">
                {{ $t('backup_jobs.days_count', { count: job.selected_weekdays.length }) }}
              </span>
              <span v-else-if="job.selected_monthdays && job.selected_monthdays.length > 1"
                    class="ml-1 text-xs text-blue-600 dark:text-blue-400"
                    :title="$t('backup_jobs.selected_days_tooltip', { days: job.selected_monthdays.join(', ') })">
                {{ $t('backup_jobs.days_count', { count: job.selected_monthdays.length }) }}
              </span>
            </span>
          </div>

          <div v-if="job.next_run_at" class="flex justify-between text-sm">
            <span class="text-gray-600 dark:text-gray-400">{{ $t('backup_jobs.next_run') }}:</span>
            <span class="font-semibold text-blue-700 dark:text-blue-400">{{ formatDateTime(job.next_run_at) }}</span>
          </div>

          <div v-if="job.last_run_at" class="flex justify-between text-sm">
            <span class="text-gray-600 dark:text-gray-400">{{ $t('backup_jobs.last_run') }}:</span>
            <span :class="[
              'font-semibold',
              job.last_status === 'success' ? 'text-green-700 dark:text-green-400' :
              job.last_status === 'failure' ? 'text-red-700 dark:text-red-400' :
              job.last_status === 'running' ? 'text-blue-700 dark:text-blue-400' : 'text-gray-700 dark:text-gray-300'
            ]">
              {{ formatDateTime(job.last_run_at) }}
              <span v-if="job.last_status === 'success'">✓</span>
              <span v-else-if="job.last_status === 'failure'">✗</span>
              <span v-else-if="job.last_status === 'running'">⏳</span>
            </span>
          </div>

          <!-- Notifications -->
          <div class="flex gap-4 text-xs text-gray-600 dark:text-gray-400 mt-2">
            <span v-if="job.notify_on_success" class="flex items-center gap-1">
              <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="currentColor" viewBox="0 0 20 20">
                <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
              </svg>
              {{ $t('backup_jobs.notify_on_success') }}
            </span>
            <span v-if="job.notify_on_failure" class="flex items-center gap-1">
              <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="currentColor" viewBox="0 0 20 20">
                <path d="M2.003 5.884L10 9.882l7.997-3.998A2 2 0 0016 4H4a2 2 0 00-1.997 1.884z" />
                <path d="M18 8.118l-8 4-8-4V14a2 2 0 002 2h12a2 2 0 002-2V8.118z" />
              </svg>
              {{ $t('backup_jobs.notify_on_failure') }}
            </span>
          </div>
        </div>

        <!-- Actions -->
        <div class="flex gap-2 pt-4 border-t border-gray-200 dark:border-gray-700">
          <button
            @click="runJobNow(job)"
            class="btn btn-primary flex-1 text-sm"
            :disabled="runningJobs.includes(job.id)"
          >
            {{ runningJobs.includes(job.id) ? $t('backup_jobs.running') : $t('backup_jobs.run_now') }}
          </button>
          <button
            @click="toggleJob(job)"
            :class="[
              'btn flex-1 text-sm',
              job.enabled ? 'btn-secondary' : 'btn-primary'
            ]"
          >
            {{ job.enabled ? $t('backup_jobs.disable') : $t('backup_jobs.enable') }}
          </button>
          <button @click="openJobModal(job)" class="btn btn-secondary flex-1 text-sm">
            {{ $t('common.edit') }}
          </button>
          <button
            @click="deleteJob(job)"
            class="btn btn-secondary flex-1 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30"
          >
            {{ $t('common.delete') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else class="card text-center py-12">
      <svg class="mx-auto h-12 w-12 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <h3 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $t('backup_jobs.no_jobs') }}</h3>
      <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $t('backup_jobs.get_started') }}</p>
      <div class="mt-6">
        <button @click="openJobModal()" class="btn btn-primary">
          {{ $t('backup_jobs.create_job') }}
        </button>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <div v-if="showJobModal" class="fixed inset-0 bg-gray-600 dark:bg-gray-900 bg-opacity-75 flex items-center justify-center z-50 p-4">
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <div class="p-6">
          <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">
              {{ editingJob ? $t('backup_jobs.edit_job') : $t('backup_jobs.create_job') }}
            </h2>
            <button @click="closeJobModal" class="text-gray-400 dark:text-gray-500 hover:text-gray-600 dark:hover:text-gray-400">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <form @submit.prevent="saveJob" class="space-y-4">
            <!-- Job Name -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_jobs.job_name') }} *</label>
              <input v-model="jobForm.name" type="text" required class="input w-full" :placeholder="$t('backup_jobs.job_name_placeholder')" />
            </div>

            <!-- Repository Selection -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_jobs.repository') }} *</label>
              <select v-model="jobForm.repository_id" required class="input w-full" :disabled="editingJob">
                <option value="">{{ $t('backup_jobs.repository_select') }}</option>
                <option v-for="repo in repositories" :key="repo.id" :value="repo.id">
                  {{ repo.type || 'Backup' }} - {{ repo.repo_path }}
                </option>
              </select>
              <p v-if="editingJob" class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('backup_jobs.repository_locked') }}</p>
            </div>

            <!-- Schedule Type -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_jobs.schedule_type') }} *</label>
              <select v-model="jobForm.schedule_type" required class="input w-full">
                <option value="manual">{{ $t('backup_jobs.schedule_manual') }}</option>
                <option value="daily">{{ $t('backup_jobs.schedule_daily') }}</option>
                <option value="weekly">{{ $t('backup_jobs.schedule_weekly') }}</option>
                <option value="monthly">{{ $t('backup_jobs.schedule_monthly') }}</option>
              </select>
            </div>

            <!-- Time (for all scheduled types) -->
            <div v-if="jobForm.schedule_type !== 'manual'">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_jobs.time') }} *</label>
              <input v-model="jobForm.schedule_time" type="time" required class="input w-full" />
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                ⏰ {{ $t('backup_jobs.time_help') }}
              </p>
            </div>

            <!-- Days of Week (for weekly) - Multi-select -->
            <div v-if="jobForm.schedule_type === 'weekly'">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_jobs.days_of_week') }} *</label>
              <div class="grid grid-cols-7 gap-2">
                <label v-for="(day, index) in weekDays" :key="index"
                       class="flex items-center justify-center p-2 border rounded cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/30 dark:border-gray-600"
                       :class="{ 'bg-blue-100 border-blue-500 dark:bg-blue-900/30 dark:border-blue-500': jobForm.selected_weekdays.includes(index + 1) }">
                  <input type="checkbox"
                         :value="index + 1"
                         v-model="jobForm.selected_weekdays"
                         class="sr-only" />
                  <span class="text-xs font-medium">{{ day.short }}</span>
                </label>
              </div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('backup_jobs.select_one_or_more') }}</p>
            </div>

            <!-- Days of Month (for monthly) - Multi-select -->
            <div v-if="jobForm.schedule_type === 'monthly'">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_jobs.days_of_month') }} *</label>
              <div class="grid grid-cols-7 gap-2 max-h-48 overflow-y-auto">
                <label v-for="day in 31" :key="day"
                       class="flex items-center justify-center p-2 border rounded cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/30 min-w-[40px] dark:border-gray-600"
                       :class="{ 'bg-blue-100 border-blue-500 dark:bg-blue-900/30 dark:border-blue-500': jobForm.selected_monthdays.includes(day) }">
                  <input type="checkbox"
                         :value="day"
                         v-model="jobForm.selected_monthdays"
                         class="sr-only" />
                  <span class="text-xs font-medium">{{ day }}</span>
                </label>
              </div>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('backup_jobs.month_last_day_note') }}</p>
            </div>

            <!-- Notifications -->
            <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">{{ $t('backup_jobs.notifications') }}</label>
              <div class="space-y-2">
                <div class="flex items-center">
                  <input v-model="jobForm.notify_on_success" type="checkbox" id="notify-success" class="mr-2" />
                  <label for="notify-success" class="text-sm text-gray-700 dark:text-gray-300">{{ $t('backup_jobs.send_notification_success') }}</label>
                </div>
                <div class="flex items-center">
                  <input v-model="jobForm.notify_on_failure" type="checkbox" id="notify-failure" class="mr-2" />
                  <label for="notify-failure" class="text-sm text-gray-700 dark:text-gray-300">{{ $t('backup_jobs.send_notification_failure') }}</label>
                </div>
              </div>
            </div>

            <!-- Enabled -->
            <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
              <div class="flex items-center">
                <input v-model="jobForm.enabled" type="checkbox" id="job-enabled" class="mr-2" />
                <label for="job-enabled" class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $t('backup_jobs.enable_job') }}</label>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-3 pt-4">
              <button type="button" @click="closeJobModal" class="btn btn-secondary flex-1">
                {{ $t('common.cancel') }}
              </button>
              <button type="submit" :disabled="saving" class="btn btn-primary flex-1">
                {{ saving ? $t('backup_jobs.saving') : (editingJob ? $t('backup_jobs.update') : $t('backup_jobs.create') ) }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { backupJobsService } from '../services/backupJobs'
import { repositoryService } from '../services/repository'
import { useToastStore } from '@/stores/toast'
import { useConfirmStore } from '@/stores/confirm'

const { t } = useI18n()
const toast = useToastStore()
const confirmDialog = useConfirmStore()

const backupJobs = ref([])
const repositories = ref([])
const loading = ref(true)
const error = ref(null)
const showJobModal = ref(false)
const editingJob = ref(null)
const saving = ref(false)
const runningJobs = ref([]) // Track jobs currently running

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

// Week days configuration - use computed for i18n
const weekDays = computed(() => [
  { short: t('backup_jobs.weekdays.mon'), full: t('backup_jobs.weekdays.monday') },
  { short: t('backup_jobs.weekdays.tue'), full: t('backup_jobs.weekdays.tuesday') },
  { short: t('backup_jobs.weekdays.wed'), full: t('backup_jobs.weekdays.wednesday') },
  { short: t('backup_jobs.weekdays.thu'), full: t('backup_jobs.weekdays.thursday') },
  { short: t('backup_jobs.weekdays.fri'), full: t('backup_jobs.weekdays.friday') },
  { short: t('backup_jobs.weekdays.sat'), full: t('backup_jobs.weekdays.saturday') },
  { short: t('backup_jobs.weekdays.sun'), full: t('backup_jobs.weekdays.sunday') }
])

// Format schedule description with translations
function formatScheduleDescription(job) {
  if (job.schedule_type === 'manual') {
    return t('backup_jobs.schedule_manual')
  }

  const scheduleTypes = {
    'daily': t('backup_wizard.schedule_types.daily'),
    'weekly': t('backup_wizard.schedule_types.weekly'),
    'monthly': t('backup_wizard.schedule_types.monthly')
  }

  const typeName = scheduleTypes[job.schedule_type] || job.schedule_type
  const time = job.schedule_time || ''

  return `${typeName.charAt(0).toUpperCase() + typeName.slice(1)} ${t('backup_wizard.review.schedule_at')} ${time}`
}

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
    error.value = err.response?.data?.error?.message || t('backup_jobs.error_failed_to_load')
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
    
    // Handle schedule_time format - strip seconds if present for UI input
    let scheduleTime = job.schedule_time || '02:00'
    if (scheduleTime && scheduleTime.split(':').length === 3) {
      // Remove seconds for the time input (HH:MM:SS -> HH:MM)
      scheduleTime = scheduleTime.substring(0, 5)
    }
    
    jobForm.value = {
      name: job.name,
      repository_id: job.repository_id,
      schedule_type: job.schedule_type,
      schedule_time: scheduleTime,
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
      error.value = t('backup_jobs.error_at_least_one_weekday')
      saving.value = false
      return
    }
    if (jobForm.value.schedule_type === 'monthly' && jobForm.value.selected_monthdays.length === 0) {
      error.value = t('backup_jobs.error_at_least_one_monthday')
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
      // Check if time already has seconds (HH:MM:SS format)
      if (jobForm.value.schedule_time && jobForm.value.schedule_time.split(':').length === 3) {
        data.schedule_time = jobForm.value.schedule_time // Already has seconds
      } else {
        data.schedule_time = jobForm.value.schedule_time + ':00' // Add seconds
      }
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
    error.value = err.response?.data?.error?.message || t('backup_jobs.error_failed_to_save')
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
    error.value = err.response?.data?.error?.message || t('backup_jobs.error_failed_to_toggle')
  }
}

async function deleteJob(job) {
  const confirmed = await confirmDialog.show({
    title: t('backup_jobs.delete_job'),
    message: t('backup_jobs.delete_confirm', { name: job.name }),
    confirmText: t('common.delete'),
    cancelText: t('common.cancel'),
    type: 'danger'
  })
  if (!confirmed) return

  try {
    error.value = null
    await backupJobsService.deleteBackupJob(job.id)
    await loadBackupJobs()
  } catch (err) {
    error.value = err.response?.data?.error?.message || t('backup_jobs.error_failed_to_delete')
  }
}

async function runJobNow(job) {
  try {
    error.value = null
    runningJobs.value.push(job.id)

    // Call the API to run the job immediately
    await backupJobsService.runBackupJob(job.id)

    // Show success message
    toast.success(t('backup_jobs.success_job_queued', { name: job.name }))

    // Reload to get updated status
    await loadBackupJobs()
  } catch (err) {
    error.value = err.response?.data?.error?.message || t('backup_jobs.error_failed_to_run')
  } finally {
    // Remove from running jobs after a delay
    setTimeout(() => {
      const index = runningJobs.value.indexOf(job.id)
      if (index > -1) {
        runningJobs.value.splice(index, 1)
      }
    }, 3000)
  }
}

function formatDateTime(dateString) {
  if (!dateString) return t('repositories.never')
  // Parse as local server time (add 'T' to force local interpretation, not UTC)
  const date = new Date(dateString.replace(' ', 'T'))
  // Format with explicit timezone display
  return date.toLocaleString('default', {
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
    timeZoneName: 'short'
  })
}
</script>
