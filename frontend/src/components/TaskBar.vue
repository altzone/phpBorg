<template>
  <!-- Task Bar Container - Fixed at bottom of content area (respects sidebar) -->
  <div
    class="fixed bottom-0 left-0 right-0 lg:left-64 z-40"
  >
    <!-- Header Bar (always visible) - Blue when jobs active, Gray when idle -->
    <div
      @click="taskBarStore.toggleExpanded"
      :class="[
        'text-white px-6 py-3 cursor-pointer transition-colors shadow-lg',
        taskBarStore.hasActivity
          ? 'bg-gradient-to-r from-blue-600 to-primary-700 dark:from-blue-800 dark:to-primary-900 hover:from-blue-700 hover:to-primary-800 dark:hover:from-blue-700 dark:hover:to-primary-800'
          : 'bg-gradient-to-r from-gray-600 to-gray-700 dark:from-gray-700 dark:to-gray-800 hover:from-gray-700 hover:to-gray-800'
      ]"
    >
      <div class="flex items-center justify-between max-w-7xl mx-auto">
        <!-- Left: Title + Count Badge -->
        <div class="flex items-center gap-3">
          <div class="flex items-center gap-2">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
            <h3 class="font-semibold">{{ $t('taskbar.title') }}</h3>
          </div>

          <!-- Total Count Badge (jobs + sessions) -->
          <span
            v-if="taskBarStore.totalCount > 0"
            class="px-2.5 py-0.5 text-xs font-bold bg-white text-primary-600 rounded-full animate-pulse"
          >
            {{ taskBarStore.totalCount }}
          </span>
        </div>

        <!-- Right: Expand/Collapse Icon -->
        <div class="flex items-center gap-3">
          <svg
            class="w-5 h-5 transition-transform duration-300"
            :class="taskBarStore.expanded ? 'rotate-180' : ''"
            fill="none"
            stroke="currentColor"
            viewBox="0 0 24 24"
          >
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
          </svg>
        </div>
      </div>
    </div>

    <!-- Expanded Content -->
    <div
      v-if="taskBarStore.expanded"
      class="bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 shadow-2xl max-h-96 overflow-y-auto"
    >
      <div class="max-w-7xl mx-auto px-6 py-4">
        <!-- Empty State -->
        <div
          v-if="!taskBarStore.hasActivity && !taskBarStore.loading"
          class="text-center py-8 text-gray-500 dark:text-gray-400"
        >
          <svg class="w-16 h-16 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <p class="text-sm">{{ $t('taskbar.no_activity') }}</p>
        </div>

        <!-- Active Tasks Grid (Jobs + Instant Recovery Sessions) -->
        <div
          v-if="taskBarStore.hasActivity"
          class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4"
        >
          <!-- Running Jobs -->
          <div
            v-for="job in taskBarStore.runningJobs"
            :key="'job-' + job.id"
            class="bg-gradient-to-br from-blue-50 to-primary-50 dark:from-blue-900/20 dark:to-primary-900/20 rounded-lg border border-blue-200 dark:border-blue-800 p-4 hover:shadow-md transition-shadow"
          >
            <!-- Job Header -->
            <div class="flex items-start justify-between mb-3">
              <div class="flex items-center gap-2">
                <div class="w-2 h-2 bg-blue-500 rounded-full animate-pulse"></div>
                <span class="text-xs font-semibold text-blue-700 dark:text-blue-400 uppercase tracking-wider">
                  {{ $t('taskbar.running') }}
                </span>
              </div>
              <span class="text-xs text-gray-500 dark:text-gray-400">
                #{{ job.id }}
              </span>
            </div>

            <!-- Job Info -->
            <div class="space-y-2 mb-3">
              <!-- Job Type + Description/Server -->
              <div class="flex items-center gap-2 text-sm">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
                </svg>
                <div class="flex-1 truncate">
                  <span class="text-gray-700 dark:text-gray-300 font-medium">
                    {{ formatJobType(job.type) }}
                  </span>
                  <span v-if="job.payload?.description" class="text-gray-500 dark:text-gray-400 text-xs ml-1">
                    • {{ job.payload.description }}
                  </span>
                </div>
              </div>

              <!-- Server Info (if available in payload) -->
              <div v-if="getServerName(job)" class="flex items-center gap-2 text-sm">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                </svg>
                <span class="text-gray-600 dark:text-gray-400 text-xs font-medium">
                  🖥️ {{ getServerName(job) }}
                </span>
              </div>

              <!-- Worker ID -->
              <div v-if="job.worker_id" class="flex items-center gap-2 text-sm">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
                </svg>
                <span class="text-gray-600 dark:text-gray-400 text-xs">
                  {{ job.worker_id }}
                </span>
              </div>

              <!-- Progress Bar (if progress available) -->
              <div v-if="job.progress !== null && job.progress !== undefined" class="mt-2">
                <div class="flex items-center justify-between mb-1">
                  <span class="text-xs text-gray-600 dark:text-gray-400">{{ $t('taskbar.progress') }}</span>
                  <span class="text-xs font-medium text-gray-700 dark:text-gray-300">{{ job.progress }}%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2">
                  <div
                    class="bg-primary-600 dark:bg-primary-500 h-2 rounded-full transition-all duration-300"
                    :style="{ width: `${job.progress}%` }"
                  ></div>
                </div>
              </div>

              <!-- Real-time progress info (from Redis via SSE) -->
              <div v-if="getProgressInfo(job.id)" class="mt-2 space-y-2">
                <!-- Borg Progress Message -->
                <div v-if="getProgressInfo(job.id).message" class="text-xs text-gray-700 dark:text-gray-300 truncate">
                  {{ getProgressInfo(job.id).message }}
                </div>

                <!-- Current File Path -->
                <div v-if="getProgressInfo(job.id).path" class="text-xs text-gray-500 dark:text-gray-400 truncate font-mono">
                  📁 {{ getProgressInfo(job.id).path }}
                </div>

                <!-- Stats Row (Files, Size, Rate) -->
                <div class="flex items-center gap-3 text-xs">
                  <!-- Files Count -->
                  <div v-if="getProgressInfo(job.id).nfiles" class="flex items-center gap-1 text-blue-600 dark:text-blue-400">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                    <span class="font-medium">{{ formatNumber(getProgressInfo(job.id).nfiles) }}</span>
                  </div>

                  <!-- Original Size -->
                  <div v-if="getProgressInfo(job.id).original_size" class="flex items-center gap-1 text-gray-600 dark:text-gray-400">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                    </svg>
                    <span>{{ formatBytes(getProgressInfo(job.id).original_size) }}</span>
                  </div>

                  <!-- Transfer Rate (highlighted) -->
                  <div v-if="getProgressInfo(job.id).transfer_rate" class="flex items-center gap-1 px-2 py-0.5 bg-green-100 dark:bg-green-900/30 rounded text-green-700 dark:text-green-400 font-bold">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                    </svg>
                    <span>{{ formatTransferRate(getProgressInfo(job.id).transfer_rate) }}</span>
                  </div>
                </div>

                <!-- Compression Stats (if available) -->
                <div v-if="getProgressInfo(job.id).compressed_size && getProgressInfo(job.id).deduplicated_size" class="flex items-center gap-2 text-xs">
                  <span class="text-gray-500 dark:text-gray-400">
                    🗜️ {{ formatBytes(getProgressInfo(job.id).compressed_size) }}
                  </span>
                  <span class="text-purple-600 dark:text-purple-400">
                    ♻️ {{ formatBytes(getProgressInfo(job.id).deduplicated_size) }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-2">
              <button
                @click="viewJobDetails(job)"
                class="flex-1 px-3 py-2 text-xs font-medium bg-primary-500 hover:bg-primary-600 text-white rounded transition-colors"
              >
                {{ $t('taskbar.view_details') }}
              </button>
              <button
                @click="cancelJob(job)"
                class="px-3 py-2 text-xs font-medium bg-red-500 hover:bg-red-600 text-white rounded transition-colors"
                :title="$t('taskbar.cancel_job')"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>

          <!-- Instant Recovery Sessions -->
          <div
            v-for="session in taskBarStore.activeSessions"
            :key="'session-' + session.id"
            class="bg-gradient-to-br from-green-50 to-blue-50 dark:from-green-900/20 dark:to-blue-900/20 rounded-lg border border-green-200 dark:border-green-800 p-4 hover:shadow-md transition-shadow"
          >
            <!-- Session Header -->
            <div class="flex items-start justify-between mb-3">
              <div class="flex items-center gap-2">
                <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
                <span class="text-xs font-semibold text-green-700 dark:text-green-400 uppercase tracking-wider flex items-center gap-1">
                  <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                  </svg>
                  {{ $t('taskbar.instant_recovery') }}
                </span>
              </div>
              <span class="text-xs text-gray-500 dark:text-gray-400">
                #{{ session.id }}
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

              <!-- DB Type + Port -->
              <div class="flex items-center gap-2 text-sm">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                </svg>
                <span class="text-gray-600 dark:text-gray-400 text-xs">
                  {{ session.db_type || 'PostgreSQL' }} • Port: {{ session.db_port }}
                </span>
              </div>

              <!-- Archive Info -->
              <div v-if="session.archive_name" class="flex items-center gap-2 text-sm">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z" />
                </svg>
                <span class="text-gray-600 dark:text-gray-400 text-xs truncate" :title="session.archive_name">
                  {{ session.archive_name }}
                </span>
              </div>
            </div>

            <!-- Connection String (compact) -->
            <div class="bg-white dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-700 p-2 mb-2">
              <div class="flex items-center gap-2">
                <input
                  type="text"
                  readonly
                  :value="buildConnectionString(session)"
                  class="flex-1 text-xs font-mono bg-transparent border-0 focus:outline-none text-gray-700 dark:text-gray-300"
                />
                <button
                  @click="copyConnectionString(session)"
                  class="p-1 hover:bg-gray-100 dark:hover:bg-gray-800 rounded transition-colors"
                  :title="$t('common.copy')"
                >
                  <svg class="w-3 h-3 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
                  </svg>
                </button>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-2">
              <!-- Open Database Admin Button -->
              <button
                v-if="session.admin_port && session.admin_token"
                @click="openDatabaseAdmin(session)"
                class="flex-1 px-3 py-2 text-xs font-medium bg-blue-500 hover:bg-blue-600 text-white rounded transition-colors flex items-center justify-center gap-1"
                :title="$t('taskbar.open_admin')"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
                </svg>
                <span>🗄️ Admin</span>
              </button>

              <button
                @click="viewSessionDetails(session)"
                class="flex-1 px-3 py-2 text-xs font-medium bg-green-500 hover:bg-green-600 text-white rounded transition-colors"
              >
                {{ $t('taskbar.view_details') }}
              </button>
              <button
                @click="stopSession(session)"
                :disabled="session.stopping"
                class="px-3 py-2 text-xs font-medium bg-red-500 hover:bg-red-600 text-white rounded transition-colors disabled:opacity-50"
                :title="$t('taskbar.stop_session')"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 10a1 1 0 011-1h4a1 1 0 011 1v4a1 1 0 01-1 1h-4a1 1 0 01-1-1v-4z" />
                </svg>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { useTaskBarStore } from '@/stores/taskbar'
import { useJobStore } from '@/stores/jobs'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'

const taskBarStore = useTaskBarStore()
const jobStore = useJobStore()
const { t } = useI18n()
const router = useRouter()

function formatJobType(type) {
  const translationKey = `jobs.types.${type}`
  if (t(translationKey) !== translationKey) {
    return t(translationKey)
  }

  return type
    .split('_')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

function getProgressInfo(jobId) {
  return jobStore.getProgressInfo(jobId)
}

function formatTransferRate(bytesPerSec) {
  if (!bytesPerSec) return ''

  const mbps = bytesPerSec / (1024 * 1024)
  return `${mbps.toFixed(2)} MB/s`
}

function formatBytes(bytes) {
  if (!bytes || bytes === 0) return '0 B'

  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))

  return `${(bytes / Math.pow(k, i)).toFixed(2)} ${sizes[i]}`
}

function formatNumber(num) {
  if (!num) return '0'
  return num.toLocaleString()
}

function getServerName(job) {
  // Try to extract server name from job payload
  if (job.payload) {
    // Description might contain server name (e.g., "Backup: virus - system")
    if (job.payload.description) {
      const match = job.payload.description.match(/:\s*([^\-]+)/)
      if (match) {
        return match[1].trim()
      }
    }
    // Or directly from server_name field
    if (job.payload.server_name) {
      return job.payload.server_name
    }
  }
  return null
}

function viewJobDetails(job) {
  router.push('/jobs')
}

async function cancelJob(job) {
  if (!confirm(t('taskbar.cancel_confirm', { id: job.id }))) {
    return
  }

  await jobStore.cancelJob(job.id)
}

// Instant Recovery helpers
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
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}

function openDatabaseAdmin(session) {
  if (!session.admin_port || !session.admin_token) {
    return
  }

  // Build Adminer URL with authentication token
  const dbServer = session.deployment_location === 'local' ? '127.0.0.1' : (session.server_hostname || 'unknown')
  const dbUser = session.db_user || (session.db_type === 'postgresql' ? 'postgres' : 'root')
  const dbName = session.db_name || (session.db_type === 'postgresql' ? 'postgres' : 'mysql')

  const adminerUrl = `http://${window.location.hostname}:${session.admin_port}/` +
    `?phpborg_token=${session.admin_token}` +
    `&phpborg_server=${dbServer}:${session.db_port}` +
    `&phpborg_username=${dbUser}` +
    `&phpborg_database=${dbName}`

  // Open in new tab
  window.open(adminerUrl, '_blank')
}

function viewSessionDetails(session) {
  // Could navigate to a detailed view
  console.log('View session details:', session.id)
}

async function stopSession(session) {
  if (session.stopping) return

  if (!confirm(t('taskbar.stop_session_confirm', { id: session.id }))) {
    return
  }

  session.stopping = true

  try {
    const { instantRecoveryService } = await import('@/services/instantRecovery')
    await instantRecoveryService.stop(session.id)
  } catch (err) {
    console.error('Failed to stop session:', err)
    session.stopping = false
  }
}
</script>
