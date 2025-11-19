<template>
  <!-- Task Bar Container - Fixed at bottom of screen -->
  <div
    v-if="taskBarStore.visible"
    class="fixed bottom-0 left-0 right-0 z-40 transition-transform duration-300"
    :class="taskBarStore.expanded ? 'translate-y-0' : 'translate-y-[calc(100%-48px)]'"
  >
    <!-- Header Bar (always visible) - Blue when jobs active, Gray when idle -->
    <div
      @click="taskBarStore.toggleExpanded"
      :class="[
        'text-white px-6 py-3 cursor-pointer transition-colors shadow-lg',
        taskBarStore.runningCount > 0
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

          <!-- Running Jobs Count Badge -->
          <span
            v-if="taskBarStore.runningCount > 0"
            class="px-2.5 py-0.5 text-xs font-bold bg-white text-primary-600 rounded-full animate-pulse"
          >
            {{ taskBarStore.runningCount }}
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
          v-if="taskBarStore.runningJobs.length === 0 && !taskBarStore.loading"
          class="text-center py-8 text-gray-500 dark:text-gray-400"
        >
          <svg class="w-16 h-16 mx-auto mb-3 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
          </svg>
          <p class="text-sm">{{ $t('taskbar.no_running_jobs') }}</p>
        </div>

        <!-- Running Jobs Grid -->
        <div
          v-if="taskBarStore.runningJobs.length > 0"
          class="grid grid-cols-1 lg:grid-cols-2 xl:grid-cols-3 gap-4"
        >
          <div
            v-for="job in taskBarStore.runningJobs"
            :key="job.id"
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
              <!-- Job Type -->
              <div class="flex items-center gap-2 text-sm">
                <svg class="w-4 h-4 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01" />
                </svg>
                <span class="text-gray-700 dark:text-gray-300 font-medium">
                  {{ formatJobType(job.type) }}
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

              <!-- Real-time progress info (from Redis) -->
              <div v-if="getProgressInfo(job.id)" class="text-xs text-gray-600 dark:text-gray-400 mt-2 space-y-1">
                <div v-if="getProgressInfo(job.id).message" class="truncate">
                  {{ getProgressInfo(job.id).message }}
                </div>
                <div v-if="getProgressInfo(job.id).path" class="truncate font-mono">
                  {{ getProgressInfo(job.id).path }}
                </div>
                <div v-if="getProgressInfo(job.id).transfer_rate" class="font-medium">
                  {{ formatTransferRate(getProgressInfo(job.id).transfer_rate) }}
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

function viewJobDetails(job) {
  router.push('/jobs')
}

async function cancelJob(job) {
  if (!confirm(t('taskbar.cancel_confirm', { id: job.id }))) {
    return
  }

  await jobStore.cancelJob(job.id)
}
</script>
