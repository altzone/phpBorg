<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $t('backups.title') }}</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">{{ $t('backups.subtitle') }}</p>
      </div>
      <RouterLink to="/restore-wizard" class="btn btn-primary">
        <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
        </svg>
        {{ $t('backups.browse_restore_wizard') }}
      </RouterLink>
    </div>

    <!-- Error Message -->
    <div v-if="backupStore.error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
      <div class="flex justify-between items-start">
        <p class="text-sm text-red-800">{{ backupStore.error }}</p>
        <button @click="backupStore.clearError()" class="text-red-500 hover:text-red-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-5 gap-6 mb-8">
      <div class="card bg-blue-50 dark:bg-blue-900/20">
        <div class="text-sm text-blue-600 dark:text-blue-400 mb-1">{{ $t('backups.total_backups') }}</div>
        <div class="text-2xl font-bold text-blue-900 dark:text-blue-100">{{ backupStore.stats.total_backups }}</div>
      </div>
      <div class="card bg-green-50 dark:bg-green-900/20">
        <div class="text-sm text-green-600 dark:text-green-400 mb-1">{{ $t('backups.total_size') }}</div>
        <div class="text-2xl font-bold text-green-900 dark:text-green-100">{{ formatBytes(backupStore.stats.total_original_size) }}</div>
        <div class="text-xs text-green-600 dark:text-green-400 mt-1">{{ $t('backups.original') }}</div>
      </div>
      <div class="card bg-purple-50 dark:bg-purple-900/20">
        <div class="text-sm text-purple-600 dark:text-purple-400 mb-1">{{ $t('backups.compression') }}</div>
        <div class="text-2xl font-bold text-purple-900 dark:text-purple-100">{{ backupStore.stats.compression_ratio }}%</div>
        <div class="text-xs text-purple-600 dark:text-purple-400 mt-1">{{ formatBytes(backupStore.stats.total_compressed_size) }} {{ $t('backups.saved') }}</div>
      </div>
      <div class="card bg-orange-50 dark:bg-orange-900/20">
        <div class="text-sm text-orange-600 dark:text-orange-400 mb-1">{{ $t('backups.deduplication') }}</div>
        <div class="text-2xl font-bold text-orange-900 dark:text-orange-100">{{ backupStore.stats.deduplication_ratio }}%</div>
        <div class="text-xs text-orange-600 dark:text-orange-400 mt-1">{{ formatBytes(backupStore.stats.total_deduplicated_size) }} {{ $t('backups.stored') }}</div>
      </div>
      <div class="card bg-teal-50 dark:bg-teal-900/20">
        <div class="text-sm text-teal-600 dark:text-teal-400 mb-1">{{ $t('backups.avg_transfer_rate') }}</div>
        <div class="text-2xl font-bold text-teal-900 dark:text-teal-100">
          {{ backupStore.stats.avg_transfer_rate ? formatRate(backupStore.stats.avg_transfer_rate) : 'N/A' }}
        </div>
        <div class="text-xs text-teal-600 dark:text-teal-400 mt-1">{{ $t('backups.average_speed') }}</div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="backupStore.loading && !backupStore.backups.length" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backups.loading_backups') }}</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="!backupStore.backups.length" class="card">
      <div class="text-center py-16 text-gray-500 dark:text-gray-400 dark:text-gray-500">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">{{ $t('backups.no_backups') }}</h3>
        <p class="text-sm">{{ $t('backups.no_backups_msg') }}</p>
      </div>
    </div>

    <!-- Backups List -->
    <div v-else class="card">
      <!-- Search Bar -->
      <div class="mb-6">
        <div class="relative">
          <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <svg class="h-5 w-5 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
          </div>
          <input
            v-model="searchQuery"
            type="text"
            class="block w-full pl-10 pr-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md leading-5 bg-white placeholder-gray-500 dark:placeholder-gray-400 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-primary-500 focus:border-primary-500 sm:text-sm"
            :placeholder="$t('backups.search_placeholder')"
          >
        </div>
      </div>

      <div class="overflow-x-auto">
        <table class="w-full">
          <thead class="bg-gray-50 dark:bg-gray-800 border-b">
            <tr>
              <th @click="sortBy('server_name')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 dark:text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700">
                <div class="flex items-center gap-1">
                  {{ $t('backups.server') }}
                  <svg v-if="sortColumn === 'server_name'" class="w-4 h-4" :class="{'rotate-180': sortDirection === 'desc'}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                  </svg>
                </div>
              </th>
              <th @click="sortBy('repository_type')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 dark:text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700">
                <div class="flex items-center gap-1">
                  {{ $t('backups.storage_pool') }}
                  <svg v-if="sortColumn === 'repository_type'" class="w-4 h-4" :class="{'rotate-180': sortDirection === 'desc'}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                  </svg>
                </div>
              </th>
              <th @click="sortBy('name')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 dark:text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700">
                <div class="flex items-center gap-1">
                  {{ $t('backups.archive') }}
                  <svg v-if="sortColumn === 'name'" class="w-4 h-4" :class="{'rotate-180': sortDirection === 'desc'}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                  </svg>
                </div>
              </th>
              <th @click="sortBy('end')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 dark:text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700">
                <div class="flex items-center gap-1">
                  {{ $t('backups.date') }}
                  <svg v-if="sortColumn === 'end'" class="w-4 h-4" :class="{'rotate-180': sortDirection === 'desc'}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                  </svg>
                </div>
              </th>
              <th @click="sortBy('original_size')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 dark:text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700">
                <div class="flex items-center gap-1">
                  {{ $t('backups.size') }}
                  <svg v-if="sortColumn === 'original_size'" class="w-4 h-4" :class="{'rotate-180': sortDirection === 'desc'}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                  </svg>
                </div>
              </th>
              <th @click="sortBy('files_count')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 dark:text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700">
                <div class="flex items-center gap-1">
                  {{ $t('backups.files') }}
                  <svg v-if="sortColumn === 'files_count'" class="w-4 h-4" :class="{'rotate-180': sortDirection === 'desc'}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7" />
                  </svg>
                </div>
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 dark:text-gray-500 uppercase tracking-wider">{{ $t('backups.stats') }}</th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 dark:text-gray-500 uppercase tracking-wider" v-if="authStore.isAdmin">{{ $t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="backup in filteredAndSortedBackups" :key="backup.id" class="hover:bg-gray-50 dark:hover:bg-gray-700 dark:bg-gray-800">
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ backup.server_name }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-primary-100 text-primary-800">
                  {{ backup.repository_type }}
                </span>
              </td>
              <td class="px-6 py-4">
                <div class="flex items-center gap-2 flex-wrap">
                  <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ backup.name }}</div>
                  <span
                    v-if="backup.mount_status === 'mounted'"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800"
                    :title="$t('backups.mounted_tooltip')"
                  >
                    <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                    </svg>
                    {{ $t('backups.mounted') }}
                  </span>
                  <span
                    v-else-if="backup.mount_status === 'mounting'"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800"
                    :title="$t('backups.mounting_tooltip')"
                  >
                    <svg class="w-3 h-3 mr-1 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    {{ $t('backups.mounting') }}
                  </span>
                  <span
                    v-else-if="backup.mount_status === 'unmounting'"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800"
                    :title="$t('backups.unmounting_tooltip')"
                  >
                    {{ $t('backups.unmounting') }}
                  </span>
                  <span
                    v-else-if="backup.mount_status === 'error'"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800"
                    :title="$t('backups.mount_error_tooltip')"
                  >
                    {{ $t('backups.mount_error') }}
                  </span>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900 dark:text-gray-100">{{ formatDate(backup.end) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">{{ backup.duration_formatted }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900 dark:text-gray-100">{{ formatBytes(backup.original_size) }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">→ {{ formatBytes(backup.deduplicated_size) }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                {{ backup.files_count.toLocaleString() }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500">
                  <div>{{ $t('backups.compression_stats') }}: <span class="font-medium">{{ backup.compression_ratio }}%</span></div>
                  <div>{{ $t('backups.dedup_stats') }}: <span class="font-medium">{{ backup.deduplication_ratio }}%</span></div>
                  <div v-if="backup.avg_transfer_rate" class="flex items-center gap-1 mt-1">
                    <svg class="w-3 h-3 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                    </svg>
                    <span class="font-medium text-green-600 dark:text-green-400">{{ formatRate(backup.avg_transfer_rate) }}</span>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium" v-if="authStore.isAdmin">
                <div class="flex justify-end gap-2">
                  <button
                    @click="handleBrowse(backup)"
                    class="text-primary-600 hover:text-primary-900"
                    :title="$t('backups.browse_files')"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                    </svg>
                  </button>
                  <button
                    @click="confirmDelete(backup)"
                    class="text-red-600 hover:text-red-900"
                    :title="$t('backups.delete_backup')"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Results count -->
      <div v-if="searchQuery" class="mt-4 text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500">
        {{ $t('backups.results_found', { count: filteredAndSortedBackups.length }) }}
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteModal" class="fixed inset-0 bg-gray-600 dark:bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="showDeleteModal = false">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $t('backups.delete_modal.title') }}</h3>
          <button @click="showDeleteModal = false" class="text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:hover:text-gray-400">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="text-sm text-gray-600 dark:text-gray-400 mb-6">
          <p class="mb-3">
            {{ $t('backups.delete_modal.question') }}
          </p>
          <div class="bg-gray-50 dark:bg-gray-700 p-3 rounded-lg mb-3">
            <p><strong>{{ $t('backups.delete_modal.archive') }}</strong> {{ backupToDelete?.name }}</p>
            <p><strong>{{ $t('backups.delete_modal.server') }}</strong> {{ backupToDelete?.server_name }}</p>
            <p><strong>{{ $t('backups.delete_modal.type') }}</strong> {{ backupToDelete?.repository_type }}</p>
          </div>
          <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3">
            <p class="text-yellow-800 dark:text-yellow-300 font-medium mb-1">{{ $t('backups.delete_modal.warning_title') }}</p>
            <p class="text-yellow-700 dark:text-yellow-400 text-xs">
              • <span v-html="$t('backups.delete_modal.warning_irreversible')"></span><br>
              • {{ $t('backups.delete_modal.warning_permanent') }}<br>
              • {{ $t('backups.delete_modal.warning_background') }}
            </p>
          </div>
        </div>

        <div class="flex gap-3">
          <button @click="showDeleteModal = false" class="btn btn-secondary flex-1">
            {{ $t('backups.delete_modal.cancel') }}
          </button>
          <button @click="handleDelete" class="btn bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-900/30 dark:text-red-300 dark:hover:bg-red-900/50 flex-1">
            {{ $t('backups.delete_modal.delete') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useBackupStore } from '@/stores/backups'
import { useServerStore } from '@/stores/server'
import { useToastStore } from '@/stores/toast'

const { t } = useI18n()

const router = useRouter()
const authStore = useAuthStore()
const backupStore = useBackupStore()
const serverStore = useServerStore()
const toast = useToastStore()

const showDeleteModal = ref(false)
const backupToDelete = ref(null)

// Search and sort
const searchQuery = ref('')
const sortColumn = ref('end')
const sortDirection = ref('desc')

// Filtered and sorted backups
const filteredAndSortedBackups = computed(() => {
  let filtered = backupStore.backups

  // Filter by search query
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(backup =>
      backup.name.toLowerCase().includes(query) ||
      backup.server_name.toLowerCase().includes(query) ||
      backup.repository_type.toLowerCase().includes(query)
    )
  }

  // Sort
  const sorted = [...filtered].sort((a, b) => {
    let aVal = a[sortColumn.value]
    let bVal = b[sortColumn.value]

    // Handle null/undefined
    if (aVal == null) aVal = ''
    if (bVal == null) bVal = ''

    // Compare
    if (typeof aVal === 'string') {
      aVal = aVal.toLowerCase()
      bVal = bVal.toLowerCase()
    }

    if (aVal < bVal) return sortDirection.value === 'asc' ? -1 : 1
    if (aVal > bVal) return sortDirection.value === 'asc' ? 1 : -1
    return 0
  })

  return sorted
})

// Sort function
function sortBy(column) {
  if (sortColumn.value === column) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortColumn.value = column
    sortDirection.value = 'asc'
  }
}

// Auto-refresh when there are mount/unmount operations in progress
const hasPendingMountOperations = computed(() => {
  return backupStore.backups.some(backup =>
    backup.mount_status === 'mounting' || backup.mount_status === 'unmounting'
  )
})

let refreshInterval = null

watch(hasPendingMountOperations, (hasPending) => {
  if (hasPending && !refreshInterval) {
    // Start polling every 2 seconds
    refreshInterval = setInterval(async () => {
      await backupStore.fetchBackups({ limit: 100 })
    }, 2000)
  } else if (!hasPending && refreshInterval) {
    // Stop polling when no operations are pending
    clearInterval(refreshInterval)
    refreshInterval = null
  }
}, { immediate: true })

onMounted(async () => {
  await Promise.all([
    backupStore.fetchBackups({ limit: 100 }),
    backupStore.fetchStats(),
    serverStore.fetchServers()
  ])
})

onBeforeUnmount(() => {
  if (refreshInterval) {
    clearInterval(refreshInterval)
    refreshInterval = null
  }
})

function confirmDelete(backup) {
  backupToDelete.value = backup
  showDeleteModal.value = true
}

async function handleDelete() {
  if (!backupToDelete.value) return

  try {
    const result = await backupStore.deleteBackup(backupToDelete.value.id)
    if (result.success) {
      showDeleteModal.value = false
      backupToDelete.value = null

      // Show success message with job info
      toast.success(
        t('backups.delete_success.title'),
        t('backups.delete_success.message', { name: result.archive_name }) + ' ' + t('backups.delete_success.job_id', { id: result.job_id })
      )
    }
  } catch (err) {
    console.error('Delete error:', err)
  }
}

function handleBrowse(backup) {
  router.push({
    name: 'archive-browser',
    params: { id: backup.id },
    query: { name: backup.name }
  })
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
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

function formatDate(dateString) {
  if (!dateString) return '-'
  const date = new Date(dateString)
  return date.toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}
</script>
