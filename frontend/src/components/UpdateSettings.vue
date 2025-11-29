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
          <div class="text-sm mt-3 space-y-2">
            <div class="flex justify-between">
              <span class="text-blue-600 dark:text-blue-400">{{ updateInfo.current_commit_short }} → {{ updateInfo.latest_commit_short }}</span>
            </div>
            <!-- Latest commit message -->
            <div v-if="updateInfo.latest_message" class="p-2 bg-blue-100 dark:bg-blue-800/30 rounded text-blue-800 dark:text-blue-200">
              <span class="font-mono text-xs">{{ updateInfo.latest_commit_short }}</span>
              <span class="ml-2">{{ updateInfo.latest_message }}</span>
            </div>
          </div>
        </div>

        <!-- Changelog Accordion -->
        <div v-if="changelog && changelog.commits && changelog.commits.length > 0">
          <button
            @click="changelogExpanded = !changelogExpanded"
            class="w-full flex items-center justify-between p-3 bg-gray-100 dark:bg-gray-700 rounded-lg hover:bg-gray-200 dark:hover:bg-gray-600 transition-colors"
          >
            <div class="flex items-center gap-2">
              <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
              </svg>
              <span class="text-sm font-semibold text-gray-800 dark:text-gray-200">
                {{ $t('settings.update.changelog_title') }}
              </span>
              <span class="px-2 py-0.5 text-xs font-bold bg-blue-600 text-white rounded-full">
                {{ changelog.commits.length }}
              </span>
            </div>
            <svg
              :class="['w-5 h-5 text-gray-500 transition-transform duration-200', changelogExpanded ? 'rotate-180' : '']"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
          </button>

          <!-- Expandable Commits List -->
          <div
            v-show="changelogExpanded"
            class="mt-2 bg-gray-50 dark:bg-gray-800 rounded-lg overflow-hidden border border-gray-200 dark:border-gray-700"
          >
            <div class="max-h-80 overflow-y-auto">
              <div
                v-for="(commit, index) in changelog.commits"
                :key="commit.hash"
                class="p-3 border-b dark:border-gray-700 last:border-0 hover:bg-gray-100 dark:hover:bg-gray-700/50 transition-colors"
              >
                <div class="flex items-start gap-3">
                  <!-- Commit Icon -->
                  <div class="flex-shrink-0 mt-1">
                    <div class="w-6 h-6 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
                      <svg class="w-3.5 h-3.5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4" />
                      </svg>
                    </div>
                  </div>
                  <!-- Commit Details -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1">
                      <span class="font-mono text-xs px-1.5 py-0.5 bg-gray-200 dark:bg-gray-600 text-gray-700 dark:text-gray-300 rounded">
                        {{ commit.hash_short }}
                      </span>
                      <span class="text-xs text-gray-500 dark:text-gray-400">
                        {{ formatDate(commit.date) }}
                      </span>
                    </div>
                    <p class="text-sm text-gray-900 dark:text-gray-100 font-medium">{{ commit.message }}</p>
                    <!-- Commit body (description) -->
                    <p v-if="commit.body" class="text-xs text-gray-600 dark:text-gray-300 mt-2 whitespace-pre-line bg-gray-100 dark:bg-gray-700 p-2 rounded">{{ commit.body }}</p>
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                      <span class="inline-flex items-center gap-1">
                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                        {{ commit.author }}
                      </span>
                    </p>
                  </div>
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

    <!-- Update Progress Modal -->
    <UpdateProgressModal
      :is-open="showUpdateModal"
      :job-id="updateJobId"
      @close="closeUpdateModal"
      @completed="handleUpdateCompleted"
      @failed="handleUpdateFailed"
    />
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useSSEStore } from '@/stores/sse'
import { useUpdateStore } from '@/stores/update'
import phpborgUpdateService from '@/services/phpborgUpdate'
import { useI18n } from 'vue-i18n'
import UpdateProgressModal from './UpdateProgressModal.vue'

const { t } = useI18n()
const router = useRouter()
const sseStore = useSSEStore()
const updateStore = useUpdateStore()

// Local state (synced with store)
const versionInfo = ref(null)
const updateInfo = ref(null)
const changelog = ref(null)
const loadingVersion = ref(true) // Start as true since we load on mount
const checkingUpdates = computed(() => updateStore.checking)
const updating = ref(false)
const changelogExpanded = ref(false) // Accordion state

// Update progress modal
const showUpdateModal = ref(false)
const updateJobId = ref(null)

// Watch store updates and sync local state
watch(() => updateStore.updateInfo, (newVal) => {
  if (newVal) updateInfo.value = newVal
}, { immediate: true })

watch(() => updateStore.changelog, (newVal) => {
  if (newVal) changelog.value = newVal
}, { immediate: true })

watch(() => updateStore.versionInfo, (newVal) => {
  if (newVal) {
    versionInfo.value = newVal
    loadingVersion.value = false
  }
}, { immediate: true })

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

/**
 * Check for updates using the store
 * The store handles the async job polling and updates its state
 */
async function checkForUpdates() {
  try {
    const result = await updateStore.refresh()

    if (result) {
      // Show toast based on result
      if (result.update_info?.available) {
        showToast(t('settings.update.update_available_toast', { commits: result.update_info.commits_behind }), '', 'info')
      } else {
        showToast(t('settings.update.uptodate_toast'), '', 'success')
      }

      // Update local loading state
      if (result.version_info) {
        loadingVersion.value = false
      }
    }
  } catch (error) {
    console.error('Failed to check updates:', error)
    showToast(t('settings.update.check_error'), '', 'error')
  }
}

async function startUpdate() {
  if (!confirm(t('settings.update.confirm_message'))) {
    return
  }

  updating.value = true
  try {
    const result = await phpborgUpdateService.startUpdate()
    if (result.success && result.data.job_id) {
      // Show progress modal instead of navigating away
      updateJobId.value = result.data.job_id
      showUpdateModal.value = true
      updating.value = false
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

function closeUpdateModal() {
  showUpdateModal.value = false
}

function handleUpdateCompleted() {
  showToast(t('settings.update.update_success'), '', 'success')
}

function handleUpdateFailed(error) {
  showToast(t('settings.update.update_error'), error || '', 'error')
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
  // If store already has data, use it; otherwise trigger a refresh
  if (updateStore.updateInfo) {
    loadingVersion.value = false
    // Expand accordion if there are updates
    if (updateStore.hasUpdate && updateStore.commits.length > 0) {
      changelogExpanded.value = true
    }
  } else {
    // Auto-check for updates on mount (will also populate version info)
    await checkForUpdates()
  }
})
</script>
