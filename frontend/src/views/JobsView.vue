<template>
  <div>
    <!-- Header -->
    <div class="mb-8">
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $t('jobs.title') }}</h1>
          <p class="mt-2 text-gray-600 dark:text-gray-400">{{ $t('jobs.subtitle') }}</p>
        </div>
        <!-- Toggle System Jobs -->
        <div class="flex items-center gap-3">
          <label class="flex items-center cursor-pointer">
            <input
              type="checkbox"
              v-model="showSystemJobs"
              class="sr-only peer"
            />
            <div class="relative w-11 h-6 bg-gray-200 peer-focus:outline-none peer-focus:ring-4 peer-focus:ring-blue-300 dark:peer-focus:ring-blue-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full rtl:peer-checked:after:-translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-blue-600"></div>
            <span class="ms-3 text-sm font-medium text-gray-900 dark:text-gray-300">{{ $t('jobs.show_system_jobs') }}</span>
          </label>
        </div>
      </div>
    </div>

    <!-- Error Message -->
    <div v-if="jobStore.error" class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
      <div class="flex justify-between items-start">
        <p class="text-sm text-red-800 dark:text-red-300">{{ jobStore.error }}</p>
        <button @click="jobStore.clearError()" class="text-red-500 hover:text-red-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-3 mb-8">
      <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3">
        <div class="text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('jobs.stats.total') }}</div>
        <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ filteredStats.total }}</div>
      </div>
      <div class="rounded-lg border border-blue-200 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20 p-3">
        <div class="text-xs text-blue-600 dark:text-blue-400 mb-1">{{ $t('jobs.stats.pending') }}</div>
        <div class="text-xl font-bold text-blue-900 dark:text-blue-300">{{ filteredStats.pending }}</div>
      </div>
      <div class="rounded-lg border border-yellow-200 dark:border-yellow-700 bg-yellow-50 dark:bg-yellow-900/20 p-3">
        <div class="text-xs text-yellow-600 dark:text-yellow-400 mb-1">{{ $t('jobs.stats.running') }}</div>
        <div class="text-xl font-bold text-yellow-900 dark:text-yellow-300">{{ filteredStats.running }}</div>
      </div>
      <div class="rounded-lg border border-green-200 dark:border-green-700 bg-green-50 dark:bg-green-900/20 p-3">
        <div class="text-xs text-green-600 dark:text-green-400 mb-1">{{ $t('jobs.stats.completed') }}</div>
        <div class="text-xl font-bold text-green-900 dark:text-green-300">{{ filteredStats.completed }}</div>
      </div>
      <div class="rounded-lg border border-red-200 dark:border-red-700 bg-red-50 dark:bg-red-900/20 p-3">
        <div class="text-xs text-red-600 dark:text-red-400 mb-1">{{ $t('jobs.stats.failed') }}</div>
        <div class="text-xl font-bold text-red-900 dark:text-red-300">{{ filteredStats.failed }}</div>
      </div>
      <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3">
        <div class="text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('jobs.stats.cancelled') }}</div>
        <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ filteredStats.cancelled }}</div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="jobStore.loading && !jobStore.jobs.length" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400">{{ $t('jobs.loading_jobs') }}</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="!filteredJobs.length" class="card">
      <div class="text-center py-16 text-gray-500 dark:text-gray-400">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">{{ $t('jobs.no_jobs') }}</h3>
        <p class="text-sm">{{ showSystemJobs ? $t('jobs.no_jobs_msg') : $t('jobs.no_user_jobs_msg') }}</p>
      </div>
    </div>

    <!-- Jobs List - Compact Cards -->
    <div v-else class="space-y-3">
      <div
        v-for="job in filteredJobs"
        :key="job.id"
        class="bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg hover:shadow-md transition-shadow"
      >
        <!-- Compact Header -->
        <div class="p-4 cursor-pointer" @click="selectedJob = job">
          <div class="flex items-center justify-between">
            <div class="flex-1 flex items-center gap-3">
              <!-- Job Type & Badges -->
              <div class="flex-1">
                <div class="flex items-center gap-2 mb-1 flex-wrap">
                  <h3 class="text-base font-semibold text-gray-900 dark:text-gray-100">
                    {{ formatJobType(job.type) }}
                  </h3>
                  <span v-if="isSystemJob(job.type)" class="px-2 py-0.5 text-xs font-semibold rounded bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300">
                    SYSTEM
                  </span>
                  <span v-if="job.worker_id" class="px-2 py-0.5 text-xs font-semibold rounded bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                    Worker #{{ job.worker_id }}
                  </span>
                  <span v-if="getJobServer(job)" class="px-2 py-0.5 text-xs font-semibold rounded bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300">
                    📦 {{ getJobServer(job) }}
                  </span>
                  <span v-if="getBackupType(job)" class="px-2 py-0.5 text-xs font-semibold rounded bg-cyan-100 text-cyan-800 dark:bg-cyan-900/30 dark:text-cyan-300">
                    {{ getBackupType(job) }}
                  </span>
                  <span v-if="getRepositoryName(job)" class="px-2 py-0.5 text-xs font-semibold rounded bg-violet-100 text-violet-800 dark:bg-violet-900/30 dark:text-violet-300">
                    📚 {{ getRepositoryName(job) }}
                  </span>
                  <span v-if="isManualTrigger(job)" class="px-2 py-0.5 text-xs font-semibold rounded bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300">
                    👤 Manual
                  </span>
                  <span v-else-if="isScheduledTrigger(job)" class="px-2 py-0.5 text-xs font-semibold rounded bg-teal-100 text-teal-800 dark:bg-teal-900/30 dark:text-teal-300">
                    ⏰ Scheduled
                  </span>
                </div>
                <div class="flex items-center gap-3 text-xs text-gray-600 dark:text-gray-400">
                  <span>ID: {{ job.id }}</span>
                  <span>•</span>
                  <span>{{ formatDateCompact(job.created_at) }}</span>
                  <span v-if="job.status === 'running' || job.status === 'completed'">•</span>
                  <span v-if="job.status === 'running' || job.status === 'completed'">{{ job.progress }}%</span>
                </div>
              </div>

              <!-- Actions -->
              <div class="flex items-center gap-2">
                <!-- Status Badge -->
                <span
                  :class="getStatusClass(job.status)"
                  class="px-3 py-1 text-sm font-semibold rounded"
                >
                  {{ formatStatus(job.status) }}
                </span>
                <button
                  v-if="job.status === 'running' || job.status === 'pending'"
                  @click.stop="handleCancel(job.id)"
                  class="text-red-600 dark:text-red-400 hover:text-red-800 dark:hover:text-red-300"
                  :title="$t('jobs.cancel_job')"
                >
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                </button>
                <button
                  v-if="job.status === 'failed' && job.attempts < job.max_attempts"
                  @click.stop="handleRetry(job.id)"
                  class="text-blue-600 dark:text-blue-400 hover:text-blue-800 dark:hover:text-blue-300"
                  :title="$t('jobs.retry_job')"
                >
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                  </svg>
                </button>
              </div>
            </div>
          </div>

          <!-- Progress Bar (compact) -->
          <div v-if="job.status === 'running' || job.status === 'completed'" class="mt-3">
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
              <div
                :class="job.status === 'completed' ? 'bg-green-600' : 'bg-primary-600'"
                class="h-1.5 rounded-full transition-all duration-300"
                :style="{ width: job.progress + '%' }"
              ></div>
            </div>
          </div>
        </div>

        <!-- Real-time Progress (compact inline) -->
        <div v-if="job.status === 'running' && jobStore.getProgressInfo(job.id)" class="px-4 pb-4">
          <div class="p-2 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded text-xs">
            <div class="flex items-center justify-between gap-4 flex-wrap">
              <div class="flex items-center gap-3">
                <svg class="w-3 h-3 text-blue-600 dark:text-blue-400 animate-spin flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                <span class="text-blue-900 dark:text-blue-300">
                  <span class="font-semibold">{{ jobStore.getProgressInfo(job.id).files_count || 0 }}</span> files
                </span>
                <span class="text-gray-400 dark:text-gray-600">•</span>
                <span class="text-blue-900 dark:text-blue-300">
                  <span class="font-mono">{{ formatBytes(jobStore.getProgressInfo(job.id).original_size || 0) }}</span>
                  <span class="text-gray-500 dark:text-gray-500 mx-1">→</span>
                  <span class="font-mono">{{ formatBytes(jobStore.getProgressInfo(job.id).compressed_size || 0) }}</span>
                </span>
                <span class="text-green-600 dark:text-green-400 font-semibold">({{ calculateCompressionRatio(jobStore.getProgressInfo(job.id).original_size, jobStore.getProgressInfo(job.id).compressed_size) }}%)</span>
                <span class="text-gray-400 dark:text-gray-600">•</span>
                <span class="text-blue-600 dark:text-blue-400">
                  Dedup: <span class="font-mono">{{ formatBytes(jobStore.getProgressInfo(job.id).deduplicated_size || 0) }}</span>
                  <span class="font-semibold ml-1">({{ calculateDeduplicationRatio(jobStore.getProgressInfo(job.id).original_size, jobStore.getProgressInfo(job.id).deduplicated_size) }}%)</span>
                </span>
              </div>
              <div v-if="jobStore.getProgressInfo(job.id).transfer_rate" class="flex items-center gap-1 px-2 py-1 bg-green-100 dark:bg-green-900/30 rounded">
                <svg class="w-3 h-3 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                </svg>
                <span class="font-mono font-bold text-green-700 dark:text-green-300">{{ formatRate(jobStore.getProgressInfo(job.id).transfer_rate) }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Job Details Modal -->
    <div v-if="selectedJob" @click.self="selectedJob = null" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
      <div class="bg-white dark:bg-gray-800 rounded-lg max-w-4xl w-full max-h-[90vh] overflow-hidden flex flex-col">
        <!-- Modal Header -->
        <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
          <div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ formatJobType(selectedJob.type) }}</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">Job #{{ selectedJob.id }}</p>
          </div>
          <button @click="selectedJob = null" class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Modal Body with Tabs -->
        <div class="flex-1 overflow-y-auto">
          <!-- Tabs -->
          <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="flex px-6 -mb-px">
              <button
                @click="activeTab = 'details'"
                :class="activeTab === 'details' ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                class="py-4 px-4 border-b-2 font-medium text-sm"
              >
                {{ $t('jobs.details') }}
              </button>
              <button
                v-if="selectedJob.output || selectedJob.error"
                @click="activeTab = 'logs'"
                :class="activeTab === 'logs' ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                class="py-4 px-4 border-b-2 font-medium text-sm"
              >
                {{ $t('jobs.logs') }}
              </button>
              <button
                @click="activeTab = 'payload'"
                :class="activeTab === 'payload' ? 'border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300'"
                class="py-4 px-4 border-b-2 font-medium text-sm"
              >
                {{ $t('jobs.payload') }}
              </button>
            </nav>
          </div>

          <!-- Tab Content -->
          <div class="p-6">
            <!-- Details Tab -->
            <div v-if="activeTab === 'details'" class="space-y-4">
              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="text-xs text-gray-600 dark:text-gray-400">{{ $t('jobs.status') }}</label>
                  <div class="mt-1">
                    <span :class="getStatusClass(selectedJob.status)" class="px-2 py-1 text-sm font-semibold rounded">
                      {{ formatStatus(selectedJob.status) }}
                    </span>
                  </div>
                </div>
                <div>
                  <label class="text-xs text-gray-600 dark:text-gray-400">{{ $t('jobs.progress') }}</label>
                  <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ selectedJob.progress }}%</div>
                </div>
                <div>
                  <label class="text-xs text-gray-600 dark:text-gray-400">{{ $t('jobs.queue') }}</label>
                  <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ selectedJob.queue }}</div>
                </div>
                <div>
                  <label class="text-xs text-gray-600 dark:text-gray-400">{{ $t('jobs.attempts') }}</label>
                  <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ selectedJob.attempts }} / {{ selectedJob.max_attempts }}</div>
                </div>
                <div>
                  <label class="text-xs text-gray-600 dark:text-gray-400">{{ $t('jobs.created_at') }}</label>
                  <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ formatDate(selectedJob.created_at) }}</div>
                </div>
                <div v-if="selectedJob.started_at">
                  <label class="text-xs text-gray-600 dark:text-gray-400">{{ $t('jobs.started_at') }}</label>
                  <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ formatDate(selectedJob.started_at) }}</div>
                </div>
                <div v-if="selectedJob.completed_at">
                  <label class="text-xs text-gray-600 dark:text-gray-400">{{ $t('jobs.completed_at') }}</label>
                  <div class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ formatDate(selectedJob.completed_at) }}</div>
                </div>
              </div>

              <!-- Real-time Progress Detail -->
              <div v-if="selectedJob.status === 'running' && jobStore.getProgressInfo(selectedJob.id)" class="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                <h3 class="text-sm font-semibold text-blue-900 dark:text-blue-300 mb-3">{{ $t('jobs.live_progress') }}</h3>
                <div class="grid grid-cols-2 gap-3 text-sm">
                  <div>
                    <span class="text-gray-600 dark:text-gray-400">{{ $t('jobs.files_count') }}:</span>
                    <span class="ml-2 font-mono text-gray-900 dark:text-gray-100">{{ jobStore.getProgressInfo(selectedJob.id).files_count || 0 }}</span>
                  </div>
                  <div>
                    <span class="text-gray-600 dark:text-gray-400">{{ $t('jobs.transfer_rate') }}:</span>
                    <span class="ml-2 font-mono text-gray-900 dark:text-gray-100">{{ formatRate(jobStore.getProgressInfo(selectedJob.id).transfer_rate || 0) }}</span>
                  </div>
                  <div>
                    <span class="text-gray-600 dark:text-gray-400">{{ $t('jobs.original_size') }}:</span>
                    <span class="ml-2 font-mono text-gray-900 dark:text-gray-100">{{ formatBytes(jobStore.getProgressInfo(selectedJob.id).original_size || 0) }}</span>
                  </div>
                  <div>
                    <span class="text-gray-600 dark:text-gray-400">{{ $t('jobs.compressed_size') }}:</span>
                    <span class="ml-2 font-mono text-gray-900 dark:text-gray-100">{{ formatBytes(jobStore.getProgressInfo(selectedJob.id).compressed_size || 0) }}</span>
                  </div>
                  <div>
                    <span class="text-gray-600 dark:text-gray-400">{{ $t('jobs.deduplicated_size') }}:</span>
                    <span class="ml-2 font-mono text-gray-900 dark:text-gray-100">{{ formatBytes(jobStore.getProgressInfo(selectedJob.id).deduplicated_size || 0) }}</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Logs Tab -->
            <div v-if="activeTab === 'logs'">
              <div v-if="selectedJob.error" class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded text-sm text-red-800 dark:text-red-300 font-mono whitespace-pre-wrap max-h-96 overflow-y-auto">{{ selectedJob.error }}</div>
              <div v-else-if="selectedJob.output" class="p-4 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded text-sm text-gray-800 dark:text-gray-200 font-mono whitespace-pre-wrap max-h-96 overflow-y-auto">{{ selectedJob.output }}</div>
              <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">{{ $t('jobs.no_logs') }}</div>
            </div>

            <!-- Payload Tab -->
            <div v-if="activeTab === 'payload'">
              <div class="p-4 bg-gray-50 dark:bg-gray-900 border border-gray-200 dark:border-gray-700 rounded text-xs text-gray-700 dark:text-gray-300 font-mono max-h-96 overflow-y-auto">
                <pre>{{ JSON.stringify(selectedJob.payload, null, 2) }}</pre>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, ref, computed } from 'vue'
import { useJobStore } from '@/stores/jobs'
import { useI18n } from 'vue-i18n'
import { useSSE } from '@/composables/useSSE'

const jobStore = useJobStore()
const { t } = useI18n()
const { subscribe } = useSSE()

// Filter state
const showSystemJobs = ref(false)
const selectedJob = ref(null)
const activeTab = ref('details')

// System job types
const SYSTEM_JOB_TYPES = [
  'server_stats_collect',
  'storage_pool_analyze',
  'capabilities_detection'
]

// Load jobs on mount
onMounted(async () => {
  // Initial data load
  await loadData()

  // Subscribe to real-time job updates via global SSE
  subscribe('jobs', (data) => {
    // Update job list (silent)
    if (data.jobs) {
      jobStore.jobs = data.jobs
    }

    // Update job stats (silent)
    if (data.stats) {
      jobStore.stats = data.stats
    }

    // Update specific job progress (silent - updates every second)
    if (data.job_id && data.progress_info) {
      jobStore.setProgressInfo(data.job_id, data.progress_info)
    }
  })
})

// Filtered jobs
const filteredJobs = computed(() => {
  if (showSystemJobs.value) {
    return jobStore.jobs
  }
  return jobStore.jobs.filter(job => !isSystemJob(job.type))
})

// Filtered stats
const filteredStats = computed(() => {
  if (showSystemJobs.value) {
    return jobStore.stats
  }

  // Calculate stats excluding system jobs
  const stats = { total: 0, pending: 0, running: 0, completed: 0, failed: 0, cancelled: 0 }
  filteredJobs.value.forEach(job => {
    stats.total++
    stats[job.status]++
  })
  return stats
})

function isSystemJob(type) {
  return SYSTEM_JOB_TYPES.includes(type)
}

async function loadData() {
  await Promise.all([
    jobStore.fetchJobs({ limit: 50 }),
    jobStore.fetchStats(),
    jobStore.fetchProgressForRunningJobs()
  ])
}

async function handleCancel(id) {
  if (!confirm(t('jobs.cancel_confirm'))) {
    return
  }

  const success = await jobStore.cancelJob(id)
  if (success) {
    // Data will refresh on next interval
  }
}

async function handleRetry(id) {
  const newJobId = await jobStore.retryJob(id)
  if (newJobId) {
    // Data will refresh on next interval
  }
}

function formatJobType(type) {
  const translationKey = `jobs.types.${type}`
  if (t(translationKey) !== translationKey) {
    return t(translationKey)
  }

  return type
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

function formatDate(dateString) {
  if (!dateString) return '-'
  const date = new Date(dateString)
  return date.toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit'
  })
}

function formatDateCompact(dateString) {
  if (!dateString) return '-'
  const date = new Date(dateString)
  return date.toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit'
  })
}

function formatStatus(status) {
  return t(`jobs.stats.${status}`)
}

function getStatusClass(status) {
  const classes = {
    pending: 'bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300',
    running: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300',
    completed: 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-300',
    cancelled: 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
  }
  return classes[status] || 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
}

function formatBytes(bytes) {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB', 'PB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

function formatRate(bytesPerSecond) {
  if (!bytesPerSecond || bytesPerSecond === 0) return '0 B/s'

  const bitsPerSecond = bytesPerSecond * 8

  if (bitsPerSecond >= 1000000000) {
    return (bitsPerSecond / 1000000000).toFixed(2) + ' Gbit/s'
  } else if (bitsPerSecond >= 1000000) {
    return (bitsPerSecond / 1000000).toFixed(2) + ' Mbit/s'
  } else if (bitsPerSecond >= 1000) {
    return (bitsPerSecond / 1000).toFixed(2) + ' Kbit/s'
  }

  const k = 1024
  if (bytesPerSecond >= k * k) {
    return (bytesPerSecond / (k * k)).toFixed(2) + ' MB/s'
  } else if (bytesPerSecond >= k) {
    return (bytesPerSecond / k).toFixed(2) + ' KB/s'
  }
  return bytesPerSecond.toFixed(2) + ' B/s'
}

function calculateCompressionRatio(original, compressed) {
  if (!original || !compressed || original === 0) return 0
  return Math.round((1 - compressed / original) * 100)
}

function calculateDeduplicationRatio(original, deduplicated) {
  if (!original || !deduplicated || original === 0) return 0
  return Math.round((1 - deduplicated / original) * 100)
}

function getJobServer(job) {
  // Try to extract server name from payload
  if (job.payload?.server_name) {
    return job.payload.server_name
  }
  return null
}

function getBackupType(job) {
  // Extract backup type from payload (backup, database, docker, etc.)
  if (job.payload?.type) {
    const typeMap = {
      'backup': '💾 System',
      'database': '🗄️ Database',
      'docker': '🐳 Docker'
    }
    return typeMap[job.payload.type] || job.payload.type
  }

  // For docker_restore jobs
  if (job.type === 'docker_restore') {
    return '🐳 Docker'
  }

  // For archive_restore jobs
  if (job.type === 'archive_restore') {
    return '📁 Archive'
  }

  return null
}

function isManualTrigger(job) {
  return job.payload?.triggered_by === 'manual'
}

function isScheduledTrigger(job) {
  return job.payload?.triggered_by === 'scheduled' || job.payload?.triggered_by === 'schedule'
}

function getRepositoryName(job) {
  // Try to extract repository name from payload
  // Could be repository_name, repo_name, or extract from repository_id
  if (job.payload?.repository_name) {
    return job.payload.repository_name
  }
  if (job.payload?.repo_name) {
    return job.payload.repo_name
  }
  // If we have a repository_id, convert to string and try to parse
  if (job.payload?.repository_id) {
    const repoId = String(job.payload.repository_id)
    // Try to extract type from repo_id format: "type-repo-serverId-timestamp"
    const parts = repoId.split('-')
    if (parts.length >= 2 && parts[1] === 'repo') {
      return parts[0] // Return the type (backup, database, etc.)
    }
    // If it's just a number, show it as "Repo #X"
    if (!isNaN(Number(repoId))) {
      return `Repo #${repoId}`
    }
    return repoId
  }
  return null
}
</script>
