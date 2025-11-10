<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <button @click="$router.back()" class="text-sm text-gray-500 hover:text-gray-700 mb-2 flex items-center">
          <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
          Back to Backups
        </button>
        <h1 class="text-3xl font-bold text-gray-900">Browse Archive</h1>
        <p class="mt-2 text-gray-600" v-if="archiveName">{{ archiveName }}</p>
      </div>
      <button
        @click="showUnmountModal = true"
        :disabled="unmounting"
        class="btn btn-secondary"
      >
        {{ unmounting ? 'Unmounting...' : 'Unmount & Close' }}
      </button>
    </div>

    <!-- Mounting State -->
    <div v-if="mounting" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600">Mounting archive...</p>
        <p class="mt-2 text-sm text-gray-500">This may take a few moments</p>
      </div>
    </div>

    <!-- Error Message -->
    <div v-else-if="error" class="card bg-red-50 border border-red-200">
      <div class="flex justify-between items-start">
        <div>
          <p class="text-sm font-semibold text-red-800 mb-2">Error</p>
          <p class="text-sm text-red-700">{{ error }}</p>
        </div>
        <button @click="$router.back()" class="text-red-500 hover:text-red-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Browser -->
    <div v-else class="card">
      <!-- Breadcrumb -->
      <div class="mb-6 flex items-center text-sm">
        <button
          @click="navigateToPath('/')"
          class="text-primary-600 hover:text-primary-800 font-medium"
        >
          Root
        </button>
        <template v-for="(segment, index) in pathSegments" :key="index">
          <svg class="w-4 h-4 mx-2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>
          <button
            @click="navigateToPath(getPathUpToSegment(index))"
            class="text-primary-600 hover:text-primary-800"
            :class="{ 'font-medium': index === pathSegments.length - 1 }"
          >
            {{ segment }}
          </button>
        </template>
      </div>

      <!-- Loading State -->
      <div v-if="loading" class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-gray-200 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 text-sm">Chargement du répertoire...</p>
      </div>

      <!-- Empty Directory -->
      <div v-else-if="!loading && !items.length" class="text-center py-16 text-gray-500">
        <svg class="w-12 h-12 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
        </svg>
        <p class="text-sm">This directory is empty</p>
      </div>

      <!-- File List -->
      <div v-else class="overflow-x-auto">
        <table class="w-full">
          <thead class="bg-gray-50 border-b">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Name</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Size</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Modified</th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr
              v-for="item in items"
              :key="item.name"
              class="hover:bg-gray-50 cursor-pointer"
              @click="handleItemClick(item)"
            >
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center">
                  <component :is="getIcon(item)" class="w-5 h-5 mr-3" :class="getIconColor(item)" />
                  <div>
                    <div class="text-sm font-medium text-gray-900">{{ item.name }}</div>
                    <div class="text-xs text-gray-500" v-if="item.type === 'file'">{{ item.mime_type }}</div>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                {{ item.type === 'file' ? formatBytes(item.size) : '-' }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                {{ formatDate(item.modified) }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium" @click.stop>
                <button
                  v-if="item.type === 'file'"
                  @click="handleDownload(item)"
                  class="text-primary-600 hover:text-primary-900 inline-flex items-center"
                  title="Download file"
                >
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                  </svg>
                </button>
                <span v-else class="text-gray-400">-</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Summary -->
      <div v-if="items.length" class="mt-4 pt-4 border-t text-sm text-gray-600">
        {{ totalDirectories }} {{ totalDirectories === 1 ? 'directory' : 'directories' }},
        {{ totalFiles }} {{ totalFiles === 1 ? 'file' : 'files' }}
      </div>
    </div>

    <!-- Unmount Confirmation Modal -->
    <div v-if="showUnmountModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="showUnmountModal = false">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-gray-900">Unmount Archive</h3>
          <button @click="showUnmountModal = false" class="text-gray-400 hover:text-gray-500">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <div class="text-sm text-gray-600 mb-6">
          <p class="mb-3">
            Êtes-vous sûr de vouloir démonter cette archive ?
          </p>
          <div class="bg-gray-50 p-3 rounded-lg mb-3">
            <p><strong>Archive :</strong> {{ archiveName }}</p>
          </div>
          <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
            <p class="text-blue-800 font-medium mb-1">ℹ️ Information :</p>
            <p class="text-blue-700 text-xs">
              • Le démontage sera effectué en arrière-plan<br>
              • Vous serez redirigé vers la liste des backups<br>
              • Vous pourrez remonter l'archive à tout moment
            </p>
          </div>
        </div>

        <div class="flex gap-3">
          <button @click="showUnmountModal = false" class="btn btn-secondary flex-1">
            Annuler
          </button>
          <button @click="confirmUnmount" :disabled="unmounting" class="btn btn-primary flex-1">
            {{ unmounting ? 'Démontage...' : 'Démonter' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, h } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { backupService } from '@/services/backups'
import { jobService } from '@/services/jobs'

const route = useRoute()
const router = useRouter()

const archiveId = parseInt(route.params.id)
const archiveName = ref(route.query.name || '')

const mounting = ref(false)
const loading = ref(false)
const unmounting = ref(false)
const error = ref(null)
const showUnmountModal = ref(false)

const currentPath = ref('/')
const items = ref([])

// Poll job until mount is complete
const pollMountStatus = async (jobId) => {
  const maxAttempts = 60 // 60 seconds
  let attempts = 0

  const poll = async () => {
    try {
      const job = await jobService.get(jobId)

      if (job.status === 'completed') {
        mounting.value = false
        await loadDirectory('/')
        return
      }

      if (job.status === 'failed') {
        throw new Error(job.error || 'Mount job failed')
      }

      if (attempts < maxAttempts && (job.status === 'pending' || job.status === 'processing')) {
        attempts++
        setTimeout(poll, 1000)
      } else if (attempts >= maxAttempts) {
        throw new Error('Mount timeout - archive took too long to mount')
      }
    } catch (err) {
      mounting.value = false
      error.value = err.message || 'Failed to check mount status'
    }
  }

  poll()
}

// Mount archive
const mountArchive = async () => {
  mounting.value = true
  error.value = null

  try {
    const result = await backupService.mount(archiveId)

    if (result.status === 'already_mounted') {
      // Already mounted, just load directory
      mounting.value = false
      await loadDirectory('/')
    } else if (result.job_id) {
      // Wait for mount job to complete
      await pollMountStatus(result.job_id)
    }
  } catch (err) {
    mounting.value = false
    error.value = err.response?.data?.error?.message || err.message || 'Failed to mount archive'
  }
}

// Load directory contents
const loadDirectory = async (path) => {
  console.log('Loading directory:', path)
  loading.value = true
  error.value = null

  try {
    const result = await backupService.browse(archiveId, path)
    console.log('Directory loaded:', result)
    currentPath.value = result.path
    items.value = result.items
  } catch (err) {
    console.error('Failed to load directory:', err)
    error.value = err.response?.data?.error?.message || err.message || 'Failed to load directory'
    items.value = [] // Clear items on error
  } finally {
    loading.value = false
    console.log('Loading done, loading =', loading.value)
  }
}

// Unmount archive
const confirmUnmount = async () => {
  showUnmountModal.value = false
  unmounting.value = true

  try {
    await backupService.unmount(archiveId)
    router.push('/backups')
  } catch (err) {
    error.value = err.response?.data?.error?.message || err.message || 'Failed to unmount archive'
    unmounting.value = false
  }
}

// Handle item click (navigate to directory)
const handleItemClick = (item) => {
  if (item.type === 'directory') {
    navigateToPath(item.path)
  }
}

// Navigate to specific path
const navigateToPath = (path) => {
  loadDirectory(path)
}

// Download file
const handleDownload = async (item) => {
  try {
    // Import api to use axios with auth
    const { default: api } = await import('@/services/api')

    const response = await api.get(`/backups/${archiveId}/download`, {
      params: { path: item.path },
      responseType: 'blob'
    })

    // Create blob and download
    const blob = new Blob([response.data])
    const url = window.URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = item.name
    document.body.appendChild(a)
    a.click()
    window.URL.revokeObjectURL(url)
    document.body.removeChild(a)
  } catch (err) {
    error.value = 'Failed to download file: ' + (err.response?.data?.error?.message || err.message || 'Unknown error')
  }
}

// Path segments for breadcrumb
const pathSegments = computed(() => {
  if (currentPath.value === '/') return []
  return currentPath.value.split('/').filter(s => s)
})

// Get path up to segment
const getPathUpToSegment = (index) => {
  const segments = pathSegments.value.slice(0, index + 1)
  return '/' + segments.join('/')
}

// Totals
const totalFiles = computed(() => items.value.filter(i => i.type === 'file').length)
const totalDirectories = computed(() => items.value.filter(i => i.type === 'directory').length)

// Get icon for file/directory
const getIcon = (item) => {
  if (item.type === 'directory') {
    return h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z' })
    ])
  }

  // File icons based on mime type
  const mime = item.mime_type || ''
  if (mime.startsWith('image/')) {
    return h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z' })
    ])
  }
  if (mime.startsWith('video/')) {
    return h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z' })
    ])
  }
  if (mime.startsWith('audio/')) {
    return h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 19V6l12-3v13M9 19c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zm12-3c0 1.105-1.343 2-3 2s-3-.895-3-2 1.343-2 3-2 3 .895 3 2zM9 10l12-3' })
    ])
  }
  if (mime.includes('zip') || mime.includes('tar') || mime.includes('gz')) {
    return h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z' })
    ])
  }
  if (mime.includes('pdf')) {
    return h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z' })
    ])
  }

  // Default file icon
  return h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
    h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z' })
  ])
}

// Get icon color
const getIconColor = (item) => {
  if (item.type === 'directory') return 'text-yellow-500'

  const mime = item.mime_type || ''
  if (mime.startsWith('image/')) return 'text-green-500'
  if (mime.startsWith('video/')) return 'text-purple-500'
  if (mime.startsWith('audio/')) return 'text-blue-500'
  if (mime.includes('zip') || mime.includes('tar') || mime.includes('gz')) return 'text-orange-500'
  if (mime.includes('pdf')) return 'text-red-500'

  return 'text-gray-400'
}

// Format bytes
const formatBytes = (bytes) => {
  if (bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i]
}

// Format date
const formatDate = (dateString) => {
  const date = new Date(dateString)
  return date.toLocaleDateString() + ' ' + date.toLocaleTimeString()
}

onMounted(() => {
  mountArchive()
})
</script>
