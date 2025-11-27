<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $t('servers.title') }}</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">{{ $t('servers.subtitle') }}</p>
      </div>
      <button
        v-if="authStore.isAdmin"
        @click="showCreateModal = true"
        class="btn btn-primary"
      >
        {{ $t('servers.add_server') }}
      </button>
    </div>

    <!-- Error Message -->
    <div v-if="serverStore.error" class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
      <div class="flex justify-between items-start">
        <p class="text-sm text-red-800 dark:text-red-200">{{ serverStore.error }}</p>
        <button @click="serverStore.clearError()" class="text-red-500 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
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
        <p class="mt-4 text-gray-600 dark:text-gray-400">{{ $t('servers.loading_servers') }}</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="!serverStore.servers.length" class="card">
      <div class="text-center py-16 text-gray-500 dark:text-gray-400">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">{{ $t('servers.no_servers') }}</h3>
        <p class="text-sm mb-4">{{ $t('servers.get_started') }}</p>
        <button
          v-if="authStore.isAdmin"
          @click="showCreateModal = true"
          class="btn btn-primary"
        >
          {{ $t('servers.add_server') }}
        </button>
      </div>
    </div>

    <!-- Servers Table -->
    <div v-else class="card overflow-hidden">
      <!-- Desktop Table -->
      <div class="hidden lg:block overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-800">
            <tr>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('servers.name') }}
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('servers.hostname') }}
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('servers.username') }}
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('servers.status') }}
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                IP
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('servers.distribution') }}
              </th>
              <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('servers.version') }}
              </th>
              <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                {{ $t('common.actions') }}
              </th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
            <tr
              v-for="server in serverStore.servers"
              :key="server.id"
              @click="viewServer(server.id)"
              class="hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer transition-colors"
              :class="{ 'opacity-60': !server.active }"
            >
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center">
                  <div :class="[
                    'p-2 rounded-lg mr-3',
                    server.active
                      ? 'bg-gradient-to-br from-primary-400 to-primary-600 shadow-md'
                      : 'bg-gradient-to-br from-gray-300 to-gray-500 dark:from-gray-600 dark:to-gray-700'
                  ]">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                    </svg>
                  </div>
                  <div class="font-medium text-gray-900 dark:text-gray-100">{{ server.name }}</div>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900 dark:text-gray-300 font-mono">{{ server.hostname }}:{{ server.port }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-700 dark:text-gray-400">{{ server.username || 'root' }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span :class="[
                  'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium',
                  server.active
                    ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400'
                    : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-400'
                ]">
                  <div :class="[
                    'w-1.5 h-1.5 rounded-full',
                    server.active ? 'bg-green-500 animate-pulse' : 'bg-gray-400'
                  ]"></div>
                  {{ server.active ? $t('servers.online') : $t('servers.offline') }}
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-700 dark:text-gray-400 font-mono">
                  {{ server.stats?.ip_address || 'N/A' }}
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900 dark:text-gray-300">
                  {{ server.stats?.os_distribution || 'N/A' }}
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-700 dark:text-gray-400">
                  <!-- Agent version for agent-based servers -->
                  <template v-if="server.agent">
                    <span class="flex items-center gap-2">
                      <span class="font-mono">{{ server.agent.version || 'N/A' }}</span>
                      <!-- Update badge (clickable) -->
                      <button
                        v-if="server.agent.needs_update && !updatingAgent[server.id]"
                        @click.stop="updateAgent(server)"
                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200 hover:bg-yellow-200 dark:hover:bg-yellow-800 cursor-pointer transition-colors"
                        :title="$t('servers.agent.update_available', { version: server.agent.latest_version })"
                      >
                        {{ $t('servers.agent.update') }}
                      </button>
                      <!-- Updating spinner -->
                      <span
                        v-if="updatingAgent[server.id]"
                        class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200"
                      >
                        <svg class="animate-spin -ml-0.5 mr-1.5 h-3 w-3" fill="none" viewBox="0 0 24 24">
                          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        {{ $t('common.updating') }}
                      </span>
                    </span>
                  </template>
                  <!-- OS version for SSH-based servers -->
                  <template v-else>
                    {{ server.stats?.os_version || 'N/A' }}
                  </template>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                <div class="flex items-center justify-end gap-2">
                  <button
                    v-if="authStore.isAdmin"
                    @click.stop="detectCapabilities(server)"
                    :disabled="detectingCapabilities[server.id]"
                    :title="$t('servers.detect_capabilities')"
                    class="text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300 disabled:opacity-50 disabled:cursor-not-allowed"
                  >
                    <svg
                      :class="['w-5 h-5', detectingCapabilities[server.id] ? 'animate-spin' : '']"
                      fill="none"
                      stroke="currentColor"
                      viewBox="0 0 24 24"
                    >
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                  </button>
                  <button
                    v-if="authStore.isAdmin"
                    @click.stop="editServer(server)"
                    :title="$t('common.edit')"
                    class="text-primary-600 hover:text-primary-900 dark:text-primary-400 dark:hover:text-primary-300"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </button>
                  <button
                    v-if="authStore.isAdmin"
                    @click.stop="confirmDelete(server)"
                    :title="$t('common.delete')"
                    class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
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

      <!-- Mobile/Tablet Cards (below lg breakpoint) -->
      <div class="lg:hidden divide-y divide-gray-200 dark:divide-gray-700">
        <div
          v-for="server in serverStore.servers"
          :key="server.id"
          @click="viewServer(server.id)"
          class="p-4 hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer transition-colors"
          :class="{ 'opacity-60': !server.active }"
        >
          <!-- Header Row -->
          <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-3">
              <div :class="[
                'p-2 rounded-lg',
                server.active
                  ? 'bg-gradient-to-br from-primary-400 to-primary-600 shadow-md'
                  : 'bg-gradient-to-br from-gray-300 to-gray-500 dark:from-gray-600 dark:to-gray-700'
              ]">
                <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                </svg>
              </div>
              <div>
                <h3 class="font-semibold text-gray-900 dark:text-gray-100">{{ server.name }}</h3>
                <span :class="[
                  'inline-flex items-center gap-1 text-xs',
                  server.active
                    ? 'text-green-600 dark:text-green-400'
                    : 'text-gray-500 dark:text-gray-400'
                ]">
                  <div :class="[
                    'w-1.5 h-1.5 rounded-full',
                    server.active ? 'bg-green-500 animate-pulse' : 'bg-gray-400'
                  ]"></div>
                  {{ server.active ? $t('servers.online') : $t('servers.offline') }}
                </span>
              </div>
            </div>
            <div v-if="authStore.isAdmin" class="flex items-center gap-2">
              <button
                @click.stop="detectCapabilities(server)"
                :disabled="detectingCapabilities[server.id]"
                :title="$t('servers.detect_capabilities')"
                class="p-2 text-blue-600 hover:text-blue-900 dark:text-blue-400 dark:hover:text-blue-300 disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <svg
                  :class="['w-5 h-5', detectingCapabilities[server.id] ? 'animate-spin' : '']"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
              </button>
              <button
                @click.stop="editServer(server)"
                :title="$t('common.edit')"
                class="p-2 text-primary-600 hover:text-primary-900 dark:text-primary-400 dark:hover:text-primary-300"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </button>
              <button
                @click.stop="confirmDelete(server)"
                :title="$t('common.delete')"
                class="p-2 text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </button>
            </div>
          </div>

          <!-- Details Grid -->
          <div class="grid grid-cols-2 gap-3 text-sm">
            <div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ $t('servers.hostname') }}</div>
              <div class="font-mono text-gray-900 dark:text-gray-300 text-xs">{{ server.hostname }}:{{ server.port }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ $t('servers.username') }}</div>
              <div class="text-gray-900 dark:text-gray-300">{{ server.username || 'root' }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">IP</div>
              <div class="font-mono text-gray-900 dark:text-gray-300 text-xs">{{ server.stats?.ip_address || 'N/A' }}</div>
            </div>
            <div>
              <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ $t('servers.distribution') }}</div>
              <div class="text-gray-900 dark:text-gray-300">{{ server.stats?.os_distribution || 'N/A' }}</div>
            </div>
            <div class="col-span-2">
              <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">{{ $t('servers.version') }}</div>
              <div class="text-gray-900 dark:text-gray-300">
                <template v-if="server.agent">
                  <span class="flex items-center gap-2">
                    <span class="font-mono">{{ server.agent.version || 'N/A' }}</span>
                    <button
                      v-if="server.agent.needs_update && !updatingAgent[server.id]"
                      @click.stop="updateAgent(server)"
                      class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200 hover:bg-yellow-200 dark:hover:bg-yellow-800 cursor-pointer"
                    >
                      {{ $t('servers.agent.update') }}
                    </button>
                    <span
                      v-if="updatingAgent[server.id]"
                      class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200"
                    >
                      <svg class="animate-spin -ml-0.5 mr-1.5 h-3 w-3" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                      </svg>
                      {{ $t('common.updating') }}
                    </span>
                  </span>
                </template>
                <template v-else>{{ server.stats?.os_version || 'N/A' }}</template>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Add Server Wizard -->
    <AddServerWizard
      :is-open="showCreateModal"
      @close="closeModal"
      @created="handleSaved"
    />

    <!-- Edit Modal -->
    <ServerFormModal
      v-if="showEditModal"
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

    <!-- Toast Notifications -->
    <div class="fixed bottom-4 right-4 z-50 space-y-3">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        :class="[
          'max-w-md rounded-lg shadow-lg p-4 transform transition-all duration-300',
          toast.type === 'success' ? 'bg-green-500 text-white' : toast.type === 'warning' ? 'bg-orange-500 text-white' : 'bg-red-500 text-white',
          'animate-slide-in'
        ]"
      >
        <div class="flex items-start gap-3">
          <div class="flex-shrink-0">
            <svg v-if="toast.type === 'success'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <svg v-else-if="toast.type === 'warning'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
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
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useServerStore } from '@/stores/server'
import api from '@/services/api'
import AddServerWizard from '@/components/AddServerWizard.vue'
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
const detectingCapabilities = ref({})
const updatingAgent = ref({})

// Toast notifications
const toasts = ref([])
let toastIdCounter = 0

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

async function handleDelete(deleteType) {
  if (deletingServer.value) {
    const serverName = deletingServer.value.name
    try {
      await serverStore.deleteServer(deletingServer.value.id, deleteType)
      showDeleteModal.value = false
      deletingServer.value = null

      // Show success toast
      if (deleteType === 'archive') {
        showToast(
          'Serveur archivé',
          `${serverName} a été archivé avec succès. Les données sont conservées.`,
          'success'
        )
      } else {
        showToast(
          'Serveur supprimé',
          `${serverName} et toutes ses données ont été supprimés définitivement.`,
          'success'
        )
      }
    } catch (err) {
      // Show error toast
      showToast(
        'Erreur',
        err.response?.data?.error?.message || 'Échec de la suppression du serveur',
        'error',
        8000
      )
    }
  }
}

async function detectCapabilities(server) {
  detectingCapabilities.value[server.id] = true

  try {
    await serverStore.detectCapabilities(server.id)
    showToast(
      '✓ Capacités détectées',
      `Détection des capacités lancée pour ${server.name}`,
      'success'
    )

    // Refresh server list after detection completes (wait 5s for job to finish)
    setTimeout(async () => {
      await serverStore.fetchServers()
    }, 5000)
  } catch (err) {
    showToast(
      'Erreur',
      err.response?.data?.error?.message || 'Échec de la détection des capacités',
      'error',
      8000
    )
  } finally {
    detectingCapabilities.value[server.id] = false
  }
}

async function updateAgent(server) {
  if (!server.agent?.uuid) return

  updatingAgent.value[server.id] = true

  try {
    // Request update using server_id (backend will resolve agent_id)
    await api.post('/agent/update/request', {
      server_id: server.id,
      force: false
    })

    showToast(
      '✓ Mise à jour lancée',
      `Mise à jour de l'agent ${server.name} vers v${server.agent.latest_version}`,
      'success'
    )

    // Refresh after some time to see updated version
    setTimeout(async () => {
      await serverStore.fetchServers()
    }, 10000)
  } catch (err) {
    showToast(
      'Erreur',
      err.response?.data?.error?.message || 'Échec de la mise à jour de l\'agent',
      'error',
      8000
    )
  } finally {
    updatingAgent.value[server.id] = false
  }
}

function showToast(title, message = '', type = 'success', duration = 5000) {
  const id = ++toastIdCounter
  toasts.value.push({ id, title, message, type })

  setTimeout(() => {
    removeToast(id)
  }, duration)
}

function removeToast(id) {
  const index = toasts.value.findIndex(t => t.id === id)
  if (index > -1) {
    toasts.value.splice(index, 1)
  }
}
</script>
