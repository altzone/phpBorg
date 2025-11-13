<template>
  <div>
    <!-- Back Button -->
    <button
      @click="$router.back()"
      class="mb-6 flex items-center text-gray-600 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100"
    >
      <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
      {{ $t('server_detail.back_to_servers') }}
    </button>

    <!-- Loading State -->
    <div v-if="serverStore.loading && !serverStore.currentServer" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400">{{ $t('server_detail.loading') }}</p>
      </div>
    </div>

    <!-- Server Not Found -->
    <div v-else-if="!serverStore.currentServer && !serverStore.loading" class="card">
      <div class="text-center py-16">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">{{ $t('server_detail.not_found') }}</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400">{{ $t('server_detail.not_found_msg') }}</p>
      </div>
    </div>

    <!-- Server Details -->
    <div v-else>
      <!-- Header -->
      <div class="flex justify-between items-start mb-8">
        <div>
          <div class="flex items-center gap-3 mb-2">
            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ server.name }}</h1>
            <span
              :class="[
                'px-3 py-1 text-xs font-semibold rounded-full',
                server.active
                  ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
                  : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200',
              ]"
            >
              {{ server.active ? $t('server_detail.active') : $t('server_detail.inactive') }}
            </span>
          </div>
          <p class="text-gray-600 dark:text-gray-400">{{ server.hostname }}:{{ server.port }}</p>
        </div>

        <div v-if="authStore.isAdmin" class="flex gap-2">
          <button @click="editServer" class="btn btn-secondary">
            {{ $t('server_detail.edit_server') }}
          </button>
          <button @click="confirmDelete" class="btn bg-red-50 text-red-700 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/30">
            {{ $t('server_detail.delete') }}
          </button>
        </div>
      </div>

      <!-- Server Info Card -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="card">
          <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">{{ $t('server_detail.connection') }}</h3>
          <div class="space-y-2">
            <div class="flex items-center text-gray-900 dark:text-gray-100">
              <svg class="w-5 h-5 mr-2 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
              </svg>
              <span>{{ server.hostname }}</span>
            </div>
            <div class="flex items-center text-gray-900 dark:text-gray-100">
              <svg class="w-5 h-5 mr-2 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" />
              </svg>
              <span>{{ $t('server_detail.port') }} {{ server.port }}</span>
            </div>
            <div class="flex items-center text-gray-900 dark:text-gray-100">
              <svg class="w-5 h-5 mr-2 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
              <span>{{ server.username || 'root' }}</span>
            </div>
          </div>
        </div>

        <div class="card">
          <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">{{ $t('server_detail.statistics') }}</h3>
          <div class="space-y-2">
            <div class="flex justify-between">
              <span class="text-gray-600 dark:text-gray-400">{{ $t('server_detail.total_repositories') }}</span>
              <span class="font-semibold text-gray-900 dark:text-gray-100">{{ statistics.total_repositories }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600 dark:text-gray-400">{{ $t('server_detail.total_backups') }}</span>
              <span class="font-semibold text-gray-900 dark:text-gray-100">{{ statistics.total_backups }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600 dark:text-gray-400">{{ $t('server_detail.storage_used') }}</span>
              <span class="font-semibold text-gray-900 dark:text-gray-100">{{ formatBytes(statistics.storage_used) }}</span>
            </div>
          </div>
        </div>

        <div class="card">
          <h3 class="text-sm font-medium text-gray-500 dark:text-gray-400 mb-2">{{ $t('server_detail.details') }}</h3>
          <div class="space-y-2 text-sm">
            <div>
              <span class="text-gray-600 dark:text-gray-400">{{ $t('server_detail.created') }}</span>
              <span class="ml-2 text-gray-900 dark:text-gray-100">{{ formatDate(server.created_at) }}</span>
            </div>
            <div v-if="server.updated_at">
              <span class="text-gray-600 dark:text-gray-400">{{ $t('server_detail.updated') }}</span>
              <span class="ml-2 text-gray-900 dark:text-gray-100">{{ formatDate(server.updated_at) }}</span>
            </div>
            <div v-if="server.description" class="pt-2 border-t dark:border-gray-700">
              <span class="text-gray-600 dark:text-gray-400">{{ $t('server_detail.description') }}</span>
              <p class="mt-1 text-gray-900 dark:text-gray-100">{{ server.description }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Repositories -->
      <div class="card">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $t('server_detail.repositories') }}</h2>

        <div v-if="repositories.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
          <p>{{ $t('server_detail.no_repositories') }}</p>
          <p class="text-sm mt-2">{{ $t('server_detail.repositories_setup_msg') }}</p>
        </div>

        <div v-else class="space-y-3">
          <div
            v-for="repo in repositories"
            :key="repo.id"
            class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 dark:bg-gray-800 transition"
          >
            <div class="flex justify-between items-start">
              <div class="flex-1">
                <div class="flex items-center gap-2 mb-1">
                  <h3 class="font-medium text-gray-900 dark:text-gray-100">{{ repo.type }} {{ $t('server_detail.repository') }}</h3>
                  <span class="px-2 py-0.5 text-xs bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300 rounded">
                    {{ repo.compression }}
                  </span>
                  <span class="px-2 py-0.5 text-xs bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-300 rounded">
                    {{ repo.encryption }}
                  </span>
                </div>
                <p class="text-sm text-gray-600 dark:text-gray-400 font-mono">{{ repo.repo_path }}</p>

                <!-- Retention Policy Display -->
                <div v-if="repo.retention" class="mt-3 flex items-center gap-4 text-sm">
                  <div class="flex items-center text-gray-600 dark:text-gray-400">
                    <svg class="w-4 h-4 mr-1.5 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <span class="font-medium mr-2">{{ $t('server_detail.retention') }}</span>
                  </div>
                  <div class="flex items-center gap-3">
                    <span v-if="repo.retention.keep_daily > 0" class="text-gray-700 dark:text-gray-300">
                      <strong>{{ repo.retention.keep_daily }}</strong> {{ $t('server_detail.daily') }}
                    </span>
                    <span v-if="repo.retention.keep_weekly > 0" class="text-gray-700 dark:text-gray-300">
                      <strong>{{ repo.retention.keep_weekly }}</strong> {{ $t('server_detail.weekly') }}
                    </span>
                    <span v-if="repo.retention.keep_monthly > 0" class="text-gray-700 dark:text-gray-300">
                      <strong>{{ repo.retention.keep_monthly }}</strong> {{ $t('server_detail.monthly') }}
                    </span>
                    <span v-if="repo.retention.keep_yearly > 0" class="text-gray-700 dark:text-gray-300">
                      <strong>{{ repo.retention.keep_yearly }}</strong> {{ $t('server_detail.yearly') }}
                    </span>
                  </div>
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400 mt-2">
                  {{ $t('server_detail.last_modified') }} {{ formatDate(repo.modified) }}
                </p>
              </div>

              <!-- Manage Retention Button -->
              <button
                @click="openRetentionModal(repo)"
                class="ml-4 px-4 py-2 text-sm font-medium text-blue-700 bg-blue-50 rounded-lg hover:bg-blue-100 dark:bg-blue-900/20 dark:text-blue-300 dark:hover:bg-blue-900/30 transition flex items-center gap-2"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4" />
                </svg>
                {{ $t('server_detail.manage_retention') }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Backups -->
      <div class="card mt-6">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $t('server_detail.recent_backups') }}</h2>

        <!-- Empty State -->
        <div v-if="recentBackups.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
          <svg class="w-12 h-12 mx-auto mb-3 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
          </svg>
          <p class="text-sm">{{ $t('server_detail.no_backups') }}</p>
        </div>

        <!-- Backups Table -->
        <div v-else class="overflow-x-auto">
          <table class="w-full">
            <thead class="bg-gray-50 dark:bg-gray-800 border-b dark:border-gray-700">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('server_detail.archive_name') }}</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('server_detail.date') }}</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('server_detail.duration') }}</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('server_detail.size') }}</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('server_detail.files') }}</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('server_detail.compression') }}</th>
              </tr>
            </thead>
            <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
              <tr v-for="backup in recentBackups" :key="backup.id" class="hover:bg-gray-50 dark:hover:bg-gray-700">
                <td class="px-4 py-3">
                  <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ backup.name }}</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">{{ backup.archive_id.substring(0, 16) }}...</div>
                </td>
                <td class="px-4 py-3">
                  <div class="text-sm text-gray-900 dark:text-gray-100">{{ formatDate(backup.end) }}</div>
                </td>
                <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                  {{ backup.duration_formatted }}
                </td>
                <td class="px-4 py-3">
                  <div class="text-sm text-gray-900 dark:text-gray-100">{{ formatBytes(backup.original_size) }}</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">→ {{ formatBytes(backup.deduplicated_size) }}</div>
                </td>
                <td class="px-4 py-3 text-sm text-gray-900 dark:text-gray-100">
                  {{ backup.files_count.toLocaleString() }}
                </td>
                <td class="px-4 py-3">
                  <div class="text-sm text-gray-900 dark:text-gray-100">{{ backup.compression_ratio }}%</div>
                  <div class="text-xs text-gray-500 dark:text-gray-400">{{ $t('server_detail.dedup') }} {{ backup.deduplication_ratio }}%</div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Edit Modal -->
    <ServerFormModal
      v-if="showEditModal"
      :server="server"
      @close="showEditModal = false"
      @saved="handleSaved"
    />

    <!-- Delete Confirmation Modal -->
    <DeleteConfirmModal
      v-if="showDeleteModal"
      :server="server"
      @close="showDeleteModal = false"
      @confirm="handleDelete"
    />

    <!-- Retention Management Modal -->
    <RetentionModal
      v-if="selectedRepository"
      :is-open="showRetentionModal"
      :repository="selectedRepository"
      :server-name="server?.name || 'Unknown Server'"
      @close="closeRetentionModal"
      @updated="handleRetentionUpdated"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useServerStore } from '@/stores/server'
import { serverService } from '@/services/server'
import ServerFormModal from '@/components/ServerFormModal.vue'
import DeleteConfirmModal from '@/components/DeleteConfirmModal.vue'
import RetentionModal from '@/components/RetentionModal.vue'

const { t } = useI18n()

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const serverStore = useServerStore()

const showEditModal = ref(false)
const showDeleteModal = ref(false)
const showRetentionModal = ref(false)
const selectedRepository = ref(null)

const server = computed(() => serverStore.currentServer?.server)
const repositories = computed(() => serverStore.currentServer?.repositories || [])
const statistics = computed(() => serverStore.currentServer?.statistics || {
  total_backups: 0,
  total_repositories: 0,
  storage_used: 0,
  original_size: 0,
  compressed_size: 0
})
const recentBackups = computed(() => serverStore.currentServer?.recent_backups || [])

onMounted(async () => {
  const serverId = parseInt(route.params.id)
  await serverStore.fetchServer(serverId)
})

function editServer() {
  showEditModal.value = true
}

function confirmDelete() {
  showDeleteModal.value = true
}

function handleSaved() {
  showEditModal.value = false
  const serverId = parseInt(route.params.id)
  serverStore.fetchServer(serverId)
}

async function handleDelete() {
  try {
    await serverStore.deleteServer(server.value.id)
    router.push('/servers')
  } catch (err) {
    // Error handled by store
    showDeleteModal.value = false
  }
}

function openRetentionModal(repository) {
  selectedRepository.value = repository
  showRetentionModal.value = true
}

function closeRetentionModal() {
  showRetentionModal.value = false
  setTimeout(() => {
    selectedRepository.value = null
  }, 300)
}

async function handleRetentionUpdated() {
  // Refresh server data to get updated retention values
  const serverId = parseInt(route.params.id)
  await serverStore.fetchServer(serverId)
}

function formatDate(dateString) {
  if (!dateString) return t('server_detail.na')
  return new Date(dateString).toLocaleString('fr-FR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function formatBytes(bytes) {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
}
</script>
