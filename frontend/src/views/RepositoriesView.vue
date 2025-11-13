<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $t('repositories.title') }}</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">{{ $t('repositories.subtitle') }}</p>
      </div>
      <RouterLink to="/backup-wizard" class="btn btn-primary">
        <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        {{ $t('repositories.new') }}
      </RouterLink>
    </div>

    <!-- Filters -->
    <div class="card mb-6">
      <div class="flex flex-col md:flex-row gap-4">
        <!-- Server Filter -->
        <div class="flex-1">
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
            {{ $t('repositories.filter_by_server') }}
          </label>
          <select
            v-model="selectedServerId"
            class="input w-full"
            @change="filterRepositories"
          >
            <option value="">{{ $t('repositories.all_servers', { count: repositories.length }) }}</option>
            <option v-for="server in servers" :key="server.id" :value="server.id">
              {{ server.name }} ({{ getServerRepoCount(server.id) }} repos)
            </option>
          </select>
        </div>

        <!-- Stats Summary -->
        <div class="flex items-end gap-4 text-sm">
          <div class="text-center">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $t('repositories.showing') }}</div>
            <div class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ filteredRepositories.length }}</div>
          </div>
          <div class="text-center">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $t('repositories.total_archives') }}</div>
            <div class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ totalArchives }}</div>
          </div>
          <div class="text-center">
            <div class="text-xs text-gray-500 dark:text-gray-400">{{ $t('repositories.total_size') }}</div>
            <div class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ formatBytes(totalSize) }}</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400">{{ $t('common.loading_msg') }}</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="!filteredRepositories.length" class="card">
      <div class="text-center py-16 text-gray-500">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
          {{ repositories.length > 0 ? $t('repositories.no_match') : $t('repositories.no_repositories') }}
        </h3>
        <p class="text-sm mb-4" v-if="repositories.length === 0">{{ $t('repositories.use_wizard') }}</p>
        <button v-else @click="selectedServerId = ''" class="btn btn-secondary">
          {{ $t('repositories.clear_filter') }}
        </button>
      </div>
    </div>

    <!-- Repositories Grid -->
    <div v-else class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4">
      <div
        v-for="repo in filteredRepositories"
        :key="repo.id"
        class="bg-gradient-to-br from-white to-gray-50 dark:from-gray-800 dark:to-gray-850 rounded-lg border border-gray-200 dark:border-gray-700 hover:shadow-lg transition-all"
      >
        <!-- Status Bar -->
        <div class="h-1 bg-gradient-to-r from-primary-500 via-primary-600 to-primary-500 rounded-t-lg"></div>

        <div class="p-4">
          <!-- Header -->
          <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-2 flex-1 min-w-0">
              <!-- Icon with type-specific colors -->
              <div
                :class="[
                  'flex-shrink-0 w-10 h-10 rounded-lg flex items-center justify-center text-white shadow-sm',
                  repo.type === 'mysql'
                    ? 'bg-gradient-to-br from-blue-500 to-cyan-600'
                    : 'bg-gradient-to-br from-orange-500 to-amber-600'
                ]"
              >
                <!-- Database Icon for MySQL -->
                <svg v-if="repo.type === 'mysql'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                </svg>
                <!-- Folder/Files Icon for Filesystem -->
                <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                </svg>
              </div>

              <!-- Info -->
              <div class="min-w-0 flex-1">
                <div class="flex items-center gap-1.5 mb-0.5">
                  <h3 class="text-sm font-bold text-gray-900 dark:text-gray-100 truncate">{{ repo.name }}</h3>
                  <!-- Type Badge -->
                  <span
                    :class="[
                      'px-1.5 py-0.5 rounded text-xs font-semibold flex-shrink-0',
                      repo.type === 'mysql'
                        ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300'
                        : 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-300'
                    ]"
                  >
                    {{ repo.type.toUpperCase() }}
                  </span>
                </div>
                <p class="text-xs text-gray-600 dark:text-gray-400 truncate">{{ repo.server_name }}</p>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-1 ml-2">
              <button
                @click="editRetention(repo)"
                class="p-1.5 text-gray-600 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 rounded hover:bg-gray-100 dark:hover:bg-gray-700"
                title="Edit retention policy"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
              </button>
              <button
                @click="confirmDelete(repo)"
                class="p-1.5 text-gray-600 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 rounded hover:bg-red-50 dark:hover:bg-red-900/20"
                title="Delete repository"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </div>

          <!-- Stats -->
          <div class="grid grid-cols-3 gap-2 mb-3">
            <div class="px-2 py-1.5 bg-blue-50/50 dark:bg-blue-900/10 rounded border border-blue-200/50 dark:border-blue-800/50 text-center">
              <div class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ repo.archive_count || 0 }}</div>
              <div class="text-xs text-gray-600 dark:text-gray-400">{{ $t('repositories.archives') }}</div>
            </div>
            <div class="px-2 py-1.5 bg-purple-50/50 dark:bg-purple-900/10 rounded border border-purple-200/50 dark:border-purple-800/50 text-center">
              <div class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ formatBytes(repo.stats?.deduplicated_size || repo.deduplicated_size) }}</div>
              <div class="text-xs text-gray-600 dark:text-gray-400">{{ $t('common.size') }}</div>
            </div>
            <div class="px-2 py-1.5 bg-green-50/50 dark:bg-green-900/10 rounded border border-green-200/50 dark:border-green-800/50 text-center">
              <div class="text-lg font-bold text-gray-900 dark:text-gray-100">{{ repo.deduplication_ratio }}%</div>
              <div class="text-xs text-gray-600 dark:text-gray-400">{{ $t('repositories.dedup') }}</div>
            </div>
          </div>

          <!-- Details -->
          <div class="space-y-1.5 text-xs mb-3">
            <div class="flex justify-between gap-2">
              <span class="text-gray-600 dark:text-gray-400 flex-shrink-0">{{ $t('common.path') }}:</span>
              <span class="text-gray-900 dark:text-gray-100 font-mono text-xs truncate">{{ repo.repo_path }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600 dark:text-gray-400">{{ $t('common.compression') }}:</span>
              <span class="text-gray-900 dark:text-gray-100">{{ repo.compression }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600 dark:text-gray-400">{{ $t('common.encryption') }}:</span>
              <span class="text-gray-900 dark:text-gray-100">{{ repo.encryption }}</span>
            </div>
          </div>

          <!-- Retention Policy -->
          <div class="pt-3 border-t border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between mb-2">
              <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ $t('repositories.retention_policy') }}</span>
              <span class="text-xs text-gray-500 dark:text-gray-400">{{ $t('repositories.last') }}: {{ repo.last_backup_at ? formatDate(repo.last_backup_at) : $t('repositories.never') }}</span>
            </div>
            <div class="grid grid-cols-2 gap-2 text-xs">
              <div class="flex items-center justify-between px-2 py-1.5 bg-gray-50 dark:bg-gray-700/50 rounded">
                <span class="text-gray-600 dark:text-gray-400">{{ $t('repositories.daily') }}:</span>
                <span class="font-bold text-gray-900 dark:text-gray-100">{{ repo.retention?.keep_daily || 0 }}</span>
              </div>
              <div class="flex items-center justify-between px-2 py-1.5 bg-gray-50 dark:bg-gray-700/50 rounded">
                <span class="text-gray-600 dark:text-gray-400">{{ $t('repositories.weekly') }}:</span>
                <span class="font-bold text-gray-900 dark:text-gray-100">{{ repo.retention?.keep_weekly || 0 }}</span>
              </div>
              <div class="flex items-center justify-between px-2 py-1.5 bg-gray-50 dark:bg-gray-700/50 rounded">
                <span class="text-gray-600 dark:text-gray-400">{{ $t('repositories.monthly') }}:</span>
                <span class="font-bold text-gray-900 dark:text-gray-100">{{ repo.retention?.keep_monthly || 0 }}</span>
              </div>
              <div class="flex items-center justify-between px-2 py-1.5 bg-gray-50 dark:bg-gray-700/50 rounded">
                <span class="text-gray-600 dark:text-gray-400">{{ $t('repositories.yearly') }}:</span>
                <span class="font-bold text-gray-900 dark:text-gray-100">{{ repo.retention?.keep_yearly || 0 }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Edit Retention Modal -->
    <div v-if="showRetentionModal" class="fixed inset-0 bg-gray-600 dark:bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="showRetentionModal = false">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $t('repositories.edit_retention') }}</h3>
          <button @click="showRetentionModal = false" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form @submit.prevent="saveRetention">
          <div class="space-y-4 mb-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $t('repositories.keep_daily') }}</label>
              <input v-model.number="retentionForm.keep_daily" type="number" min="0" required class="input w-full">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $t('repositories.keep_weekly') }}</label>
              <input v-model.number="retentionForm.keep_weekly" type="number" min="0" required class="input w-full">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $t('repositories.keep_monthly') }}</label>
              <input v-model.number="retentionForm.keep_monthly" type="number" min="0" required class="input w-full">
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">{{ $t('repositories.keep_yearly') }}</label>
              <input v-model.number="retentionForm.keep_yearly" type="number" min="0" required class="input w-full">
            </div>
          </div>

          <div class="flex gap-3">
            <button type="button" @click="showRetentionModal = false" class="btn btn-secondary flex-1">
              {{ $t('common.cancel') }}
            </button>
            <button type="submit" class="btn btn-primary flex-1">
              {{ $t('common.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteModal" class="fixed inset-0 bg-gray-900/60 backdrop-blur-md overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4" @click.self="showDeleteModal = false">
      <div class="relative mx-auto border-4 max-w-2xl shadow-2xl rounded-lg pulse-border">
        <div class="p-6 bg-white dark:bg-gray-800 rounded-md">
        <div class="flex justify-between items-start mb-6">
          <div class="flex items-center gap-3">
            <div class="p-3 bg-red-100 dark:bg-red-900/30 rounded-full">
              <svg class="w-8 h-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
              </svg>
            </div>
            <div>
              <h3 class="text-2xl font-bold text-red-600">{{ $t('repositories.danger_zone') }}</h3>
              <p class="text-sm text-gray-600 dark:text-gray-400">{{ $t('repositories.action_irreversible') }}</p>
            </div>
          </div>
          <button @click="showDeleteModal = false" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="mb-6 space-y-4">
          <!-- Main Warning -->
          <div class="bg-red-50 dark:bg-red-900/20 border-2 border-red-300 dark:border-red-700 rounded-lg p-4">
            <h4 class="font-bold text-red-900 dark:text-red-300 mb-2 text-lg">{{ $t('repositories.delete_warning') }}</h4>
            <p class="text-red-800 dark:text-red-400 font-mono font-bold text-lg mb-3">{{ repositoryToDelete?.name }}</p>

            <ul class="space-y-2 text-sm text-red-800 dark:text-red-300">
              <li class="flex items-start gap-2">
                <span class="font-bold">❌</span>
                <span v-html="$t('repositories.all_backups_lost', { count: repositoryToDelete?.archive_count || 0 })"></span>
              </li>
              <li class="flex items-start gap-2">
                <span class="font-bold">❌</span>
                <span v-html="$t('repositories.data_deleted', { size: formatBytes(repositoryToDelete?.deduplicated_size) })"></span>
              </li>
              <li class="flex items-start gap-2">
                <span class="font-bold">❌</span>
                <span v-html="$t('repositories.no_recovery')"></span>
              </li>
            </ul>
          </div>

          <!-- Active Jobs Check -->
          <div v-if="deleteCheck.hasActiveJobs" class="bg-yellow-50 dark:bg-yellow-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-lg p-4">
            <h4 class="font-bold text-yellow-900 dark:text-yellow-300 mb-2">{{ $t('repositories.deletion_blocked') }}</h4>
            <p class="text-yellow-800 dark:text-yellow-400 text-sm" v-html="$t('repositories.active_jobs_warning', { count: deleteCheck.activeJobsCount })">
            </p>
          </div>

          <div v-if="deleteCheck.hasMountedArchives" class="bg-yellow-50 dark:bg-yellow-900/20 border-2 border-yellow-300 dark:border-yellow-700 rounded-lg p-4">
            <h4 class="font-bold text-yellow-900 dark:text-yellow-300 mb-2">{{ $t('repositories.deletion_blocked') }}</h4>
            <p class="text-yellow-800 dark:text-yellow-400 text-sm" v-html="$t('repositories.mounted_archives_warning', { count: deleteCheck.mountedCount })">
            </p>
          </div>

          <!-- Confirmation Input -->
          <div v-if="!deleteCheck.hasActiveJobs && !deleteCheck.hasMountedArchives" class="bg-gray-50 dark:bg-gray-700 rounded-lg p-4">
            <label class="block text-sm font-bold text-gray-900 dark:text-gray-100 mb-2">
              {{ $t('repositories.confirm_deletion') }}
            </label>
            <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">{{ $t('repositories.expected_name') }} <code class="bg-gray-200 dark:bg-gray-600 px-2 py-1 rounded">{{ repositoryToDelete?.name }}</code></p>
            <input
              v-model="deleteConfirmName"
              type="text"
              class="input w-full font-mono"
              :placeholder="repositoryToDelete?.name"
              @keyup.enter="executeDelete"
            >
          </div>
        </div>

        <div class="flex gap-3">
          <button
            @click="showDeleteModal = false"
            class="btn btn-secondary flex-1"
          >
            {{ $t('repositories.cancel_recommended') }}
          </button>
          <button
            @click="executeDelete"
            :disabled="deleteCheck.hasActiveJobs || deleteCheck.hasMountedArchives || deleteConfirmName !== repositoryToDelete?.name"
            :class="[
              'flex-1 px-4 py-2 rounded-lg font-semibold transition',
              (deleteCheck.hasActiveJobs || deleteCheck.hasMountedArchives || deleteConfirmName !== repositoryToDelete?.name)
                ? 'bg-gray-300 text-gray-500 cursor-not-allowed'
                : 'bg-red-600 hover:bg-red-700 text-white'
            ]"
          >
            {{ $t('repositories.delete_permanently') }}
          </button>
        </div>
        </div>
      </div>
    </div>

    <!-- Toast Notifications -->
    <div class="fixed bottom-4 right-4 z-[100] space-y-3">
      <div
        v-for="(toast, index) in toasts"
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
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { serverService } from '@/services/server'
import { repositoryService } from '@/services/repository'

const { t } = useI18n()

const loading = ref(false)
const repositories = ref([])
const servers = ref([])
const selectedServerId = ref('')
const showRetentionModal = ref(false)
const retentionForm = ref({
  repository_id: null,
  keep_daily: 7,
  keep_weekly: 4,
  keep_monthly: 6,
  keep_yearly: 0
})

// Delete modal state
const showDeleteModal = ref(false)
const repositoryToDelete = ref(null)
const deleteConfirmName = ref('')
const deleteCheck = ref({
  hasActiveJobs: false,
  activeJobsCount: 0,
  hasMountedArchives: false,
  mountedCount: 0
})

// Toast notifications
const toasts = ref([])
let toastIdCounter = 0

function showToast(title, message = '', type = 'success', duration = 5000) {
  const id = ++toastIdCounter
  toasts.value.push({ id, title, message, type })

  // Auto remove after duration
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

// Computed properties
const filteredRepositories = computed(() => {
  if (!selectedServerId.value) {
    return repositories.value
  }
  return repositories.value.filter(repo => repo.server_id === parseInt(selectedServerId.value))
})

const totalArchives = computed(() => {
  return filteredRepositories.value.reduce((sum, repo) => sum + (repo.archive_count || 0), 0)
})

const totalSize = computed(() => {
  return filteredRepositories.value.reduce((sum, repo) => sum + (repo.deduplicated_size || 0), 0)
})

onMounted(async () => {
  await loadRepositories()
})

async function loadRepositories() {
  loading.value = true
  try {
    // Get all servers
    const serversList = await serverService.list()
    servers.value = serversList

    // Get repositories for each server
    const allRepos = []
    for (const server of serversList) {
      const repos = await repositoryService.listByServer(server.id)
      // Add server name and id to each repo
      repos.forEach(repo => {
        repo.server_id = server.id
        repo.server_name = server.name
        repo.deduplication_ratio = repo.size > 0
          ? Math.round((1 - (repo.deduplicated_size / repo.size)) * 100)
          : 0
      })
      allRepos.push(...repos)
    }

    repositories.value = allRepos
  } catch (err) {
    console.error('Failed to load repositories:', err)
  } finally {
    loading.value = false
  }
}

function getServerRepoCount(serverId) {
  return repositories.value.filter(repo => repo.server_id === serverId).length
}

function filterRepositories() {
  // Computed property handles filtering automatically
}

function editRetention(repo) {
  retentionForm.value = {
    repository_id: repo.id,
    keep_daily: repo.retention?.keep_daily || 7,
    keep_weekly: repo.retention?.keep_weekly || 4,
    keep_monthly: repo.retention?.keep_monthly || 6,
    keep_yearly: repo.retention?.keep_yearly || 0
  }
  showRetentionModal.value = true
}

async function saveRetention() {
  try {
    await repositoryService.updateRetention(
      retentionForm.value.repository_id,
      {
        keep_daily: retentionForm.value.keep_daily,
        keep_weekly: retentionForm.value.keep_weekly,
        keep_monthly: retentionForm.value.keep_monthly,
        keep_yearly: retentionForm.value.keep_yearly
      }
    )

    showRetentionModal.value = false
    await loadRepositories()
    showToast(t('repositories.retention_updated'), t('repositories.retention_updated_msg'), 'success')
  } catch (err) {
    console.error('Failed to update retention:', err)
    showToast(t('repositories.update_failed'), err.response?.data?.error?.message || t('repositories.update_failed_msg'), 'error')
  }
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
}

function formatDate(dateString) {
  if (!dateString) return 'Never'
  const date = new Date(dateString)
  return date.toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

async function confirmDelete(repo) {
  repositoryToDelete.value = repo
  deleteConfirmName.value = ''

  // Reset delete check - backend will enforce safety checks
  deleteCheck.value = {
    hasActiveJobs: false,
    activeJobsCount: 0,
    hasMountedArchives: false,
    mountedCount: 0
  }

  showDeleteModal.value = true
}

async function executeDelete() {
  if (!repositoryToDelete.value) return
  if (deleteConfirmName.value !== repositoryToDelete.value.name) {
    showToast(t('repositories.incorrect_name'), t('repositories.name_mismatch'), 'error')
    return
  }

  try {
    const result = await repositoryService.delete(repositoryToDelete.value.id)

    // Show success message - deletion is now async via job
    showToast(
      t('repositories.deletion_in_progress'),
      t('repositories.deletion_job_created', { count: result.archives_count }),
      'success',
      6000
    )

    showDeleteModal.value = false
    repositoryToDelete.value = null
    deleteConfirmName.value = ''

    // Reload repositories after a short delay to let the job complete
    setTimeout(async () => {
      await loadRepositories()
    }, 5000)
  } catch (err) {
    console.error('Failed to delete repository:', err)

    const errorData = err.response?.data?.error

    // Handle safety check failures from backend
    if (errorData?.code === 'REPOSITORY_HAS_ACTIVE_JOBS') {
      deleteCheck.value = {
        hasActiveJobs: true,
        activeJobsCount: errorData.data?.active_jobs_count || 0,
        hasMountedArchives: false,
        mountedCount: 0
      }
      // Don't close modal, show the blocking message
      return
    }

    if (errorData?.code === 'REPOSITORY_HAS_MOUNTED_ARCHIVES') {
      deleteCheck.value = {
        hasActiveJobs: false,
        activeJobsCount: 0,
        hasMountedArchives: true,
        mountedCount: errorData.data?.mounted_count || 0
      }
      // Don't close modal, show the blocking message
      return
    }

    // Other errors - show toast and close modal
    showToast(t('repositories.deletion_failed'), errorData?.message || err.message, 'error')
    showDeleteModal.value = false
  }
}
</script>

<style scoped>
@keyframes pulse-border {
  0%, 100% {
    border-color: rgb(239, 68, 68); /* red-500 */
  }
  50% {
    border-color: rgb(220, 38, 38); /* red-600 */
    box-shadow: 0 0 0 4px rgba(239, 68, 68, 0.4);
  }
}

.pulse-border {
  animation: pulse-border 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
  border-color: rgb(239, 68, 68);
}

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
