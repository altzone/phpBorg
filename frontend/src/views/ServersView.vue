<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900">Servers</h1>
        <p class="mt-2 text-gray-600">Manage your backup servers</p>
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
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600">Loading servers...</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="!serverStore.servers.length" class="card">
      <div class="text-center py-16 text-gray-500">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No servers yet</h3>
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
        class="card hover:shadow-md transition-shadow cursor-pointer"
        @click="viewServer(server.id)"
      >
        <!-- Server Header -->
        <div class="flex items-start justify-between mb-4">
          <div class="flex-1">
            <h3 class="text-lg font-semibold text-gray-900">{{ server.name }}</h3>
            <p class="text-sm text-gray-600">{{ server.hostname }}:{{ server.port }}</p>
          </div>
          <span
            :class="[
              'px-2 py-1 text-xs font-semibold rounded',
              server.active
                ? 'bg-green-100 text-green-800'
                : 'bg-gray-100 text-gray-800',
            ]"
          >
            {{ server.active ? 'Active' : 'Inactive' }}
          </span>
        </div>

        <!-- Server Info -->
        <div class="space-y-2 text-sm">
          <div class="flex items-center text-gray-600">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
            <span>{{ server.username || 'root' }}</span>
          </div>

          <div v-if="server.description" class="flex items-start text-gray-600">
            <svg class="w-4 h-4 mr-2 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span class="line-clamp-2">{{ server.description }}</span>
          </div>

          <div class="flex items-center text-gray-500 text-xs pt-2 border-t">
            <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>Created {{ formatDate(server.created_at) }}</span>
          </div>
        </div>

        <!-- Actions -->
        <div v-if="authStore.isAdmin" class="mt-4 pt-4 border-t flex gap-2">
          <button
            @click.stop="editServer(server)"
            class="flex-1 btn btn-secondary text-sm py-1"
          >
            Edit
          </button>
          <button
            @click.stop="confirmDelete(server)"
            class="flex-1 btn bg-red-50 text-red-700 hover:bg-red-100 text-sm py-1"
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

function handleSaved() {
  closeModal()
  serverStore.fetchServers()
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
</script>
