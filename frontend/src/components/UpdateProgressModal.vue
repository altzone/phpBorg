<template>
  <Teleport to="body">
    <div
      v-if="isOpen"
      class="fixed inset-0 z-50 overflow-y-auto"
      aria-labelledby="update-modal-title"
      role="dialog"
      aria-modal="true"
    >
      <!-- Backdrop -->
      <div class="fixed inset-0 bg-black bg-opacity-60 transition-opacity"></div>

      <!-- Modal -->
      <div class="flex min-h-full items-center justify-center p-4">
        <div class="relative bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-lg transform transition-all">
          <!-- Header -->
          <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
              <div
                :class="[
                  'w-10 h-10 rounded-full flex items-center justify-center',
                  status === 'running' ? 'bg-blue-100 dark:bg-blue-900/30' :
                  status === 'completed' ? 'bg-green-100 dark:bg-green-900/30' :
                  status === 'failed' ? 'bg-red-100 dark:bg-red-900/30' :
                  'bg-gray-100 dark:bg-gray-700'
                ]"
              >
                <!-- Running spinner -->
                <svg v-if="status === 'running'" class="w-6 h-6 text-blue-600 dark:text-blue-400 animate-spin" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <!-- Completed check -->
                <svg v-else-if="status === 'completed'" class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <!-- Failed X -->
                <svg v-else-if="status === 'failed'" class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
                <!-- Pending clock -->
                <svg v-else class="w-6 h-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>
              <div>
                <h3 id="update-modal-title" class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                  {{ $t('update.modal.title') }}
                </h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                  {{ statusText }}
                </p>
              </div>
            </div>
          </div>

          <!-- Content -->
          <div class="px-6 py-5">
            <!-- Progress bar -->
            <div class="mb-4">
              <div class="flex justify-between text-sm mb-1">
                <span class="text-gray-600 dark:text-gray-400">{{ $t('update.modal.progress') }}</span>
                <span class="font-medium text-gray-900 dark:text-gray-100">{{ progress }}%</span>
              </div>
              <div class="h-3 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                <div
                  :class="[
                    'h-full rounded-full transition-all duration-500 ease-out',
                    status === 'failed' ? 'bg-red-500' :
                    status === 'completed' ? 'bg-green-500' :
                    'bg-blue-500'
                  ]"
                  :style="{ width: `${progress}%` }"
                ></div>
              </div>
            </div>

            <!-- Current step message -->
            <div
              :class="[
                'p-4 rounded-lg mb-4',
                status === 'failed' ? 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800' :
                status === 'completed' ? 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' :
                'bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800'
              ]"
            >
              <div class="flex items-start gap-3">
                <div class="flex-shrink-0 mt-0.5">
                  <div v-if="status === 'running'" class="w-2 h-2 bg-blue-500 rounded-full animate-pulse"></div>
                  <svg v-else-if="status === 'completed'" class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                  </svg>
                  <svg v-else-if="status === 'failed'" class="w-4 h-4 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
                  </svg>
                </div>
                <p
                  :class="[
                    'text-sm font-medium',
                    status === 'failed' ? 'text-red-800 dark:text-red-200' :
                    status === 'completed' ? 'text-green-800 dark:text-green-200' :
                    'text-blue-800 dark:text-blue-200'
                  ]"
                >
                  {{ currentMessage || $t('update.modal.waiting') }}
                </p>
              </div>
            </div>

            <!-- Steps history (accordion) -->
            <div v-if="steps.length > 0">
              <button
                @click="stepsExpanded = !stepsExpanded"
                class="w-full flex items-center justify-between p-2 text-sm text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-200 transition-colors"
              >
                <span>{{ $t('update.modal.steps_history') }} ({{ steps.length }})</span>
                <svg
                  :class="['w-4 h-4 transition-transform', stepsExpanded ? 'rotate-180' : '']"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </button>

              <div v-show="stepsExpanded" class="mt-2 max-h-48 overflow-y-auto bg-gray-50 dark:bg-gray-900 rounded-lg p-3">
                <div
                  v-for="(step, index) in steps"
                  :key="index"
                  class="flex items-start gap-2 py-1.5 text-xs border-b border-gray-200 dark:border-gray-700 last:border-0"
                >
                  <span class="text-gray-400 font-mono w-8">{{ step.progress }}%</span>
                  <span class="text-gray-700 dark:text-gray-300 flex-1">{{ step.message }}</span>
                  <span
                    class="font-mono whitespace-nowrap"
                    :class="index === 0 ? 'text-gray-400' : 'text-emerald-600 dark:text-emerald-400'"
                    :title="formatTime(step.time)"
                  >
                    {{ index === 0 ? '—' : getStepDuration(index) }}
                  </span>
                </div>
                <!-- Total duration -->
                <div v-if="steps.length > 1" class="flex items-center justify-end gap-2 pt-2 mt-2 border-t border-gray-300 dark:border-gray-600">
                  <span class="text-xs text-gray-500 dark:text-gray-400">{{ $t('update.modal.total_duration') || 'Total' }}:</span>
                  <span class="text-xs font-mono font-semibold text-blue-600 dark:text-blue-400">{{ getTotalDuration() }}</span>
                </div>
              </div>
            </div>

            <!-- Error details -->
            <div v-if="status === 'failed' && errorMessage" class="mt-4 p-3 bg-red-100 dark:bg-red-900/30 rounded-lg">
              <p class="text-xs font-mono text-red-800 dark:text-red-200 break-all">
                {{ errorMessage }}
              </p>
            </div>
          </div>

          <!-- Footer -->
          <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50 rounded-b-xl">
            <div class="flex justify-end gap-3">
              <!-- Close button (always available - job runs in background) -->
              <button
                @click="close"
                :class="[
                  'btn',
                  status === 'completed' ? 'btn-secondary' : 'btn-secondary'
                ]"
              >
                {{ status === 'running' ? $t('update.modal.minimize') : $t('common.close') }}
              </button>

              <!-- Refresh page button (on success) -->
              <button
                v-if="status === 'completed'"
                @click="forceReload"
                class="btn btn-primary"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
                {{ $t('update.modal.close_and_reload') }}
              </button>

              <!-- Info text -->
              <p v-if="status === 'running'" class="text-xs text-gray-500 dark:text-gray-400 ml-2">
                {{ $t('update.modal.job_continues') }}
              </p>
            </div>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, watch, onUnmounted, computed } from 'vue'
import { useSSEStore } from '@/stores/sse'
import { useI18n } from 'vue-i18n'
import phpborgUpdateService from '@/services/phpborgUpdate'

const { t } = useI18n()
const sseStore = useSSEStore()

const props = defineProps({
  isOpen: {
    type: Boolean,
    default: false
  },
  jobId: {
    type: Number,
    default: null
  }
})

const emit = defineEmits(['close', 'completed', 'failed'])

// State
const progress = ref(0)
const currentMessage = ref('')
const status = ref('pending') // pending, running, completed, failed
const errorMessage = ref('')
const steps = ref([])
const stepsExpanded = ref(false)

// Polling/SSE
let pollingInterval = null
let sseUnsubscribe = null

const statusText = computed(() => {
  switch (status.value) {
    case 'running': return t('update.modal.status_running')
    case 'completed': return t('update.modal.status_completed')
    case 'failed': return t('update.modal.status_failed')
    default: return t('update.modal.status_pending')
  }
})

function formatTime(timestamp) {
  const date = new Date(timestamp)
  return date.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', second: '2-digit' })
}

function formatDuration(ms) {
  if (ms < 1000) {
    return `${ms}ms`
  } else if (ms < 60000) {
    const seconds = (ms / 1000).toFixed(1)
    return `${seconds}s`
  } else {
    const minutes = Math.floor(ms / 60000)
    const seconds = Math.floor((ms % 60000) / 1000)
    return `${minutes}m ${seconds}s`
  }
}

function getStepDuration(index) {
  if (index === 0) {
    return null // First step has no previous to compare
  }

  const currentStep = steps.value[index]
  const previousStep = steps.value[index - 1]

  if (!currentStep || !previousStep) {
    return null
  }

  const duration = currentStep.time - previousStep.time
  return formatDuration(duration)
}

function getTotalDuration() {
  if (steps.value.length < 2) {
    return '0s'
  }

  const firstStep = steps.value[0]
  const lastStep = steps.value[steps.value.length - 1]
  const duration = lastStep.time - firstStep.time

  return formatDuration(duration)
}

function addStep(progressValue, message) {
  // Avoid duplicates
  if (steps.value.length > 0) {
    const last = steps.value[steps.value.length - 1]
    if (last.progress === progressValue && last.message === message) {
      return
    }
  }

  steps.value.push({
    progress: progressValue,
    message: message,
    time: Date.now()
  })

  // Keep last 20 steps
  if (steps.value.length > 20) {
    steps.value.shift()
  }
}

async function pollJobStatus() {
  if (!props.jobId) return

  try {
    const result = await phpborgUpdateService.getJobResult(props.jobId)

    if (result.success && result.data.job) {
      const job = result.data.job

      // Update progress
      if (job.progress !== undefined && job.progress !== progress.value) {
        progress.value = job.progress
      }

      // Get progress message from Redis (via progress_info)
      if (result.data.progress_info?.message && result.data.progress_info.message !== currentMessage.value) {
        currentMessage.value = result.data.progress_info.message
        addStep(job.progress || 0, result.data.progress_info.message)
      }

      // Update status
      if (job.status === 'running' || job.status === 'processing') {
        status.value = 'running'
      } else if (job.status === 'completed') {
        status.value = 'completed'
        progress.value = 100
        currentMessage.value = t('update.modal.success_message')
        stopPolling()
        emit('completed')
      } else if (job.status === 'failed') {
        status.value = 'failed'
        errorMessage.value = job.error || t('update.modal.unknown_error')
        currentMessage.value = t('update.modal.failed_message')
        stopPolling()
        emit('failed', job.error)
      }
    }
  } catch (error) {
    console.error('[UpdateModal] Poll error:', error)
  }
}

function startPolling() {
  if (pollingInterval) return

  status.value = 'running'
  currentMessage.value = t('update.modal.starting')

  // Initial poll
  pollJobStatus()

  // Poll every 2 seconds
  pollingInterval = setInterval(pollJobStatus, 2000)

  // Also subscribe to SSE for faster updates
  sseUnsubscribe = sseStore.subscribe('jobs', (data) => {
    if (data.job_id === props.jobId && data.progress_info) {
      progress.value = data.progress_info.progress || progress.value
      if (data.progress_info.message) {
        currentMessage.value = data.progress_info.message
        addStep(data.progress_info.progress || 0, data.progress_info.message)
      }
    }
  })
}

function stopPolling() {
  if (pollingInterval) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }
  if (sseUnsubscribe) {
    sseUnsubscribe()
    sseUnsubscribe = null
  }
}

function close() {
  stopPolling()
  emit('close')
}

function forceReload() {
  // Force a complete page reload (bypass cache)
  window.location.href = window.location.href.split('?')[0] + '?t=' + Date.now()
}

// Watch for jobId changes
watch(() => props.jobId, (newJobId) => {
  if (newJobId && props.isOpen) {
    // Reset state
    progress.value = 0
    currentMessage.value = ''
    status.value = 'pending'
    errorMessage.value = ''
    steps.value = []

    startPolling()
  }
}, { immediate: true })

// Watch for modal open/close
watch(() => props.isOpen, (isOpen) => {
  if (!isOpen) {
    stopPolling()
  } else if (props.jobId) {
    startPolling()
  }
})

// Cleanup
onUnmounted(() => {
  stopPolling()
})
</script>
