<template>
  <div class="min-h-screen">
    <!-- Back Button -->
    <button
      @click="$router.back()"
      class="mb-4 flex items-center text-gray-500 dark:text-gray-400 hover:text-gray-900 dark:hover:text-gray-100 transition-colors"
    >
      <svg class="w-5 h-5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
      </svg>
      {{ $t('serverDetail.back_to_servers') }}
    </button>

    <!-- Loading State -->
    <div v-if="loading && !serverData" class="flex items-center justify-center h-96">
      <div class="text-center">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-500 dark:text-gray-400">{{ $t('serverDetail.loading_dashboard') }}</p>
      </div>
    </div>

    <!-- Server Not Found -->
    <div v-else-if="!serverData && !loading" class="card text-center py-16">
      <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">{{ $t('serverDetail.server_not_found') }}</h3>
      <p class="text-sm text-gray-500 dark:text-gray-400">{{ $t('serverDetail.server_not_found_desc') }}</p>
    </div>

    <!-- Dashboard Content -->
    <div v-else class="space-y-6">
      <!-- Header Section -->
      <div class="bg-gradient-to-r from-slate-800 to-slate-900 dark:from-slate-900 dark:to-black rounded-2xl p-6 text-white shadow-xl">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <!-- Server Identity -->
          <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-xl bg-white/10 flex items-center justify-center">
              <DistroIcon :distribution="system?.os?.distribution" size="xl" class="text-white" />
            </div>
            <div>
              <div class="flex items-center gap-3">
                <h1 class="text-2xl font-bold">{{ server.name }}</h1>
                <span
                  :class="[
                    'px-2.5 py-0.5 text-xs font-medium rounded-full',
                    server.active ? 'bg-green-500/20 text-green-300' : 'bg-gray-500/20 text-gray-300'
                  ]"
                >
                  {{ server.active ? $t('serverDetail.online') : $t('serverDetail.offline') }}
                </span>
                <span v-if="server.agent?.is_online" class="flex items-center gap-1 px-2.5 py-0.5 text-xs font-medium rounded-full bg-blue-500/20 text-blue-300">
                  <span class="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span>
                  Agent v{{ server.agent.version }}
                </span>
                <!-- SSE Connection Status -->
                <span
                  v-if="isConnected"
                  :class="[
                    'flex items-center gap-1 px-2 py-0.5 text-xs font-medium rounded-full',
                    connectionType === 'sse' ? 'bg-emerald-500/20 text-emerald-300' : 'bg-yellow-500/20 text-yellow-300'
                  ]"
                  :title="connectionType === 'sse' ? $t('serverDetail.live_tooltip') : $t('serverDetail.polling_tooltip')"
                >
                  <span :class="['w-1.5 h-1.5 rounded-full', connectionType === 'sse' ? 'bg-emerald-400 animate-pulse' : 'bg-yellow-400']"></span>
                  {{ connectionType === 'sse' ? $t('serverDetail.live') : $t('serverDetail.polling') }}
                </span>
              </div>
              <div class="flex items-center gap-4 mt-1 text-sm text-gray-300">
                <span class="flex items-center gap-1">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9" />
                  </svg>
                  {{ server.hostname }}:{{ server.port }}
                </span>
                <span v-if="system?.network?.ip_address" class="flex items-center gap-1">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                  </svg>
                  {{ system.network.ip_address }}
                </span>
              </div>
            </div>
          </div>

          <!-- OS Info -->
          <div v-if="system?.os" class="flex items-center gap-6 text-sm">
            <div class="text-right">
              <div class="text-gray-400 text-xs uppercase tracking-wide">{{ $t('serverDetail.system') }}</div>
              <div class="font-medium">{{ system.os.distribution }} {{ system.os.version }}</div>
            </div>
            <div class="text-right">
              <div class="text-gray-400 text-xs uppercase tracking-wide">{{ $t('serverDetail.kernel') }}</div>
              <div class="font-medium font-mono text-xs">{{ system.os.kernel }}</div>
            </div>
            <div class="text-right">
              <div class="text-gray-400 text-xs uppercase tracking-wide">{{ $t('serverDetail.uptime') }}</div>
              <div class="font-medium">{{ system.uptime?.human || 'N/A' }}</div>
            </div>
          </div>

          <!-- Action Buttons -->
          <div v-if="authStore.isAdmin" class="flex gap-2">
            <button @click="showEditModal = true" class="px-4 py-2 bg-white/10 hover:bg-white/20 rounded-lg text-sm font-medium transition-colors">
              {{ $t('serverDetail.edit') }}
            </button>
            <button @click="showDeleteModal = true" class="px-4 py-2 bg-red-500/20 hover:bg-red-500/30 text-red-300 rounded-lg text-sm font-medium transition-colors">
              {{ $t('serverDetail.delete') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Quick Stats Row -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- CPU -->
        <div class="card p-4">
          <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $t('serverDetail.cpu') }}</span>
            <div class="w-8 h-8 rounded-lg bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center">
              <svg class="w-4 h-4 text-blue-600 dark:text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
              </svg>
            </div>
          </div>
          <div class="flex items-end gap-2">
            <span class="text-3xl font-bold text-gray-900 dark:text-white">{{ system?.cpu?.usage_percent?.toFixed(0) || 0 }}</span>
            <span class="text-lg text-gray-500 dark:text-gray-400 mb-1">%</span>
          </div>
          <div class="mt-2 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
            <div
              class="h-full rounded-full transition-all duration-500"
              :class="getCpuColor(system?.cpu?.usage_percent || 0)"
              :style="{ width: (system?.cpu?.usage_percent || 0) + '%' }"
            ></div>
          </div>
          <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ system?.cpu?.cores || 0 }} {{ $t('serverDetail.cores') }} &bull; {{ $t('serverDetail.load') }}: {{ system?.cpu?.load_1?.toFixed(2) || '0.00' }}
          </div>
        </div>

        <!-- Memory -->
        <div class="card p-4">
          <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $t('serverDetail.memory') }}</span>
            <div class="w-8 h-8 rounded-lg bg-purple-100 dark:bg-purple-900/30 flex items-center justify-center">
              <svg class="w-4 h-4 text-purple-600 dark:text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
              </svg>
            </div>
          </div>
          <div class="flex items-end gap-2">
            <span class="text-3xl font-bold text-gray-900 dark:text-white">{{ system?.memory?.percent?.toFixed(0) || 0 }}</span>
            <span class="text-lg text-gray-500 dark:text-gray-400 mb-1">%</span>
          </div>
          <div class="mt-2 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
            <div
              class="h-full rounded-full transition-all duration-500"
              :class="getMemoryColor(system?.memory?.percent || 0)"
              :style="{ width: (system?.memory?.percent || 0) + '%' }"
            ></div>
          </div>
          <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ formatMB(system?.memory?.used_mb) }} / {{ formatMB(system?.memory?.total_mb) }}
          </div>
        </div>

        <!-- Disk -->
        <div class="card p-4">
          <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $t('serverDetail.disk') }}</span>
            <div class="w-8 h-8 rounded-lg bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
              <svg class="w-4 h-4 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
              </svg>
            </div>
          </div>
          <div class="flex items-end gap-2">
            <span class="text-3xl font-bold text-gray-900 dark:text-white">{{ system?.disk?.percent?.toFixed(0) || 0 }}</span>
            <span class="text-lg text-gray-500 dark:text-gray-400 mb-1">%</span>
          </div>
          <div class="mt-2 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
            <div
              class="h-full rounded-full transition-all duration-500"
              :class="getDiskColor(system?.disk?.percent || 0)"
              :style="{ width: (system?.disk?.percent || 0) + '%' }"
            ></div>
          </div>
          <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ system?.disk?.used_gb?.toFixed(1) || 0 }} GB / {{ system?.disk?.total_gb?.toFixed(1) || 0 }} GB
          </div>
        </div>

        <!-- Backups -->
        <div class="card p-4">
          <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $t('serverDetail.backups') }}</span>
            <div class="w-8 h-8 rounded-lg bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
              <svg class="w-4 h-4 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
              </svg>
            </div>
          </div>
          <div class="flex items-end gap-2">
            <span class="text-3xl font-bold text-gray-900 dark:text-white">{{ backupStats?.total_backups || 0 }}</span>
          </div>
          <div class="mt-2 flex items-center gap-2">
            <span class="text-xs px-2 py-0.5 rounded bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300">
              {{ $t('serverDetail.success_rate', { rate: backupStats?.success_rate?.toFixed(0) || 100 }) }}
            </span>
          </div>
          <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ formatBytes(backupStats?.storage_used || 0) }} {{ $t('serverDetail.used') }}
          </div>
        </div>
      </div>

      <!-- Main Content Grid -->
      <div class="grid grid-cols-1 xl:grid-cols-3 gap-6">
        <!-- Left Column (2/3) -->
        <div class="xl:col-span-2 space-y-6">
          <!-- Resource History Chart -->
          <div class="card">
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $t('serverDetail.resource_history') }}</h2>
              <select
                v-model="chartHours"
                @change="fetchStatsHistory"
                class="text-sm border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-1.5 bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
              >
                <option :value="1">{{ $t('serverDetail.hour_1') }}</option>
                <option :value="6">{{ $t('serverDetail.hours_6') }}</option>
                <option :value="24">{{ $t('serverDetail.hours_24') }}</option>
                <option :value="72">{{ $t('serverDetail.days_3') }}</option>
                <option :value="168">{{ $t('serverDetail.days_7') }}</option>
              </select>
            </div>

            <!-- ApexCharts Line Chart -->
            <div class="h-72" v-if="statsHistory.length > 0">
              <apexchart
                type="area"
                height="280"
                :options="chartOptions"
                :series="chartSeries"
              />
            </div>
            <div v-else class="h-72 flex items-center justify-center text-gray-500 dark:text-gray-400">
              <div class="text-center">
                <svg class="w-12 h-12 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                <p>{{ $t('serverDetail.no_history') }}</p>
              </div>
            </div>
          </div>

          <!-- Recent Backups -->
          <div class="card">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">{{ $t('serverDetail.recent_backups') }}</h2>

            <div v-if="recentBackups.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
              <svg class="w-12 h-12 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
              </svg>
              <p>{{ $t('serverDetail.no_backups') }}</p>
            </div>

            <div v-else class="overflow-x-auto">
              <table class="w-full">
                <thead>
                  <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    <th class="pb-3">{{ $t('serverDetail.archive') }}</th>
                    <th class="pb-3">{{ $t('serverDetail.date') }}</th>
                    <th class="pb-3">{{ $t('serverDetail.duration') }}</th>
                    <th class="pb-3">{{ $t('serverDetail.size') }}</th>
                    <th class="pb-3">{{ $t('serverDetail.files') }}</th>
                    <th class="pb-3">{{ $t('serverDetail.dedup') }}</th>
                  </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                  <tr v-for="backup in recentBackups" :key="backup.id" class="text-sm">
                    <td class="py-3">
                      <div class="font-medium text-gray-900 dark:text-white">{{ backup.name }}</div>
                      <div class="text-xs text-gray-500 dark:text-gray-400 font-mono">{{ backup.archive_id?.substring(0, 12) }}...</div>
                    </td>
                    <td class="py-3 text-gray-600 dark:text-gray-300">{{ formatDate(backup.end) }}</td>
                    <td class="py-3 text-gray-600 dark:text-gray-300">{{ backup.duration_formatted }}</td>
                    <td class="py-3">
                      <div class="text-gray-900 dark:text-white">{{ formatBytes(backup.original_size) }}</div>
                      <div class="text-xs text-gray-500 dark:text-gray-400">→ {{ formatBytes(backup.deduplicated_size) }}</div>
                    </td>
                    <td class="py-3 text-gray-600 dark:text-gray-300">{{ backup.files_count?.toLocaleString() }}</td>
                    <td class="py-3">
                      <span class="px-2 py-0.5 text-xs rounded bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300">
                        {{ backup.deduplication_ratio }}%
                      </span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- Right Column (1/3) -->
        <div class="space-y-6">
          <!-- Storage Pools -->
          <div class="card">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">{{ $t('serverDetail.storage_pools') }}</h2>

            <div v-if="storagePools.length === 0" class="text-center py-6 text-gray-500 dark:text-gray-400 text-sm">
              {{ $t('serverDetail.no_pools') }}
            </div>

            <div v-else class="space-y-4">
              <div v-for="pool in storagePools" :key="pool.id" class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="flex items-center justify-between mb-2">
                  <span class="font-medium text-gray-900 dark:text-white">{{ pool.name }}</span>
                  <span class="text-sm text-gray-500 dark:text-gray-400">{{ $t('serverDetail.repo_count', { count: pool.repository_count }) }}</span>
                </div>
                <div class="h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                  <div
                    class="h-full rounded-full transition-all"
                    :class="getDiskColor(pool.usage_percent)"
                    :style="{ width: pool.usage_percent + '%' }"
                  ></div>
                </div>
                <div class="flex justify-between mt-1 text-xs text-gray-500 dark:text-gray-400">
                  <span>{{ pool.used_size_gb?.toFixed(1) }} GB</span>
                  <span>{{ pool.total_size_gb?.toFixed(1) }} GB</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Repositories -->
          <div class="card">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">{{ $t('serverDetail.repositories') }}</h2>

            <div v-if="repositories.length === 0" class="text-center py-6 text-gray-500 dark:text-gray-400 text-sm">
              {{ $t('serverDetail.no_repositories') }}
            </div>

            <div v-else class="space-y-3">
              <div
                v-for="repo in repositories"
                :key="repo.id"
                class="p-3 border border-gray-200 dark:border-gray-700 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors cursor-pointer"
                @click="openRetentionModal(repo)"
              >
                <div class="flex items-center justify-between mb-1">
                  <span class="font-medium text-gray-900 dark:text-white capitalize">{{ repo.type }}</span>
                  <div class="flex gap-1">
                    <span class="px-1.5 py-0.5 text-xs bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300 rounded">
                      {{ repo.compression }}
                    </span>
                    <span class="px-1.5 py-0.5 text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 rounded">
                      {{ repo.encryption }}
                    </span>
                  </div>
                </div>
                <div class="text-xs text-gray-500 dark:text-gray-400 font-mono truncate">{{ repo.repo_path }}</div>
                <div v-if="repo.retention" class="flex gap-2 mt-2 text-xs text-gray-500 dark:text-gray-400">
                  <span v-if="repo.retention.keep_daily">{{ repo.retention.keep_daily }}J</span>
                  <span v-if="repo.retention.keep_weekly">{{ repo.retention.keep_weekly }}S</span>
                  <span v-if="repo.retention.keep_monthly">{{ repo.retention.keep_monthly }}M</span>
                  <span v-if="repo.retention.keep_yearly">{{ repo.retention.keep_yearly }}A</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Scheduled Jobs -->
          <div class="card" v-if="scheduledJobs.length > 0">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">{{ $t('serverDetail.scheduled_jobs') }}</h2>

            <div class="space-y-3">
              <div
                v-for="job in scheduledJobs"
                :key="job.id"
                class="p-3 rounded-lg"
                :class="job.isNext ? 'bg-primary-50 dark:bg-primary-900/20 border border-primary-200 dark:border-primary-800' : 'bg-gray-50 dark:bg-gray-800'"
              >
                <div class="flex items-center justify-between mb-1">
                  <span class="font-medium text-gray-900 dark:text-white text-sm">{{ job.name }}</span>
                  <span
                    v-if="job.isNext"
                    class="px-2 py-0.5 text-xs bg-primary-100 dark:bg-primary-900/40 text-primary-700 dark:text-primary-300 rounded-full"
                  >
                    {{ $t('serverDetail.next') }}
                  </span>
                </div>
                <div class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
                  <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <span>{{ formatSchedule(job) }}</span>
                </div>
                <div v-if="job.next_run_at" class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                  <span class="font-medium">{{ $t('serverDetail.next_execution') }}</span>
                  {{ formatDate(job.next_run_at) }}
                </div>
                <div v-if="job.last_run_at" class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  {{ $t('serverDetail.last') }} {{ formatDate(job.last_run_at) }}
                  <span
                    v-if="job.last_status"
                    :class="[
                      'ml-1 px-1.5 py-0.5 rounded',
                      job.last_status === 'completed' ? 'bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300' :
                      job.last_status === 'failed' ? 'bg-red-100 dark:bg-red-900/30 text-red-700 dark:text-red-300' :
                      'bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300'
                    ]"
                  >
                    {{ job.last_status }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- Capabilities -->
          <div class="card">
            <div class="flex items-center justify-between mb-4">
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ $t('serverDetail.capabilities') }}</h2>
              <button
                v-if="authStore.isAdmin"
                @click="detectCapabilities"
                :disabled="detectingCapabilities"
                :title="$t('serverDetail.detect_capabilities')"
                class="p-2 text-gray-500 hover:text-primary-600 dark:text-gray-400 dark:hover:text-primary-400 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <svg
                  :class="['w-5 h-5', detectingCapabilities ? 'animate-spin' : '']"
                  fill="none"
                  stroke="currentColor"
                  viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
              </button>
            </div>

            <div v-if="capabilities && hasAnyCapability" class="space-y-2">
              <!-- Databases (array structure) -->
              <div
                v-for="db in detectedDatabases"
                :key="db.type"
                class="flex items-center gap-2 text-sm"
              >
                <DistroIcon :distribution="db.type" type="database" size="sm" :class="getDbIconColor(db.type)" />
                <span class="text-gray-700 dark:text-gray-300">{{ db.name }} {{ formatDbVersion(db.version) }}</span>
                <span v-if="db.running" class="w-2 h-2 rounded-full bg-green-500" :title="$t('serverDetail.running')"></span>
              </div>

              <!-- Docker -->
              <div v-if="capabilities.docker?.installed" class="flex items-center gap-2 text-sm">
                <DistroIcon distribution="docker" type="service" size="sm" class="text-blue-500 dark:text-blue-400" />
                <span class="text-gray-700 dark:text-gray-300">Docker {{ capabilities.docker.version }}</span>
                <span v-if="capabilities.docker.running" class="w-2 h-2 rounded-full bg-green-500" :title="$t('serverDetail.running')"></span>
              </div>

              <!-- LVM -->
              <div v-if="capabilities.lvm?.available" class="flex items-center gap-2 text-sm">
                <DistroIcon distribution="lvm" type="service" size="sm" class="text-purple-600 dark:text-purple-400" />
                <span class="text-gray-700 dark:text-gray-300">{{ $t('serverDetail.lvm_snapshots') }}</span>
              </div>

              <!-- Borg -->
              <div v-if="capabilities.borg?.installed" class="flex items-center gap-2 text-sm">
                <DistroIcon distribution="borg" type="service" size="sm" class="text-amber-600 dark:text-amber-400" />
                <span class="text-gray-700 dark:text-gray-300">Borg {{ capabilities.borg.version }}</span>
              </div>
            </div>

            <!-- Empty state -->
            <div v-else class="text-center py-4 text-gray-500 dark:text-gray-400">
              <svg class="w-8 h-8 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
              </svg>
              <p class="text-sm">{{ $t('serverDetail.no_capabilities') }}</p>
              <p class="text-xs mt-1">{{ $t('serverDetail.click_refresh_to_detect') }}</p>
            </div>
          </div>

          <!-- System Info -->
          <div class="card">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">{{ $t('serverDetail.system_info') }}</h2>

            <dl class="space-y-3 text-sm">
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">{{ $t('serverDetail.hostname') }}</dt>
                <dd class="text-gray-900 dark:text-white font-mono">{{ system?.os?.hostname || server.hostname }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">{{ $t('serverDetail.architecture') }}</dt>
                <dd class="text-gray-900 dark:text-white">{{ system?.os?.architecture || 'N/A' }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">{{ $t('serverDetail.cpu') }}</dt>
                <dd class="text-gray-900 dark:text-white text-right text-xs">{{ system?.cpu?.model || 'N/A' }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">{{ $t('serverDetail.swap') }}</dt>
                <dd class="text-gray-900 dark:text-white">
                  {{ formatMB(system?.swap?.used_mb) }} / {{ formatMB(system?.swap?.total_mb) }}
                </dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">{{ $t('serverDetail.mount_point') }}</dt>
                <dd class="text-gray-900 dark:text-white font-mono">{{ system?.disk?.mount_point || '/' }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">{{ $t('serverDetail.created_at') }}</dt>
                <dd class="text-gray-900 dark:text-white">{{ formatDate(server.created_at) }}</dd>
              </div>
            </dl>
          </div>
        </div>
      </div>
    </div>

    <!-- Edit Modal -->
    <ServerFormModal
      v-if="showEditModal"
      :server="server"
      @close="showEditModal = false"
      @saved="handleSaved"
    />

    <!-- Delete Confirmation Modal -->
    <DeleteConfirmModal
      v-if="showDeleteModal"
      :server="server"
      @close="showDeleteModal = false"
      @confirm="handleDelete"
    />

    <!-- Retention Modal -->
    <RetentionModal
      v-if="selectedRepository"
      :is-open="showRetentionModal"
      :repository="selectedRepository"
      :server-name="server?.name || 'Unknown Server'"
      @close="closeRetentionModal"
      @updated="handleRetentionUpdated"
    />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToastStore } from '@/stores/toast'
import { useSSE } from '@/composables/useSSE'
import { serverService } from '@/services/server'
import VueApexCharts from 'vue3-apexcharts'
import ServerFormModal from '@/components/ServerFormModal.vue'
import DeleteConfirmModal from '@/components/DeleteConfirmModal.vue'
import RetentionModal from '@/components/RetentionModal.vue'
import DistroIcon from '@/components/DistroIcon.vue'

const { t } = useI18n()
const apexchart = VueApexCharts

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const toast = useToastStore()
const { subscribe, isConnected, connectionType } = useSSE()

// State
const loading = ref(true)
const serverData = ref(null)
const statsHistory = ref([])
const chartHours = ref(24)
const showEditModal = ref(false)
const showDeleteModal = ref(false)
const showRetentionModal = ref(false)
const selectedRepository = ref(null)
const detectingCapabilities = ref(false)

// Computed
const server = computed(() => serverData.value?.server || {})
const system = computed(() => serverData.value?.system || null)
const backupStats = computed(() => serverData.value?.backup_statistics || {})
const repositories = computed(() => serverData.value?.repositories || [])
const storagePools = computed(() => serverData.value?.storage_pools || [])
const recentBackups = computed(() => serverData.value?.recent_backups || [])
const capabilities = computed(() => serverData.value?.capabilities || null)
const scheduledJobs = computed(() => {
  const jobs = serverData.value?.scheduled_jobs || []
  if (jobs.length === 0) return []

  // Sort by next_run_at and mark the next one
  const sorted = [...jobs]
    .filter(j => j.enabled && j.next_run_at)
    .sort((a, b) => new Date(a.next_run_at) - new Date(b.next_run_at))

  if (sorted.length > 0) {
    sorted[0].isNext = true
  }

  return sorted
})

// Detected databases from capabilities array
const detectedDatabases = computed(() => {
  if (!capabilities.value?.databases || !Array.isArray(capabilities.value.databases)) {
    return []
  }
  return capabilities.value.databases.filter(db => db.detected)
})

// Check if any capability is detected
const hasAnyCapability = computed(() => {
  if (!capabilities.value) return false

  const hasDatabases = detectedDatabases.value.length > 0
  const hasDocker = capabilities.value.docker?.installed
  const hasLvm = capabilities.value.lvm?.available
  const hasBorg = capabilities.value.borg?.installed

  return hasDatabases || hasDocker || hasLvm || hasBorg
})

// ApexCharts configuration
const chartOptions = computed(() => {
  const isDark = document.documentElement.classList.contains('dark')

  return {
    chart: {
      type: 'area',
      height: 280,
      toolbar: {
        show: true,
        tools: {
          download: false,
          selection: true,
          zoom: true,
          zoomin: true,
          zoomout: true,
          pan: true,
          reset: true
        }
      },
      animations: {
        enabled: true,
        easing: 'easeinout',
        speed: 300
      },
      background: 'transparent',
      fontFamily: 'Inter, system-ui, sans-serif'
    },
    colors: ['#3b82f6', '#8b5cf6', '#f59e0b'],
    dataLabels: {
      enabled: false
    },
    stroke: {
      curve: 'smooth',
      width: 2
    },
    fill: {
      type: 'gradient',
      gradient: {
        shadeIntensity: 1,
        opacityFrom: 0.4,
        opacityTo: 0.1,
        stops: [0, 90, 100]
      }
    },
    xaxis: {
      type: 'datetime',
      labels: {
        style: {
          colors: isDark ? '#9ca3af' : '#6b7280',
          fontSize: '11px'
        },
        datetimeFormatter: {
          year: 'yyyy',
          month: "MMM 'yy",
          day: 'dd MMM',
          hour: 'HH:mm'
        }
      },
      axisBorder: {
        show: false
      },
      axisTicks: {
        show: false
      }
    },
    yaxis: {
      min: 0,
      max: 100,
      tickAmount: 5,
      labels: {
        style: {
          colors: isDark ? '#9ca3af' : '#6b7280',
          fontSize: '11px'
        },
        formatter: (val) => val.toFixed(0) + '%'
      }
    },
    grid: {
      borderColor: isDark ? '#374151' : '#e5e7eb',
      strokeDashArray: 4,
      xaxis: {
        lines: {
          show: true
        }
      }
    },
    legend: {
      position: 'top',
      horizontalAlign: 'center',
      labels: {
        colors: isDark ? '#d1d5db' : '#374151'
      },
      markers: {
        radius: 2
      }
    },
    tooltip: {
      theme: isDark ? 'dark' : 'light',
      x: {
        format: 'dd MMM HH:mm'
      },
      y: {
        formatter: (val) => val.toFixed(1) + '%'
      }
    }
  }
})

const chartSeries = computed(() => {
  if (statsHistory.value.length === 0) return []

  // Data is already in chronological order from backend
  const history = statsHistory.value

  // Helper to parse MySQL datetime format "YYYY-MM-DD HH:MM:SS" to timestamp
  const parseTimestamp = (ts) => {
    if (!ts) return Date.now()
    // Replace space with T for ISO format compatibility
    const isoDate = ts.replace(' ', 'T')
    return new Date(isoDate).getTime()
  }

  return [
    {
      name: 'CPU',
      data: history.map(stat => ({
        x: parseTimestamp(stat.timestamp),
        y: parseFloat(stat.cpu_usage) || 0
      }))
    },
    {
      name: 'Mémoire',
      data: history.map(stat => ({
        x: parseTimestamp(stat.timestamp),
        y: parseFloat(stat.memory_percent) || 0
      }))
    },
    {
      name: 'Disque',
      data: history.map(stat => ({
        x: parseTimestamp(stat.timestamp),
        y: parseFloat(stat.disk_percent) || 0
      }))
    }
  ]
})

// Methods
async function fetchServerData() {
  loading.value = true
  try {
    const serverId = parseInt(route.params.id)
    const response = await serverService.getFullDetails(serverId)
    if (response.success) {
      serverData.value = response.data
    }
  } catch (error) {
    console.error('Failed to fetch server details:', error)
  } finally {
    loading.value = false
  }
}

async function fetchStatsHistory() {
  try {
    const serverId = parseInt(route.params.id)
    const response = await serverService.getStatsHistory(serverId, chartHours.value)
    if (response.success) {
      statsHistory.value = response.data.history || []
    }
  } catch (error) {
    console.error('Failed to fetch stats history:', error)
  }
}

function formatSchedule(job) {
  if (job.cron_expression) {
    return t('serverDetail.schedule_cron', { expr: job.cron_expression })
  }

  const scheduleType = job.schedule_type
  const time = job.schedule_time || '00:00'

  switch (scheduleType) {
    case 'daily':
      return t('serverDetail.schedule_daily', { time })
    case 'weekly': {
      const dayKeys = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday']
      const dayIndex = job.schedule_day_of_week || 1
      const dayKey = dayKeys[dayIndex % 7]
      const day = t(`serverDetail.days.${dayKey}`)
      return t('serverDetail.schedule_weekly', { day, time })
    }
    case 'monthly':
      return t('serverDetail.schedule_monthly', { day: job.schedule_day_of_month || 1, time })
    case 'manual':
      return t('serverDetail.schedule_manual')
    default:
      return scheduleType
  }
}

function getCpuColor(percent) {
  if (percent >= 90) return 'bg-red-500'
  if (percent >= 70) return 'bg-amber-500'
  return 'bg-blue-500'
}

function getMemoryColor(percent) {
  if (percent >= 90) return 'bg-red-500'
  if (percent >= 70) return 'bg-amber-500'
  return 'bg-purple-500'
}

function getDiskColor(percent) {
  if (percent >= 90) return 'bg-red-500'
  if (percent >= 80) return 'bg-amber-500'
  return 'bg-green-500'
}

function getDbIconColor(type) {
  const colors = {
    mysql: 'text-blue-600 dark:text-blue-400',
    mariadb: 'text-amber-600 dark:text-amber-400',
    postgresql: 'text-blue-700 dark:text-blue-300',
    mongodb: 'text-green-600 dark:text-green-400',
    redis: 'text-red-600 dark:text-red-400',
    sqlite: 'text-cyan-600 dark:text-cyan-400'
  }
  return colors[type] || 'text-gray-600 dark:text-gray-400'
}

function formatDbVersion(version) {
  if (!version) return ''
  // Extract version number from long version strings
  // e.g. "mysql  Ver 15.1 Distrib 10.11.13-MariaDB..." -> "10.11.13"
  const match = version.match(/(\d+\.\d+\.?\d*)/g)
  if (match && match.length > 0) {
    // Return the most relevant version (usually the last one for MariaDB/MySQL)
    return match[match.length - 1]
  }
  return version.substring(0, 20)
}

function formatBytes(bytes) {
  if (!bytes || bytes === 0) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
}

function formatMB(mb) {
  if (!mb) return '0 MB'
  if (mb >= 1024) {
    return (mb / 1024).toFixed(1) + ' GB'
  }
  return mb + ' MB'
}

function formatDate(dateString) {
  if (!dateString) return 'N/A'
  return new Date(dateString).toLocaleString('fr-FR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

function openRetentionModal(repo) {
  selectedRepository.value = repo
  showRetentionModal.value = true
}

function closeRetentionModal() {
  showRetentionModal.value = false
  setTimeout(() => {
    selectedRepository.value = null
  }, 300)
}

async function handleSaved() {
  showEditModal.value = false
  await fetchServerData()
}

async function handleDelete() {
  try {
    const serverId = parseInt(route.params.id)
    await serverService.delete(serverId)
    router.push('/servers')
  } catch (error) {
    console.error('Failed to delete server:', error)
    showDeleteModal.value = false
  }
}

async function handleRetentionUpdated() {
  await fetchServerData()
}

async function detectCapabilities() {
  const serverId = parseInt(route.params.id)
  detectingCapabilities.value = true

  try {
    await serverService.detectCapabilities(serverId)
    toast.success(
      t('serverDetail.capabilities_detection_started'),
      t('serverDetail.capabilities_detection_started_desc')
    )

    // Refresh server data after a delay to get updated capabilities
    setTimeout(async () => {
      await fetchServerData()
      detectingCapabilities.value = false
      toast.success(
        t('serverDetail.capabilities_detected'),
        t('serverDetail.capabilities_detected_desc')
      )
    }, 5000)
  } catch (error) {
    console.error('Failed to detect capabilities:', error)
    detectingCapabilities.value = false
    toast.error(
      t('serverDetail.capabilities_detection_failed'),
      error.response?.data?.error?.message || t('common.unknown_error')
    )
  }
}

// SSE event handlers
function handleStatsUpdate(data) {
  // Check if this update is for our server
  const serverId = parseInt(route.params.id)

  if (data.server_id === serverId && serverData.value) {
    // Update system stats in real-time
    if (data.cpu !== undefined && serverData.value.system) {
      serverData.value.system.cpu = {
        ...serverData.value.system.cpu,
        usage_percent: data.cpu,
        load_1: data.load_1 || serverData.value.system.cpu?.load_1
      }
    }
    if (data.memory !== undefined && serverData.value.system) {
      serverData.value.system.memory = {
        ...serverData.value.system.memory,
        percent: data.memory
      }
    }
    if (data.disk !== undefined && serverData.value.system) {
      serverData.value.system.disk = {
        ...serverData.value.system.disk,
        percent: data.disk
      }
    }
    if (data.uptime && serverData.value.system) {
      serverData.value.system.uptime = {
        ...serverData.value.system.uptime,
        human: data.uptime
      }
    }

    // Add to stats history for real-time chart update
    if (statsHistory.value.length > 0) {
      const newPoint = {
        created_at: new Date().toISOString(),
        cpu_usage: data.cpu || 0,
        memory_percent: data.memory || 0,
        disk_percent: data.disk || 0
      }
      statsHistory.value.push(newPoint)
      // Keep max 100 points for performance
      if (statsHistory.value.length > 100) {
        statsHistory.value.shift()
      }
    }
  }
}

function handleServerUpdate(data) {
  const serverId = parseInt(route.params.id)

  // Handle server status changes
  if (data.server?.id === serverId || data.id === serverId) {
    if (serverData.value?.server) {
      // Update server active status
      if (data.active !== undefined) {
        serverData.value.server.active = data.active
      }
      if (data.server?.active !== undefined) {
        serverData.value.server.active = data.server.active
      }
      // Update agent status
      if (data.agent) {
        serverData.value.server.agent = {
          ...serverData.value.server.agent,
          ...data.agent
        }
      }
    }
  }

  // Handle server deleted
  if (data.deleted && (data.server_id === serverId || data.id === serverId)) {
    router.push('/servers')
  }
}

function handleBackupUpdate(data) {
  const serverId = parseInt(route.params.id)

  // Check if backup is for one of our repositories
  if (data.server_id === serverId && data.backup) {
    // Add new backup to recent backups
    if (serverData.value?.recent_backups) {
      serverData.value.recent_backups.unshift(data.backup)
      // Keep max 10 recent backups
      if (serverData.value.recent_backups.length > 10) {
        serverData.value.recent_backups.pop()
      }
    }

    // Update backup stats
    if (serverData.value?.backup_statistics) {
      serverData.value.backup_statistics.total_backups =
        (serverData.value.backup_statistics.total_backups || 0) + 1
      if (data.backup.deduplicated_size) {
        serverData.value.backup_statistics.storage_used =
          (serverData.value.backup_statistics.storage_used || 0) + data.backup.deduplicated_size
      }
    }
  }
}

// Lifecycle
onMounted(async () => {
  await fetchServerData()
  await fetchStatsHistory()

  // Subscribe to SSE events for real-time updates
  subscribe('stats', handleStatsUpdate)
  subscribe('servers', handleServerUpdate)
  subscribe('backups', handleBackupUpdate)
})

watch(() => route.params.id, async () => {
  await fetchServerData()
  await fetchStatsHistory()
})
</script>
