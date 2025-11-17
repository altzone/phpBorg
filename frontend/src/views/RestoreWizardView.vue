<template>
  <div>
    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $t('restore_wizard.title') }}</h1>
      <p class="mt-2 text-gray-600 dark:text-gray-400">{{ $t('restore_wizard.subtitle') }}</p>
    </div>

    <!-- Progress Steps -->
    <div class="mb-8">
      <div class="flex items-center justify-center">
        <div class="flex items-center">
          <!-- Step 1 -->
          <div class="flex items-center">
            <div :class="[
              'flex items-center justify-center w-10 h-10 rounded-full border-2 font-semibold',
              currentStep >= 1 ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-300 text-gray-300'
            ]">
              1
            </div>
            <span :class="['ml-2 font-medium', currentStep >= 1 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400']">
              {{ $t('restore_wizard.step1') }}
            </span>
          </div>

          <!-- Arrow -->
          <svg class="w-6 h-6 mx-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>

          <!-- Step 2 -->
          <div class="flex items-center">
            <div :class="[
              'flex items-center justify-center w-10 h-10 rounded-full border-2 font-semibold',
              currentStep >= 2 ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-300 text-gray-300'
            ]">
              2
            </div>
            <span :class="['ml-2 font-medium', currentStep >= 2 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400']">
              {{ $t('restore_wizard.step2') }}
            </span>
          </div>

          <!-- Arrow -->
          <svg class="w-6 h-6 mx-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>

          <!-- Step 3 -->
          <div class="flex items-center">
            <div :class="[
              'flex items-center justify-center w-10 h-10 rounded-full border-2 font-semibold',
              currentStep >= 3 ? 'bg-primary-600 border-primary-600 text-white' : 'border-gray-300 text-gray-300'
            ]">
              3
            </div>
            <span :class="['ml-2 font-medium', currentStep >= 3 ? 'text-gray-900 dark:text-gray-100' : 'text-gray-400']">
              {{ $t('restore_wizard.step3') }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400">{{ $t('restore_wizard.loading') }}</p>
      </div>
    </div>

    <!-- Step 1: Select Server -->
    <div v-else-if="currentStep === 1" class="card">
      <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100 mb-6">{{ $t('restore_wizard.select_server_title') }}</h2>

      <div v-if="!servers.length" class="text-center py-12 text-gray-500">
        <p>{{ $t('restore_wizard.no_servers') }}</p>
      </div>

      <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <div
          v-for="server in servers"
          :key="server.id"
          @click="selectServer(server)"
          class="group cursor-pointer p-5 border-2 border-gray-200 dark:border-gray-700 rounded-lg hover:border-primary-500 dark:hover:border-primary-500 hover:shadow-lg transition-all"
        >
          <div class="flex items-start gap-3 mb-3">
            <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-gradient-to-br from-blue-500 to-blue-600 dark:from-blue-600 dark:to-blue-700 flex items-center justify-center text-white">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
              </svg>
            </div>
            <div class="flex-1 min-w-0">
              <div class="flex items-start justify-between mb-1">
                <h3 class="font-bold text-gray-900 dark:text-gray-100 truncate">{{ server.name }}</h3>
                <span v-if="server.active" class="flex-shrink-0 ml-2 px-2 py-0.5 text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 rounded">
                  {{ $t('restore_wizard.active') }}
                </span>
                <span v-else class="flex-shrink-0 ml-2 px-2 py-0.5 text-xs font-semibold bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200 rounded">
                  {{ $t('restore_wizard.inactive') }}
                </span>
              </div>
              <p class="text-sm text-gray-600 dark:text-gray-400 mb-2 truncate">{{ server.hostname }}</p>
              <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
                </svg>
                <span>{{ server.repository_count || 0 }} {{ server.repository_count === 1 ? $t('restore_wizard.repository') : $t('restore_wizard.repositories') }}</span>
              </div>
            </div>
          </div>
          <div class="flex justify-end">
            <span class="text-xs text-primary-600 dark:text-primary-400 group-hover:text-primary-700 dark:group-hover:text-primary-300 font-medium">
              {{ $t('restore_wizard.select_arrow') }}
            </span>
          </div>
        </div>
      </div>
    </div>

    <!-- Step 2: Select Repository -->
    <div v-else-if="currentStep === 2" class="card">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $t('restore_wizard.select_repository_title') }}</h2>
          <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">{{ $t('restore_wizard.server_label') }} {{ selectedServer?.name }}</p>
        </div>
        <button @click="goToStep(1)" class="btn btn-secondary">
          {{ $t('restore_wizard.back_to_servers') }}
        </button>
      </div>

      <div v-if="!repositories.length" class="text-center py-12 text-gray-500">
        <p>{{ $t('restore_wizard.no_repositories') }}</p>
      </div>

      <div v-else class="space-y-4">
        <div
          v-for="repo in repositories"
          :key="repo.id"
          @click="selectRepository(repo)"
          class="group cursor-pointer p-5 border-2 border-gray-200 dark:border-gray-700 rounded-lg hover:border-primary-500 dark:hover:border-primary-500 hover:shadow-lg transition-all"
        >
          <div class="flex items-start gap-4">
            <!-- Icon -->
            <div class="flex-shrink-0 w-12 h-12 rounded-lg bg-gradient-to-br from-primary-500 to-primary-600 dark:from-primary-600 dark:to-primary-700 flex items-center justify-center text-white">
              <svg v-if="repo.type === 'mysql'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
              </svg>
              <svg v-else-if="repo.type === 'filesystem'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
              </svg>
              <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
              </svg>
            </div>

            <!-- Content -->
            <div class="flex-1 min-w-0">
              <div class="flex items-start justify-between mb-3">
                <div class="flex-1">
                  <h3 class="text-lg font-bold text-gray-900 dark:text-gray-100 mb-1">
                    {{ repo.name }}
                  </h3>
                  <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
                    </svg>
                    <span class="truncate">{{ repo.backup_path }}</span>
                  </div>
                </div>
                <svg class="w-5 h-5 text-gray-400 group-hover:text-primary-500 transition flex-shrink-0 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
              </div>

              <!-- Stats Grid -->
              <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-3">
                <div class="flex items-center gap-2">
                  <div class="w-8 h-8 rounded bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center">
                    <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                  </div>
                  <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $t('restore_wizard.archives') }}</div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ repo.archive_count || 0 }}</div>
                  </div>
                </div>

                <div class="flex items-center gap-2">
                  <div class="w-8 h-8 rounded bg-purple-50 dark:bg-purple-900/20 flex items-center justify-center">
                    <svg class="w-4 h-4 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                    </svg>
                  </div>
                  <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $t('restore_wizard.size') }}</div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ formatBytes(repo.deduplicated_size) }}</div>
                  </div>
                </div>

                <div class="flex items-center gap-2">
                  <div class="w-8 h-8 rounded bg-green-50 dark:bg-green-900/20 flex items-center justify-center">
                    <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                  </div>
                  <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $t('restore_wizard.last_backup') }}</div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                      {{ repo.last_backup_at ? formatDateShort(repo.last_backup_at) : $t('restore_wizard.never') }}
                    </div>
                  </div>
                </div>

                <div class="flex items-center gap-2">
                  <div class="w-8 h-8 rounded bg-orange-50 dark:bg-orange-900/20 flex items-center justify-center">
                    <svg class="w-4 h-4 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                    </svg>
                  </div>
                  <div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">{{ $t('restore_wizard.type') }}</div>
                    <div class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ repo.type }}</div>
                  </div>
                </div>
              </div>

              <!-- Tags -->
              <div class="flex gap-2 flex-wrap">
                <span class="px-2 py-1 text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded">
                  {{ repo.compression }}
                </span>
                <span class="px-2 py-1 text-xs font-medium bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded">
                  {{ repo.encryption }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Step 3: Select Archive -->
    <div v-else-if="currentStep === 3" class="card">
      <div class="flex items-center justify-between mb-6">
        <div>
          <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ $t('restore_wizard.select_archive_title') }}</h2>
          <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
            {{ $t('restore_wizard.server_label') }} {{ selectedServer?.name }} / {{ $t('restore_wizard.step2') }} #{{ selectedRepository?.id }}
          </p>
        </div>
        <button @click="goToStep(2)" class="btn btn-secondary">
          {{ $t('restore_wizard.back_to_repositories') }}
        </button>
      </div>

      <div v-if="!archives.length" class="text-center py-12 text-gray-500">
        <p>{{ $t('restore_wizard.no_archives') }}</p>
      </div>

      <div v-else class="space-y-3">
        <div
          v-for="archive in archives"
          :key="archive.id"
          class="p-4 border border-gray-200 dark:border-gray-700 rounded-lg"
        >
          <div class="flex items-start justify-between">
            <div class="flex-1">
              <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ archive.name }}</h3>
              <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm mb-3">
                <div>
                  <span class="text-gray-600 dark:text-gray-400">{{ $t('restore_wizard.date') }}</span>
                  <span class="ml-1 text-gray-900 dark:text-gray-100">{{ formatDate(archive.end) }}</span>
                </div>
                <div>
                  <span class="text-gray-600 dark:text-gray-400">{{ $t('restore_wizard.size_label') }}</span>
                  <span class="ml-1 text-gray-900 dark:text-gray-100">{{ formatBytes(archive.original_size) }}</span>
                </div>
                <div>
                  <span class="text-gray-600 dark:text-gray-400">{{ $t('restore_wizard.files') }}</span>
                  <span class="ml-1 text-gray-900 dark:text-gray-100">{{ archive.files_count }}</span>
                </div>
                <div>
                  <span class="text-gray-600 dark:text-gray-400">{{ $t('restore_wizard.duration') }}</span>
                  <span class="ml-1 text-gray-900 dark:text-gray-100">{{ archive.duration_formatted }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="flex gap-3 pt-3 border-t border-gray-200 dark:border-gray-700">
            <button
              @click="handleMountAndBrowse(archive)"
              :disabled="archive.mount_status === 'mounting'"
              class="btn btn-primary flex-1"
            >
              <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" />
              </svg>
              {{ archive.mount_status === 'mounting' ? $t('restore_wizard.mounting') : $t('restore_wizard.mount_browse') }}
            </button>

            <!-- Instant Recovery Button (only for databases) -->
            <button
              v-if="isDatabaseArchive(archive)"
              @click="handleInstantRecovery(archive)"
              :disabled="archive.instant_recovery_starting"
              class="btn btn-success flex-1"
            >
              <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
              </svg>
              {{ archive.instant_recovery_starting ? $t('restore_wizard.instant_recovery.starting') : $t('restore_wizard.instant_recovery.button') }}
            </button>

            <button
              @click="handleDirectRestore(archive)"
              class="btn btn-secondary flex-1"
            >
              <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
              </svg>
              {{ $t('restore_wizard.direct_restore') }}
            </button>
          </div>
        </div>
      </div>
    </div>

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

    <!-- Instant Recovery Modal -->
    <div
      v-if="instantRecoveryModal.show"
      class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4"
      @click.self="instantRecoveryModal.show = false"
    >
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-y-auto">
        <!-- Header -->
        <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
          <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
              <div class="w-12 h-12 rounded-lg bg-gradient-to-br from-green-500 to-green-600 flex items-center justify-center text-white">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                </svg>
              </div>
              <div>
                <h3 class="text-xl font-bold text-gray-900 dark:text-gray-100">
                  {{ $t('restore_wizard.instant_recovery.modal_title') }}
                </h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                  {{ instantRecoveryModal.archive?.name }}
                </p>
              </div>
            </div>
            <button
              @click="instantRecoveryModal.show = false"
              class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>
        </div>

        <!-- Body -->
        <div class="px-6 py-6">
          <p class="text-gray-700 dark:text-gray-300 mb-6">
            {{ $t('restore_wizard.instant_recovery.modal_description') }}
          </p>

          <!-- Deployment Location Selection -->
          <div class="space-y-4">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
              {{ $t('restore_wizard.instant_recovery.deployment_label') }}
            </label>

            <!-- Remote Option -->
            <div
              @click="instantRecoveryModal.deploymentLocation = 'remote'"
              :class="[
                'group cursor-pointer p-5 border-2 rounded-lg transition-all',
                instantRecoveryModal.deploymentLocation === 'remote'
                  ? 'border-green-500 bg-green-50 dark:bg-green-900/20'
                  : 'border-gray-200 dark:border-gray-700 hover:border-green-300 dark:hover:border-green-600'
              ]"
            >
              <div class="flex items-start gap-4">
                <div :class="[
                  'flex-shrink-0 w-6 h-6 rounded-full border-2 flex items-center justify-center',
                  instantRecoveryModal.deploymentLocation === 'remote'
                    ? 'border-green-500 bg-green-500'
                    : 'border-gray-300 dark:border-gray-600'
                ]">
                  <svg v-if="instantRecoveryModal.deploymentLocation === 'remote'" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <div class="flex-1">
                  <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-1">
                    {{ $t('restore_wizard.instant_recovery.remote_title') }}
                  </h4>
                  <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $t('restore_wizard.instant_recovery.remote_description', { server: selectedServer?.name || '' }) }}
                  </p>
                  <div class="mt-3 flex flex-wrap gap-2">
                    <span class="px-2 py-1 text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded">
                      {{ $t('restore_wizard.instant_recovery.remote_tag1') }}
                    </span>
                    <span class="px-2 py-1 text-xs font-medium bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded">
                      {{ $t('restore_wizard.instant_recovery.remote_tag2') }}
                    </span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Local Option -->
            <div
              @click="instantRecoveryModal.deploymentLocation = 'local'"
              :class="[
                'group cursor-pointer p-5 border-2 rounded-lg transition-all',
                instantRecoveryModal.deploymentLocation === 'local'
                  ? 'border-green-500 bg-green-50 dark:bg-green-900/20'
                  : 'border-gray-200 dark:border-gray-700 hover:border-green-300 dark:hover:border-green-600'
              ]"
            >
              <div class="flex items-start gap-4">
                <div :class="[
                  'flex-shrink-0 w-6 h-6 rounded-full border-2 flex items-center justify-center',
                  instantRecoveryModal.deploymentLocation === 'local'
                    ? 'border-green-500 bg-green-500'
                    : 'border-gray-300 dark:border-gray-600'
                ]">
                  <svg v-if="instantRecoveryModal.deploymentLocation === 'local'" class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7" />
                  </svg>
                </div>
                <div class="flex-1">
                  <h4 class="font-semibold text-gray-900 dark:text-gray-100 mb-1">
                    {{ $t('restore_wizard.instant_recovery.local_title') }}
                  </h4>
                  <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $t('restore_wizard.instant_recovery.local_description') }}
                  </p>
                  <div class="mt-3 flex flex-wrap gap-2">
                    <span class="px-2 py-1 text-xs font-medium bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 rounded">
                      {{ $t('restore_wizard.instant_recovery.local_tag1') }}
                    </span>
                    <span class="px-2 py-1 text-xs font-medium bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 rounded">
                      {{ $t('restore_wizard.instant_recovery.local_tag2') }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex justify-end gap-3">
          <button
            @click="instantRecoveryModal.show = false"
            class="btn btn-secondary"
          >
            {{ $t('restore_wizard.instant_recovery.cancel') }}
          </button>
          <button
            @click="confirmInstantRecovery"
            :disabled="instantRecoveryModal.starting"
            class="btn btn-success"
          >
            <svg v-if="!instantRecoveryModal.starting" class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            <div v-else class="inline-block w-4 h-4 mr-2 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
            {{ instantRecoveryModal.starting ? $t('restore_wizard.instant_recovery.starting') : $t('restore_wizard.instant_recovery.start_button') }}
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Job Polling Status (only while job is running) -->
  <div v-if="jobPolling.active" class="fixed bottom-6 right-6 z-50 w-96 max-w-full">
    <!-- Job Polling Card -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-2xl border border-gray-200 dark:border-gray-700 overflow-hidden">
      <div class="px-5 py-4 bg-gradient-to-r from-blue-500 to-blue-600">
        <h3 class="text-white font-semibold text-lg flex items-center gap-2">
          <div class="w-5 h-5 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
          {{ $t('restore_wizard.instant_recovery.job_polling_title') }}
        </h3>
      </div>
      <div class="p-5">
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
          {{ $t('restore_wizard.instant_recovery.job_polling_message') }}
        </p>

        <!-- Job Status -->
        <div class="flex items-center gap-3 text-sm">
          <div class="flex-1">
            <div class="flex items-center gap-2">
              <div v-if="jobPolling.status === 'pending'" class="w-2 h-2 bg-yellow-400 rounded-full animate-pulse"></div>
              <div v-else-if="jobPolling.status === 'running'" class="w-2 h-2 bg-blue-500 rounded-full animate-pulse"></div>
              <span class="text-gray-700 dark:text-gray-300">
                <span v-if="jobPolling.status === 'pending'">{{ $t('restore_wizard.instant_recovery.job_starting') }}</span>
                <span v-else-if="jobPolling.status === 'running'">{{ $t('restore_wizard.instant_recovery.job_running') }}</span>
              </span>
            </div>
          </div>
          <span class="text-gray-500 dark:text-gray-500 text-xs">Job #{{ jobPolling.jobId }}</span>
        </div>
      </div>
    </div>
  </div>

  <!-- Error Card (only on failure) -->
  <div v-if="jobPolling.error && !jobPolling.active" class="fixed bottom-6 right-6 z-50 w-96 max-w-full">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-2xl border border-red-200 dark:border-red-900 overflow-hidden">
      <div class="px-5 py-4 bg-gradient-to-r from-red-500 to-red-600">
        <h3 class="text-white font-semibold text-lg">
          {{ $t('restore_wizard.instant_recovery.job_failed_title') }}
        </h3>
      </div>
      <div class="p-5">
        <p class="text-sm text-gray-700 dark:text-gray-300 mb-4">
          {{ $t('restore_wizard.instant_recovery.job_failed_message', { error: jobPolling.error }) }}
        </p>
        <button
          @click="jobPolling.active = false; jobPolling.error = null"
          class="btn btn-secondary w-full text-sm"
        >
          {{ $t('common.close') }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { serverService } from '@/services/server'
import { repositoryService } from '@/services/repository'
import { backupService } from '@/services/backups'
import { instantRecoveryService } from '@/services/instantRecovery'
import { jobService } from '@/services/jobs'
import { useInstantRecoveryStore } from '@/stores/instantRecovery'
import api from '@/services/api'

const router = useRouter()
const { t } = useI18n()
const instantRecoveryStore = useInstantRecoveryStore()

const currentStep = ref(1)
const loading = ref(false)

const servers = ref([])
const selectedServer = ref(null)

const repositories = ref([])
const selectedRepository = ref(null)

const archives = ref([])

// Toast notifications
const toasts = ref([])
let toastIdCounter = 0

// Instant Recovery Modal
const instantRecoveryModal = ref({
  show: false,
  archive: null,
  deploymentLocation: 'remote',
  starting: false
})

// Instant Recovery Job Polling State
const jobPolling = ref({
  active: false,
  jobId: null,
  status: null,
  sessionId: null,
  error: null
})
let pollingInterval = null

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

// Load servers on mount
onMounted(async () => {
  loading.value = true
  try {
    const result = await serverService.list()
    // Filter only active servers for restore wizard
    servers.value = result.filter(s => s.active)
  } catch (err) {
    console.error('Failed to load servers:', err)
  } finally {
    loading.value = false
  }
})

function goToStep(step) {
  currentStep.value = step
}

async function selectServer(server) {
  selectedServer.value = server
  currentStep.value = 2

  // Load repositories for this server
  loading.value = true
  try {
    const result = await repositoryService.listByServer(server.id)
    repositories.value = result
  } catch (err) {
    console.error('Failed to load repositories:', err)
  } finally {
    loading.value = false
  }
}

async function selectRepository(repo) {
  selectedRepository.value = repo
  currentStep.value = 3

  // Load archives for this repository
  loading.value = true
  try {
    const result = await backupService.list({ repo_id: repo.repo_id, limit: 100 })
    // Add repository type to each archive
    archives.value = result.map(archive => ({
      ...archive,
      type: repo.type || archive.type
    }))
  } catch (err) {
    console.error('Failed to load archives:', err)
  } finally {
    loading.value = false
  }
}

async function handleMountAndBrowse(archive) {
  try {
    // Check if already mounted
    if (archive.mount_status === 'mounted') {
      router.push(`/backups/${archive.id}/browse`)
      return
    }

    // Mount the archive
    archive.mount_status = 'mounting'
    const mountResult = await backupService.mount(archive.id)

    // If mount already completed immediately (was cached/already mounted)
    if (mountResult.status === 'mounted') {
      router.push(`/backups/${archive.id}/browse`)
      return
    }

    const jobId = mountResult.job_id

    if (!jobId) {
      archive.mount_status = null
      showToast(
        t('restore_wizard.mount_error_title') || 'Erreur de Montage',
        'No job ID returned from mount request',
        'error',
        8000
      )
      return
    }

    // Poll for mount completion using the job_id directly
    let attempts = 0
    const maxAttempts = 60

    const poll = async () => {
      // Poll the job directly using job_id
      const response = await api.get(`/jobs/${jobId}`)
      const job = response.data.data?.job || response.data.job

      if (job.status === 'completed') {
        archive.mount_status = 'mounted'
        showToast(
          t('restore_wizard.mount_success_title') || 'Archive Montée',
          t('restore_wizard.mount_success_msg') || 'Redirection vers le navigateur...',
          'success',
          2000
        )
        setTimeout(() => {
          router.push(`/backups/${archive.id}/browse`)
        }, 500)
      } else if (job.status === 'failed') {
        archive.mount_status = null
        showToast(
          t('restore_wizard.mount_failed_title') || 'Échec du Montage',
          job.error || t('restore_wizard.mount_failed', { error: 'Unknown error' }) || 'Erreur inconnue',
          'error',
          8000
        )
      } else if (attempts < maxAttempts && (job.status === 'pending' || job.status === 'running')) {
        attempts++
        setTimeout(poll, 1000)
      } else {
        archive.mount_status = null
        showToast(
          t('restore_wizard.mount_timeout_title') || 'Délai Dépassé',
          t('restore_wizard.mount_timeout') || 'Le montage prend trop de temps',
          'warning',
          8000
        )
      }
    }

    poll()
  } catch (err) {
    archive.mount_status = null
    console.error('Mount error:', err)
    showToast(
      t('restore_wizard.mount_error_title') || 'Erreur de Montage',
      err.response?.data?.error?.message || err.message || 'Erreur inconnue',
      'error',
      8000
    )
  }
}

function handleDirectRestore(archive) {
  // TODO: Open restore modal/wizard
  showToast(
    t('restore_wizard.direct_restore_title') || 'Restauration Directe',
    t('restore_wizard.direct_restore_msg') || 'Cette fonctionnalité sera bientôt disponible',
    'warning'
  )
}

// Check if archive is a database type (PostgreSQL, MySQL, MongoDB)
function isDatabaseArchive(archive) {
  const dbTypes = ['postgresql', 'postgres', 'mysql', 'mariadb', 'mongodb']
  return archive.type && dbTypes.includes(archive.type.toLowerCase())
}

// Handle Instant Recovery button click
function handleInstantRecovery(archive) {
  instantRecoveryModal.value.archive = archive
  instantRecoveryModal.value.deploymentLocation = 'remote'
  instantRecoveryModal.value.starting = false
  instantRecoveryModal.value.show = true
}

// Poll job status every 2 seconds
async function pollJobStatus() {
  try {
    const job = await jobService.get(jobPolling.value.jobId)
    jobPolling.value.status = job.status

    if (job.status === 'completed') {
      // Job succeeded - get session info
      clearInterval(pollingInterval)
      pollingInterval = null

      // Extract session_id from job output (handler returns JSON)
      let sessionId = null
      if (job.output && typeof job.output === 'string') {
        try {
          const parsed = JSON.parse(job.output)
          sessionId = parsed.session_id
        } catch (e) {
          // If not JSON, try to parse plain text "Session ID: X"
          console.warn('Failed to parse job output as JSON:', e)
          const match = job.output.match(/Session ID:\s*(\d+)/)
          if (match) {
            sessionId = parseInt(match[1])
          }
        }
      }

      if (sessionId) {
        // Show the global task bar when session starts successfully
        instantRecoveryStore.showTaskBar()
        instantRecoveryStore.fetchActiveSessions()
      } else {
        console.error('Could not extract session_id from job output:', job.output)
        jobPolling.value.error = 'Failed to extract session information from job result'
      }

      jobPolling.value.active = false

    } else if (job.status === 'failed') {
      // Job failed
      clearInterval(pollingInterval)
      pollingInterval = null
      jobPolling.value.error = job.error_message || t('restore_wizard.instant_recovery.job_failed_message')
      jobPolling.value.active = false
    }
    // If pending or running, keep polling

  } catch (err) {
    console.error('Polling error:', err)
  }
}

// Load session info when job completes
// Removed: loadSessionInfo, copyConnectionString, openDatabaseManager, stopInstantRecovery
// These functions are now handled by the global InstantRecoveryTaskBar component

// Cleanup polling on component unmount
onUnmounted(() => {
  if (pollingInterval) {
    clearInterval(pollingInterval)
    pollingInterval = null
  }
})

// Confirm and start Instant Recovery
async function confirmInstantRecovery() {
  const archive = instantRecoveryModal.value.archive
  const deploymentLocation = instantRecoveryModal.value.deploymentLocation

  if (!archive) return

  instantRecoveryModal.value.starting = true
  archive.instant_recovery_starting = true

  try {
    const result = await instantRecoveryService.start(archive.id, deploymentLocation)

    instantRecoveryModal.value.show = false
    instantRecoveryModal.value.starting = false
    archive.instant_recovery_starting = false

    // Start job polling
    jobPolling.value.active = true
    jobPolling.value.jobId = result.job_id
    jobPolling.value.status = 'pending'
    jobPolling.value.sessionId = null
    jobPolling.value.error = null

    // Start polling every 2 seconds
    pollingInterval = setInterval(pollJobStatus, 2000)
    // Poll immediately
    pollJobStatus()

  } catch (err) {
    console.error('Failed to start instant recovery:', err)
    showToast(
      t('restore_wizard.instant_recovery.error_title'),
      err.response?.data?.error?.message || err.message || t('restore_wizard.instant_recovery.error_message'),
      'error',
      8000
    )
    instantRecoveryModal.value.starting = false
    archive.instant_recovery_starting = false
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

function formatDateShort(dateString) {
  if (!dateString) return t('restore_wizard.never')
  const date = new Date(dateString)
  const now = new Date()
  const diffTime = Math.abs(now - date)
  const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24))

  if (diffDays === 0) return t('restore_wizard.time.today')
  if (diffDays === 1) return t('restore_wizard.time.yesterday')
  if (diffDays < 7) return t('restore_wizard.time.days_ago', { count: diffDays })
  if (diffDays < 30) return t('restore_wizard.time.weeks_ago', { count: Math.floor(diffDays / 7) })
  if (diffDays < 365) return t('restore_wizard.time.months_ago', { count: Math.floor(diffDays / 30) })
  return t('restore_wizard.time.years_ago', { count: Math.floor(diffDays / 365) })
}
</script>
