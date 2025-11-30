<template>
  <div>
    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $t('settings.maintenance.title') }}</h3>
    <p class="text-sm text-gray-600 dark:text-gray-400 mb-6">{{ $t('settings.maintenance.description') }}</p>

    <!-- Status Card -->
    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 mb-6">
      <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">{{ $t('settings.maintenance.system_status') }}</h4>
      <div v-if="loadingStatus" class="flex items-center text-gray-500">
        <svg class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        {{ $t('settings.maintenance.loading') }}
      </div>
      <div v-else-if="status" class="grid grid-cols-2 md:grid-cols-3 gap-4 text-sm">
        <div>
          <span class="text-gray-500 dark:text-gray-400">{{ $t('settings.maintenance.agent_version') }}:</span>
          <span class="ml-2 font-mono text-gray-900 dark:text-gray-100">{{ status.agent_source_version || 'N/A' }}</span>
        </div>
        <div>
          <span class="text-gray-500 dark:text-gray-400">{{ $t('settings.maintenance.agent_built') }}:</span>
          <span class="ml-2 text-gray-900 dark:text-gray-100">{{ status.agent_binary_modified || 'N/A' }}</span>
        </div>
        <div>
          <span class="text-gray-500 dark:text-gray-400">{{ $t('settings.maintenance.frontend_built') }}:</span>
          <span class="ml-2 text-gray-900 dark:text-gray-100">{{ status.frontend_built || 'N/A' }}</span>
        </div>
        <div>
          <span class="text-gray-500 dark:text-gray-400">PHP:</span>
          <span class="ml-2 font-mono text-gray-900 dark:text-gray-100">{{ status.php_version || 'N/A' }}</span>
        </div>
        <div>
          <span class="text-gray-500 dark:text-gray-400">Go:</span>
          <span class="ml-2 font-mono text-gray-900 dark:text-gray-100">{{ formatGoVersion(status.go_version) }}</span>
        </div>
        <div>
          <span class="text-gray-500 dark:text-gray-400">Node:</span>
          <span class="ml-2 font-mono text-gray-900 dark:text-gray-100">{{ status.node_version || 'N/A' }}</span>
        </div>
        <div>
          <span class="text-gray-500 dark:text-gray-400">{{ $t('settings.maintenance.disk_free') }}:</span>
          <span class="ml-2 text-gray-900 dark:text-gray-100">{{ status.disk_free_gb }} GB</span>
        </div>
        <div>
          <span class="text-gray-500 dark:text-gray-400">Workers:</span>
          <span class="ml-2 text-gray-900 dark:text-gray-100">{{ status.workers_running }}/{{ status.workers_total }}</span>
        </div>
      </div>
    </div>

    <!-- Maintenance Actions -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
      <!-- Restart Workers -->
      <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
        <div class="flex items-start justify-between">
          <div class="flex-1 mr-4">
            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.maintenance.restart_workers') }}</h4>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.maintenance.restart_workers_desc') }}</p>
            <div v-if="jobProgress['restart-workers']" class="mt-2">
              <div class="flex items-center text-sm text-blue-600 dark:text-blue-400">
                <span>{{ jobProgress['restart-workers'].message }}</span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                <div class="bg-blue-600 h-1.5 rounded-full transition-all" :style="{ width: jobProgress['restart-workers'].progress + '%' }"></div>
              </div>
            </div>
          </div>
          <button
            @click="runAction('restart-workers')"
            :disabled="running['restart-workers']"
            class="btn btn-secondary flex items-center shrink-0"
          >
            <svg v-if="running['restart-workers']" class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg v-else class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ $t('settings.maintenance.restart') }}
          </button>
        </div>
      </div>

      <!-- Rebuild Agent -->
      <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
        <div class="flex items-start justify-between">
          <div class="flex-1 mr-4">
            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.maintenance.rebuild_agent') }}</h4>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.maintenance.rebuild_agent_desc') }}</p>
            <div v-if="jobProgress['rebuild-agent']" class="mt-2">
              <div class="flex items-center text-sm text-blue-600 dark:text-blue-400">
                <span>{{ jobProgress['rebuild-agent'].message }}</span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                <div class="bg-blue-600 h-1.5 rounded-full transition-all" :style="{ width: jobProgress['rebuild-agent'].progress + '%' }"></div>
              </div>
            </div>
          </div>
          <button
            @click="runAction('rebuild-agent')"
            :disabled="running['rebuild-agent']"
            class="btn btn-secondary flex items-center shrink-0"
          >
            <svg v-if="running['rebuild-agent']" class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg v-else class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            {{ $t('settings.maintenance.rebuild') }}
          </button>
        </div>
      </div>

      <!-- Rebuild Frontend -->
      <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
        <div class="flex items-start justify-between">
          <div class="flex-1 mr-4">
            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.maintenance.rebuild_frontend') }}</h4>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.maintenance.rebuild_frontend_desc') }}</p>
            <div v-if="jobProgress['rebuild-frontend']" class="mt-2">
              <div class="flex items-center text-sm text-blue-600 dark:text-blue-400">
                <span>{{ jobProgress['rebuild-frontend'].message }}</span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                <div class="bg-blue-600 h-1.5 rounded-full transition-all" :style="{ width: jobProgress['rebuild-frontend'].progress + '%' }"></div>
              </div>
            </div>
          </div>
          <button
            @click="runAction('rebuild-frontend')"
            :disabled="running['rebuild-frontend']"
            class="btn btn-secondary flex items-center shrink-0"
          >
            <svg v-if="running['rebuild-frontend']" class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg v-else class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
            {{ $t('settings.maintenance.rebuild') }}
          </button>
        </div>
      </div>

      <!-- Composer Install -->
      <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
        <div class="flex items-start justify-between">
          <div class="flex-1 mr-4">
            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.maintenance.composer_install') }}</h4>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.maintenance.composer_install_desc') }}</p>
            <div v-if="jobProgress['composer-install']" class="mt-2">
              <div class="flex items-center text-sm text-blue-600 dark:text-blue-400">
                <span>{{ jobProgress['composer-install'].message }}</span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                <div class="bg-blue-600 h-1.5 rounded-full transition-all" :style="{ width: jobProgress['composer-install'].progress + '%' }"></div>
              </div>
            </div>
          </div>
          <button
            @click="runAction('composer-install')"
            :disabled="running['composer-install']"
            class="btn btn-secondary flex items-center shrink-0"
          >
            <svg v-if="running['composer-install']" class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg v-else class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            {{ $t('settings.maintenance.install') }}
          </button>
        </div>
      </div>

      <!-- Run Migrations -->
      <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
        <div class="flex items-start justify-between">
          <div class="flex-1 mr-4">
            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.maintenance.run_migrations') }}</h4>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.maintenance.run_migrations_desc') }}</p>
            <div v-if="jobProgress['run-migrations']" class="mt-2">
              <div class="flex items-center text-sm text-blue-600 dark:text-blue-400">
                <span>{{ jobProgress['run-migrations'].message }}</span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                <div class="bg-blue-600 h-1.5 rounded-full transition-all" :style="{ width: jobProgress['run-migrations'].progress + '%' }"></div>
              </div>
            </div>
          </div>
          <button
            @click="runAction('run-migrations')"
            :disabled="running['run-migrations']"
            class="btn btn-secondary flex items-center shrink-0"
          >
            <svg v-if="running['run-migrations']" class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg v-else class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
            </svg>
            {{ $t('settings.maintenance.run') }}
          </button>
        </div>
      </div>

      <!-- Recompute Stats -->
      <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
        <div class="flex items-start justify-between">
          <div class="flex-1 mr-4">
            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.maintenance.recompute_stats') }}</h4>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.maintenance.recompute_stats_desc') }}</p>
            <div v-if="jobProgress['recompute-stats']" class="mt-2">
              <div class="flex items-center text-sm text-blue-600 dark:text-blue-400">
                <span>{{ jobProgress['recompute-stats'].message }}</span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                <div class="bg-blue-600 h-1.5 rounded-full transition-all" :style="{ width: jobProgress['recompute-stats'].progress + '%' }"></div>
              </div>
            </div>
          </div>
          <button
            @click="runAction('recompute-stats')"
            :disabled="running['recompute-stats']"
            class="btn btn-secondary flex items-center shrink-0"
          >
            <svg v-if="running['recompute-stats']" class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg v-else class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
            {{ $t('settings.maintenance.recompute') }}
          </button>
        </div>
      </div>

      <!-- Clear Cache -->
      <div class="border border-gray-200 dark:border-gray-700 rounded-lg p-4">
        <div class="flex items-start justify-between">
          <div class="flex-1 mr-4">
            <h4 class="font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.maintenance.clear_cache') }}</h4>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.maintenance.clear_cache_desc') }}</p>
            <div v-if="jobProgress['clear-cache']" class="mt-2">
              <div class="flex items-center text-sm text-blue-600 dark:text-blue-400">
                <span>{{ jobProgress['clear-cache'].message }}</span>
              </div>
              <div class="w-full bg-gray-200 rounded-full h-1.5 mt-1">
                <div class="bg-blue-600 h-1.5 rounded-full transition-all" :style="{ width: jobProgress['clear-cache'].progress + '%' }"></div>
              </div>
            </div>
          </div>
          <button
            @click="runAction('clear-cache')"
            :disabled="running['clear-cache']"
            class="btn btn-secondary flex items-center shrink-0"
          >
            <svg v-if="running['clear-cache']" class="animate-spin h-4 w-4 mr-2" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
            <svg v-else class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
            </svg>
            {{ $t('settings.maintenance.clear') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Toast Notifications -->
    <div class="fixed bottom-4 right-4 z-50 space-y-2">
      <TransitionGroup name="toast">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          :class="[
            'p-4 rounded-lg shadow-lg max-w-sm',
            toast.type === 'success' ? 'bg-green-500 text-white' : '',
            toast.type === 'error' ? 'bg-red-500 text-white' : '',
            toast.type === 'warning' ? 'bg-yellow-500 text-white' : ''
          ]"
        >
          <div class="font-medium">{{ toast.title }}</div>
          <div v-if="toast.message" class="text-sm opacity-90 mt-1">{{ toast.message }}</div>
        </div>
      </TransitionGroup>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '@/services/api'
import { jobService } from '@/services/jobs'

const { t } = useI18n()

const status = ref(null)
const loadingStatus = ref(true)
const running = reactive({})
const jobProgress = reactive({})
const toasts = ref([])
let toastIdCounter = 0
const pollIntervals = {}

onMounted(async () => {
  await loadStatus()
})

onUnmounted(() => {
  // Clear all polling intervals
  Object.values(pollIntervals).forEach(interval => clearInterval(interval))
})

async function loadStatus() {
  loadingStatus.value = true
  try {
    const response = await api.get('/maintenance/status')
    status.value = response.data.data
  } catch (err) {
    console.error('Failed to load maintenance status:', err)
  } finally {
    loadingStatus.value = false
  }
}

async function runAction(action) {
  running[action] = true
  jobProgress[action] = { progress: 0, message: t('settings.maintenance.starting') }

  try {
    const response = await api.post(`/maintenance/${action}`)
    const data = response.data.data
    const jobId = data.job_id

    if (jobId) {
      // Start polling for job status
      await pollJobStatus(action, jobId)
    } else {
      // Direct response (shouldn't happen with new implementation)
      showToast('success', t('settings.maintenance.success'), data.message)
      running[action] = false
      delete jobProgress[action]
    }
  } catch (err) {
    const message = err.response?.data?.error?.message || err.message
    showToast('error', t('settings.maintenance.error'), message)
    running[action] = false
    delete jobProgress[action]
  }
}

async function pollJobStatus(action, jobId) {
  const poll = async () => {
    try {
      const job = await jobService.get(jobId)

      if (!job) {
        clearInterval(pollIntervals[action])
        delete pollIntervals[action]
        running[action] = false
        delete jobProgress[action]
        return
      }

      // Update progress
      jobProgress[action] = {
        progress: job.progress || 0,
        message: job.progress_message || t('settings.maintenance.processing')
      }

      if (job.status === 'completed') {
        clearInterval(pollIntervals[action])
        delete pollIntervals[action]
        running[action] = false
        delete jobProgress[action]

        showToast('success', t('settings.maintenance.success'), job.output || t('settings.maintenance.completed'))

        // Refresh status after certain actions
        if (['rebuild-agent', 'rebuild-frontend', 'restart-workers'].includes(action)) {
          await loadStatus()
        }
      } else if (job.status === 'failed') {
        clearInterval(pollIntervals[action])
        delete pollIntervals[action]
        running[action] = false
        delete jobProgress[action]

        showToast('error', t('settings.maintenance.error'), job.error || t('settings.maintenance.failed'))
      }
      // Otherwise keep polling (pending, processing)
    } catch (err) {
      console.error('Failed to poll job status:', err)
      // Don't stop polling on temporary errors
    }
  }

  // Poll immediately, then every 1 second
  await poll()
  if (running[action]) {
    pollIntervals[action] = setInterval(poll, 1000)
  }
}

function showToast(type, title, message = '') {
  const id = ++toastIdCounter
  toasts.value.push({ id, type, title, message })

  setTimeout(() => {
    const index = toasts.value.findIndex(t => t.id === id)
    if (index !== -1) toasts.value.splice(index, 1)
  }, 5000)
}

function formatGoVersion(version) {
  if (!version) return 'N/A'
  // Extract just the version number from "go version go1.21.0 linux/amd64"
  const match = version.match(/go(\d+\.\d+\.?\d*)/)
  return match ? match[1] : version
}
</script>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.3s ease;
}

.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateX(100%);
}
</style>
