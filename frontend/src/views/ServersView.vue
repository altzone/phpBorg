<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">Servers</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400 dark:text-gray-500">Manage your backup servers</p>
      </div>
      <button
        v-if="authStore.isAdmin"
        @click="showCreateModal = true"
        class="btn btn-primary"
      >
        + Add Server
      </button>
    </div>

    <!-- Error Message -->
    <div v-if="serverStore.error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
      <div class="flex justify-between items-start">
        <p class="text-sm text-red-800">{{ serverStore.error }}</p>
        <button @click="serverStore.clearError()" class="text-red-500 hover:text-red-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="serverStore.loading && !serverStore.servers.length" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400 dark:text-gray-500">Loading servers...</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="!serverStore.servers.length" class="card">
      <div class="text-center py-16 text-gray-500 dark:text-gray-400 dark:text-gray-500">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">No servers yet</h3>
        <p class="text-sm mb-4">Get started by adding your first backup server</p>
        <button
          v-if="authStore.isAdmin"
          @click="showCreateModal = true"
          class="btn btn-primary"
        >
          + Add Server
        </button>
      </div>
    </div>

    <!-- Servers List -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
      <div
        v-for="server in serverStore.servers"
        :key="server.id"
        class="group relative bg-white dark:bg-gray-800 rounded-xl border-2 border-gray-200 dark:border-gray-700 hover:border-primary-400 dark:hover:border-primary-500 transition-all duration-300 hover:shadow-xl hover:-translate-y-1 cursor-pointer overflow-hidden"
        @click="viewServer(server.id)"
      >
        <!-- Top colored bar -->
        <div
          :class="[
            'h-2 w-full',
            server.active
              ? 'bg-gradient-to-r from-green-400 to-emerald-500'
              : 'bg-gradient-to-r from-gray-300 to-gray-400 dark:from-gray-600 dark:to-gray-700'
          ]"
        ></div>

        <div class="p-6">
          <!-- Server Icon & Status -->
          <div class="flex items-start justify-between mb-4">
            <div class="flex items-center gap-3">
              <!-- Server Icon with gradient -->
              <div :class="[
                'p-3 rounded-lg',
                server.active
                  ? 'bg-gradient-to-br from-primary-400 to-primary-600 shadow-lg shadow-primary-500/30'
                  : 'bg-gradient-to-br from-gray-300 to-gray-500 dark:from-gray-600 dark:to-gray-700'
              ]">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                </svg>
              </div>

              <div>
                <h3 class="text-lg font-bold text-gray-900 dark:text-white">{{ server.name }}</h3>
                <div class="flex items-center gap-1 mt-0.5">
                  <div :class="[
                    'w-2 h-2 rounded-full',
                    server.active
                      ? 'bg-green-500 animate-pulse'
                      : 'bg-gray-400 dark:bg-gray-600'
                  ]"></div>
                  <span :class="[
                    'text-xs font-medium',
                    server.active
                      ? 'text-green-700 dark:text-green-400'
                      : 'text-gray-500 dark:text-gray-400'
                  ]">
                    {{ server.active ? 'Online' : 'Offline' }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- Server Details -->
          <div class="space-y-3 mb-4">
            <!-- Hostname -->
            <div class="flex items-center gap-2 p-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
              <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
              </svg>
              <span class="text-sm font-mono text-gray-700 dark:text-gray-300">{{ server.hostname }}:{{ server.port }}</span>
            </div>

            <!-- Username -->
            <div class="flex items-center gap-2 p-2 bg-gray-50 dark:bg-gray-700/50 rounded-lg">
              <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
              <span class="text-sm text-gray-700 dark:text-gray-300">{{ server.username || 'root' }}</span>
            </div>

            <!-- Description -->
            <div v-if="server.description" class="p-2 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-100 dark:border-blue-800">
              <p class="text-sm text-blue-900 dark:text-blue-200 line-clamp-2">{{ server.description }}</p>
            </div>
          </div>

          <!-- System Stats -->
          <div v-if="server.stats" class="space-y-2 mb-4 p-3 bg-gradient-to-br from-gray-50 to-blue-50 dark:from-gray-700/30 dark:to-blue-900/10 rounded-lg border border-gray-200 dark:border-gray-600">
            <div class="flex items-center justify-between text-xs mb-2">
              <span class="font-semibold text-gray-700 dark:text-gray-300">System Info</span>
              <button
                @click.stop="refreshStats(server.id)"
                class="text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 transition-colors"
                title="Refresh stats"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
              </button>
            </div>

            <!-- OS -->
            <div class="flex items-center gap-2 text-xs">
              <svg class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
              </svg>
              <span class="text-gray-700 dark:text-gray-300">
                {{ server.stats.os_distribution }} {{ server.stats.os_version }}
              </span>
            </div>

            <!-- CPU -->
            <div class="flex items-center justify-between text-xs">
              <div class="flex items-center gap-2">
                <svg class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                </svg>
                <span class="text-gray-700 dark:text-gray-300">
                  CPU: {{ server.stats.cpu_cores }} cores
                </span>
              </div>
              <span class="font-medium" :class="getCpuColor(server.stats.cpu_usage_percent)">
                {{ server.stats.cpu_usage_percent?.toFixed(1) }}%
              </span>
            </div>

            <!-- Memory -->
            <div class="text-xs">
              <div class="flex items-center justify-between mb-1">
                <div class="flex items-center gap-2">
                  <svg class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2 1 3 3 3h10c2 0 3-1 3-3V7c0-2-1-3-3-3H7c-2 0-3 1-3 3z" />
                  </svg>
                  <span class="text-gray-700 dark:text-gray-300">RAM</span>
                </div>
                <span class="font-medium" :class="getMemoryColor(server.stats.memory_percent)">
                  {{ server.stats.memory_percent?.toFixed(0) }}%
                </span>
              </div>
              <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                <div
                  class="h-1.5 rounded-full transition-all"
                  :class="getMemoryColorBg(server.stats.memory_percent)"
                  :style="{ width: `${server.stats.memory_percent}%` }"
                ></div>
              </div>
              <div class="flex justify-between mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                <span>{{ formatBytes(server.stats.memory_used_mb * 1024 * 1024) }}</span>
                <span>{{ formatBytes(server.stats.memory_total_mb * 1024 * 1024) }}</span>
              </div>
            </div>

            <!-- Disk -->
            <div class="text-xs">
              <div class="flex items-center justify-between mb-1">
                <div class="flex items-center gap-2">
                  <svg class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z" />
                  </svg>
                  <span class="text-gray-700 dark:text-gray-300">Disk</span>
                </div>
                <span class="font-medium" :class="getDiskColor(server.stats.disk_percent)">
                  {{ server.stats.disk_percent?.toFixed(0) }}%
                </span>
              </div>
              <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5">
                <div
                  class="h-1.5 rounded-full transition-all"
                  :class="getDiskColorBg(server.stats.disk_percent)"
                  :style="{ width: `${server.stats.disk_percent}%` }"
                ></div>
              </div>
              <div class="flex justify-between mt-0.5 text-[10px] text-gray-500 dark:text-gray-400">
                <span>{{ server.stats.disk_used_gb?.toFixed(1) }} GB</span>
                <span>{{ server.stats.disk_total_gb?.toFixed(1) }} GB</span>
              </div>
            </div>

            <!-- Uptime -->
            <div class="flex items-center gap-2 text-xs">
              <svg class="w-3.5 h-3.5 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span class="text-gray-700 dark:text-gray-300">
                Uptime: {{ server.stats.uptime_human }}
              </span>
            </div>
          </div>

          <!-- No stats available -->
          <div v-else class="mb-4 p-3 bg-yellow-50 dark:bg-yellow-900/20 rounded-lg border border-yellow-200 dark:border-yellow-800">
            <div class="flex items-start justify-between gap-2">
              <div class="flex items-start gap-2">
                <svg class="w-4 h-4 text-yellow-600 dark:text-yellow-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <p class="text-xs text-yellow-800 dark:text-yellow-200">No stats yet</p>
              </div>
              <button
                @click.stop="refreshStats(server.id)"
                class="text-xs text-yellow-700 dark:text-yellow-300 hover:text-yellow-900 dark:hover:text-yellow-100 font-medium whitespace-nowrap"
              >
                Collect now
              </button>
            </div>
          </div>

          <!-- Footer Info -->
          <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400 pt-3 border-t border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-1">
              <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span>{{ formatDate(server.created_at) }}</span>
            </div>
            <span class="text-primary-600 dark:text-primary-400 font-medium group-hover:translate-x-1 transition-transform">
              View details →
            </span>
          </div>
        </div>

        <!-- Actions Overlay (appears on hover) -->
        <div
          v-if="authStore.isAdmin"
          class="absolute bottom-0 left-0 right-0 p-4 bg-gradient-to-t from-white dark:from-gray-800 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300 flex gap-2"
        >
          <button
            @click.stop="editServer(server)"
            class="flex-1 px-3 py-2 bg-primary-600 hover:bg-primary-700 text-white text-sm font-medium rounded-lg transition-colors shadow-lg"
          >
            Edit
          </button>
          <button
            @click.stop="confirmDelete(server)"
            class="flex-1 px-3 py-2 bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-lg transition-colors shadow-lg"
          >
            Delete
          </button>
        </div>
      </div>
    </div>

    <!-- Create/Edit Modal -->
    <ServerFormModal
      v-if="showCreateModal || showEditModal"
      :server="editingServer"
      @close="closeModal"
      @saved="handleSaved"
    />

    <!-- Delete Confirmation Modal -->
    <DeleteConfirmModal
      v-if="showDeleteModal"
      :server="deletingServer"
      @close="showDeleteModal = false"
      @confirm="handleDelete"
    />
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useServerStore } from '@/stores/server'
import ServerFormModal from '@/components/ServerFormModal.vue'
import DeleteConfirmModal from '@/components/DeleteConfirmModal.vue'

const router = useRouter()
const authStore = useAuthStore()
const serverStore = useServerStore()

const showCreateModal = ref(false)
const showEditModal = ref(false)
const showDeleteModal = ref(false)
const editingServer = ref(null)
const deletingServer = ref(null)

onMounted(async () => {
  await serverStore.fetchServers()
})

function viewServer(id) {
  router.push(`/servers/${id}`)
}

function editServer(server) {
  editingServer.value = server
  showEditModal.value = true
}

function confirmDelete(server) {
  deletingServer.value = server
  showDeleteModal.value = true
}

function closeModal() {
  showCreateModal.value = false
  showEditModal.value = false
  editingServer.value = null
}

function handleSaved(result) {
  closeModal()

  // If this was a server creation with auto-setup, redirect to jobs page
  if (result?.setup_job_id) {
    router.push('/jobs')
  } else {
    // Otherwise just refresh the server list
    serverStore.fetchServers()
  }
}

async function handleDelete() {
  if (deletingServer.value) {
    try {
      await serverStore.deleteServer(deletingServer.value.id)
      showDeleteModal.value = false
      deletingServer.value = null
    } catch (err) {
      // Error is handled by store
    }
  }
}

function formatDate(dateString) {
  if (!dateString) return ''
  const date = new Date(dateString)
  const now = new Date()
  const diff = now - date
  const days = Math.floor(diff / (1000 * 60 * 60 * 24))

  if (days === 0) return 'today'
  if (days === 1) return 'yesterday'
  if (days < 7) return `${days} days ago`
  if (days < 30) return `${Math.floor(days / 7)} weeks ago`
  if (days < 365) return `${Math.floor(days / 30)} months ago`
  return `${Math.floor(days / 365)} years ago`
}

function formatBytes(bytes) {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

async function refreshStats(serverId) {
  try {
    await serverStore.collectStats(serverId)
    // Refresh the server list to get updated stats
    await serverStore.fetchServers()
  } catch (err) {
    // Error is handled by store
  }
}

// Color helpers for stats
function getCpuColor(percent) {
  if (!percent) return 'text-gray-500 dark:text-gray-400'
  if (percent < 50) return 'text-green-600 dark:text-green-400'
  if (percent < 80) return 'text-yellow-600 dark:text-yellow-400'
  return 'text-red-600 dark:text-red-400'
}

function getMemoryColor(percent) {
  if (!percent) return 'text-gray-500 dark:text-gray-400'
  if (percent < 70) return 'text-green-600 dark:text-green-400'
  if (percent < 85) return 'text-yellow-600 dark:text-yellow-400'
  return 'text-red-600 dark:text-red-400'
}

function getMemoryColorBg(percent) {
  if (!percent) return 'bg-gray-400'
  if (percent < 70) return 'bg-green-500'
  if (percent < 85) return 'bg-yellow-500'
  return 'bg-red-500'
}

function getDiskColor(percent) {
  if (!percent) return 'text-gray-500 dark:text-gray-400'
  if (percent < 70) return 'text-green-600 dark:text-green-400'
  if (percent < 85) return 'text-yellow-600 dark:text-yellow-400'
  return 'text-red-600 dark:text-red-400'
}

function getDiskColorBg(percent) {
  if (!percent) return 'bg-gray-400'
  if (percent < 70) return 'bg-green-500'
  if (percent < 85) return 'bg-yellow-500'
  return 'bg-red-500'
}
</script>
