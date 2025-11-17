<template>
  <!-- Task Bar Container - Fixed at bottom of screen -->
  <div
    v-if="store.taskBarVisible"
    class="fixed bottom-0 left-0 right-0 z-40 transition-transform duration-300"
    :class="store.taskBarExpanded ? 'translate-y-0' : 'translate-y-[calc(100%-48px)]'"
  >
    <!-- Header Bar (always visible) -->
    <div
      @click="store.toggleExpanded"
      class="bg-gradient-to-r from-blue-600 to-blue-700 dark:from-blue-800 dark:to-blue-900 text-white px-6 py-3 cursor-pointer hover:from-blue-700 hover:to-blue-800 dark:hover:from-blue-700 dark:hover:to-blue-800 transition-colors shadow-lg"
    >
      <div class="flex items-center justify-between max-w-7xl mx-auto">
        <!-- Left: Title + Count -->
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            <h3 class="font-semibold">{{ $t('restore_wizard.instant_recovery.taskbar_title') }}</h3>
          </div>

          <!-- Session Count Badge -->
          <span
            v-if="store.activeSessions.length > 0"
            class="px-2 py-0.5 text-xs font-bold bg-white text-blue-600 rounded-full animate-pulse"
          >
            {{ store.activeSessions.length }}
          </span>
        </div>

        <!-- Right: Controls -->
        <div class="flex items-center gap-3">
          <!-- Refresh Button -->
          <button
            @click.stop="store.fetchActiveSessions"
            :disabled="store.loading"
            class="p-1.5 hover:bg-white/20 rounded transition-colors"
            :title="$t('common.refresh')"
          >
            <svg
              class="w-4 h-4"
              :class="store.loading ? 'animate-spin' : ''"
              fill="none"
              stroke="currentColor"
              viewBox="0 0 24 24"
            >
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
          </button>

          <!-- Expand/Collapse Icon -->
          <svg
            class="w-5 h-5 transition-transform duration-300"
            :class="store.taskBarExpanded ? 'rotate-180' : ''"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>

          <!-- Close Button -->
          <button
            @click.stop="store.hideTaskBar"
            class="p-1.5 hover:bg-white/20 rounded transition-colors"
            :title="$t('common.close')"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Expanded Content -->
    <div
      v-if="store.taskBarExpanded"
      class="bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 shadow-2xl max-h-96 overflow-y-auto"
    >
      <div class="max-w-7xl mx-auto px-6 py-4">
        <!-- Empty State -->
        <div
          v-if="store.activeSessions.length === 0 && !store.loading"
          class="text-center py-8 text-gray-500 dark:text-gray-400"
        >
          <svg class="w-16 h-16 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
          </svg>
          <p class="text-sm">{{ $t('restore_wizard.instant_recovery.no_active_sessions') }}</p>
        </div>

        <!-- Loading State -->
        <div
          v-if="store.loading && store.activeSessions.length === 0"
          class="text-center py-8"
        >
          <div class="inline-block w-8 h-8 border-4 border-blue-500 border-t-transparent rounded-full animate-spin"></div>
        </div>

        <!-- Active Sessions Grid -->
        <div
          v-if="store.activeSessions.length > 0"
          class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4"
        >
          <div
            v-for="session in store.activeSessions"
            :key="session.id"
            class="bg-gradient-to-br from-green-50 to-blue-50 dark:from-green-900/20 dark:to-blue-900/20 rounded-lg border border-green-200 dark:border-green-800 p-4 hover:shadow-md transition-shadow"
          >
            <!-- Session Header -->
            <div class="flex items-start justify-between mb-3">
              <div class="flex items-center gap-2">
                <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                <span class="text-xs font-semibold text-green-700 dark:text-green-400 uppercase tracking-wider">
                  {{ $t('restore_wizard.instant_recovery.active_status') }}
                </span>
              </div>
              <span class="text-xs text-gray-500 dark:text-gray-400">
                {{ $t('restore_wizard.instant_recovery.session_id') }}: {{ session.id }}
              </span>
            </div>

            <!-- Session Info -->
            <div class="space-y-2 mb-3">
              <!-- Server Name -->
              <div class="flex items-center gap-2 text-sm">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                </svg>
                <span class="text-gray-700 dark:text-gray-300 font-medium truncate">
                  {{ session.server_name || 'Unknown Server' }}
                </span>
              </div>

              <!-- Archive Name + Date -->
              <div class="flex items-center gap-2 text-sm">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z" />
                </svg>
                <span class="text-gray-600 dark:text-gray-400 text-xs truncate" :title="session.archive_name">
                  {{ session.archive_name || 'Unknown Archive' }}
                </span>
              </div>

              <!-- Archive Date -->
              <div v-if="session.archive_time" class="flex items-center gap-2 text-sm">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
                <span class="text-gray-600 dark:text-gray-400 text-xs">
                  {{ $t('common.backup_date') }}: {{ formatDateTime(session.archive_time) }}
                </span>
              </div>

              <!-- DB Type + Port -->
              <div class="flex items-center gap-2 text-sm">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                </svg>
                <span class="text-gray-600 dark:text-gray-400 text-xs">
                  {{ session.db_type || 'PostgreSQL' }} • {{ $t('common.port') }}: {{ session.db_port }}
                </span>
              </div>
            </div>

            <!-- Connection String -->
            <div class="bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-700 p-2 mb-3">
              <div class="flex items-center gap-2">
                <input
                  type="text"
                  readonly
                  :value="session.connection_string || buildConnectionString(session)"
                  class="flex-1 text-xs font-mono bg-transparent border-0 focus:outline-none text-gray-700 dark:text-gray-300"
                />
                <button
                  @click="copyConnectionString(session)"
                  class="p-1.5 hover:bg-gray-100 dark:hover:bg-gray-800 rounded transition-colors"
                  :title="$t('common.copy')"
                >
                  <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-2">
              <button
                @click="openSessionDetails(session)"
                class="flex-1 px-3 py-2 text-xs font-medium bg-blue-500 hover:bg-blue-600 text-white rounded transition-colors"
              >
                {{ $t('restore_wizard.instant_recovery.view_details') }}
              </button>
              <button
                @click="stopSession(session)"
                :disabled="session.stopping"
                class="flex-1 px-3 py-2 text-xs font-medium bg-red-500 hover:bg-red-600 text-white rounded transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span v-if="!session.stopping">{{ $t('restore_wizard.instant_recovery.stop_session') }}</span>
                <span v-else class="flex items-center justify-center gap-1">
                  <div class="w-3 h-3 border-2 border-white border-t-transparent rounded-full animate-spin"></div>
                  {{ $t('restore_wizard.instant_recovery.stopping') }}
                </span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Confirmation Modal -->
    <ConfirmModal
      v-model="showConfirmModal"
      :title="$t('restore_wizard.instant_recovery.stop_confirm_title')"
      :message="sessionToStop ? $t('restore_wizard.instant_recovery.stop_confirm_message', {
        server: sessionToStop.server_name || 'Unknown',
        port: sessionToStop.db_port,
        archive: sessionToStop.archive_name || 'Unknown',
        date: formatDateTime(sessionToStop.archive_time) || '-'
      }) : ''"
      :confirm-text="$t('restore_wizard.instant_recovery.stop_confirm_button')"
      :cancel-text="$t('common.cancel')"
      variant="danger"
      @confirm="confirmStopSession"
    />
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useInstantRecoveryStore } from '@/stores/instantRecovery'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import ConfirmModal from './ConfirmModal.vue'

const store = useInstantRecoveryStore()
const { t } = useI18n()
const router = useRouter()

// Confirmation modal state
const showConfirmModal = ref(false)
const sessionToStop = ref(null)

function formatDateTime(dateString) {
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

function buildConnectionString(session) {
  const host = session.deployment_location === 'local' ? '127.0.0.1' : (session.server_hostname || 'unknown')
  const user = session.db_user || 'postgres'
  const dbName = session.db_name || 'postgres'
  return `postgresql://${user}@${host}:${session.db_port}/${dbName}`
}

async function copyConnectionString(session) {
  const connString = session.connection_string || buildConnectionString(session)
  try {
    await navigator.clipboard.writeText(connString)
    // Could add a toast notification here
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}

function openSessionDetails(session) {
  // Navigate to a detailed view or open modal
  // For now, just expand and show toast
  console.log('Open details for session:', session.id)
}

function stopSession(session) {
  if (session.stopping) return

  // Store session and show confirmation modal
  sessionToStop.value = session
  showConfirmModal.value = true
}

async function confirmStopSession() {
  const session = sessionToStop.value
  if (!session) return

  session.stopping = true

  try {
    await store.stopSession(session.id)
    // Success - session will be removed from list on next poll
  } catch (err) {
    console.error('Failed to stop session:', err)
    session.stopping = false
    alert(t('restore_wizard.instant_recovery.stop_error_message') + ': ' + (err.message || 'Unknown error'))
  } finally {
    sessionToStop.value = null
  }
}
</script>
