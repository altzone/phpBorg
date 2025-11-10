<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900">Backups</h1>
        <p class="mt-2 text-gray-600">Browse and manage backup archives</p>
      </div>
      <button
        v-if="authStore.isAdmin || authStore.isOperator"
        @click="showCreateModal = true"
        class="btn btn-primary"
      >
        + Create Backup
      </button>
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
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <div class="card bg-blue-50">
        <div class="text-sm text-blue-600 mb-1">Total Backups</div>
        <div class="text-2xl font-bold text-blue-900">{{ backupStore.stats.total_backups }}</div>
      </div>
      <div class="card bg-green-50">
        <div class="text-sm text-green-600 mb-1">Total Size</div>
        <div class="text-2xl font-bold text-green-900">{{ formatBytes(backupStore.stats.total_original_size) }}</div>
        <div class="text-xs text-green-600 mt-1">Original</div>
      </div>
      <div class="card bg-purple-50">
        <div class="text-sm text-purple-600 mb-1">Compression</div>
        <div class="text-2xl font-bold text-purple-900">{{ backupStore.stats.compression_ratio }}%</div>
        <div class="text-xs text-purple-600 mt-1">{{ formatBytes(backupStore.stats.total_compressed_size) }} saved</div>
      </div>
      <div class="card bg-orange-50">
        <div class="text-sm text-orange-600 mb-1">Deduplication</div>
        <div class="text-2xl font-bold text-orange-900">{{ backupStore.stats.deduplication_ratio }}%</div>
        <div class="text-xs text-orange-600 mt-1">{{ formatBytes(backupStore.stats.total_deduplicated_size) }} stored</div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="backupStore.loading && !backupStore.backups.length" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600">Loading backups...</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="!backupStore.backups.length" class="card">
      <div class="text-center py-16 text-gray-500">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 mb-2">No backups yet</h3>
        <p class="text-sm mb-4">Create your first backup to get started</p>
        <button
          v-if="authStore.isAdmin || authStore.isOperator"
          @click="showCreateModal = true"
          class="btn btn-primary"
        >
          + Create Backup
        </button>
      </div>
    </div>

    <!-- Backups List -->
    <div v-else class="card">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Archive Name</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Date</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Duration</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Files</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Compression</th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider" v-if="authStore.isAdmin">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr v-for="backup in backupStore.backups" :key="backup.id" class="hover:bg-gray-50">
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm font-medium text-gray-900">{{ backup.name }}</div>
                <div class="text-xs text-gray-500">{{ backup.server_name }} - {{ backup.repository_type }}</div>
                <div class="text-xs text-gray-400">ID: {{ backup.archive_id.substring(0, 16) }}...</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900">{{ formatDate(backup.end) }}</div>
                <div class="text-xs text-gray-500">Started: {{ formatDate(backup.start) }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                {{ backup.duration_formatted }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900">{{ formatBytes(backup.original_size) }}</div>
                <div class="text-xs text-gray-500">→ {{ formatBytes(backup.deduplicated_size) }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                {{ backup.files_count.toLocaleString() }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900">{{ backup.compression_ratio }}%</div>
                <div class="text-xs text-gray-500">Dedup: {{ backup.deduplication_ratio }}%</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium" v-if="authStore.isAdmin">
                <button
                  @click="confirmDelete(backup)"
                  class="text-red-600 hover:text-red-900"
                  title="Delete backup"
                >
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                  </svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Create Backup Modal -->
    <div v-if="showCreateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="showCreateModal = false">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-gray-900">Create Backup</h3>
          <button @click="showCreateModal = false" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form @submit.prevent="handleCreateBackup">
          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">Server</label>
            <select v-model="createForm.server_id" required class="input w-full">
              <option value="">Select a server...</option>
              <option v-for="server in serverStore.servers" :key="server.id" :value="server.id">
                {{ server.name }} ({{ server.hostname }})
              </option>
            </select>
          </div>

          <div class="mb-4">
            <label class="block text-sm font-medium text-gray-700 mb-2">Backup Type</label>
            <select v-model="createForm.type" required class="input w-full">
              <option value="backup">Filesystem</option>
              <option value="mysql">MySQL</option>
              <option value="postgres">PostgreSQL</option>
              <option value="mongodb">MongoDB</option>
              <option value="elasticsearch">Elasticsearch</option>
            </select>
          </div>

          <div class="flex gap-3">
            <button type="button" @click="showCreateModal = false" class="btn btn-secondary flex-1">
              Cancel
            </button>
            <button type="submit" class="btn btn-primary flex-1">
              Create Backup
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <div v-if="showDeleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="showDeleteModal = false">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-gray-900">Delete Backup</h3>
          <button @click="showDeleteModal = false" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="text-sm text-gray-600 mb-6">
          <p class="mb-3">
            Êtes-vous sûr de vouloir supprimer cette archive de backup ?
          </p>
          <div class="bg-gray-50 p-3 rounded-lg mb-3">
            <p><strong>Archive :</strong> {{ backupToDelete?.name }}</p>
            <p><strong>Serveur :</strong> {{ backupToDelete?.server_name }}</p>
            <p><strong>Type :</strong> {{ backupToDelete?.repository_type }}</p>
          </div>
          <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-3">
            <p class="text-yellow-800 font-medium mb-1">⚠️ Attention :</p>
            <p class="text-yellow-700 text-xs">
              • Cette action est <strong>irréversible</strong><br>
              • L'archive sera supprimée définitivement du repository Borg<br>
              • La suppression sera effectuée par le worker en arrière-plan
            </p>
          </div>
        </div>

        <div class="flex gap-3">
          <button @click="showDeleteModal = false" class="btn btn-secondary flex-1">
            Cancel
          </button>
          <button @click="handleDelete" class="btn bg-red-50 text-red-700 hover:bg-red-100 flex-1">
            Delete
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
import { useBackupStore } from '@/stores/backups'
import { useServerStore } from '@/stores/server'

const router = useRouter()
const authStore = useAuthStore()
const backupStore = useBackupStore()
const serverStore = useServerStore()

const showCreateModal = ref(false)
const showDeleteModal = ref(false)
const backupToDelete = ref(null)
const createForm = ref({
  server_id: '',
  type: 'backup'
})

onMounted(async () => {
  await Promise.all([
    backupStore.fetchBackups({ limit: 100 }),
    backupStore.fetchStats(),
    serverStore.fetchServers()
  ])
})

async function handleCreateBackup() {
  try {
    const result = await backupStore.createBackup({
      server_id: parseInt(createForm.value.server_id),
      type: createForm.value.type
    })

    showCreateModal.value = false
    createForm.value = { server_id: '', type: 'backup' }

    // Redirect to jobs page to monitor progress
    router.push('/jobs')
  } catch (err) {
    // Error already handled by store
  }
}

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
      alert(`✅ Suppression programmée avec succès !\n\nL'archive "${result.archive_name}" va être supprimée par le worker.\n\nJob ID: ${result.job_id}\n\nVous pouvez suivre le progrès dans la section Jobs.`)
      
      // Optionally redirect to jobs page to monitor progress
      // router.push('/jobs')
    }
  } catch (err) {
    console.error('Delete error:', err)
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
