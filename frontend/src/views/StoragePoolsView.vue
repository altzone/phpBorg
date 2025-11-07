<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900">Storage Pools</h1>
        <p class="mt-2 text-gray-600">Manage backup storage locations</p>
      </div>
      <button @click="openStorageModal()" class="btn btn-primary">
        <svg class="w-5 h-5 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
        </svg>
        Add Storage Pool
      </button>
    </div>

    <!-- Error Message -->
    <div v-if="storageStore.error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
      <div class="flex justify-between items-start">
        <p class="text-sm text-red-800">{{ storageStore.error }}</p>
        <button @click="storageStore.clearError()" class="text-red-500 hover:text-red-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Info Card -->
    <div class="card mb-6 bg-blue-50 border-blue-200">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3">
          <h3 class="text-sm font-medium text-blue-800">About Storage Pools</h3>
          <div class="mt-2 text-sm text-blue-700">
            <p>Storage pools are physical or network locations where backup repositories are stored. You can configure multiple pools to distribute backups across different storage devices or locations, similar to professional backup solutions like Veeam or Acronis.</p>
            <ul class="list-disc pl-5 mt-2 space-y-1">
              <li>Each repository must be assigned to a storage pool</li>
              <li>One pool must be set as default for new repositories</li>
              <li>Pools can be temporarily deactivated without deleting data</li>
              <li>Cannot delete a pool that has active repositories</li>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="storageStore.loading && !storageStore.storagePools.length" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600">Loading storage pools...</p>
      </div>
    </div>

    <!-- Storage Pools List -->
    <div v-else class="grid grid-cols-1 md:grid-cols-2 gap-6">
      <div v-for="pool in storageStore.storagePools" :key="pool.id" class="card">
        <div class="flex justify-between items-start mb-4">
          <div class="flex items-center gap-2">
            <h3 class="text-lg font-semibold text-gray-900">{{ pool.name }}</h3>
            <span v-if="pool.default_pool" class="px-2 py-1 text-xs font-semibold bg-primary-100 text-primary-800 rounded">
              Default
            </span>
            <span
              :class="[
                'px-2 py-1 text-xs font-semibold rounded',
                pool.active ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'
              ]"
            >
              {{ pool.active ? 'Active' : 'Inactive' }}
            </span>
          </div>
        </div>

        <p v-if="pool.description" class="text-sm text-gray-600 mb-3">{{ pool.description }}</p>
        <p class="text-sm text-gray-600 font-mono mb-3 bg-gray-50 p-2 rounded">{{ pool.path }}</p>

        <div class="space-y-2 mb-4">
          <div class="flex justify-between text-sm">
            <span class="text-gray-600">Repositories:</span>
            <span class="font-semibold text-gray-900">{{ pool.repository_count || 0 }}</span>
          </div>

          <div v-if="pool.capacity_total" class="space-y-1">
            <div class="flex justify-between text-sm">
              <span class="text-gray-600">Storage Used:</span>
              <span class="font-semibold text-gray-900">
                {{ formatBytes(pool.capacity_used) }} / {{ formatBytes(pool.capacity_total) }}
              </span>
            </div>
            <div class="w-full bg-gray-200 rounded-full h-2">
              <div
                :class="[
                  'h-2 rounded-full transition-all',
                  pool.usage_percentage >= 90 ? 'bg-red-600' :
                  pool.usage_percentage >= 75 ? 'bg-yellow-600' : 'bg-green-600'
                ]"
                :style="{ width: `${Math.min(pool.usage_percentage || 0, 100)}%` }"
              ></div>
            </div>
            <p class="text-xs text-gray-500">{{ pool.usage_percentage?.toFixed(1) || 0 }}% used</p>
          </div>
        </div>

        <div class="flex gap-2 pt-4 border-t">
          <button @click="openStorageModal(pool)" class="btn btn-secondary flex-1 text-sm">
            Edit
          </button>
          <button
            @click="deleteStoragePool(pool)"
            class="btn btn-secondary flex-1 text-sm"
            :class="{ 'opacity-50 cursor-not-allowed': pool.repository_count > 0 }"
            :disabled="pool.repository_count > 0"
            :title="pool.repository_count > 0 ? 'Cannot delete pool with repositories' : 'Delete pool'"
          >
            Delete
          </button>
        </div>
      </div>
    </div>

    <!-- Empty State -->
    <div v-if="!storageStore.loading && !storageStore.storagePools.length" class="card text-center py-12">
      <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
      </svg>
      <h3 class="mt-2 text-sm font-medium text-gray-900">No storage pools</h3>
      <p class="mt-1 text-sm text-gray-500">Get started by creating your first storage pool</p>
      <div class="mt-6">
        <button @click="openStorageModal()" class="btn btn-primary">
          Add Storage Pool
        </button>
      </div>
    </div>

    <!-- Storage Pool Modal -->
    <div v-if="showStorageModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="closeStorageModal">
      <div class="relative top-20 mx-auto p-5 border w-[600px] shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-gray-900">
            {{ editingPool ? 'Edit Storage Pool' : 'Add Storage Pool' }}
          </h3>
          <button @click="closeStorageModal" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form @submit.prevent="saveStoragePool">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Name *</label>
              <input v-model="storageForm.name" type="text" class="input w-full" required />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Path *</label>
              <div class="flex gap-2">
                <input v-model="storageForm.path" type="text" class="input flex-1" placeholder="/backup/pool2" required />
                <button
                  type="button"
                  @click="analyzePath"
                  class="btn btn-secondary whitespace-nowrap"
                  :disabled="!storageForm.path || analyzingPath"
                >
                  <span v-if="analyzingPath">Analyzing...</span>
                  <span v-else>Analyze Path</span>
                </button>
              </div>
              <p class="text-xs text-gray-500 mt-1">Absolute path where repositories will be stored</p>
            </div>

            <!-- Path Analysis Results -->
            <div v-if="pathAnalysis" class="p-3 bg-blue-50 border border-blue-200 rounded-lg">
              <h4 class="text-sm font-semibold text-blue-900 mb-2">📊 Path Analysis</h4>
              <div class="grid grid-cols-2 gap-2 text-xs">
                <div>
                  <span class="text-blue-700 font-medium">Type:</span>
                  <span class="ml-1 text-blue-900">{{ getStorageTypeLabel(pathAnalysis.type) }}</span>
                </div>
                <div>
                  <span class="text-blue-700 font-medium">Filesystem:</span>
                  <span class="ml-1 text-blue-900 font-mono">{{ pathAnalysis.filesystem || 'N/A' }}</span>
                </div>
                <div>
                  <span class="text-blue-700 font-medium">Total:</span>
                  <span class="ml-1 text-blue-900">{{ pathAnalysis.total || 'N/A' }}</span>
                </div>
                <div>
                  <span class="text-blue-700 font-medium">Available:</span>
                  <span class="ml-1 text-blue-900">{{ pathAnalysis.available || 'N/A' }}</span>
                </div>
                <div>
                  <span class="text-blue-700 font-medium">Used:</span>
                  <span class="ml-1 text-blue-900">{{ pathAnalysis.used || 'N/A' }}</span>
                </div>
                <div>
                  <span class="text-blue-700 font-medium">Usage:</span>
                  <span :class="[
                    'ml-1 font-semibold',
                    pathAnalysis.usage_percent >= 90 ? 'text-red-700' :
                    pathAnalysis.usage_percent >= 75 ? 'text-yellow-700' : 'text-green-700'
                  ]">
                    {{ pathAnalysis.usage_percent }}%
                  </span>
                </div>
              </div>
              <button
                v-if="pathAnalysis.total_bytes"
                type="button"
                @click="applyAnalysisCapacity"
                class="mt-2 text-xs text-blue-700 hover:text-blue-900 font-medium"
              >
                → Apply detected capacity ({{ pathAnalysis.total }})
              </button>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Description</label>
              <textarea v-model="storageForm.description" class="input w-full" rows="2" placeholder="Optional description"></textarea>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Total Capacity (GB)</label>
              <input v-model.number="storageForm.capacity_gb" type="number" class="input w-full" placeholder="1000" />
              <p class="text-xs text-gray-500 mt-1">Leave empty to auto-detect from filesystem, or enter manually</p>
            </div>
            <div class="flex items-center space-x-4">
              <div class="flex items-center">
                <input v-model="storageForm.active" type="checkbox" class="mr-2" id="pool-active" />
                <label for="pool-active" class="text-sm font-medium text-gray-700">Active</label>
              </div>
              <div class="flex items-center">
                <input v-model="storageForm.default_pool" type="checkbox" class="mr-2" id="pool-default" />
                <label for="pool-default" class="text-sm font-medium text-gray-700">Set as Default</label>
              </div>
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <button type="button" @click="closeStorageModal" class="btn btn-secondary flex-1">
              Cancel
            </button>
            <button type="submit" class="btn btn-primary flex-1" :disabled="storageStore.loading">
              {{ editingPool ? 'Update Pool' : 'Create Pool' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, reactive } from 'vue'
import { useStorageStore } from '@/stores/storage'
import { storageService } from '@/services/storage'

const storageStore = useStorageStore()

const showStorageModal = ref(false)
const editingPool = ref(null)
const analyzingPath = ref(false)
const pathAnalysis = ref(null)

const storageForm = reactive({
  name: '',
  path: '',
  description: '',
  capacity_gb: null,
  active: true,
  default_pool: false,
})

onMounted(async () => {
  await storageStore.fetchStoragePools()
})

function formatBytes(bytes) {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
}

function openStorageModal(pool = null) {
  editingPool.value = pool
  if (pool) {
    storageForm.name = pool.name
    storageForm.path = pool.path
    storageForm.description = pool.description || ''
    storageForm.capacity_gb = pool.capacity_total ? Math.round(pool.capacity_total / (1024 * 1024 * 1024)) : null
    storageForm.active = pool.active
    storageForm.default_pool = pool.default_pool
  } else {
    storageForm.name = ''
    storageForm.path = ''
    storageForm.description = ''
    storageForm.capacity_gb = null
    storageForm.active = true
    storageForm.default_pool = false
  }
  showStorageModal.value = true
  pathAnalysis.value = null
}

function closeStorageModal() {
  showStorageModal.value = false
  editingPool.value = null
  pathAnalysis.value = null
}

async function analyzePath() {
  if (!storageForm.path) return

  analyzingPath.value = true
  try {
    const result = await storageService.analyzePath(storageForm.path)
    // Extract the analysis object from the nested structure
    pathAnalysis.value = result.analysis

    // Auto-suggest name based on path if creating new pool
    if (!editingPool.value && !storageForm.name && result.analysis?.mount_point) {
      const pathParts = storageForm.path.split('/').filter(Boolean)
      storageForm.name = pathParts[pathParts.length - 1] || 'Storage Pool'
    }

  } catch (error) {
    alert(error.response?.data?.error || 'Failed to analyze path. Please check if the path exists and is accessible.')
    pathAnalysis.value = null
  } finally {
    analyzingPath.value = false
  }
}

function applyAnalysisCapacity() {
  if (pathAnalysis.value?.total_bytes) {
    storageForm.capacity_gb = Math.round(pathAnalysis.value.total_bytes / (1024 * 1024 * 1024))
  }
}

function getStorageTypeLabel(type) {
  const labels = {
    nfs: '🌐 NFS Network Share',
    smb: '🌐 SMB/CIFS Share',
    local_disk: '💾 Local Disk',
    unknown: '❓ Unknown'
  }
  return labels[type] || `📁 ${type}`
}

async function saveStoragePool() {
  try {
    const data = {
      name: storageForm.name,
      path: storageForm.path,
      description: storageForm.description,
      capacity_total: storageForm.capacity_gb ? storageForm.capacity_gb * 1024 * 1024 * 1024 : null,
      active: storageForm.active,
      default_pool: storageForm.default_pool,
    }

    if (editingPool.value) {
      await storageStore.updateStoragePool(editingPool.value.id, data)
    } else {
      await storageStore.createStoragePool(data)
    }

    closeStorageModal()
  } catch (err) {
    // Error handled by store
  }
}

async function deleteStoragePool(pool) {
  if (pool.repository_count > 0) {
    alert(`Cannot delete storage pool "${pool.name}" because it has ${pool.repository_count} repositories. Please move or delete the repositories first.`)
    return
  }

  if (!confirm(`Delete storage pool "${pool.name}"?`)) {
    return
  }

  try {
    await storageStore.deleteStoragePool(pool.id)
  } catch (err) {
    // Error handled by store
  }
}
</script>
