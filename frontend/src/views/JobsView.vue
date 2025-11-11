<template>
  <div>
    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">Background Jobs</h1>
      <p class="mt-2 text-gray-600 dark:text-gray-400 dark:text-gray-500">Monitor and manage background tasks</p>
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
    <div class="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
      <div class="card bg-gray-50 dark:bg-gray-800">
        <div class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">Total</div>
        <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ jobStore.stats.total }}</div>
      </div>
      <div class="card bg-blue-50">
        <div class="text-sm text-blue-600 mb-1">Pending</div>
        <div class="text-2xl font-bold text-blue-900">{{ jobStore.stats.pending }}</div>
      </div>
      <div class="card bg-yellow-50">
        <div class="text-sm text-yellow-600 mb-1">Running</div>
        <div class="text-2xl font-bold text-yellow-900">{{ jobStore.stats.running }}</div>
      </div>
      <div class="card bg-green-50">
        <div class="text-sm text-green-600 mb-1">Completed</div>
        <div class="text-2xl font-bold text-green-900">{{ jobStore.stats.completed }}</div>
      </div>
      <div class="card bg-red-50">
        <div class="text-sm text-red-600 mb-1">Failed</div>
        <div class="text-2xl font-bold text-red-900">{{ jobStore.stats.failed }}</div>
      </div>
      <div class="card bg-gray-50 dark:bg-gray-800">
        <div class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">Cancelled</div>
        <div class="text-2xl font-bold text-gray-900 dark:text-gray-100">{{ jobStore.stats.cancelled }}</div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="jobStore.loading && !jobStore.jobs.length" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400 dark:text-gray-500">Loading jobs...</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="!jobStore.jobs.length" class="card">
      <div class="text-center py-16 text-gray-500 dark:text-gray-400 dark:text-gray-500">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">No jobs yet</h3>
        <p class="text-sm">Background jobs will appear here when they are created</p>
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
                {{ job.status.toUpperCase() }}
              </span>
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500">
              Job #{{ job.id }} • Queue: {{ job.queue }}
            </p>
          </div>
          <div class="flex gap-2">
            <button
              v-if="job.status === 'running' || job.status === 'pending'"
              @click="handleCancel(job.id)"
              class="btn btn-sm btn-secondary"
              title="Cancel job"
            >
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
            <button
              v-if="job.status === 'failed' && job.attempts < job.max_attempts"
              @click="handleRetry(job.id)"
              class="btn btn-sm btn-primary"
              title="Retry job"
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
            <span>Progress</span>
            <span>{{ job.progress }}%</span>
          </div>
          <div class="w-full bg-gray-200 rounded-full h-2">
            <div
              :class="job.status === 'completed' ? 'bg-green-600' : 'bg-primary-600'"
              class="h-2 rounded-full transition-all duration-300"
              :style="{ width: job.progress + '%' }"
            ></div>
          </div>
        </div>

        <!-- Job Info -->
        <div class="grid grid-cols-2 gap-4 text-sm mb-4">
          <div>
            <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Created:</span>
            <span class="ml-2 text-gray-900 dark:text-gray-100">{{ formatDate(job.created_at) }}</span>
          </div>
          <div v-if="job.started_at">
            <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Started:</span>
            <span class="ml-2 text-gray-900 dark:text-gray-100">{{ formatDate(job.started_at) }}</span>
          </div>
          <div v-if="job.completed_at">
            <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Completed:</span>
            <span class="ml-2 text-gray-900 dark:text-gray-100">{{ formatDate(job.completed_at) }}</span>
          </div>
          <div>
            <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Attempts:</span>
            <span class="ml-2 text-gray-900 dark:text-gray-100">{{ job.attempts }} / {{ job.max_attempts }}</span>
          </div>
        </div>

        <!-- Output/Error -->
        <div v-if="job.output || job.error" class="mt-4 pt-4 border-t">
          <details class="cursor-pointer">
            <summary class="text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:text-gray-100">
              {{ job.error ? 'Error Details' : 'Output Logs' }}
            </summary>
            <div
              v-if="job.error"
              class="mt-2 p-3 bg-red-50 border border-red-200 rounded text-sm text-red-800 font-mono whitespace-pre-wrap"
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
        <div class="mt-4 pt-4 border-t">
          <details class="cursor-pointer">
            <summary class="text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-gray-900 dark:text-gray-100">
              Job Payload
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

const jobStore = useJobStore()
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
    jobStore.fetchStats()
  ])
}

async function handleCancel(id) {
  if (!confirm('Are you sure you want to cancel this job?')) {
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

function getStatusClass(status) {
  const classes = {
    pending: 'bg-blue-100 text-blue-800',
    running: 'bg-yellow-100 text-yellow-800',
    completed: 'bg-green-100 text-green-800',
    failed: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-800 dark:text-gray-200'
  }
  return classes[status] || 'bg-gray-100 text-gray-800 dark:text-gray-200'
}
</script>
