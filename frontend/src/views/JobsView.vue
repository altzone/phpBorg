<template>
  <div>
    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $t('jobs.title') }}</h1>
      <p class="mt-2 text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('jobs.subtitle') }}</p>
    </div>

    <!-- Error Message -->
    <div v-if="jobStore.error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
      <div class="flex justify-between items-start">
        <p class="text-sm text-red-800">{{ jobStore.error }}</p>
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
        <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ jobStore.stats.total }}</div>
      </div>
      <div class="rounded-lg border border-blue-200 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20 p-3">
        <div class="text-xs text-blue-600 dark:text-blue-400 mb-1">{{ $t('jobs.stats.pending') }}</div>
        <div class="text-xl font-bold text-blue-900 dark:text-blue-300">{{ jobStore.stats.pending }}</div>
      </div>
      <div class="rounded-lg border border-yellow-200 dark:border-yellow-700 bg-yellow-50 dark:bg-yellow-900/20 p-3">
        <div class="text-xs text-yellow-600 dark:text-yellow-400 mb-1">{{ $t('jobs.stats.running') }}</div>
        <div class="text-xl font-bold text-yellow-900 dark:text-yellow-300">{{ jobStore.stats.running }}</div>
      </div>
      <div class="rounded-lg border border-green-200 dark:border-green-700 bg-green-50 dark:bg-green-900/20 p-3">
        <div class="text-xs text-green-600 dark:text-green-400 mb-1">{{ $t('jobs.stats.completed') }}</div>
        <div class="text-xl font-bold text-green-900 dark:text-green-300">{{ jobStore.stats.completed }}</div>
      </div>
      <div class="rounded-lg border border-red-200 dark:border-red-700 bg-red-50 dark:bg-red-900/20 p-3">
        <div class="text-xs text-red-600 dark:text-red-400 mb-1">{{ $t('jobs.stats.failed') }}</div>
        <div class="text-xl font-bold text-red-900 dark:text-red-300">{{ jobStore.stats.failed }}</div>
      </div>
      <div class="rounded-lg border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 p-3">
        <div class="text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('jobs.stats.cancelled') }}</div>
        <div class="text-xl font-bold text-gray-900 dark:text-gray-100">{{ jobStore.stats.cancelled }}</div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="jobStore.loading && !jobStore.jobs.length" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('jobs.loading_jobs') }}</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="!jobStore.jobs.length" class="card">
      <div class="text-center py-16 text-gray-500 dark:text-gray-400 dark:text-gray-500">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">{{ $t('jobs.no_jobs') }}</h3>
        <p class="text-sm">{{ $t('jobs.no_jobs_msg') }}</p>
      </div>
    </div>

    <!-- Jobs List -->
    <div v-else class="space-y-4">
      <div
        v-for="job in jobStore.jobs"
        :key="job.id"
        class="card hover:shadow-md transition-shadow"
      >
        <!-- Job Header -->
        <div class="flex items-start justify-between mb-4">
          <div class="flex-1">
            <div class="flex items-center gap-3 mb-2">
              <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                {{ formatJobType(job.type) }}
              </h3>
              <span
                :class="getStatusClass(job.status)"
                class="px-2 py-1 text-xs font-semibold rounded"
              >
                {{ formatStatus(job.status) }}
              </span>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500">
              {{ $t('jobs.job_id') }}{{ job.id }} • {{ $t('jobs.queue') }}: {{ job.queue }}
            </p>
          </div>
          <div class="flex gap-2">
            <button
              v-if="job.status === 'running' || job.status === 'pending'"
              @click="handleCancel(job.id)"
              class="btn btn-sm btn-secondary"
              :title="$t('jobs.cancel_job')"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
            <button
              v-if="job.status === 'failed' && job.attempts < job.max_attempts"
              @click="handleRetry(job.id)"
              class="btn btn-sm btn-primary"
              :title="$t('jobs.retry_job')"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
            </button>
          </div>
        </div>

        <!-- Progress Bar -->
        <div v-if="job.status === 'running' || job.status === 'completed'" class="mb-4">
          <div class="flex justify-between text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">
            <span>{{ $t('jobs.progress') }}</span>
            <span>{{ job.progress }}%</span>
          </div>
          <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
            <div
              :class="job.status === 'completed' ? 'bg-green-600' : 'bg-primary-600'"
              class="h-2 rounded-full transition-all duration-300"
              :style="{ width: job.progress + '%' }"
            ></div>
          </div>
        </div>

        <!-- Real-time Borg Progress (from Redis) -->
        <div v-if="job.status === 'running' && jobStore.getProgressInfo(job.id)" class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
          <div class="flex items-center justify-between mb-2">
            <div class="flex items-center gap-2">
              <svg class="w-4 h-4 text-blue-600 dark:text-blue-400 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              <span class="text-sm font-medium text-blue-900 dark:text-blue-300">{{ $t('jobs.live_progress') }}</span>
            </div>
            <div v-if="jobStore.getProgressInfo(job.id).transfer_rate" class="flex items-center gap-1 px-2 py-1 bg-green-100 dark:bg-green-900/30 rounded">
              <svg class="w-3 h-3 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
              </svg>
              <span class="text-xs font-mono font-semibold text-green-700 dark:text-green-300">{{ formatRate(jobStore.getProgressInfo(job.id).transfer_rate) }}</span>
            </div>
          </div>
          <div class="grid grid-cols-2 gap-3 text-xs mb-2">
            <div>
              <span class="text-gray-600 dark:text-gray-400">{{ $t('jobs.files_count') }}:</span>
              <span class="ml-1 font-mono text-gray-900 dark:text-gray-100">{{ jobStore.getProgressInfo(job.id).files_count || 0 }}</span>
            </div>
            <div>
              <span class="text-gray-600 dark:text-gray-400">{{ $t('jobs.original_size') }}:</span>
              <span class="ml-1 font-mono text-gray-900 dark:text-gray-100">{{ formatBytes(jobStore.getProgressInfo(job.id).original_size || 0) }}</span>
            </div>
            <div>
              <span class="text-gray-600 dark:text-gray-400">{{ $t('jobs.compressed_size') }}:</span>
              <span class="ml-1 font-mono text-gray-900 dark:text-gray-100">{{ formatBytes(jobStore.getProgressInfo(job.id).compressed_size || 0) }}</span>
              <span class="ml-1 text-green-600 dark:text-green-400 font-semibold">({{ calculateCompressionRatio(jobStore.getProgressInfo(job.id).original_size, jobStore.getProgressInfo(job.id).compressed_size) }}%)</span>
            </div>
            <div>
              <span class="text-gray-600 dark:text-gray-400">{{ $t('jobs.deduplicated_size') }}:</span>
              <span class="ml-1 font-mono text-gray-900 dark:text-gray-100">{{ formatBytes(jobStore.getProgressInfo(job.id).deduplicated_size || 0) }}</span>
              <span class="ml-1 text-blue-600 dark:text-blue-400 font-semibold">({{ calculateDeduplicationRatio(jobStore.getProgressInfo(job.id).original_size, jobStore.getProgressInfo(job.id).deduplicated_size) }}%)</span>
            </div>
          </div>
          <div v-if="jobStore.getProgressInfo(job.id).message" class="mt-2 text-xs text-gray-700 dark:text-gray-300 font-mono">
            {{ jobStore.getProgressInfo(job.id).message }}
          </div>
        </div>

        <!-- Job Info -->
        <div class="grid grid-cols-2 gap-4 text-sm mb-4">
          <div>
            <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('jobs.created_at') }}:</span>
            <span class="ml-2 text-gray-900 dark:text-gray-100">{{ formatDate(job.created_at) }}</span>
          </div>
          <div v-if="job.started_at">
            <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('jobs.started_at') }}:</span>
            <span class="ml-2 text-gray-900 dark:text-gray-100">{{ formatDate(job.started_at) }}</span>
          </div>
          <div v-if="job.completed_at">
            <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('jobs.completed_at') }}:</span>
            <span class="ml-2 text-gray-900 dark:text-gray-100">{{ formatDate(job.completed_at) }}</span>
          </div>
          <div>
            <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('jobs.attempts') }}:</span>
            <span class="ml-2 text-gray-900 dark:text-gray-100">{{ job.attempts }} / {{ job.max_attempts }}</span>
          </div>
        </div>

        <!-- Output/Error -->
        <div v-if="job.output || job.error" class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
          <details class="cursor-pointer">
            <summary class="text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100">
              {{ job.error ? $t('jobs.error_details') : $t('jobs.output_logs') }}
            </summary>
            <div
              v-if="job.error"
              class="mt-2 p-3 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded text-sm text-red-800 dark:text-red-300 font-mono whitespace-pre-wrap"
            >
              {{ job.error }}
            </div>
            <div
              v-else-if="job.output"
              class="mt-2 p-3 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded text-sm text-gray-800 dark:text-gray-200 font-mono whitespace-pre-wrap"
            >
              {{ job.output }}
            </div>
          </details>
        </div>

        <!-- Payload Info (collapsed by default) -->
        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
          <details class="cursor-pointer">
            <summary class="text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:hover:text-gray-100">
              {{ $t('jobs.job_payload') }}
            </summary>
            <div class="mt-2 p-3 bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded text-xs text-gray-700 dark:text-gray-300 font-mono">
              <pre>{{ JSON.stringify(job.payload, null, 2) }}</pre>
            </div>
          </details>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref } from 'vue'
import { useJobStore } from '@/stores/jobs'
import { useI18n } from 'vue-i18n'

const jobStore = useJobStore()
const { t } = useI18n()
let refreshInterval = null

// Load jobs on mount
onMounted(() => {
  loadData()

  // Auto-refresh every 5 seconds
  refreshInterval = setInterval(loadData, 5000)
})

onUnmounted(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
  }
})

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
  // Check if translation exists for this job type
  const translationKey = `jobs.types.${type}`
  if (t(translationKey) !== translationKey) {
    return t(translationKey)
  }

  // Fallback to title case if no translation
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

  // Convert to bits per second for network-style display
  const bitsPerSecond = bytesPerSecond * 8

  // Check if we should display in Gbit/s or Mbit/s
  if (bitsPerSecond >= 1000000000) {
    return (bitsPerSecond / 1000000000).toFixed(2) + ' Gbit/s'
  } else if (bitsPerSecond >= 1000000) {
    return (bitsPerSecond / 1000000).toFixed(2) + ' Mbit/s'
  } else if (bitsPerSecond >= 1000) {
    return (bitsPerSecond / 1000).toFixed(2) + ' Kbit/s'
  }

  // Fallback to MB/s for very low speeds
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
</script>
