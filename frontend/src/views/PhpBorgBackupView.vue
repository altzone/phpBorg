<template>
  <div class="phpborg-backup-view">
    <!-- Header with title and actions -->
    <div class="flex justify-between items-start mb-6">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-white">
          {{ $t('backup.selfBackup.title') }}
        </h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">
          {{ $t('backup.selfBackup.description') }}
        </p>
      </div>

      <div class="flex gap-3">
        <button
          @click="refreshData"
          :disabled="loading"
          class="btn-secondary"
          :title="$t('common.refresh')"
        >
          <svg class="w-5 h-5" :class="{ 'animate-spin': loading }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          <span class="ml-2">{{ $t('common.refresh') }}</span>
        </button>

        <button
          @click="openCreateModal"
          :disabled="loading"
          class="btn-primary"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
          </svg>
          <span class="ml-2">{{ $t('backup.selfBackup.createBackup') }}</span>
        </button>
      </div>
    </div>

    <!-- Statistics Cards -->
    <div v-if="stats" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <!-- Total Backups -->
      <div class="stats-card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $t('backup.selfBackup.stats.totalBackups') }}</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ stats.total }}</p>
          </div>
          <div class="stats-icon bg-blue-100 dark:bg-blue-900/30">
            <svg class="w-8 h-8 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z" />
            </svg>
          </div>
        </div>
        <div class="mt-4 flex gap-3 text-xs text-gray-500 dark:text-gray-400">
          <span>{{ $t('backup.selfBackup.types.manual') }}: {{ stats.manual }}</span>
          <span>{{ $t('backup.selfBackup.types.scheduled') }}: {{ stats.scheduled }}</span>
        </div>
      </div>

      <!-- Total Size -->
      <div class="stats-card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $t('backup.selfBackup.stats.totalSize') }}</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ stats.total_size_human }}</p>
          </div>
          <div class="stats-icon bg-purple-100 dark:bg-purple-900/30">
            <svg class="w-8 h-8 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Encrypted Backups -->
      <div class="stats-card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $t('backup.selfBackup.stats.encrypted') }}</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ stats.encrypted }}</p>
          </div>
          <div class="stats-icon bg-green-100 dark:bg-green-900/30">
            <svg class="w-8 h-8 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
          </div>
        </div>
        <div class="mt-4 text-xs text-gray-500 dark:text-gray-400">
          {{ Math.round((stats.encrypted / stats.total) * 100) }}% {{ $t('backup.selfBackup.stats.encryptionRate') }}
        </div>
      </div>

      <!-- Pre-Update Backups -->
      <div class="stats-card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600 dark:text-gray-400">{{ $t('backup.selfBackup.types.preUpdate') }}</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-white mt-1">{{ stats.pre_update }}</p>
          </div>
          <div class="stats-icon bg-orange-100 dark:bg-orange-900/30">
            <svg class="w-8 h-8 text-orange-600 dark:text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
          </div>
        </div>
      </div>
    </div>

    <!-- Filters and Search -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md p-4 mb-6">
      <div class="flex flex-col md:flex-row gap-4 items-center">
        <!-- Search -->
        <div class="flex-1 w-full md:w-auto">
          <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <input
              v-model="searchQuery"
              type="text"
              :placeholder="$t('common.search')"
              class="input pl-10 w-full"
            />
          </div>
        </div>

        <!-- Type Filter -->
        <select v-model="filterType" class="input w-full md:w-48">
          <option value="all">{{ $t('backup.selfBackup.filters.allTypes') }}</option>
          <option value="manual">{{ $t('backup.selfBackup.types.manual') }}</option>
          <option value="scheduled">{{ $t('backup.selfBackup.types.scheduled') }}</option>
          <option value="pre_update">{{ $t('backup.selfBackup.types.preUpdate') }}</option>
          <option value="pre_restore">{{ $t('backup.selfBackup.types.preRestore') }}</option>
        </select>

        <!-- Encryption Filter -->
        <select v-model="filterEncryption" class="input w-full md:w-48">
          <option value="all">{{ $t('backup.selfBackup.filters.allEncryption') }}</option>
          <option value="encrypted">{{ $t('backup.selfBackup.encrypted') }}</option>
          <option value="unencrypted">{{ $t('backup.selfBackup.unencrypted') }}</option>
        </select>
      </div>
    </div>

    <!-- Backups List -->
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-md overflow-hidden">
      <!-- Loading State -->
      <div v-if="loading && backups.length === 0" class="p-12 text-center">
        <svg class="animate-spin h-12 w-12 text-primary-600 mx-auto mb-4" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        <p class="text-gray-500 dark:text-gray-400">{{ $t('common.loading') }}</p>
      </div>

      <!-- Empty State -->
      <div v-else-if="filteredBackups.length === 0" class="p-12 text-center">
        <svg class="w-16 h-16 text-gray-400 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
        </svg>
        <p class="text-lg font-medium text-gray-900 dark:text-white mb-2">
          {{ searchQuery || filterType !== 'all' || filterEncryption !== 'all'
            ? $t('backup.selfBackup.noBackupsFiltered')
            : $t('backup.selfBackup.noBackups') }}
        </p>
        <p class="text-gray-500 dark:text-gray-400 mb-6">
          {{ $t('backup.selfBackup.noBackupsDescription') }}
        </p>
        <button @click="openCreateModal" class="btn-primary mx-auto">
          {{ $t('backup.selfBackup.createFirstBackup') }}
        </button>
      </div>

      <!-- Backups Table -->
      <div v-else class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
          <thead class="bg-gray-50 dark:bg-gray-900">
            <tr>
              <th class="table-header">{{ $t('backup.selfBackup.table.filename') }}</th>
              <th class="table-header">{{ $t('backup.selfBackup.table.type') }}</th>
              <th class="table-header">{{ $t('backup.selfBackup.table.size') }}</th>
              <th class="table-header">{{ $t('backup.selfBackup.table.encryption') }}</th>
              <th class="table-header">{{ $t('backup.selfBackup.table.createdAt') }}</th>
              <th class="table-header">{{ $t('backup.selfBackup.table.actions') }}</th>
            </tr>
          </thead>
          <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
            <tr
              v-for="backup in paginatedBackups"
              :key="backup.id"
              class="hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
            >
              <!-- Filename -->
              <td class="table-cell">
                <div class="flex items-center">
                  <svg class="w-5 h-5 text-gray-400 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                  </svg>
                  <div class="min-w-0">
                    <p class="font-medium text-gray-900 dark:text-white truncate">{{ backup.filename }}</p>
                    <p v-if="backup.notes" class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ backup.notes }}</p>
                  </div>
                </div>
              </td>

              <!-- Type -->
              <td class="table-cell">
                <span :class="getTypeBadgeClass(backup.backup_type)" class="badge">
                  {{ $t(`backup.selfBackup.types.${backup.backup_type}`) }}
                </span>
              </td>

              <!-- Size -->
              <td class="table-cell">
                <span class="text-sm text-gray-900 dark:text-white font-medium">{{ backup.size_human }}</span>
              </td>

              <!-- Encryption -->
              <td class="table-cell">
                <span v-if="backup.encrypted" class="badge badge-success">
                  <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                  </svg>
                  {{ $t('backup.selfBackup.encrypted') }}
                </span>
                <span v-else class="badge badge-gray">
                  {{ $t('backup.selfBackup.unencrypted') }}
                </span>
              </td>

              <!-- Created At -->
              <td class="table-cell">
                <div class="text-sm">
                  <p class="text-gray-900 dark:text-white">{{ formatDate(backup.created_at) }}</p>
                  <p class="text-xs text-gray-500 dark:text-gray-400">{{ formatTime(backup.created_at) }}</p>
                </div>
              </td>

              <!-- Actions -->
              <td class="table-cell">
                <div class="flex items-center gap-2">
                  <!-- Download -->
                  <button
                    @click="downloadBackup(backup)"
                    :title="$t('backup.selfBackup.actions.download')"
                    class="btn-icon-sm text-blue-600 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                    </svg>
                  </button>

                  <!-- Restore -->
                  <button
                    @click="openRestoreModal(backup)"
                    :title="$t('backup.selfBackup.actions.restore')"
                    class="btn-icon-sm text-green-600 hover:text-green-700 dark:text-green-400 dark:hover:text-green-300"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                  </button>

                  <!-- Delete -->
                  <button
                    @click="openDeleteModal(backup)"
                    :title="$t('backup.selfBackup.actions.delete')"
                    class="btn-icon-sm text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300"
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

      <!-- Pagination -->
      <div v-if="totalPages > 1" class="bg-gray-50 dark:bg-gray-900 px-6 py-4 flex items-center justify-between border-t border-gray-200 dark:border-gray-700">
        <div class="text-sm text-gray-700 dark:text-gray-300">
          {{ $t('common.pagination.showing', {
            from: (currentPage - 1) * itemsPerPage + 1,
            to: Math.min(currentPage * itemsPerPage, filteredBackups.length),
            total: filteredBackups.length
          }) }}
        </div>
        <div class="flex gap-2">
          <button
            @click="currentPage--"
            :disabled="currentPage === 1"
            class="btn-secondary btn-sm"
          >
            {{ $t('common.pagination.previous') }}
          </button>
          <button
            @click="currentPage++"
            :disabled="currentPage === totalPages"
            class="btn-secondary btn-sm"
          >
            {{ $t('common.pagination.next') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Create Backup Modal -->
    <Teleport to="body">
      <div v-if="showCreateModal" class="modal-overlay" @click.self="closeCreateModal">
        <div class="modal-container max-w-md">
          <div class="modal-header">
            <h3 class="modal-title">{{ $t('backup.selfBackup.createBackup') }}</h3>
            <button @click="closeCreateModal" class="modal-close">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="modal-body">
            <p class="text-gray-600 dark:text-gray-400 mb-4">
              {{ $t('backup.selfBackup.createBackupDescription') }}
            </p>

            <div class="mb-4">
              <label class="label">{{ $t('backup.selfBackup.notes') }} ({{ $t('common.optional') }})</label>
              <textarea
                v-model="createBackupNotes"
                rows="3"
                class="input"
                :placeholder="$t('backup.selfBackup.notesPlaceholder')"
              ></textarea>
            </div>

            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
              <div class="flex items-start">
                <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 mt-0.5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div class="text-sm text-blue-800 dark:text-blue-200">
                  <p class="font-medium mb-1">{{ $t('backup.selfBackup.backupInfo') }}</p>
                  <ul class="list-disc list-inside space-y-1 text-xs">
                    <li>{{ $t('backup.selfBackup.backupIncludes.database') }}</li>
                    <li>{{ $t('backup.selfBackup.backupIncludes.code') }}</li>
                    <li>{{ $t('backup.selfBackup.backupIncludes.configs') }}</li>
                    <li>{{ $t('backup.selfBackup.backupIncludes.sshKeys') }}</li>
                    <li>{{ $t('backup.selfBackup.backupIncludes.systemd') }}</li>
                  </ul>
                </div>
              </div>
            </div>
          </div>

          <div class="modal-footer">
            <button @click="closeCreateModal" class="btn-secondary">
              {{ $t('common.cancel') }}
            </button>
            <button @click="handleCreateBackup" class="btn-primary" :disabled="creatingBackup">
              <svg v-if="creatingBackup" class="animate-spin w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ $t('backup.selfBackup.createBackup') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Restore Modal -->
    <Teleport to="body">
      <div v-if="showRestoreModal && selectedBackupForRestore" class="modal-overlay" @click.self="closeRestoreModal">
        <div class="modal-container max-w-lg">
          <div class="modal-header">
            <h3 class="modal-title">{{ $t('backup.selfBackup.restoreBackup') }}</h3>
            <button @click="closeRestoreModal" class="modal-close">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="modal-body">
            <div class="bg-orange-50 dark:bg-orange-900/20 border border-orange-200 dark:border-orange-800 rounded-lg p-4 mb-4">
              <div class="flex items-start">
                <svg class="w-6 h-6 text-orange-600 dark:text-orange-400 mt-0.5 mr-3 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div class="text-sm">
                  <p class="font-semibold text-orange-800 dark:text-orange-200 mb-2">
                    {{ $t('backup.selfBackup.restoreWarning') }}
                  </p>
                  <p class="text-orange-700 dark:text-orange-300">
                    {{ $t('backup.selfBackup.restoreWarningDescription') }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Backup Details -->
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-4 mb-4">
              <h4 class="font-medium text-gray-900 dark:text-white mb-3">{{ $t('backup.selfBackup.backupDetails') }}</h4>
              <dl class="space-y-2">
                <div class="flex justify-between text-sm">
                  <dt class="text-gray-600 dark:text-gray-400">{{ $t('backup.selfBackup.table.filename') }}:</dt>
                  <dd class="text-gray-900 dark:text-white font-medium">{{ selectedBackupForRestore.filename }}</dd>
                </div>
                <div class="flex justify-between text-sm">
                  <dt class="text-gray-600 dark:text-gray-400">{{ $t('backup.selfBackup.table.createdAt') }}:</dt>
                  <dd class="text-gray-900 dark:text-white">{{ formatDateTime(selectedBackupForRestore.created_at) }}</dd>
                </div>
                <div class="flex justify-between text-sm">
                  <dt class="text-gray-600 dark:text-gray-400">{{ $t('backup.selfBackup.table.size') }}:</dt>
                  <dd class="text-gray-900 dark:text-white">{{ selectedBackupForRestore.size_human }}</dd>
                </div>
                <div class="flex justify-between text-sm">
                  <dt class="text-gray-600 dark:text-gray-400">{{ $t('backup.selfBackup.table.encryption') }}:</dt>
                  <dd>
                    <span v-if="selectedBackupForRestore.encrypted" class="badge badge-success badge-sm">
                      {{ $t('backup.selfBackup.encrypted') }}
                    </span>
                    <span v-else class="badge badge-gray badge-sm">
                      {{ $t('backup.selfBackup.unencrypted') }}
                    </span>
                  </dd>
                </div>
              </dl>
            </div>

            <!-- Pre-restore Backup Option -->
            <div class="mb-4">
              <label class="flex items-start cursor-pointer">
                <input
                  v-model="createPreRestoreBackup"
                  type="checkbox"
                  class="mt-1 mr-3 h-5 w-5 text-primary-600 border-gray-300 rounded focus:ring-primary-500"
                />
                <div>
                  <span class="text-sm font-medium text-gray-900 dark:text-white">
                    {{ $t('backup.selfBackup.createPreRestoreBackup') }}
                  </span>
                  <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                    {{ $t('backup.selfBackup.createPreRestoreBackupDescription') }}
                  </p>
                </div>
              </label>
            </div>

            <!-- Confirmation -->
            <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
              <p class="text-sm text-red-800 dark:text-red-200 font-medium">
                {{ $t('backup.selfBackup.restoreConfirmation') }}
              </p>
            </div>
          </div>

          <div class="modal-footer">
            <button @click="closeRestoreModal" class="btn-secondary">
              {{ $t('common.cancel') }}
            </button>
            <button @click="handleRestoreBackup" class="btn-danger" :disabled="restoringBackup">
              <svg v-if="restoringBackup" class="animate-spin w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ $t('backup.selfBackup.confirmRestore') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Delete Modal -->
    <Teleport to="body">
      <div v-if="showDeleteModal && selectedBackupForDelete" class="modal-overlay" @click.self="closeDeleteModal">
        <div class="modal-container max-w-md">
          <div class="modal-header">
            <h3 class="modal-title">{{ $t('backup.selfBackup.deleteBackup') }}</h3>
            <button @click="closeDeleteModal" class="modal-close">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="modal-body">
            <div class="flex items-center gap-4 mb-4">
              <div class="flex-shrink-0 w-12 h-12 bg-red-100 dark:bg-red-900/30 rounded-full flex items-center justify-center">
                <svg class="w-6 h-6 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
              </div>
              <div>
                <p class="font-medium text-gray-900 dark:text-white">{{ selectedBackupForDelete.filename }}</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">{{ selectedBackupForDelete.size_human }}</p>
              </div>
            </div>

            <p class="text-gray-600 dark:text-gray-400">
              {{ $t('backup.selfBackup.deleteConfirmation') }}
            </p>
          </div>

          <div class="modal-footer">
            <button @click="closeDeleteModal" class="btn-secondary">
              {{ $t('common.cancel') }}
            </button>
            <button @click="handleDeleteBackup" class="btn-danger" :disabled="deletingBackup">
              <svg v-if="deletingBackup" class="animate-spin w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ $t('common.delete') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>

    <!-- Toast Notifications -->
    <div class="fixed bottom-4 right-4 z-50 space-y-3">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        :class="[
          'max-w-md rounded-lg shadow-lg p-4 transform transition-all duration-300',
          toast.type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white',
          'animate-slide-in'
        ]"
      >
        <div class="flex items-start gap-3">
          <div class="flex-shrink-0">
            <svg v-if="toast.type === 'success'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
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
import { ref, computed, onMounted } from 'vue'
import { usePhpBorgBackupStore } from '@/stores/phpborgBackup'
import { storeToRefs } from 'pinia'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const backupStore = usePhpBorgBackupStore()
const { backups, stats, loading } = storeToRefs(backupStore)

// UI State
const searchQuery = ref('')
const filterType = ref('all')
const filterEncryption = ref('all')
const currentPage = ref(1)
const itemsPerPage = 20

// Modal State
const showCreateModal = ref(false)
const showRestoreModal = ref(false)
const showDeleteModal = ref(false)
const createBackupNotes = ref('')
const selectedBackupForRestore = ref(null)
const selectedBackupForDelete = ref(null)
const createPreRestoreBackup = ref(true)
const creatingBackup = ref(false)
const restoringBackup = ref(false)
const deletingBackup = ref(false)

// Computed
const filteredBackups = computed(() => {
  let filtered = backups.value

  // Search filter
  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    filtered = filtered.filter(b =>
      b.filename.toLowerCase().includes(query) ||
      (b.notes && b.notes.toLowerCase().includes(query))
    )
  }

  // Type filter
  if (filterType.value !== 'all') {
    filtered = filtered.filter(b => b.backup_type === filterType.value)
  }

  // Encryption filter
  if (filterEncryption.value !== 'all') {
    filtered = filtered.filter(b =>
      filterEncryption.value === 'encrypted' ? b.encrypted : !b.encrypted
    )
  }

  // Sort by created_at DESC
  return filtered.sort((a, b) => new Date(b.created_at) - new Date(a.created_at))
})

const totalPages = computed(() => Math.ceil(filteredBackups.value.length / itemsPerPage))

const paginatedBackups = computed(() => {
  const start = (currentPage.value - 1) * itemsPerPage
  const end = start + itemsPerPage
  return filteredBackups.value.slice(start, end)
})

// Methods
async function refreshData() {
  await Promise.all([
    backupStore.loadBackups(),
    backupStore.loadStats()
  ])
}

function getDownloadUrl(backupId) {
  return backupStore.getDownloadUrl(backupId)
}

function downloadBackup(backup) {
  // Force file download using window.location
  window.location.href = backupStore.getDownloadUrl(backup.id)
}

function openCreateModal() {
  createBackupNotes.value = ''
  showCreateModal.value = true
}

function closeCreateModal() {
  showCreateModal.value = false
}

async function handleCreateBackup() {
  creatingBackup.value = true
  try {
    await backupStore.createBackup(createBackupNotes.value || null)
    closeCreateModal()
    await refreshData()
  } finally {
    creatingBackup.value = false
  }
}

function openRestoreModal(backup) {
  selectedBackupForRestore.value = backup
  createPreRestoreBackup.value = true
  showRestoreModal.value = true
}

function closeRestoreModal() {
  showRestoreModal.value = false
  selectedBackupForRestore.value = null
}

async function handleRestoreBackup() {
  restoringBackup.value = true
  try {
    await backupStore.restoreBackup(
      selectedBackupForRestore.value.id,
      createPreRestoreBackup.value
    )
    closeRestoreModal()
    await refreshData()
  } finally {
    restoringBackup.value = false
  }
}

function openDeleteModal(backup) {
  selectedBackupForDelete.value = backup
  showDeleteModal.value = true
}

function closeDeleteModal() {
  showDeleteModal.value = false
  selectedBackupForDelete.value = null
}

async function handleDeleteBackup() {
  deletingBackup.value = true
  try {
    await backupStore.deleteBackup(selectedBackupForDelete.value.id)
    closeDeleteModal()
  } finally {
    deletingBackup.value = false
  }
}

function getTypeBadgeClass(type) {
  const classes = {
    manual: 'badge-blue',
    scheduled: 'badge-purple',
    pre_update: 'badge-orange',
    pre_restore: 'badge-yellow'
  }
  return classes[type] || 'badge-gray'
}

function formatDate(dateString) {
  return new Date(dateString).toLocaleDateString()
}

function formatTime(dateString) {
  return new Date(dateString).toLocaleTimeString()
}

function formatDateTime(dateString) {
  return new Date(dateString).toLocaleString()
}

// Toast notifications
const toasts = ref([])
let toastIdCounter = 0

function showToast(title, message = '', type = 'success', duration = 5000) {
  const id = ++toastIdCounter
  toasts.value.push({ id, title, message, type })

  setTimeout(() => {
    removeToast(id)
  }, duration)
}

function removeToast(id) {
  const index = toasts.value.findIndex(t => t.id === id)
  if (index !== -1) {
    toasts.value.splice(index, 1)
  }
}

// Use global SSE for real-time updates
import { useSSE } from '@/composables/useSSE'
const { subscribe } = useSSE()

// Lifecycle
onMounted(() => {
  refreshData()

  // Subscribe to backup events via SSE
  subscribe('jobs', async (data) => {
    // Listen for phpborg_backup_create job completion
    if (data.job && data.job.type === 'phpborg_backup_create') {
      if (data.job.status === 'completed') {
        showToast(
          t('backup.selfBackup.backupCreated'),
          t('backup.selfBackup.backupCreatedDescription'),
          'success'
        )
        await refreshData()
      } else if (data.job.status === 'failed') {
        showToast(
          t('backup.selfBackup.backupFailed'),
          data.job.error || t('backup.selfBackup.backupFailedDescription'),
          'error',
          8000
        )
      }
    }
  })
})
</script>

<style scoped>
.stats-card {
  @apply bg-white dark:bg-gray-800 rounded-lg shadow-md p-6 transition-all hover:shadow-lg;
}

.stats-icon {
  @apply w-16 h-16 rounded-full flex items-center justify-center;
}

.badge-blue {
  @apply bg-blue-100 text-blue-800 dark:bg-blue-900/30 dark:text-blue-300;
}

.badge-purple {
  @apply bg-purple-100 text-purple-800 dark:bg-purple-900/30 dark:text-purple-300;
}

.badge-orange {
  @apply bg-orange-100 text-orange-800 dark:bg-orange-900/30 dark:text-orange-300;
}

.badge-yellow {
  @apply bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-300;
}

.badge-sm {
  @apply text-xs px-2 py-0.5;
}

/* Modal Styles */
.modal-overlay {
  @apply fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50;
}

.modal-container {
  @apply bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-2xl w-full max-h-[90vh] overflow-hidden;
}

.modal-header {
  @apply flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700;
}

.modal-title {
  @apply text-xl font-semibold text-gray-900 dark:text-white;
}

.modal-close {
  @apply text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors;
}

.modal-body {
  @apply p-6 overflow-y-auto;
  max-height: calc(90vh - 180px);
}

.modal-footer {
  @apply flex items-center justify-end gap-3 p-6 border-t border-gray-200 dark:border-gray-700;
}

.label {
  @apply block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2;
}
</style>
