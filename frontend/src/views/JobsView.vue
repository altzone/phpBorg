<template>
  <div class="max-w-7xl mx-auto">
    <!-- Header with gradient -->
    <div class="mb-8">
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center shadow-lg">
            <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
            </svg>
          </div>
          <div>
            <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $t('jobs.title') }}</h1>
            <p class="text-gray-500 dark:text-gray-400">{{ $t('jobs.subtitle') }}</p>
          </div>
        </div>

        <!-- Filters -->
        <div class="flex items-center gap-3">
          <label class="flex items-center gap-2 px-3 py-2 bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 cursor-pointer hover:border-purple-300 dark:hover:border-purple-600 transition-colors">
            <input type="checkbox" v-model="showSystemJobs" class="sr-only peer" />
            <div class="w-9 h-5 bg-gray-200 peer-focus:ring-2 peer-focus:ring-purple-300 dark:peer-focus:ring-purple-800 rounded-full peer dark:bg-gray-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-purple-600 relative"></div>
            <span class="text-sm text-gray-600 dark:text-gray-300">{{ $t('jobs.show_system_jobs') }}</span>
          </label>
        </div>
      </div>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
      <!-- Total -->
      <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-slate-50 to-slate-100 dark:from-slate-800 dark:to-slate-900 p-4 border border-slate-200 dark:border-slate-700">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-slate-500 dark:text-slate-400">{{ $t('jobs.stats.total') }}</p>
            <p class="text-3xl font-bold text-slate-900 dark:text-slate-100">{{ filteredStats.total }}</p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-slate-200 dark:bg-slate-700 flex items-center justify-center">
            <svg class="w-6 h-6 text-slate-600 dark:text-slate-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Pending -->
      <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-blue-50 to-blue-100 dark:from-blue-900/30 dark:to-blue-800/30 p-4 border border-blue-200 dark:border-blue-700">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-blue-600 dark:text-blue-400">{{ $t('jobs.stats.pending') }}</p>
            <p class="text-3xl font-bold text-blue-900 dark:text-blue-100">{{ filteredStats.pending }}</p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-blue-200 dark:bg-blue-800 flex items-center justify-center">
            <svg class="w-6 h-6 text-blue-600 dark:text-blue-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Running -->
      <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-amber-50 to-orange-100 dark:from-amber-900/30 dark:to-orange-800/30 p-4 border border-amber-200 dark:border-amber-700">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-amber-600 dark:text-amber-400">{{ $t('jobs.stats.running') }}</p>
            <p class="text-3xl font-bold text-amber-900 dark:text-amber-100">{{ filteredStats.running }}</p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-amber-200 dark:bg-amber-800 flex items-center justify-center">
            <svg class="w-6 h-6 text-amber-600 dark:text-amber-300 animate-spin" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Completed -->
      <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-50 to-green-100 dark:from-emerald-900/30 dark:to-green-800/30 p-4 border border-emerald-200 dark:border-emerald-700">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-emerald-600 dark:text-emerald-400">{{ $t('jobs.stats.completed') }}</p>
            <p class="text-3xl font-bold text-emerald-900 dark:text-emerald-100">{{ filteredStats.completed }}</p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-emerald-200 dark:bg-emerald-800 flex items-center justify-center">
            <svg class="w-6 h-6 text-emerald-600 dark:text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Failed -->
      <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-red-50 to-rose-100 dark:from-red-900/30 dark:to-rose-800/30 p-4 border border-red-200 dark:border-red-700">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-red-600 dark:text-red-400">{{ $t('jobs.stats.failed') }}</p>
            <p class="text-3xl font-bold text-red-900 dark:text-red-100">{{ filteredStats.failed }}</p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-red-200 dark:bg-red-800 flex items-center justify-center">
            <svg class="w-6 h-6 text-red-600 dark:text-red-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Cancelled -->
      <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-gray-50 to-gray-100 dark:from-gray-800 dark:to-gray-900 p-4 border border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $t('jobs.stats.cancelled') }}</p>
            <p class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ filteredStats.cancelled }}</p>
          </div>
          <div class="w-12 h-12 rounded-xl bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
            <svg class="w-6 h-6 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
            </svg>
          </div>
        </div>
      </div>
    </div>

    <!-- Status Filter Chips -->
    <div class="flex items-center gap-2 mb-6 flex-wrap">
      <span class="text-sm text-gray-500 dark:text-gray-400 mr-2">Filtrer:</span>
      <button
        @click="statusFilter = null"
        :class="[
          'px-3 py-1.5 rounded-full text-sm font-medium transition-all',
          statusFilter === null
            ? 'bg-indigo-600 text-white shadow-md'
            : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'
        ]"
      >
        Tous
      </button>
      <button
        v-for="status in ['pending', 'running', 'completed', 'failed']"
        :key="status"
        @click="statusFilter = status"
        :class="[
          'px-3 py-1.5 rounded-full text-sm font-medium transition-all flex items-center gap-1.5',
          statusFilter === status
            ? getFilterChipActiveClass(status)
            : 'bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-200 dark:hover:bg-gray-700'
        ]"
      >
        <span :class="getStatusDotClass(status)"></span>
        {{ $t(`jobs.stats.${status}`) }}
      </button>
    </div>

    <!-- Loading State -->
    <div v-if="jobStore.loading && !jobStore.jobs.length" class="flex items-center justify-center py-20">
      <div class="text-center">
        <div class="w-16 h-16 rounded-full border-4 border-indigo-200 dark:border-indigo-800 border-t-indigo-600 dark:border-t-indigo-400 animate-spin mx-auto"></div>
        <p class="mt-4 text-gray-500 dark:text-gray-400">{{ $t('jobs.loading_jobs') }}</p>
      </div>
    </div>

    <!-- Empty State -->
    <div v-else-if="!displayedJobs.length" class="text-center py-20">
      <div class="w-20 h-20 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center mx-auto mb-4">
        <svg class="w-10 h-10 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
        </svg>
      </div>
      <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ $t('jobs.no_jobs') }}</h3>
      <p class="text-gray-500 dark:text-gray-400">{{ showSystemJobs ? $t('jobs.no_jobs_msg') : $t('jobs.no_user_jobs_msg') }}</p>
    </div>

    <!-- Jobs Timeline -->
    <div v-else class="space-y-6">
      <div v-for="(group, date) in groupedJobs" :key="date">
        <!-- Date Header -->
        <div class="flex items-center gap-3 mb-3">
          <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-indigo-500 to-purple-600 flex items-center justify-center text-white font-bold text-sm shadow">
            {{ formatDayShort(date) }}
          </div>
          <div>
            <p class="font-semibold text-gray-900 dark:text-gray-100">{{ formatDateHeader(date) }}</p>
            <p class="text-xs text-gray-500 dark:text-gray-400">{{ group.length }} job(s)</p>
          </div>
        </div>

        <!-- Jobs for this date -->
        <div class="space-y-3 ml-5 border-l-2 border-gray-200 dark:border-gray-700 pl-6">
          <div
            v-for="job in group"
            :key="job.id"
            @click="selectedJob = job"
            class="relative bg-white dark:bg-gray-800 rounded-2xl border border-gray-200 dark:border-gray-700 hover:border-indigo-300 dark:hover:border-indigo-600 hover:shadow-lg transition-all cursor-pointer overflow-hidden"
          >
            <!-- Status indicator line -->
            <div :class="['absolute left-0 top-0 bottom-0 w-1', getStatusBarClass(job.status)]"></div>

            <div class="p-4 pl-5">
              <div class="flex items-start justify-between gap-4">
                <!-- Job Info -->
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-3 mb-2">
                    <!-- Job Type Icon -->
                    <div :class="['w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0', getJobIconBg(job)]">
                      <component :is="getJobIcon(job)" class="w-5 h-5" :class="getJobIconColor(job)" />
                    </div>
                    <div class="flex-1 min-w-0">
                      <h3 class="font-semibold text-gray-900 dark:text-gray-100 truncate">
                        {{ formatJobType(job.type) }}
                      </h3>
                      <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                        <span>#{{ job.id }}</span>
                        <span>•</span>
                        <span>{{ formatTime(job.created_at) }}</span>
                        <span v-if="job.worker_id">• Worker {{ job.worker_id }}</span>
                      </div>
                    </div>
                  </div>

                  <!-- Tags -->
                  <div class="flex items-center gap-2 flex-wrap">
                    <span v-if="isSystemJob(job.type)" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300">
                      <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      </svg>
                      System
                    </span>
                    <span v-if="getJobServer(job)" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                      <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
                      </svg>
                      {{ getJobServer(job) }}
                    </span>
                    <span v-if="getBackupType(job)" class="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-cyan-100 text-cyan-700 dark:bg-cyan-900/40 dark:text-cyan-300">
                      {{ getBackupType(job) }}
                    </span>
                  </div>
                </div>

                <!-- Status & Actions -->
                <div class="flex items-center gap-3 flex-shrink-0">
                  <!-- Progress for running jobs -->
                  <div v-if="job.status === 'running'" class="text-right mr-2">
                    <div class="text-2xl font-bold text-amber-600 dark:text-amber-400">{{ job.progress }}%</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">en cours</div>
                  </div>

                  <!-- Status Badge -->
                  <div :class="['px-3 py-1.5 rounded-xl text-sm font-semibold flex items-center gap-1.5', getStatusBadgeClass(job.status)]">
                    <span :class="['w-2 h-2 rounded-full', getStatusDotClass(job.status), job.status === 'running' ? 'animate-pulse' : '']"></span>
                    {{ formatStatus(job.status) }}
                  </div>

                  <!-- Actions -->
                  <div class="flex items-center gap-1">
                    <button
                      v-if="job.status === 'running' || job.status === 'pending'"
                      @click.stop="handleCancel(job.id)"
                      class="p-2 rounded-lg text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors"
                      :title="$t('jobs.cancel_job')"
                    >
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                      </svg>
                    </button>
                    <button
                      v-if="job.status === 'failed' && job.attempts < job.max_attempts"
                      @click.stop="handleRetry(job.id)"
                      class="p-2 rounded-lg text-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                      :title="$t('jobs.retry_job')"
                    >
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                      </svg>
                    </button>
                  </div>
                </div>
              </div>

              <!-- Progress Bar -->
              <div v-if="job.status === 'running'" class="mt-3">
                <div class="h-2 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                  <div
                    class="h-full bg-gradient-to-r from-amber-400 to-orange-500 rounded-full transition-all duration-500 relative"
                    :style="{ width: job.progress + '%' }"
                  >
                    <div class="absolute inset-0 bg-white/30 animate-pulse"></div>
                  </div>
                </div>
              </div>

              <!-- Real-time stats for running jobs -->
              <div v-if="job.status === 'running' && jobStore.getProgressInfo(job.id)" class="mt-3 p-3 bg-gradient-to-r from-blue-50 to-indigo-50 dark:from-blue-900/20 dark:to-indigo-900/20 rounded-xl">
                <div class="flex items-center justify-between gap-4 text-sm">
                  <div class="flex items-center gap-4">
                    <div class="flex items-center gap-1.5">
                      <svg class="w-4 h-4 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                      </svg>
                      <span class="font-semibold text-blue-900 dark:text-blue-100">{{ jobStore.getProgressInfo(job.id).files_count || 0 }}</span>
                      <span class="text-blue-600 dark:text-blue-400">fichiers</span>
                    </div>
                    <div class="flex items-center gap-1.5">
                      <svg class="w-4 h-4 text-purple-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                      </svg>
                      <span class="font-mono font-semibold text-purple-900 dark:text-purple-100">{{ formatBytes(jobStore.getProgressInfo(job.id).original_size || 0) }}</span>
                      <span class="text-gray-400 mx-1">→</span>
                      <span class="font-mono font-semibold text-green-600 dark:text-green-400">{{ formatBytes(jobStore.getProgressInfo(job.id).deduplicated_size || 0) }}</span>
                    </div>
                  </div>
                  <div v-if="jobStore.getProgressInfo(job.id).transfer_rate" class="flex items-center gap-1.5 px-2 py-1 bg-green-100 dark:bg-green-900/30 rounded-lg">
                    <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6" />
                    </svg>
                    <span class="font-mono font-bold text-green-700 dark:text-green-300">{{ formatRate(jobStore.getProgressInfo(job.id).transfer_rate) }}</span>
                  </div>
                </div>
              </div>

              <!-- Error message for failed jobs -->
              <div v-if="job.status === 'failed' && job.error" class="mt-3 p-3 bg-red-50 dark:bg-red-900/20 rounded-xl">
                <p class="text-sm text-red-700 dark:text-red-300 line-clamp-2">{{ job.error }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Job Details Modal -->
    <Teleport to="body">
      <div v-if="selectedJob" @click.self="selectedJob = null" class="fixed inset-0 bg-black/60 backdrop-blur-sm flex items-center justify-center z-50 p-4">
        <div class="bg-white dark:bg-gray-800 rounded-2xl max-w-3xl w-full max-h-[85vh] overflow-hidden flex flex-col shadow-2xl">
          <!-- Modal Header -->
          <div class="p-6 border-b border-gray-200 dark:border-gray-700 bg-gradient-to-r from-indigo-500 to-purple-600">
            <div class="flex items-center justify-between">
              <div class="flex items-center gap-4">
                <div class="w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center">
                  <component :is="getJobIcon(selectedJob)" class="w-6 h-6 text-white" />
                </div>
                <div>
                  <h2 class="text-xl font-bold text-white">{{ formatJobType(selectedJob.type) }}</h2>
                  <p class="text-indigo-100">Job #{{ selectedJob.id }}</p>
                </div>
              </div>
              <button @click="selectedJob = null" class="p-2 rounded-xl hover:bg-white/20 transition-colors">
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>

          <!-- Tabs -->
          <div class="border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
            <nav class="flex px-6">
              <button
                @click="activeTab = 'details'"
                :class="[
                  'py-3 px-4 text-sm font-medium border-b-2 transition-colors',
                  activeTab === 'details'
                    ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                    : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'
                ]"
              >
                Détails
              </button>
              <button
                v-if="selectedJob.output || selectedJob.error"
                @click="activeTab = 'logs'"
                :class="[
                  'py-3 px-4 text-sm font-medium border-b-2 transition-colors',
                  activeTab === 'logs'
                    ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                    : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'
                ]"
              >
                Logs
              </button>
              <button
                @click="activeTab = 'payload'"
                :class="[
                  'py-3 px-4 text-sm font-medium border-b-2 transition-colors',
                  activeTab === 'payload'
                    ? 'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400'
                    : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-300'
                ]"
              >
                Payload
              </button>
            </nav>
          </div>

          <!-- Tab Content -->
          <div class="flex-1 overflow-y-auto p-6">
            <!-- Details Tab -->
            <div v-if="activeTab === 'details'" class="space-y-6">
              <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                <div class="p-4 bg-gray-50 dark:bg-gray-900 rounded-xl">
                  <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Status</p>
                  <span :class="['px-2 py-1 rounded-lg text-sm font-semibold', getStatusBadgeClass(selectedJob.status)]">
                    {{ formatStatus(selectedJob.status) }}
                  </span>
                </div>
                <div class="p-4 bg-gray-50 dark:bg-gray-900 rounded-xl">
                  <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Progression</p>
                  <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ selectedJob.progress }}%</p>
                </div>
                <div class="p-4 bg-gray-50 dark:bg-gray-900 rounded-xl">
                  <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Tentatives</p>
                  <p class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ selectedJob.attempts }} / {{ selectedJob.max_attempts }}</p>
                </div>
                <div class="p-4 bg-gray-50 dark:bg-gray-900 rounded-xl">
                  <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Créé le</p>
                  <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatDate(selectedJob.created_at) }}</p>
                </div>
                <div v-if="selectedJob.started_at" class="p-4 bg-gray-50 dark:bg-gray-900 rounded-xl">
                  <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Démarré le</p>
                  <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatDate(selectedJob.started_at) }}</p>
                </div>
                <div v-if="selectedJob.completed_at" class="p-4 bg-gray-50 dark:bg-gray-900 rounded-xl">
                  <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Terminé le</p>
                  <p class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ formatDate(selectedJob.completed_at) }}</p>
                </div>
              </div>

              <!-- Real-time Progress -->
              <div v-if="selectedJob.status === 'running' && jobStore.getProgressInfo(selectedJob.id)" class="p-4 bg-blue-50 dark:bg-blue-900/20 rounded-xl border border-blue-200 dark:border-blue-800">
                <h3 class="text-sm font-semibold text-blue-900 dark:text-blue-300 mb-3 flex items-center gap-2">
                  <span class="w-2 h-2 rounded-full bg-blue-500 animate-pulse"></span>
                  Progression en direct
                </h3>
                <div class="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <span class="text-gray-500">Fichiers:</span>
                    <span class="ml-2 font-mono font-semibold">{{ jobStore.getProgressInfo(selectedJob.id).files_count || 0 }}</span>
                  </div>
                  <div>
                    <span class="text-gray-500">Débit:</span>
                    <span class="ml-2 font-mono font-semibold text-green-600">{{ formatRate(jobStore.getProgressInfo(selectedJob.id).transfer_rate || 0) }}</span>
                  </div>
                  <div>
                    <span class="text-gray-500">Taille originale:</span>
                    <span class="ml-2 font-mono">{{ formatBytes(jobStore.getProgressInfo(selectedJob.id).original_size || 0) }}</span>
                  </div>
                  <div>
                    <span class="text-gray-500">Après dédup:</span>
                    <span class="ml-2 font-mono text-emerald-600">{{ formatBytes(jobStore.getProgressInfo(selectedJob.id).deduplicated_size || 0) }}</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- Logs Tab -->
            <div v-if="activeTab === 'logs'">
              <div v-if="selectedJob.error" class="p-4 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-xl">
                <pre class="text-sm text-red-800 dark:text-red-300 font-mono whitespace-pre-wrap overflow-x-auto">{{ selectedJob.error }}</pre>
              </div>
              <div v-else-if="selectedJob.output" class="p-4 bg-gray-900 rounded-xl">
                <pre class="text-sm text-green-400 font-mono whitespace-pre-wrap overflow-x-auto">{{ selectedJob.output }}</pre>
              </div>
              <div v-else class="text-center py-12 text-gray-500">Aucun log disponible</div>
            </div>

            <!-- Payload Tab -->
            <div v-if="activeTab === 'payload'">
              <div class="p-4 bg-gray-900 rounded-xl">
                <pre class="text-sm text-emerald-400 font-mono overflow-x-auto">{{ JSON.stringify(selectedJob.payload, null, 2) }}</pre>
              </div>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { onMounted, ref, computed, h } from 'vue'
import { useJobStore } from '@/stores/jobs'
import { useI18n } from 'vue-i18n'
import { useSSE } from '@/composables/useSSE'
import { useConfirmStore } from '@/stores/confirm'

const jobStore = useJobStore()
const { t } = useI18n()
const { subscribe } = useSSE()
const confirmDialog = useConfirmStore()

// Filter state
const showSystemJobs = ref(false)
const statusFilter = ref(null)
const selectedJob = ref(null)
const activeTab = ref('details')

// System job types
const SYSTEM_JOB_TYPES = [
  'server_stats_collect',
  'storage_pool_analyze',
  'capabilities_detection'
]

// Load jobs on mount
onMounted(async () => {
  await loadData()

  subscribe('jobs', (data) => {
    if (data.jobs) jobStore.jobs = data.jobs
    if (data.stats) jobStore.stats = data.stats
    if (data.job_id && data.progress_info) {
      jobStore.setProgressInfo(data.job_id, data.progress_info)
    }
  })
})

// Filtered jobs
const filteredJobs = computed(() => {
  let jobs = jobStore.jobs

  if (!showSystemJobs.value) {
    jobs = jobs.filter(job => !isSystemJob(job.type))
  }

  if (statusFilter.value) {
    jobs = jobs.filter(job => job.status === statusFilter.value)
  }

  return jobs
})

// Displayed jobs (for rendering)
const displayedJobs = computed(() => filteredJobs.value)

// Group jobs by date
const groupedJobs = computed(() => {
  const groups = {}

  filteredJobs.value.forEach(job => {
    const date = new Date(job.created_at).toISOString().split('T')[0]
    if (!groups[date]) groups[date] = []
    groups[date].push(job)
  })

  // Sort dates descending
  const sortedGroups = {}
  Object.keys(groups).sort().reverse().forEach(date => {
    sortedGroups[date] = groups[date]
  })

  return sortedGroups
})

// Filtered stats
const filteredStats = computed(() => {
  if (showSystemJobs.value) return jobStore.stats

  const stats = { total: 0, pending: 0, running: 0, completed: 0, failed: 0, cancelled: 0 }
  const userJobs = jobStore.jobs.filter(job => !isSystemJob(job.type))
  userJobs.forEach(job => {
    stats.total++
    if (stats[job.status] !== undefined) stats[job.status]++
  })
  return stats
})

function isSystemJob(type) {
  return SYSTEM_JOB_TYPES.includes(type)
}

async function loadData() {
  await Promise.all([
    jobStore.fetchJobs({ limit: 50 }),
    jobStore.fetchStats(),
    jobStore.fetchProgressForRunningJobs()
  ])
}

async function handleCancel(id) {
  const confirmed = await confirmDialog.show({
    title: t('jobs.cancel_job'),
    message: t('jobs.cancel_confirm'),
    confirmText: t('common.cancel'),
    cancelText: t('common.close'),
    type: 'warning'
  })
  if (!confirmed) return
  await jobStore.cancelJob(id)
}

async function handleRetry(id) {
  await jobStore.retryJob(id)
}

function formatJobType(type) {
  const translationKey = `jobs.types.${type}`
  if (t(translationKey) !== translationKey) return t(translationKey)
  return type.split('_').map(w => w.charAt(0).toUpperCase() + w.slice(1)).join(' ')
}

function formatDate(dateString) {
  if (!dateString) return '-'
  return new Date(dateString).toLocaleString('fr-FR', {
    day: '2-digit', month: '2-digit', year: 'numeric',
    hour: '2-digit', minute: '2-digit', second: '2-digit'
  })
}

function formatTime(dateString) {
  if (!dateString) return '-'
  return new Date(dateString).toLocaleString('fr-FR', {
    hour: '2-digit', minute: '2-digit'
  })
}

function formatDateHeader(dateString) {
  const date = new Date(dateString)
  const today = new Date()
  const yesterday = new Date(today)
  yesterday.setDate(yesterday.getDate() - 1)

  if (date.toDateString() === today.toDateString()) return "Aujourd'hui"
  if (date.toDateString() === yesterday.toDateString()) return 'Hier'

  return date.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long' })
}

function formatDayShort(dateString) {
  return new Date(dateString).getDate()
}

function formatStatus(status) {
  return t(`jobs.stats.${status}`)
}

function getStatusBarClass(status) {
  const classes = {
    pending: 'bg-blue-500',
    running: 'bg-amber-500',
    completed: 'bg-emerald-500',
    failed: 'bg-red-500',
    cancelled: 'bg-gray-400'
  }
  return classes[status] || 'bg-gray-400'
}

function getStatusBadgeClass(status) {
  const classes = {
    pending: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
    running: 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    completed: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
    failed: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
    cancelled: 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300'
  }
  return classes[status] || 'bg-gray-100 text-gray-700'
}

function getStatusDotClass(status) {
  const classes = {
    pending: 'bg-blue-500',
    running: 'bg-amber-500',
    completed: 'bg-emerald-500',
    failed: 'bg-red-500',
    cancelled: 'bg-gray-400'
  }
  return classes[status] || 'bg-gray-400'
}

function getFilterChipActiveClass(status) {
  const classes = {
    pending: 'bg-blue-600 text-white shadow-md',
    running: 'bg-amber-600 text-white shadow-md',
    completed: 'bg-emerald-600 text-white shadow-md',
    failed: 'bg-red-600 text-white shadow-md'
  }
  return classes[status] || 'bg-gray-600 text-white'
}

function getJobIconBg(job) {
  if (job.type.includes('backup')) return 'bg-blue-100 dark:bg-blue-900/30'
  if (job.type.includes('restore')) return 'bg-purple-100 dark:bg-purple-900/30'
  if (job.type.includes('docker')) return 'bg-cyan-100 dark:bg-cyan-900/30'
  if (job.type.includes('database')) return 'bg-orange-100 dark:bg-orange-900/30'
  if (job.type.includes('update')) return 'bg-indigo-100 dark:bg-indigo-900/30'
  return 'bg-gray-100 dark:bg-gray-700'
}

function getJobIconColor(job) {
  if (job.type.includes('backup')) return 'text-blue-600 dark:text-blue-400'
  if (job.type.includes('restore')) return 'text-purple-600 dark:text-purple-400'
  if (job.type.includes('docker')) return 'text-cyan-600 dark:text-cyan-400'
  if (job.type.includes('database')) return 'text-orange-600 dark:text-orange-400'
  if (job.type.includes('update')) return 'text-indigo-600 dark:text-indigo-400'
  return 'text-gray-600 dark:text-gray-400'
}

function getJobIcon(job) {
  // Return an SVG component based on job type
  const icons = {
    backup: () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12' })
    ]),
    restore: () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12' })
    ]),
    docker: () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4' })
    ]),
    default: () => h('svg', { fill: 'none', stroke: 'currentColor', viewBox: '0 0 24 24' }, [
      h('path', { 'stroke-linecap': 'round', 'stroke-linejoin': 'round', 'stroke-width': '2', d: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2' })
    ])
  }

  if (job.type.includes('backup')) return icons.backup
  if (job.type.includes('restore')) return icons.restore
  if (job.type.includes('docker')) return icons.docker
  return icons.default
}

function formatBytes(bytes) {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round((bytes / Math.pow(k, i)) * 100) / 100 + ' ' + sizes[i]
}

function formatRate(bytesPerSecond) {
  if (!bytesPerSecond) return '0 B/s'
  const k = 1024
  if (bytesPerSecond >= k * k) return (bytesPerSecond / (k * k)).toFixed(1) + ' MB/s'
  if (bytesPerSecond >= k) return (bytesPerSecond / k).toFixed(1) + ' KB/s'
  return bytesPerSecond.toFixed(0) + ' B/s'
}

function getJobServer(job) {
  if (job.payload?.server_name) return job.payload.server_name
  if (job.payload?.description) {
    const match = job.payload.description.match(/:\s*([^\-]+)/)
    if (match) return match[1].trim()
  }
  return null
}

function getBackupType(job) {
  if (job.payload?.type) {
    const types = { backup: '💾 System', database: '🗄️ Database', docker: '🐳 Docker' }
    return types[job.payload.type] || job.payload.type
  }
  if (job.type === 'docker_restore') return '🐳 Docker'
  if (job.type === 'archive_restore') return '📁 Archive'
  return null
}
</script>
