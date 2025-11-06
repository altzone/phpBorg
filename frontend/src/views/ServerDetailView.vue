<template>
  <div>
    <!-- Back Button -->
    <button
      @click="$router.back()"
      class="mb-6 flex items-center text-gray-600 hover:text-gray-900"
    >
      <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
      Back to Servers
    </button>

    <!-- Loading State -->
    <div v-if="serverStore.loading && !serverStore.currentServer" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600">Loading server details...</p>
      </div>
    </div>

    <!-- Server Not Found -->
    <div v-else-if="!serverStore.currentServer && !serverStore.loading" class="card">
      <div class="text-center py-16">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 mb-2">Server not found</h3>
        <p class="text-sm text-gray-600">The server you're looking for doesn't exist.</p>
      </div>
    </div>

    <!-- Server Details -->
    <div v-else>
      <!-- Header -->
      <div class="flex justify-between items-start mb-8">
        <div>
          <div class="flex items-center gap-3 mb-2">
            <h1 class="text-3xl font-bold text-gray-900">{{ server.name }}</h1>
            <span
              :class="[
                'px-3 py-1 text-xs font-semibold rounded-full',
                server.active
                  ? 'bg-green-100 text-green-800'
                  : 'bg-gray-100 text-gray-800',
              ]"
            >
              {{ server.active ? 'Active' : 'Inactive' }}
            </span>
          </div>
          <p class="text-gray-600">{{ server.hostname }}:{{ server.port }}</p>
        </div>

        <div v-if="authStore.isAdmin" class="flex gap-2">
          <button @click="editServer" class="btn btn-secondary">
            Edit Server
          </button>
          <button @click="confirmDelete" class="btn bg-red-50 text-red-700 hover:bg-red-100">
            Delete
          </button>
        </div>
      </div>

      <!-- Server Info Card -->
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 mb-8">
        <div class="card">
          <h3 class="text-sm font-medium text-gray-500 mb-2">Connection</h3>
          <div class="space-y-2">
            <div class="flex items-center text-gray-900">
              <svg class="w-5 h-5 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
              </svg>
              <span>{{ server.hostname }}</span>
            </div>
            <div class="flex items-center text-gray-900">
              <svg class="w-5 h-5 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z" />
              </svg>
              <span>Port {{ server.port }}</span>
            </div>
            <div class="flex items-center text-gray-900">
              <svg class="w-5 h-5 mr-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
              </svg>
              <span>{{ server.username || 'root' }}</span>
            </div>
          </div>
        </div>

        <div class="card">
          <h3 class="text-sm font-medium text-gray-500 mb-2">Statistics</h3>
          <div class="space-y-2">
            <div class="flex justify-between">
              <span class="text-gray-600">Repositories</span>
              <span class="font-semibold text-gray-900">{{ repositories.length }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Total Backups</span>
              <span class="font-semibold text-gray-900">-</span>
            </div>
            <div class="flex justify-between">
              <span class="text-gray-600">Storage Used</span>
              <span class="font-semibold text-gray-900">-</span>
            </div>
          </div>
        </div>

        <div class="card">
          <h3 class="text-sm font-medium text-gray-500 mb-2">Details</h3>
          <div class="space-y-2 text-sm">
            <div>
              <span class="text-gray-600">Created:</span>
              <span class="ml-2 text-gray-900">{{ formatDate(server.created_at) }}</span>
            </div>
            <div v-if="server.updated_at">
              <span class="text-gray-600">Updated:</span>
              <span class="ml-2 text-gray-900">{{ formatDate(server.updated_at) }}</span>
            </div>
            <div v-if="server.description" class="pt-2 border-t">
              <span class="text-gray-600">Description:</span>
              <p class="mt-1 text-gray-900">{{ server.description }}</p>
            </div>
          </div>
        </div>
      </div>

      <!-- Repositories -->
      <div class="card">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">Repositories</h2>

        <div v-if="repositories.length === 0" class="text-center py-8 text-gray-500">
          <p>No repositories configured for this server</p>
          <p class="text-sm mt-2">Repositories will be created during server setup</p>
        </div>

        <div v-else class="space-y-3">
          <div
            v-for="repo in repositories"
            :key="repo.id"
            class="p-4 border border-gray-200 rounded-lg hover:bg-gray-50"
          >
            <div class="flex justify-between items-start">
              <div class="flex-1">
                <div class="flex items-center gap-2 mb-1">
                  <h3 class="font-medium text-gray-900">{{ repo.type }} repository</h3>
                  <span class="px-2 py-0.5 text-xs bg-gray-100 text-gray-700 rounded">
                    {{ repo.compression }}
                  </span>
                </div>
                <p class="text-sm text-gray-600 font-mono">{{ repo.repo_path }}</p>
                <p class="text-xs text-gray-500 mt-1">
                  Created {{ formatDate(repo.created_at) }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Backups -->
      <div class="card mt-6">
        <h2 class="text-xl font-semibold text-gray-900 mb-4">Recent Backups</h2>
        <div class="text-center py-8 text-gray-500">
          <p>No recent backups</p>
          <p class="text-sm mt-2">Backup management coming in Phase 5</p>
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
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useServerStore } from '@/stores/server'
import ServerFormModal from '@/components/ServerFormModal.vue'
import DeleteConfirmModal from '@/components/DeleteConfirmModal.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const serverStore = useServerStore()

const showEditModal = ref(false)
const showDeleteModal = ref(false)

const server = computed(() => serverStore.currentServer?.server)
const repositories = computed(() => serverStore.currentServer?.repositories || [])

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

function formatDate(dateString) {
  if (!dateString) return 'N/A'
  return new Date(dateString).toLocaleString('en-US', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>
