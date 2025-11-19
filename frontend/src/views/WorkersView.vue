<template>
  <div>
    <!-- Connection Status Indicator -->
    <div v-if="connectionType !== 'connecting'" class="mb-4 px-4 py-2 rounded-lg text-sm flex items-center gap-2" :class="{
      'bg-green-50 dark:bg-green-900/20 text-green-700 dark:text-green-400': connectionType === 'sse',
      'bg-yellow-50 dark:bg-yellow-900/20 text-yellow-700 dark:text-yellow-400': connectionType === 'polling',
      'bg-red-50 dark:bg-red-900/20 text-red-700 dark:text-red-400': connectionType === 'error'
    }">
      <div class="w-2 h-2 rounded-full animate-pulse" :class="{
        'bg-green-500': connectionType === 'sse',
        'bg-yellow-500': connectionType === 'polling',
        'bg-red-500': connectionType === 'error'
      }"></div>
      <span v-if="connectionType === 'sse'">🚀 {{ t('workers.connection.realtime_sse') }}</span>
      <span v-else-if="connectionType === 'polling'">⏱️ {{ t('workers.connection.polling_mode') }}</span>
      <span v-else>❌ {{ t('workers.connection.connection_error') }}</span>
    </div>

    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ t('workers.title') }}</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">{{ t('workers.subtitle') }}</p>
      </div>
      <div class="flex gap-3">
        <button
          @click="restartAllWorkers"
          :disabled="workerStore.loading || restartingAll"
          class="btn btn-primary flex items-center gap-2"
        >
          <svg
            class="w-5 h-5"
            :class="{ 'animate-spin': restartingAll }"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ t('workers.restart_all') }}
        </button>
        <button
          @click="refreshWorkers"
          :disabled="workerStore.loading"
          class="btn btn-secondary flex items-center gap-2"
        >
          <svg
            class="w-5 h-5"
            :class="{ 'animate-spin': workerStore.loading }"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ t('common.refresh') }}
        </button>
      </div>
    </div>

    <!-- Error Message -->
    <div v-if="workerStore.error" class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
      <div class="flex justify-between items-start">
        <p class="text-sm text-red-800 dark:text-red-300">{{ workerStore.error }}</p>
        <button @click="workerStore.clearError()" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="workerStore.loading && !workerStore.workers.length" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400">{{ t('workers.loading_workers') }}</p>
      </div>
    </div>

    <!-- Workers Grid -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
      <div
        v-for="worker in workerStore.workers"
        :key="worker.name"
        class="group relative bg-gradient-to-br from-white to-gray-50 dark:from-gray-800 dark:to-gray-850 rounded-lg border border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-500 transition-all duration-200 hover:shadow-lg overflow-hidden"
      >
        <!-- Status indicator bar -->
        <div :class="[
          'absolute top-0 left-0 right-0 h-1',
          worker.active
            ? 'bg-gradient-to-r from-emerald-500 via-green-500 to-emerald-600'
            : 'bg-gray-300 dark:bg-gray-600'
        ]"></div>

        <div class="p-4 pt-5">
          <!-- Header -->
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2.5">
              <!-- Icon -->
              <div :class="[
                'p-2 rounded-md transition-all duration-200',
                worker.active
                  ? 'bg-gradient-to-br from-primary-500 to-primary-600 shadow-md'
                  : 'bg-gray-200 dark:bg-gray-700'
              ]">
                <svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
              </div>

              <div>
                <h3 class="text-sm font-semibold text-gray-900 dark:text-white">{{ worker.display_name }}</h3>
                <div class="flex items-center gap-1 mt-0.5">
                  <div :class="[
                    'w-1.5 h-1.5 rounded-full',
                    worker.active ? 'bg-emerald-500 animate-pulse' : 'bg-gray-400'
                  ]"></div>
                  <span :class="[
                    'text-xs',
                    worker.active ? 'text-emerald-600 dark:text-emerald-400' : 'text-gray-500'
                  ]">
                    {{ worker.status }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- Current Job Badge -->
          <div v-if="worker.current_job" class="mb-3 px-2.5 py-1.5 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-md">
            <div class="flex items-center gap-1.5">
              <svg class="w-3.5 h-3.5 text-blue-500 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              <div class="flex-1 min-w-0">
                <p class="text-xs font-medium text-blue-700 dark:text-blue-300 truncate">{{ worker.current_job.type }}</p>
                <div class="flex items-center gap-2 mt-0.5">
                  <div class="flex-1 h-1 bg-blue-200 dark:bg-blue-900 rounded-full overflow-hidden">
                    <div class="h-full bg-blue-500 transition-all duration-300" :style="{ width: worker.current_job.progress + '%' }"></div>
                  </div>
                  <span class="text-xs text-blue-600 dark:text-blue-400 font-medium">{{ worker.current_job.progress }}%</span>
                </div>
              </div>
            </div>
          </div>
          <div v-else-if="worker.active" class="mb-3">
            <div class="px-2.5 py-1 bg-gray-100 dark:bg-gray-700/50 rounded-md inline-flex items-center gap-1.5">
              <div class="w-1.5 h-1.5 rounded-full bg-gray-400"></div>
              <span class="text-xs text-gray-600 dark:text-gray-400">{{ t('workers.status.idle') }}</span>
            </div>
          </div>

          <!-- Metrics Grid -->
          <div class="grid grid-cols-2 gap-2 mb-3">
            <!-- Memory -->
            <div v-if="worker.memory" class="px-2 py-1.5 bg-blue-50/50 dark:bg-blue-900/10 rounded border border-blue-100 dark:border-blue-900/30">
              <div class="flex items-center gap-1.5">
                <svg class="w-3 h-3 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                </svg>
                <span class="text-xs font-medium text-blue-700 dark:text-blue-300">{{ worker.memory }}</span>
              </div>
            </div>

            <!-- CPU -->
            <div v-if="worker.cpu" class="px-2 py-1.5 bg-emerald-50/50 dark:bg-emerald-900/10 rounded border border-emerald-100 dark:border-emerald-900/30">
              <div class="flex items-center gap-1.5">
                <svg class="w-3 h-3 text-emerald-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
                <span class="text-xs font-medium text-emerald-700 dark:text-emerald-300">{{ worker.cpu }}</span>
              </div>
            </div>

            <!-- PID -->
            <div v-if="worker.pid" class="px-2 py-1.5 bg-gray-50 dark:bg-gray-700/30 rounded border border-gray-200 dark:border-gray-600">
              <div class="flex items-center gap-1.5">
                <svg class="w-3 h-3 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                </svg>
                <span class="text-xs font-mono text-gray-600 dark:text-gray-400">{{ worker.pid }}</span>
              </div>
            </div>

            <!-- Uptime -->
            <div v-if="worker.uptime" class="px-2 py-1.5 bg-purple-50/50 dark:bg-purple-900/10 rounded border border-purple-100 dark:border-purple-900/30">
              <div class="flex items-center gap-1.5">
                <svg class="w-3 h-3 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="text-xs font-medium text-purple-700 dark:text-purple-300 truncate">{{ worker.uptime }}</span>
              </div>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="flex gap-1.5">
            <button
              v-if="!worker.active"
              @click.stop="startWorker(worker.name)"
              class="flex-1 px-2.5 py-1.5 bg-emerald-500 hover:bg-emerald-600 text-white rounded text-xs font-medium transition-colors flex items-center justify-center gap-1"
            >
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              {{ t('workers.actions.start') }}
            </button>
            <button
              v-if="worker.active"
              @click.stop="stopWorker(worker.name)"
              class="flex-1 px-2.5 py-1.5 bg-red-500 hover:bg-red-600 text-white rounded text-xs font-medium transition-colors flex items-center justify-center gap-1"
            >
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
              </svg>
              {{ t('workers.actions.stop') }}
            </button>
            <button
              @click.stop="restartWorker(worker.name)"
              class="flex-1 px-2.5 py-1.5 bg-amber-500 hover:bg-amber-600 text-white rounded text-xs font-medium transition-colors flex items-center justify-center gap-1"
            >
              <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
              </svg>
              {{ t('workers.actions.restart') }}
            </button>
            <button
              @click.stop="openLogsModal(worker)"
              class="px-2.5 py-1.5 bg-gray-600 hover:bg-gray-700 text-white rounded text-xs font-medium transition-colors"
            >
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Logs Modal -->
    <div
      v-if="showLogsModal"
      class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
      @click.self="closeLogsModal"
    >
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-2xl w-full max-w-5xl max-h-[90vh] flex flex-col">
        <!-- Modal Header -->
        <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
          <div>
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">{{ t('workers.logs.title') }}</h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ selectedWorker?.display_name }}</p>
          </div>
          <button
            @click="closeLogsModal"
            class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Log Controls -->
        <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex gap-4">
          <div class="flex items-center gap-2">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('workers.logs.lines') }}:</label>
            <select
              v-model="logLines"
              @change="loadLogs"
              class="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
            >
              <option value="50">50</option>
              <option value="100">100</option>
              <option value="200">200</option>
              <option value="500">500</option>
              <option value="1000">1000</option>
            </select>
          </div>
          <div class="flex items-center gap-2">
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ t('workers.logs.since') }}:</label>
            <select
              v-model="logSince"
              @change="loadLogs"
              class="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
            >
              <option value="5 minutes ago">{{ t('workers.logs.time_options.5min') }}</option>
              <option value="15 minutes ago">{{ t('workers.logs.time_options.15min') }}</option>
              <option value="1 hour ago">{{ t('workers.logs.time_options.1hour') }}</option>
              <option value="6 hours ago">{{ t('workers.logs.time_options.6hours') }}</option>
              <option value="1 day ago">{{ t('workers.logs.time_options.1day') }}</option>
            </select>
          </div>
          <button
            @click="loadLogs"
            :disabled="workerStore.loading"
            class="btn btn-secondary text-sm flex items-center gap-2"
          >
            <svg
              class="w-4 h-4"
              :class="{ 'animate-spin': workerStore.loading }"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            {{ t('common.refresh') }}
          </button>
        </div>

        <!-- Modal Body -->
        <div class="flex-1 overflow-y-auto p-6">
          <div v-if="workerStore.loading" class="text-center py-8">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
            <p class="mt-4 text-gray-600 dark:text-gray-400">{{ t('workers.logs.loading_logs') }}</p>
          </div>
          <pre
            v-else
            class="bg-gray-900 dark:bg-black text-green-400 p-4 rounded-lg text-xs font-mono whitespace-pre-wrap overflow-x-auto"
          >{{ workerStore.logs || t('workers.logs.no_logs') }}</pre>
        </div>

        <!-- Modal Footer -->
        <div class="flex justify-end gap-3 p-6 border-t border-gray-200 dark:border-gray-700">
          <button @click="closeLogsModal" class="btn btn-secondary">
            {{ t('common.close') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Toast Notifications -->
    <div class="fixed bottom-4 right-4 z-50 space-y-3">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        :class="[
          'max-w-md rounded-lg shadow-lg p-4 transform transition-all duration-300',
          toast.type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white',
          'animate-slide-in'
        ]"
      >
        <div class="flex items-start gap-3">
          <div class="flex-shrink-0">
            <svg v-if="toast.type === 'success'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </div>
          <div class="flex-1 min-w-0">
            <p class="font-semibold">{{ toast.title }}</p>
            <p v-if="toast.message" class="text-sm mt-1 opacity-90">{{ toast.message }}</p>
          </div>
          <button @click="removeToast(toast.id)" class="flex-shrink-0 hover:opacity-75">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useWorkerStore } from '@/stores/workers'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()

const workerStore = useWorkerStore()
const authStore = useAuthStore()

const showLogsModal = ref(false)
const selectedWorker = ref(null)
const logLines = ref(100)
const logSince = ref('1 hour ago')
const restartingAll = ref(false)

// Toast notifications
const toasts = ref([])
let toastIdCounter = 0

function showToast(title, message = '', type = 'success', duration = 5000) {
  const id = ++toastIdCounter
  toasts.value.push({ id, title, message, type })

  setTimeout(() => {
    removeToast(id)
  }, duration)
}

function removeToast(id) {
  const index = toasts.value.findIndex(t => t.id === id)
  if (index !== -1) {
    toasts.value.splice(index, 1)
  }
}

// Use global SSE
import { useSSE } from '@/composables/useSSE'
const { subscribe, connectionType } = useSSE()

onMounted(async () => {
  // Initial data fetch
  await workerStore.fetchWorkers()

  // Subscribe to real-time worker updates via global SSE
  subscribe('workers', (data) => {
    console.log('[Workers] SSE update received:', data)

    // Update workers list
    if (data.workers) {
      workerStore.workers = data.workers
    }
  })
})

async function refreshWorkers() {
  await workerStore.fetchWorkers()
}

async function restartAllWorkers() {
  restartingAll.value = true
  try {
    // Restart all worker pool instances
    for (let i = 1; i <= 4; i++) {
      await workerStore.restartWorker(`phpborg-worker@${i}`)
    }

    // Also restart scheduler
    await workerStore.restartWorker('phpborg-scheduler')

    showToast(t('workers.notifications.all_restarted'), t('workers.notifications.all_restarted_msg'), 'success')
    await refreshWorkers()
  } catch (err) {
    console.error('Failed to restart all workers:', err)
    showToast(t('toast.error'), t('workers.notifications.restart_all_failed', { error: err.message }), 'error')
  } finally {
    restartingAll.value = false
  }
}

async function startWorker(name) {
  try {
    await workerStore.startWorker(name)
    showToast(t('workers.notifications.worker_started'), t('workers.notifications.worker_started_msg', { name }), 'success')
  } catch (err) {
    console.error('Failed to start worker:', err)
    showToast(t('toast.error'), t('workers.notifications.start_failed', { name, error: err.message }), 'error')
  }
}

async function stopWorker(name) {
  try {
    await workerStore.stopWorker(name)
    showToast(t('workers.notifications.worker_stopped'), t('workers.notifications.worker_stopped_msg', { name }), 'success')
  } catch (err) {
    console.error('Failed to stop worker:', err)
    showToast(t('toast.error'), t('workers.notifications.stop_failed', { name, error: err.message }), 'error')
  }
}

async function restartWorker(name) {
  try {
    await workerStore.restartWorker(name)
    showToast(t('workers.notifications.worker_restarted'), t('workers.notifications.worker_restarted_msg', { name }), 'success')
  } catch (err) {
    console.error('Failed to restart worker:', err)
    showToast(t('toast.error'), t('workers.notifications.restart_failed', { name, error: err.message }), 'error')
  }
}

function openLogsModal(worker) {
  selectedWorker.value = worker
  showLogsModal.value = true
  loadLogs()
}

function closeLogsModal() {
  showLogsModal.value = false
  selectedWorker.value = null
  workerStore.clearLogs()
}

async function loadLogs() {
  if (!selectedWorker.value) return
  await workerStore.fetchLogs(
    selectedWorker.value.name,
    parseInt(logLines.value),
    logSince.value
  )
}
</script>

<style scoped>
@keyframes slide-in {
  from {
    transform: translateX(100%);
    opacity: 0;
  }
  to {
    transform: translateX(0);
    opacity: 1;
  }
}

.animate-slide-in {
  animation: slide-in 0.3s ease-out;
}
</style>
