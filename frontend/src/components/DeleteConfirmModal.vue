<template>
  <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg max-w-2xl w-full p-6">
      <!-- Icon -->
      <div class="flex items-center justify-center w-12 h-12 rounded-full bg-red-100 dark:bg-red-900/30 mx-auto mb-4">
        <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
      </div>

      <!-- Title -->
      <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 text-center mb-2">
        {{ $t('servers.delete_server_title') }}
      </h3>

      <!-- Message -->
      <p class="text-sm text-gray-600 dark:text-gray-400 text-center mb-6">
        {{ $t('servers.delete_server_question', { name: server?.name }) }}
      </p>

      <!-- Options Grid -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
        <!-- Option 1: Archive -->
        <button
          @click="selectedOption = 'archive'"
          :class="[
            'p-4 rounded-lg border-2 text-left transition-all',
            selectedOption === 'archive'
              ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
              : 'border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700'
          ]"
        >
          <div class="flex items-start gap-3">
            <div :class="[
              'mt-0.5 w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0',
              selectedOption === 'archive'
                ? 'border-blue-500 bg-blue-500'
                : 'border-gray-300 dark:border-gray-600'
            ]">
              <div v-if="selectedOption === 'archive'" class="w-2 h-2 bg-white rounded-full"></div>
            </div>
            <div class="flex-1">
              <div class="flex items-center gap-2 mb-1">
                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                </svg>
                <h4 class="font-semibold text-gray-900 dark:text-gray-100">{{ $t('servers.archive_option') }}</h4>
              </div>
              <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">
                {{ $t('servers.archive_description') }}
              </p>
              <div class="flex flex-wrap gap-1">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                  <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                  {{ $t('servers.keeps_backups') }}
                </span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                  <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                  </svg>
                  {{ $t('servers.reversible') }}
                </span>
              </div>
            </div>
          </div>
        </button>

        <!-- Option 2: Full Delete -->
        <button
          @click="selectedOption = 'full'"
          :class="[
            'p-4 rounded-lg border-2 text-left transition-all',
            selectedOption === 'full'
              ? 'border-red-500 bg-red-50 dark:bg-red-900/20'
              : 'border-gray-200 dark:border-gray-700 hover:border-red-300 dark:hover:border-red-700'
          ]"
        >
          <div class="flex items-start gap-3">
            <div :class="[
              'mt-0.5 w-5 h-5 rounded-full border-2 flex items-center justify-center flex-shrink-0',
              selectedOption === 'full'
                ? 'border-red-500 bg-red-500'
                : 'border-gray-300 dark:border-gray-600'
            ]">
              <div v-if="selectedOption === 'full'" class="w-2 h-2 bg-white rounded-full"></div>
            </div>
            <div class="flex-1">
              <div class="flex items-center gap-2 mb-1">
                <svg class="w-5 h-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                <h4 class="font-semibold text-gray-900 dark:text-gray-100">{{ $t('servers.full_delete_option') }}</h4>
              </div>
              <p class="text-xs text-gray-600 dark:text-gray-400 mb-2">
                {{ $t('servers.full_delete_description') }}
              </p>
              <div class="flex flex-wrap gap-1">
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">
                  <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                  {{ $t('servers.deletes_repos') }}
                </span>
                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400">
                  <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                  </svg>
                  {{ $t('servers.irreversible') }}
                </span>
              </div>
            </div>
          </div>
        </button>
      </div>

      <!-- Warning for Full Delete -->
      <div v-if="selectedOption === 'full'" class="mb-6 p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg">
        <div class="flex items-start gap-3">
          <svg class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
          </svg>
          <div class="flex-1">
            <h5 class="font-semibold text-red-800 dark:text-red-300 mb-1">{{ $t('servers.danger_zone') }}</h5>
            <p class="text-sm text-red-700 dark:text-red-400 mb-3">
              {{ $t('servers.full_delete_warning') }}
            </p>

            <!-- Deletion Statistics -->
            <div v-if="loadingStats" class="text-sm text-red-700 dark:text-red-400">
              <svg class="animate-spin h-4 w-4 inline mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ $t('servers.loading_stats') }}
            </div>
            <div v-else-if="deleteStats" class="bg-white dark:bg-gray-900 rounded p-3 mt-2">
              <p class="text-xs font-semibold text-red-800 dark:text-red-300 mb-2">{{ $t('servers.deletion_impact') }}</p>
              <div class="grid grid-cols-2 gap-2 text-xs">
                <div class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                  </svg>
                  <span class="text-gray-700 dark:text-gray-300">
                    <strong class="text-red-700 dark:text-red-400">{{ deleteStats.repositories }}</strong> {{ $t('servers.repositories') }}
                  </span>
                </div>
                <div class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                  </svg>
                  <span class="text-gray-700 dark:text-gray-300">
                    <strong class="text-red-700 dark:text-red-400">{{ deleteStats.archives }}</strong> {{ $t('servers.archives') }}
                  </span>
                </div>
                <div class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <span class="text-gray-700 dark:text-gray-300">
                    <strong class="text-red-700 dark:text-red-400">{{ deleteStats.backup_jobs }}</strong> {{ $t('servers.backup_jobs') }}
                  </span>
                </div>
                <div class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                  </svg>
                  <span class="text-gray-700 dark:text-gray-300">
                    <strong class="text-red-700 dark:text-red-400">{{ deleteStats.total_size_gb }} GB</strong> {{ $t('servers.total_data') }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Actions -->
      <div class="flex gap-3">
        <button
          @click="$emit('close')"
          class="flex-1 btn btn-secondary"
        >
          {{ $t('common.cancel') }}
        </button>
        <button
          @click="handleConfirm"
          :disabled="!selectedOption"
          :class="[
            'flex-1 btn transition-all',
            selectedOption === 'full'
              ? 'bg-red-600 text-white hover:bg-red-700 disabled:bg-gray-300 disabled:cursor-not-allowed'
              : selectedOption === 'archive'
              ? 'bg-blue-600 text-white hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed'
              : 'bg-gray-300 text-gray-500 cursor-not-allowed'
          ]"
        >
          {{ selectedOption === 'archive' ? $t('servers.archive_server') : selectedOption === 'full' ? $t('servers.delete_permanently') : $t('common.confirm') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { serverService } from '@/services/server'

const props = defineProps({
  server: {
    type: Object,
    required: true,
  },
})

const emit = defineEmits(['close', 'confirm'])

const selectedOption = ref(null)
const loadingStats = ref(false)
const deleteStats = ref(null)

// Fetch deletion stats when full delete is selected
watch(selectedOption, async (newValue) => {
  if (newValue === 'full') {
    loadingStats.value = true
    deleteStats.value = null
    try {
      deleteStats.value = await serverService.getDeleteStats(props.server.id)
    } catch (error) {
      console.error('Failed to fetch delete stats:', error)
      // If stats fail to load, user can still proceed
      deleteStats.value = null
    } finally {
      loadingStats.value = false
    }
  } else {
    deleteStats.value = null
  }
})

function handleConfirm() {
  if (selectedOption.value) {
    emit('confirm', selectedOption.value)
  }
}
</script>
