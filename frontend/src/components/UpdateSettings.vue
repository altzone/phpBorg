<template>
  <div>
    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $t('settings.update.title') }}</h3>

    <!-- Current Version Info -->
    <div class="card p-4 mb-6">
      <h4 class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-3">{{ $t('settings.update.current_version') }}</h4>

      <div v-if="loadingVersion" class="text-center py-4">
        <div class="inline-block animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600"></div>
      </div>

      <div v-else-if="versionInfo" class="space-y-2 text-sm">
        <div class="flex justify-between">
          <span class="text-gray-600 dark:text-gray-400">{{ $t('settings.update.commit') }}:</span>
          <span class="font-mono text-gray-900 dark:text-gray-100">{{ versionInfo.commit_short }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-600 dark:text-gray-400">{{ $t('settings.update.branch') }}:</span>
          <span class="text-gray-900 dark:text-gray-100">{{ versionInfo.branch }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-600 dark:text-gray-400">{{ $t('settings.update.date') }}:</span>
          <span class="text-gray-900 dark:text-gray-100">{{ formatDate(versionInfo.date) }}</span>
        </div>
        <div class="flex justify-between">
          <span class="text-gray-600 dark:text-gray-400">{{ $t('settings.update.author') }}:</span>
          <span class="text-gray-900 dark:text-gray-100">{{ versionInfo.author }}</span>
        </div>
        <div class="border-t dark:border-gray-700 pt-2 mt-2">
          <span class="text-gray-600 dark:text-gray-400">{{ $t('settings.update.message') }}:</span>
          <p class="text-gray-900 dark:text-gray-100 mt-1">{{ versionInfo.message }}</p>
        </div>
      </div>
    </div>

    <!-- Update Check -->
    <div class="card p-4 mb-6">
      <div class="flex justify-between items-center mb-4">
        <h4 class="text-md font-semibold text-gray-800 dark:text-gray-200">{{ $t('settings.update.check_title') }}</h4>
        <button
          @click="checkForUpdates"
          :disabled="checkingUpdates"
          class="btn btn-secondary btn-sm"
        >
          <svg v-if="!checkingUpdates" class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <svg v-else class="w-4 h-4 mr-1 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ $t('settings.update.check_button') }}
        </button>
      </div>

      <!-- Update Available -->
      <div v-if="updateInfo && updateInfo.available" class="space-y-4">
        <div class="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
          <div class="flex items-center gap-3 mb-2">
            <svg class="w-6 h-6 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div>
              <p class="font-semibold text-blue-900 dark:text-blue-100">{{ $t('settings.update.available_title') }}</p>
              <p class="text-sm text-blue-700 dark:text-blue-300">
                {{ $t('settings.update.available_description', { commits: updateInfo.commits_behind }) }}
              </p>
            </div>
          </div>
          <div class="flex justify-between text-sm mt-3">
            <span class="text-blue-600 dark:text-blue-400">{{ updateInfo.current_commit_short }} → {{ updateInfo.latest_commit_short }}</span>
          </div>
        </div>

        <!-- Changelog -->
        <div v-if="changelog && changelog.commits && changelog.commits.length > 0">
          <h5 class="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-2">{{ $t('settings.update.changelog_title') }}</h5>
          <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 max-h-64 overflow-y-auto">
            <div v-for="commit in changelog.commits" :key="commit.hash" class="mb-3 last:mb-0 pb-3 last:pb-0 border-b dark:border-gray-700 last:border-0">
              <div class="flex items-start gap-2">
                <span class="font-mono text-xs text-gray-500 dark:text-gray-400 mt-0.5">{{ commit.hash_short }}</span>
                <div class="flex-1 min-w-0">
                  <p class="text-sm text-gray-900 dark:text-gray-100">{{ commit.message }}</p>
                  <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    {{ commit.author }} • {{ formatDate(commit.date) }}
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Update Button -->
        <div class="flex gap-3">
          <button
            @click="startUpdate"
            :disabled="updating"
            class="btn btn-primary flex-1"
          >
            <svg v-if="!updating" class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
            <div v-else class="inline-block animate-spin rounded-full h-5 w-5 border-b-2 border-white mr-2"></div>
            {{ updating ? $t('settings.update.updating') : $t('settings.update.install_button') }}
          </button>
        </div>

        <!-- Warning -->
        <div class="p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
          <p class="text-xs text-yellow-900 dark:text-yellow-200">
            <strong>{{ $t('settings.update.warning_title') }}:</strong> {{ $t('settings.update.warning_message') }}
          </p>
        </div>
      </div>

      <!-- No Update Available -->
      <div v-else-if="updateInfo && !updateInfo.available" class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
        <div class="flex items-center gap-3">
          <svg class="w-6 h-6 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
          <div>
            <p class="font-semibold text-green-900 dark:text-green-100">{{ $t('settings.update.uptodate_title') }}</p>
            <p class="text-sm text-green-700 dark:text-green-300">{{ $t('settings.update.uptodate_description') }}</p>
          </div>
        </div>
      </div>

      <!-- Not checked yet -->
      <div v-else class="text-center py-6 text-gray-500 dark:text-gray-400">
        {{ $t('settings.update.not_checked') }}
      </div>
    </div>

    <!-- Toast Notifications -->
    <div class="fixed bottom-4 right-4 z-50 space-y-3">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        :class="[
          'max-w-md rounded-lg shadow-lg p-4 transform transition-all duration-300',
          toast.type === 'success' ? 'bg-green-500 text-white' :
          toast.type === 'error' ? 'bg-red-500 text-white' :
          'bg-blue-500 text-white',
          'animate-slide-in'
        ]"
      >
        <div class="flex items-start gap-3">
          <div class="flex-shrink-0">
            <svg v-if="toast.type === 'success'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <svg v-else-if="toast.type === 'error'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
            <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
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
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useSSEStore } from '@/stores/sse'
import phpborgUpdateService from '@/services/phpborgUpdate'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
const router = useRouter()
const sseStore = useSSEStore()

const versionInfo = ref(null)
const updateInfo = ref(null)
const changelog = ref(null)
const loadingVersion = ref(true) // Start as true since we load on mount
const checkingUpdates = ref(false)
const updating = ref(false)

// Toast system
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

async function loadCurrentVersion() {
  loadingVersion.value = true
  try {
    const result = await phpborgUpdateService.getCurrentVersion()
    if (result.success) {
      versionInfo.value = result.data
    }
  } catch (error) {
    console.error('Failed to load version:', error)
  } finally {
    loadingVersion.value = false
  }
}

let checkJobId = ref(null)
let sseUnsubscribe = null
let pollingInterval = null

async function checkForUpdates() {
  checkingUpdates.value = true
  try {
    const result = await phpborgUpdateService.checkForUpdates()
    if (result.success && result.data.job_id) {
      checkJobId.value = result.data.job_id

      // Subscribe to SSE for this job
      sseUnsubscribe = sseStore.subscribe(`job.${checkJobId.value}.completed`, handleCheckCompleted)

      // Fallback: polling every 2 seconds
      pollingInterval = setInterval(async () => {
        const jobResult = await phpborgUpdateService.getJobResult(checkJobId.value)
        if (jobResult.success && jobResult.data.job &&
            (jobResult.data.job.status === 'completed' || jobResult.data.job.status === 'failed')) {
          handleCheckCompleted({ job_id: checkJobId.value })
        }
      }, 2000)
    }
  } catch (error) {
    console.error('Failed to check updates:', error)
    showToast(t('settings.update.check_error'), '', 'error')
    checkingUpdates.value = false
  }
}

async function handleCheckCompleted(event) {
  // Cleanup
  if (sseUnsubscribe) {
    sseUnsubscribe()
    sseUnsubscribe = null
  }
  if (pollingInterval) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }

  try {
    // Get job result
    const jobResult = await phpborgUpdateService.getJobResult(checkJobId.value)
    if (jobResult.success && jobResult.data.job) {
      const job = jobResult.data.job

      if (job.status === 'completed') {
        // Parse the output field (not result)
        const resultData = JSON.parse(job.output || '{}')

        if (resultData.update_info) {
          updateInfo.value = resultData.update_info
          changelog.value = resultData.changelog

          // Populate version info from the check result
          if (resultData.version_info) {
            versionInfo.value = resultData.version_info
            loadingVersion.value = false
          }

          if (resultData.update_info.available) {
            showToast(t('settings.update.update_available_toast', { commits: resultData.update_info.commits_behind }), '', 'info')
          } else {
            showToast(t('settings.update.uptodate_toast'), '', 'success')
          }
        }
      } else if (job.status === 'failed') {
        showToast(t('settings.update.check_error'), job.error || '', 'error')
        loadingVersion.value = false
      }
    }
  } catch (error) {
    console.error('Failed to get check result:', error)
    showToast(t('settings.update.check_error'), '', 'error')
    loadingVersion.value = false
  } finally {
    checkingUpdates.value = false
    checkJobId.value = null
  }
}

// Cleanup on unmount
onUnmounted(() => {
  if (sseUnsubscribe) {
    sseUnsubscribe()
  }
  if (pollingInterval) {
    clearInterval(pollingInterval)
  }
})

async function startUpdate() {
  if (!confirm(t('settings.update.confirm_message'))) {
    return
  }

  updating.value = true
  try {
    const result = await phpborgUpdateService.startUpdate()
    if (result.success && result.data.job_id) {
      showToast(t('settings.update.update_started'), '', 'success')

      // Job will automatically appear in taskbar via SSE

      // Reset state
      updating.value = false

      // Navigate to jobs page
      router.push({ name: 'jobs' })
    } else {
      // API returned success:false
      showToast(t('settings.update.update_error'), result.message || '', 'error')
      updating.value = false
    }
  } catch (error) {
    console.error('Failed to start update:', error)
    const errorMsg = error.response?.data?.message || error.message || t('settings.update.update_error')
    showToast(t('settings.update.update_error'), errorMsg, 'error')
    updating.value = false
  }
}

function formatDate(dateString) {
  if (!dateString) return ''
  const date = new Date(dateString)
  return new Intl.DateTimeFormat(undefined, {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  }).format(date)
}

onMounted(async () => {
  // Auto-check for updates on mount (will also populate version info)
  await checkForUpdates()
})
</script>
