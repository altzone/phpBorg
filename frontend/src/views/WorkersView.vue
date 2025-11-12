<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">Workers</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">Manage your backup worker pool and scheduler</p>
      </div>
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
        Refresh
      </button>
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
        <p class="mt-4 text-gray-600 dark:text-gray-400">Loading workers...</p>
      </div>
    </div>

    <!-- Workers Grid -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div
        v-for="worker in workerStore.workers"
        :key="worker.name"
        class="group relative bg-white dark:bg-gray-800 rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-500 transition-all duration-300 hover:shadow-xl overflow-hidden"
      >
        <!-- Top colored bar -->
        <div
          :class="[
            'h-2 w-full',
            worker.active
              ? 'bg-gradient-to-r from-green-400 to-emerald-500'
              : 'bg-gradient-to-r from-gray-300 to-gray-400 dark:from-gray-600 dark:to-gray-700'
          ]"
        ></div>

        <div class="p-6">
          <!-- Worker Icon & Status -->
          <div class="flex items-start justify-between mb-4">
            <div class="flex items-center gap-3">
              <!-- Worker Icon -->
              <div :class="[
                'p-3 rounded-lg',
                worker.active
                  ? 'bg-gradient-to-br from-primary-400 to-primary-600 shadow-lg shadow-primary-500/30'
                  : 'bg-gradient-to-br from-gray-300 to-gray-500 dark:from-gray-600 dark:to-gray-700'
              ]">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
                </svg>
              </div>

              <div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ worker.display_name }}</h3>
                <div class="flex items-center gap-1 mt-0.5">
                  <div :class="[
                    'w-2 h-2 rounded-full',
                    worker.active
                      ? 'bg-green-500 animate-pulse'
                      : 'bg-gray-400 dark:bg-gray-600'
                  ]"></div>
                  <span :class="[
                    'text-xs font-medium',
                    worker.active
                      ? 'text-green-700 dark:text-green-400'
                      : 'text-gray-500 dark:text-gray-400'
                  ]">
                    {{ worker.status }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- Worker Details -->
          <div class="space-y-3 mb-4">
            <!-- Service Name -->
            <div class="flex items-center gap-2 p-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
              <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
              </svg>
              <span class="text-sm font-mono text-gray-700 dark:text-gray-300">{{ worker.name }}</span>
            </div>

            <!-- PID -->
            <div v-if="worker.pid" class="flex items-center gap-2 p-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
              <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
              </svg>
              <span class="text-sm text-gray-700 dark:text-gray-300">PID: {{ worker.pid }}</span>
            </div>

            <!-- Memory -->
            <div v-if="worker.memory" class="flex items-center gap-2 p-2 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800">
              <svg class="w-4 h-4 text-blue-500 dark:text-blue-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
              </svg>
              <span class="text-sm text-blue-900 dark:text-blue-200">Memory: {{ worker.memory }}</span>
            </div>

            <!-- CPU -->
            <div v-if="worker.cpu" class="flex items-center gap-2 p-2 bg-green-50 dark:bg-green-900/20 rounded-lg border border-green-100 dark:border-green-800">
              <svg class="w-4 h-4 text-green-500 dark:text-green-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
              <span class="text-sm text-green-900 dark:text-green-200">CPU: {{ worker.cpu }}</span>
            </div>

            <!-- Uptime -->
            <div v-if="worker.uptime" class="flex items-center gap-2 p-2 bg-purple-50 dark:bg-purple-900/20 rounded-lg border border-purple-100 dark:border-purple-800">
              <svg class="w-4 h-4 text-purple-500 dark:text-purple-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span class="text-sm text-purple-900 dark:text-purple-200">{{ worker.uptime }}</span>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="flex gap-2">
            <button
              v-if="!worker.active"
              @click.stop="startWorker(worker.name)"
              class="flex-1 px-3 py-2 bg-green-500 hover:bg-green-600 text-white rounded-lg text-sm font-medium transition-colors"
            >
              Start
            </button>
            <button
              v-if="worker.active"
              @click.stop="stopWorker(worker.name)"
              class="flex-1 px-3 py-2 bg-red-500 hover:bg-red-600 text-white rounded-lg text-sm font-medium transition-colors"
            >
              Stop
            </button>
            <button
              @click.stop="restartWorker(worker.name)"
              class="flex-1 px-3 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-lg text-sm font-medium transition-colors"
            >
              Restart
            </button>
            <button
              @click.stop="openLogsModal(worker)"
              class="px-3 py-2 bg-gray-600 hover:bg-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600 text-white rounded-lg text-sm font-medium transition-colors"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
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
            <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Worker Logs</h2>
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
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Lines:</label>
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
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Since:</label>
            <select
              v-model="logSince"
              @change="loadLogs"
              class="px-3 py-1 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-gray-100"
            >
              <option value="5 minutes ago">5 minutes</option>
              <option value="15 minutes ago">15 minutes</option>
              <option value="1 hour ago">1 hour</option>
              <option value="6 hours ago">6 hours</option>
              <option value="1 day ago">1 day</option>
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
            Refresh
          </button>
        </div>

        <!-- Modal Body -->
        <div class="flex-1 overflow-y-auto p-6">
          <div v-if="workerStore.loading" class="text-center py-8">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
            <p class="mt-4 text-gray-600 dark:text-gray-400">Loading logs...</p>
          </div>
          <pre
            v-else
            class="bg-gray-900 dark:bg-black text-green-400 p-4 rounded-lg text-xs font-mono whitespace-pre-wrap overflow-x-auto"
          >{{ workerStore.logs || 'No logs available' }}</pre>
        </div>

        <!-- Modal Footer -->
        <div class="flex justify-end gap-3 p-6 border-t border-gray-200 dark:border-gray-700">
          <button @click="closeLogsModal" class="btn btn-secondary">
            Close
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useWorkerStore } from '@/stores/workers'
import { useAuthStore } from '@/stores/auth'

const workerStore = useWorkerStore()
const authStore = useAuthStore()

const showLogsModal = ref(false)
const selectedWorker = ref(null)
const logLines = ref(100)
const logSince = ref('1 hour ago')

onMounted(async () => {
  await workerStore.fetchWorkers()
})

async function refreshWorkers() {
  await workerStore.fetchWorkers()
}

async function startWorker(name) {
  try {
    await workerStore.startWorker(name)
  } catch (err) {
    console.error('Failed to start worker:', err)
  }
}

async function stopWorker(name) {
  try {
    await workerStore.stopWorker(name)
  } catch (err) {
    console.error('Failed to stop worker:', err)
  }
}

async function restartWorker(name) {
  try {
    await workerStore.restartWorker(name)
  } catch (err) {
    console.error('Failed to restart worker:', err)
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
