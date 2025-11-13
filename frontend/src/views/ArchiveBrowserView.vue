<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Header -->
    <div class="bg-white border-b border-gray-200 sticky top-0 z-10">
      <div class="px-6 py-4">
        <!-- Top Bar -->
        <div class="flex items-center justify-between mb-4">
          <button
            @click="$router.back()"
            class="flex items-center gap-2 text-gray-600 hover:text-gray-900 transition-colors"
          >
            <ArrowLeft :size="20" />
            <span class="font-medium">{{ $t('archive_browser.back_to_backups') }}</span>
          </button>

          <div class="flex items-center gap-3">
            <!-- Restore Selected Button -->
            <div v-if="selectedItems.length > 0" class="flex items-center gap-2">
              <button
                @click="showRestoreWizard = true"
                class="flex items-center gap-2 px-4 py-2 bg-green-50 text-green-700 hover:bg-green-100 rounded-lg transition-colors font-medium border border-green-200"
              >
                <Download :size="18" />
                {{ $t('archive_browser.restore_selected', { count: selectedItems.length }) }}
              </button>
              <button
                @click="clearSelection"
                class="px-3 py-2 text-gray-600 hover:bg-gray-100 rounded-lg transition-colors text-sm"
                :title="$t('archive_browser.clear_selection')"
              >
                <X :size="18" />
              </button>
            </div>

            <!-- View Toggle -->
            <div class="flex items-center bg-gray-100 rounded-lg p-1">
              <button
                @click="viewMode = 'grid'"
                :class="[
                  'p-2 rounded transition-all',
                  viewMode === 'grid' ? 'bg-white shadow-sm' : 'hover:bg-gray-200'
                ]"
                :title="$t('archive_browser.grid_view')"
              >
                <Grid3x3 :size="18" :class="viewMode === 'grid' ? 'text-primary-600' : 'text-gray-600'" />
              </button>
              <button
                @click="viewMode = 'list'"
                :class="[
                  'p-2 rounded transition-all',
                  viewMode === 'list' ? 'bg-white shadow-sm' : 'hover:bg-gray-200'
                ]"
                :title="$t('archive_browser.list_view')"
              >
                <List :size="18" :class="viewMode === 'list' ? 'text-primary-600' : 'text-gray-600'" />
              </button>
            </div>

            <button
              @click="showUnmountModal = true"
              :disabled="unmounting"
              class="flex items-center gap-2 px-4 py-2 bg-red-50 text-red-700 hover:bg-red-100 rounded-lg transition-colors font-medium"
            >
              <Power :size="18" />
              {{ unmounting ? $t('archive_browser.unmounting') : $t('archive_browser.unmount') }}
            </button>
          </div>
        </div>

        <!-- Archive Info -->
        <div class="mb-4">
          <h1 class="text-2xl font-bold text-gray-900 flex items-center gap-3">
            <HardDrive :size="28" class="text-primary-600" />
            {{ archiveName }}
          </h1>
        </div>

        <!-- Breadcrumb & Search -->
        <div class="flex items-center gap-4">
          <!-- Breadcrumb -->
          <div class="flex-1 flex items-center gap-2 text-sm bg-gray-50 rounded-lg px-4 py-2">
            <Folder :size="16" class="text-gray-500" />
            <button
              @click="navigateToPath('/')"
              class="text-primary-600 hover:text-primary-800 font-medium transition-colors"
            >
              {{ $t('archive_browser.root') }}
            </button>
            <template v-for="(segment, index) in pathSegments" :key="index">
              <ChevronRight :size="14" class="text-gray-400" />
              <button
                @click="navigateToPath(getPathUpToSegment(index))"
                :class="[
                  'hover:text-primary-800 transition-colors',
                  index === pathSegments.length - 1 ? 'text-gray-900 font-medium' : 'text-primary-600'
                ]"
              >
                {{ segment }}
              </button>
            </template>
          </div>

          <!-- Search -->
          <div class="relative w-80">
            <Search :size="18" class="absolute left-3 top-1/2 transform -translate-y-1/2 text-gray-400" />
            <input
              v-model="searchQuery"
              type="text"
              :placeholder="$t('archive_browser.search_placeholder')"
              class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary-500 focus:border-transparent"
            >
          </div>
        </div>
      </div>
    </div>

    <!-- Main Content -->
    <div class="px-6 py-6">
      <!-- Mounting State -->
      <div v-if="mounting" class="flex flex-col items-center justify-center py-32">
        <Loader2 :size="48" class="text-primary-600 animate-spin mb-4" />
        <p class="text-lg text-gray-700 font-medium">{{ $t('archive_browser.mounting_archive') }}</p>
        <p class="text-sm text-gray-500 mt-2">{{ $t('archive_browser.mounting_wait') }}</p>
      </div>

      <!-- Error State -->
      <div v-else-if="error" class="bg-red-50 border border-red-200 rounded-lg p-6">
        <div class="flex items-start gap-3">
          <AlertCircle :size="24" class="text-red-600 flex-shrink-0 mt-1" />
          <div class="flex-1">
            <h3 class="text-lg font-semibold text-red-900 mb-1">{{ $t('archive_browser.error') }}</h3>
            <p class="text-red-700">{{ error }}</p>
          </div>
          <button @click="$router.back()" class="text-red-600 hover:text-red-800">
            <X :size="20" />
          </button>
        </div>
      </div>

      <!-- Browser Content -->
      <div v-else>
        <!-- Loading Overlay -->
        <div v-if="loading" class="flex items-center justify-center py-16">
          <Loader2 :size="32" class="text-primary-600 animate-spin" />
        </div>

        <!-- Empty Directory -->
        <div v-else-if="!filteredItems.length" class="flex flex-col items-center justify-center py-32 text-gray-500">
          <FolderOpen :size="64" class="mb-4 text-gray-300" />
          <p class="text-lg font-medium">{{ searchQuery ? $t('archive_browser.no_files_search') : $t('archive_browser.empty_directory') }}</p>
        </div>

        <!-- Grid View -->
        <div v-else-if="viewMode === 'grid'" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 xl:grid-cols-8 gap-4">
          <div
            v-for="item in filteredItems"
            :key="item.name"
            @click="handleItemClick(item)"
            @dblclick="item.type === 'directory' ? navigateToPath(item.path) : null"
            :class="[
              'group relative bg-white rounded-lg p-4 border-2 transition-all cursor-pointer',
              isSelected(item) ? 'border-green-500 bg-green-50' : 'border-gray-200 hover:border-primary-400 hover:shadow-lg'
            ]"
          >
            <!-- Selection Checkbox -->
            <button
              @click.stop="toggleSelection(item)"
              class="absolute top-2 left-2 z-10"
              :title="isSelected(item) ? $t('archive_browser.deselect') : $t('archive_browser.select_for_restore')"
            >
              <CheckSquare v-if="isSelected(item)" :size="20" class="text-green-600" />
              <Square v-else :size="20" class="text-gray-400 opacity-0 group-hover:opacity-100 transition-opacity" />
            </button>

            <!-- Icon -->
            <div class="flex flex-col items-center">
              <div class="mb-3 transition-transform group-hover:scale-110">
                <component :is="getFileIcon(item)" :size="48" :class="getFileIconColor(item)" />
              </div>

              <!-- Name -->
              <p class="text-xs text-center text-gray-700 font-medium line-clamp-2 w-full">
                {{ item.name }}
              </p>

              <!-- Size -->
              <p v-if="item.type === 'file'" class="text-xs text-gray-500 mt-1">
                {{ formatBytes(item.size) }}
              </p>
            </div>

            <!-- Quick Actions -->
            <div v-if="item.type === 'file'" class="absolute top-2 right-2 flex gap-1 opacity-0 group-hover:opacity-100 transition-opacity">
              <button
                @click.stop="handlePreview(item)"
                class="bg-blue-600 text-white p-2 rounded-lg hover:bg-blue-700"
                :title="$t('archive_browser.preview')"
              >
                <Eye :size="16" />
              </button>
              <button
                @click.stop="handleDownload(item)"
                class="bg-primary-600 text-white p-2 rounded-lg hover:bg-primary-700"
                :title="$t('archive_browser.download')"
              >
                <Download :size="16" />
              </button>
            </div>
          </div>
        </div>

        <!-- List View -->
        <div v-else class="bg-white rounded-lg border border-gray-200 overflow-hidden">
          <table class="w-full">
            <thead class="bg-gray-50 border-b border-gray-200">
              <tr>
                <th class="px-4 py-3 w-12">
                  <button
                    @click="toggleSelectAll"
                    class="hover:bg-gray-100 p-1 rounded transition-colors"
                    :title="selectedItems.length === filteredItems.length ? $t('archive_browser.deselect_all') : $t('archive_browser.select_all')"
                  >
                    <CheckSquare v-if="selectedItems.length > 0 && selectedItems.length === filteredItems.length" :size="18" class="text-green-600" />
                    <Square v-else :size="18" class="text-gray-400" />
                  </button>
                </th>
                <th @click="sortBy('name')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                  <div class="flex items-center gap-2">
                    {{ $t('archive_browser.name') }}
                    <ChevronsUpDown v-if="sortColumn !== 'name'" :size="14" class="text-gray-400" />
                    <ChevronUp v-else-if="sortDirection === 'asc'" :size="14" class="text-primary-600" />
                    <ChevronDown v-else :size="14" class="text-primary-600" />
                  </div>
                </th>
                <th @click="sortBy('size')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                  <div class="flex items-center gap-2">
                    {{ $t('archive_browser.size') }}
                    <ChevronsUpDown v-if="sortColumn !== 'size'" :size="14" class="text-gray-400" />
                    <ChevronUp v-else-if="sortDirection === 'asc'" :size="14" class="text-primary-600" />
                    <ChevronDown v-else :size="14" class="text-primary-600" />
                  </div>
                </th>
                <th @click="sortBy('modified')" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider cursor-pointer hover:bg-gray-100">
                  <div class="flex items-center gap-2">
                    {{ $t('archive_browser.modified') }}
                    <ChevronsUpDown v-if="sortColumn !== 'modified'" :size="14" class="text-gray-400" />
                    <ChevronUp v-else-if="sortDirection === 'asc'" :size="14" class="text-primary-600" />
                    <ChevronDown v-else :size="14" class="text-primary-600" />
                  </div>
                </th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Actions</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
              <tr
                v-for="item in filteredItems"
                :key="item.name"
                @click="handleItemClick(item)"
                :class="[
                  'transition-colors cursor-pointer',
                  isSelected(item) ? 'bg-green-50' : 'hover:bg-gray-50'
                ]"
              >
                <td class="px-4 py-4">
                  <button
                    @click.stop="toggleSelection(item)"
                    class="hover:bg-gray-100 p-1 rounded transition-colors"
                    :title="isSelected(item) ? $t('archive_browser.deselect') : $t('archive_browser.select_for_restore')"
                  >
                    <CheckSquare v-if="isSelected(item)" :size="18" class="text-green-600" />
                    <Square v-else :size="18" class="text-gray-400" />
                  </button>
                </td>
                <td class="px-6 py-4">
                  <div class="flex items-center gap-3">
                    <component :is="getFileIcon(item)" :size="24" :class="getFileIconColor(item)" />
                    <div>
                      <div class="text-sm font-medium text-gray-900">{{ item.name }}</div>
                      <div v-if="item.mime_type" class="text-xs text-gray-500">{{ item.mime_type }}</div>
                    </div>
                  </div>
                </td>
                <td class="px-6 py-4 text-sm text-gray-700">
                  {{ item.type === 'file' ? formatBytes(item.size) : '-' }}
                </td>
                <td class="px-6 py-4 text-sm text-gray-700">
                  {{ formatDate(item.modified) }}
                </td>
                <td class="px-6 py-4 text-right">
                  <div v-if="item.type === 'file'" class="flex gap-2 justify-end">
                    <button
                      @click.stop="handlePreview(item)"
                      class="inline-flex items-center gap-2 px-3 py-1.5 text-sm text-blue-700 hover:bg-blue-50 rounded-lg transition-colors"
                    >
                      <Eye :size="16" />
                      Preview
                    </button>
                    <button
                      @click.stop="handleDownload(item)"
                      class="inline-flex items-center gap-2 px-3 py-1.5 text-sm text-primary-700 hover:bg-primary-50 rounded-lg transition-colors"
                    >
                      <Download :size="16" />
                      Download
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Stats Footer -->
        <div class="mt-6 flex items-center justify-between text-sm text-gray-600 bg-white rounded-lg px-6 py-3 border border-gray-200">
          <div class="flex items-center gap-6">
            <span class="flex items-center gap-2">
              <Folder :size="16" class="text-yellow-500" />
              {{ totalDirectories }} {{ totalDirectories === 1 ? 'folder' : 'folders' }}
            </span>
            <span class="flex items-center gap-2">
              <FileText :size="16" class="text-gray-500" />
              {{ totalFiles }} {{ totalFiles === 1 ? 'file' : 'files' }}
            </span>
          </div>
          <div v-if="searchQuery" class="text-primary-600 font-medium">
            {{ filteredItems.length }} result(s) found
          </div>
        </div>
      </div>
    </div>

    <!-- Unmount Modal -->
    <div v-if="showUnmountModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50" @click.self="showUnmountModal = false">
      <div class="bg-white rounded-xl shadow-2xl max-w-md w-full mx-4 overflow-hidden">
        <div class="bg-gradient-to-r from-red-500 to-red-600 px-6 py-4">
          <div class="flex items-center justify-between text-white">
            <h3 class="text-lg font-semibold flex items-center gap-2">
              <Power :size="20" />
              {{ $t('archive_browser.unmount_modal.title') }}
            </h3>
            <button @click="showUnmountModal = false" class="hover:bg-red-700 rounded-lg p-1 transition-colors">
              <X :size="20" />
            </button>
          </div>
        </div>

        <div class="p-6">
          <p class="text-gray-700 mb-4">
            {{ $t('archive_browser.unmount_modal.warning') }}
          </p>
          <div class="bg-gray-50 rounded-lg p-4 mb-4">
            <p class="text-sm"><strong>{{ $t('archive_browser.unmount_modal.archive_label') }}</strong> {{ archiveName }}</p>
          </div>
          <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm text-blue-800">
            <p class="font-medium mb-1">{{ $t('archive_browser.unmount_modal.info_title') }}</p>
            <ul class="list-disc list-inside space-y-1 text-xs">
              <li v-for="(item, index) in $tm('archive_browser.unmount_modal.info_items')" :key="index">{{ item }}</li>
            </ul>
          </div>
        </div>

        <div class="bg-gray-50 px-6 py-4 flex gap-3">
          <button @click="showUnmountModal = false" class="flex-1 px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
            {{ $t('archive_browser.unmount_modal.cancel') }}
          </button>
          <button @click="confirmUnmount" :disabled="unmounting" class="flex-1 px-4 py-2 bg-red-600 text-white rounded-lg hover:bg-red-700 transition-colors font-medium disabled:opacity-50">
            {{ unmounting ? $t('archive_browser.unmounting') : $t('archive_browser.unmount_modal.confirm') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Preview Modal -->
    <div v-if="showPreview" class="fixed inset-0 bg-black bg-opacity-75 flex items-center justify-center z-50 p-4" @click.self="showPreview = false">
      <div class="bg-white rounded-xl shadow-2xl w-full max-w-6xl max-h-[90vh] flex flex-col overflow-hidden">
        <!-- Header -->
        <div class="bg-gradient-to-r from-blue-500 to-blue-600 px-6 py-4">
          <div class="flex items-center justify-between text-white">
            <h3 class="text-lg font-semibold flex items-center gap-2">
              <Eye :size="20" />
              {{ $t('archive_browser.preview_modal.title') }}
            </h3>
            <button @click="showPreview = false" class="hover:bg-blue-700 rounded-lg p-1 transition-colors">
              <X :size="20" />
            </button>
          </div>
          <div v-if="previewFile" class="mt-2 text-sm text-blue-100">
            {{ previewFile.name }}
            <span class="ml-2 text-xs opacity-75">{{ formatBytes(previewFile.size) }}</span>
          </div>
        </div>

        <!-- Content -->
        <div class="flex-1 overflow-auto bg-gray-50">
          <!-- Loading -->
          <div v-if="previewLoading" class="flex items-center justify-center h-full min-h-[400px]">
            <div class="text-center">
              <Loader2 :size="48" class="animate-spin text-blue-600 mx-auto mb-4" />
              <p class="text-gray-600">{{ $t('archive_browser.preview_modal.loading') }}</p>
            </div>
          </div>

          <!-- Error -->
          <div v-else-if="previewError" class="flex items-center justify-center h-full min-h-[400px]">
            <div class="text-center">
              <AlertCircle :size="48" class="text-red-600 mx-auto mb-4" />
              <p class="text-gray-800 font-medium mb-2">{{ $t('archive_browser.preview_modal.cannot_preview') }}</p>
              <p class="text-gray-600 text-sm">{{ previewError }}</p>
            </div>
          </div>

          <!-- Image Preview -->
          <div v-else-if="previewContent && previewContent.type === 'image'" class="flex items-center justify-center p-6 min-h-[400px]">
            <img :src="previewContent.url" :alt="previewFile.name" class="max-w-full max-h-[70vh] object-contain rounded-lg shadow-lg" />
          </div>

          <!-- Text Preview -->
          <div v-else-if="previewContent && previewContent.type === 'text'" class="p-6">
            <div class="bg-gray-900 rounded-lg border border-gray-700 overflow-hidden">
              <div class="bg-gray-800 px-4 py-2 border-b border-gray-700 flex items-center justify-between">
                <span class="text-xs text-gray-400 font-mono uppercase">{{ previewContent.language }}</span>
                <span class="text-xs text-gray-500">{{ previewFile.name }}</span>
              </div>
              <pre class="p-4 overflow-x-auto text-sm"><code ref="codeElement" :class="`language-${previewContent.language}`">{{ previewContent.content }}</code></pre>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="bg-gray-50 px-6 py-4 border-t border-gray-200 flex gap-3 justify-end">
          <button @click="showPreview = false" class="px-4 py-2 bg-white border border-gray-300 text-gray-700 rounded-lg hover:bg-gray-50 transition-colors font-medium">
            {{ $t('archive_browser.preview_modal.close') }}
          </button>
          <button
            v-if="previewFile"
            @click="handleDownload(previewFile)"
            class="flex items-center gap-2 px-4 py-2 bg-primary-600 text-white rounded-lg hover:bg-primary-700 transition-colors font-medium"
          >
            <Download :size="16" />
            {{ $t('archive_browser.preview_modal.download') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Restore Wizard Modal -->
    <RestoreWizard
      v-if="showRestoreWizard"
      :show="showRestoreWizard"
      :archive-id="archiveId"
      :archive-name="archiveName"
      :selected-files="selectedItems.map(item => item.path)"
      @close="showRestoreWizard = false"
      @success="handleRestoreStarted"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, nextTick, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { backupService } from '@/services/backups'
import { jobService } from '@/services/jobs'
import hljs from 'highlight.js'
import 'highlight.js/styles/atom-one-dark.css'

// Components
import RestoreWizard from '@/components/RestoreWizard.vue'

// Lucide Icons
import {
  ArrowLeft, HardDrive, Folder, FolderOpen, ChevronRight, Search, Grid3x3, List,
  Power, X, Download, Loader2, AlertCircle, FileText, File, FileImage, FileVideo,
  FileAudio, FileArchive, FileCode, FileSpreadsheet, Database, Link, Settings,
  ChevronUp, ChevronDown, ChevronsUpDown, Eye, CheckSquare, Square
} from 'lucide-vue-next'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()

const archiveId = parseInt(route.params.id)
const archiveName = ref(route.query.name || '')

const mounting = ref(false)
const loading = ref(false)
const unmounting = ref(false)
const error = ref(null)
const showUnmountModal = ref(false)

// Preview state
const showPreview = ref(false)
const previewLoading = ref(false)
const previewError = ref(null)
const previewContent = ref(null)
const previewFile = ref(null)
const codeElement = ref(null)

const currentPath = ref('/')
const items = ref([])
const viewMode = ref('grid') // 'grid' or 'list'
const searchQuery = ref('')

// Sort state
const sortColumn = ref('name')
const sortDirection = ref('asc')

// Selection & Restore state
const selectedItems = ref([])
const showRestoreWizard = ref(false)

// Poll job until mount is complete
const pollMountStatus = async (jobId) => {
  const maxAttempts = 60
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
        throw new Error(job.error || t('archive_browser.errors.mount_failed'))
      }

      if (attempts < maxAttempts && (job.status === 'pending' || job.status === 'running')) {
        attempts++
        setTimeout(poll, 1000)
      } else if (attempts >= maxAttempts) {
        throw new Error(t('archive_browser.errors.mount_timeout'))
      }
    } catch (err) {
      mounting.value = false
      error.value = err.message || t('archive_browser.errors.mount_status_failed')
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
      mounting.value = false
      await loadDirectory('/')
    } else if (result.job_id) {
      await pollMountStatus(result.job_id)
    }
  } catch (err) {
    mounting.value = false
    error.value = err.response?.data?.error?.message || err.message || t('archive_browser.errors.mount_failed')
  }
}

// Load directory contents
const loadDirectory = async (path) => {
  loading.value = true
  error.value = null

  try {
    const result = await backupService.browse(archiveId, path)
    currentPath.value = result.path
    items.value = result.items
  } catch (err) {
    const errorCode = err.response?.data?.error?.code
    const errorMessage = err.response?.data?.error?.message || err.message || 'Failed to load directory'

    // If mount has expired, automatically remount
    if (errorCode === 'MOUNT_EXPIRED') {
      error.value = t('archive_browser.errors.mount_expired')
      items.value = []
      loading.value = false
      // Automatically remount
      await mountArchive()
      return
    }

    error.value = errorMessage
    items.value = []
  } finally {
    loading.value = false
  }
}

// Unmount archive
const confirmUnmount = async () => {
  showUnmountModal.value = false
  unmounting.value = true

  try {
    await backupService.unmount(archiveId)
    unmounting.value = false
    router.push('/backups')
  } catch (err) {
    error.value = err.response?.data?.error?.message || err.message || t('archive_browser.errors.unmount_failed')
    unmounting.value = false
  }
}

// Handle item click
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
    const { default: api } = await import('@/services/api')

    const response = await api.get(`/backups/${archiveId}/download`, {
      params: { path: item.path },
      responseType: 'blob'
    })

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
    error.value = t('archive_browser.errors.download_failed') + ': ' + (err.response?.data?.error?.message || err.message)
  }
}

// Detect language from filename for syntax highlighting
const detectLanguage = (filename) => {
  const ext = filename.split('.').pop()?.toLowerCase()

  const languageMap = {
    // Web
    'js': 'javascript', 'jsx': 'javascript', 'ts': 'typescript', 'tsx': 'typescript',
    'html': 'html', 'htm': 'html', 'css': 'css', 'scss': 'scss', 'sass': 'sass', 'less': 'less',
    'vue': 'vue', 'svelte': 'svelte',

    // Backend
    'php': 'php', 'py': 'python', 'rb': 'ruby', 'java': 'java', 'kt': 'kotlin',
    'go': 'go', 'rs': 'rust', 'c': 'c', 'cpp': 'cpp', 'cc': 'cpp', 'cxx': 'cpp',
    'cs': 'csharp', 'swift': 'swift', 'scala': 'scala',

    // Shell/Script
    'sh': 'bash', 'bash': 'bash', 'zsh': 'bash', 'fish': 'bash',
    'ps1': 'powershell', 'bat': 'batch', 'cmd': 'batch',

    // Data formats
    'json': 'json', 'xml': 'xml', 'yaml': 'yaml', 'yml': 'yaml',
    'toml': 'toml', 'ini': 'ini', 'conf': 'nginx', 'config': 'nginx',

    // Database
    'sql': 'sql',

    // Markup
    'md': 'markdown', 'markdown': 'markdown', 'rst': 'rst',

    // Other
    'diff': 'diff', 'patch': 'diff',
    'log': 'accesslog',
    'txt': 'plaintext'
  }

  return languageMap[ext] || 'plaintext'
}

// Apply syntax highlighting
const applySyntaxHighlighting = () => {
  nextTick(() => {
    if (codeElement.value && previewContent.value?.type === 'text') {
      hljs.highlightElement(codeElement.value)
    }
  })
}

// Watch for content changes to apply highlighting
watch(() => previewContent.value, (newContent) => {
  if (newContent?.type === 'text') {
    applySyntaxHighlighting()
  }
})

// Preview file
const handlePreview = async (item) => {
  previewFile.value = item
  previewContent.value = null
  previewError.value = null
  previewLoading.value = true
  showPreview.value = true

  try {
    const { default: api } = await import('@/services/api')

    // Check if file is an image by mime type
    const isImage = item.mime_type && item.mime_type.startsWith('image/')

    if (isImage) {
      // For images, create blob URL from API response
      const response = await api.get(`/backups/${archiveId}/preview`, {
        params: { path: item.path },
        responseType: 'blob'
      })

      const url = window.URL.createObjectURL(response.data)
      previewContent.value = {
        type: 'image',
        url: url
      }
    } else {
      // For text files, get JSON response
      const response = await api.get(`/backups/${archiveId}/preview`, {
        params: { path: item.path }
      })

      if (response.data.success && response.data.data.type === 'text') {
        const language = detectLanguage(item.name)
        previewContent.value = {
          type: 'text',
          content: response.data.data.content,
          language: language
        }
      } else {
        previewError.value = t('archive_browser.preview_modal.cannot_preview')
      }
    }
  } catch (err) {
    previewError.value = err.response?.data?.error?.message || t('archive_browser.preview_modal.error')
  } finally {
    previewLoading.value = false
  }
}

// Get file icon based on type
const getFileIcon = (item) => {
  if (item.type === 'directory') return Folder
  if (item.type === 'symlink') return Link

  const mime = item.mime_type || ''
  const ext = item.name.split('.').pop()?.toLowerCase() || ''

  // Images
  if (mime.startsWith('image/')) return FileImage

  // Videos
  if (mime.startsWith('video/')) return FileVideo

  // Audio
  if (mime.startsWith('audio/')) return FileAudio

  // Archives
  if (mime.includes('zip') || mime.includes('tar') || mime.includes('gz') || mime.includes('rar') ||
      ['zip', 'tar', 'gz', 'rar', '7z', 'bz2', 'xz'].includes(ext)) {
    return FileArchive
  }

  // Code files
  if (['js', 'ts', 'jsx', 'tsx', 'vue', 'php', 'py', 'rb', 'go', 'rs', 'java', 'c', 'cpp', 'h', 'css', 'scss', 'html', 'json', 'xml', 'yaml', 'yml'].includes(ext)) {
    return FileCode
  }

  // Spreadsheets
  if (mime.includes('spreadsheet') || ['xls', 'xlsx', 'csv', 'ods'].includes(ext)) {
    return FileSpreadsheet
  }

  // Databases
  if (['db', 'sql', 'sqlite', 'mdb'].includes(ext)) {
    return Database
  }

  // Config files
  if (['conf', 'config', 'ini', 'env'].includes(ext)) {
    return Settings
  }

  return File
}

// Get file icon color
const getFileIconColor = (item) => {
  if (item.type === 'directory') return 'text-yellow-500'
  if (item.type === 'symlink') return 'text-blue-500'

  const mime = item.mime_type || ''
  const ext = item.name.split('.').pop()?.toLowerCase() || ''

  if (mime.startsWith('image/')) return 'text-green-500'
  if (mime.startsWith('video/')) return 'text-purple-500'
  if (mime.startsWith('audio/')) return 'text-pink-500'
  if (mime.includes('zip') || mime.includes('tar') || mime.includes('gz') || ['zip', 'tar', 'gz', 'rar', '7z'].includes(ext)) return 'text-orange-500'
  if (['js', 'ts', 'jsx', 'tsx', 'vue', 'php', 'py'].includes(ext)) return 'text-blue-600'
  if (mime.includes('pdf')) return 'text-red-500'

  return 'text-gray-400'
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

// Filtered items
const filteredItems = computed(() => {
  let filtered = items.value

  // Filter by search
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(item => item.name.toLowerCase().includes(query))
  }

  // Sort
  const sorted = [...filtered].sort((a, b) => {
    // Always put directories first
    if (a.type === 'directory' && b.type !== 'directory') return -1
    if (a.type !== 'directory' && b.type === 'directory') return 1

    let aVal = a[sortColumn.value]
    let bVal = b[sortColumn.value]

    if (aVal == null) aVal = ''
    if (bVal == null) bVal = ''

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
const sortBy = (column) => {
  if (sortColumn.value === column) {
    sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortColumn.value = column
    sortDirection.value = 'asc'
  }
}

// Totals
const totalFiles = computed(() => items.value.filter(i => i.type === 'file').length)
const totalDirectories = computed(() => items.value.filter(i => i.type === 'directory').length)

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
  return date.toLocaleString('fr-FR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}

// Selection functions
const isSelected = (item) => {
  return selectedItems.value.some(selected => selected.path === item.path)
}

const toggleSelection = (item) => {
  const index = selectedItems.value.findIndex(selected => selected.path === item.path)

  if (index > -1) {
    // Item is selected, remove it
    selectedItems.value.splice(index, 1)
  } else {
    // Item is not selected, add it
    selectedItems.value.push(item)
  }
}

const clearSelection = () => {
  selectedItems.value = []
}

const toggleSelectAll = () => {
  if (selectedItems.value.length === filteredItems.value.length) {
    // All selected, deselect all
    clearSelection()
  } else {
    // Not all selected, select all
    selectedItems.value = [...filteredItems.value]
  }
}

const handleRestoreStarted = (result) => {
  // Clear selection
  clearSelection()

  // Close wizard (déjà fait par le wizard lui-même)
  showRestoreWizard.value = false

  // Navigate to jobs page to show progress
  if (result.job_id) {
    router.push({ name: 'jobs', query: { highlight: result.job_id } })
  }
}

onMounted(() => {
  mountArchive()
})
</script>
