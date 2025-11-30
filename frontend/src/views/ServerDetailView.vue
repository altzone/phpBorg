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
      Retour aux serveurs
    </button>

    <!-- Loading State -->
    <div v-if="loading && !serverData" class="flex items-center justify-center h-96">
      <div class="text-center">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-500 dark:text-gray-400">Chargement du dashboard...</p>
      </div>
    </div>

    <!-- Server Not Found -->
    <div v-else-if="!serverData && !loading" class="card text-center py-16">
      <svg class="w-16 h-16 mx-auto mb-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.172 16.172a4 4 0 015.656 0M9 10h.01M15 10h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Serveur introuvable</h3>
      <p class="text-sm text-gray-500 dark:text-gray-400">Ce serveur n'existe pas ou a été supprimé.</p>
    </div>

    <!-- Dashboard Content -->
    <div v-else class="space-y-6">
      <!-- Header Section -->
      <div class="bg-gradient-to-r from-slate-800 to-slate-900 dark:from-slate-900 dark:to-black rounded-2xl p-6 text-white shadow-xl">
        <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-4">
          <!-- Server Identity -->
          <div class="flex items-center gap-4">
            <div class="w-16 h-16 rounded-xl bg-white/10 flex items-center justify-center">
              <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
              </svg>
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
                  {{ server.active ? 'En ligne' : 'Hors ligne' }}
                </span>
                <span v-if="server.agent?.is_online" class="flex items-center gap-1 px-2.5 py-0.5 text-xs font-medium rounded-full bg-blue-500/20 text-blue-300">
                  <span class="w-1.5 h-1.5 rounded-full bg-blue-400 animate-pulse"></span>
                  Agent v{{ server.agent.version }}
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
              <div class="text-gray-400 text-xs uppercase tracking-wide">Système</div>
              <div class="font-medium">{{ system.os.distribution }} {{ system.os.version }}</div>
            </div>
            <div class="text-right">
              <div class="text-gray-400 text-xs uppercase tracking-wide">Kernel</div>
              <div class="font-medium font-mono text-xs">{{ system.os.kernel }}</div>
            </div>
            <div class="text-right">
              <div class="text-gray-400 text-xs uppercase tracking-wide">Uptime</div>
              <div class="font-medium">{{ system.uptime?.human || 'N/A' }}</div>
            </div>
          </div>

          <!-- Action Buttons -->
          <div v-if="authStore.isAdmin" class="flex gap-2">
            <button @click="showEditModal = true" class="px-4 py-2 bg-white/10 hover:bg-white/20 rounded-lg text-sm font-medium transition-colors">
              Modifier
            </button>
            <button @click="showDeleteModal = true" class="px-4 py-2 bg-red-500/20 hover:bg-red-500/30 text-red-300 rounded-lg text-sm font-medium transition-colors">
              Supprimer
            </button>
          </div>
        </div>
      </div>

      <!-- Quick Stats Row -->
      <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <!-- CPU -->
        <div class="card p-4">
          <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">CPU</span>
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
            {{ system?.cpu?.cores || 0 }} cores &bull; Load: {{ system?.cpu?.load_1?.toFixed(2) || '0.00' }}
          </div>
        </div>

        <!-- Memory -->
        <div class="card p-4">
          <div class="flex items-center justify-between mb-3">
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Mémoire</span>
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
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Disque</span>
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
            <span class="text-sm font-medium text-gray-500 dark:text-gray-400">Backups</span>
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
              {{ backupStats?.success_rate?.toFixed(0) || 100 }}% succès
            </span>
          </div>
          <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">
            {{ formatBytes(backupStats?.storage_used || 0) }} utilisés
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
              <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Historique des ressources</h2>
              <select
                v-model="chartHours"
                @change="fetchStatsHistory"
                class="text-sm border border-gray-300 dark:border-gray-600 rounded-lg px-3 py-1.5 bg-white dark:bg-gray-700 text-gray-900 dark:text-white"
              >
                <option :value="1">1 heure</option>
                <option :value="6">6 heures</option>
                <option :value="24">24 heures</option>
                <option :value="72">3 jours</option>
                <option :value="168">7 jours</option>
              </select>
            </div>

            <!-- Simple Line Chart using SVG -->
            <div class="h-64 relative" v-if="statsHistory.length > 0">
              <svg class="w-full h-full" viewBox="0 0 800 200" preserveAspectRatio="none">
                <!-- Grid lines -->
                <line x1="0" y1="50" x2="800" y2="50" stroke="#e5e7eb" stroke-width="1" class="dark:stroke-gray-700"/>
                <line x1="0" y1="100" x2="800" y2="100" stroke="#e5e7eb" stroke-width="1" class="dark:stroke-gray-700"/>
                <line x1="0" y1="150" x2="800" y2="150" stroke="#e5e7eb" stroke-width="1" class="dark:stroke-gray-700"/>

                <!-- CPU Line -->
                <polyline
                  :points="getChartPoints('cpu_usage')"
                  fill="none"
                  stroke="#3b82f6"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />

                <!-- Memory Line -->
                <polyline
                  :points="getChartPoints('memory_percent')"
                  fill="none"
                  stroke="#8b5cf6"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />

                <!-- Disk Line -->
                <polyline
                  :points="getChartPoints('disk_percent')"
                  fill="none"
                  stroke="#f59e0b"
                  stroke-width="2"
                  stroke-linecap="round"
                  stroke-linejoin="round"
                />
              </svg>

              <!-- Legend -->
              <div class="absolute bottom-0 left-0 right-0 flex items-center justify-center gap-6 text-xs">
                <span class="flex items-center gap-1.5">
                  <span class="w-3 h-0.5 bg-blue-500 rounded"></span>
                  CPU
                </span>
                <span class="flex items-center gap-1.5">
                  <span class="w-3 h-0.5 bg-purple-500 rounded"></span>
                  Mémoire
                </span>
                <span class="flex items-center gap-1.5">
                  <span class="w-3 h-0.5 bg-amber-500 rounded"></span>
                  Disque
                </span>
              </div>
            </div>
            <div v-else class="h-64 flex items-center justify-center text-gray-500 dark:text-gray-400">
              <div class="text-center">
                <svg class="w-12 h-12 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
                <p>Aucun historique disponible</p>
              </div>
            </div>
          </div>

          <!-- Recent Backups -->
          <div class="card">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Derniers backups</h2>

            <div v-if="recentBackups.length === 0" class="text-center py-8 text-gray-500 dark:text-gray-400">
              <svg class="w-12 h-12 mx-auto mb-2 opacity-50" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
              </svg>
              <p>Aucun backup enregistré</p>
            </div>

            <div v-else class="overflow-x-auto">
              <table class="w-full">
                <thead>
                  <tr class="text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                    <th class="pb-3">Archive</th>
                    <th class="pb-3">Date</th>
                    <th class="pb-3">Durée</th>
                    <th class="pb-3">Taille</th>
                    <th class="pb-3">Fichiers</th>
                    <th class="pb-3">Dédup</th>
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
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Storage Pools</h2>

            <div v-if="storagePools.length === 0" class="text-center py-6 text-gray-500 dark:text-gray-400 text-sm">
              Aucun pool configuré
            </div>

            <div v-else class="space-y-4">
              <div v-for="pool in storagePools" :key="pool.id" class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg">
                <div class="flex items-center justify-between mb-2">
                  <span class="font-medium text-gray-900 dark:text-white">{{ pool.name }}</span>
                  <span class="text-sm text-gray-500 dark:text-gray-400">{{ pool.repository_count }} repo(s)</span>
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
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Repositories</h2>

            <div v-if="repositories.length === 0" class="text-center py-6 text-gray-500 dark:text-gray-400 text-sm">
              Aucun repository
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

          <!-- Capabilities -->
          <div class="card" v-if="capabilities">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Capacités détectées</h2>

            <div class="space-y-2">
              <div v-if="capabilities.databases?.mysql" class="flex items-center gap-2 text-sm">
                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                <span class="text-gray-700 dark:text-gray-300">MySQL {{ capabilities.databases.mysql.version }}</span>
              </div>
              <div v-if="capabilities.databases?.postgresql" class="flex items-center gap-2 text-sm">
                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                <span class="text-gray-700 dark:text-gray-300">PostgreSQL {{ capabilities.databases.postgresql.version }}</span>
              </div>
              <div v-if="capabilities.databases?.mongodb" class="flex items-center gap-2 text-sm">
                <span class="w-2 h-2 rounded-full bg-green-500"></span>
                <span class="text-gray-700 dark:text-gray-300">MongoDB {{ capabilities.databases.mongodb.version }}</span>
              </div>
              <div v-if="capabilities.docker?.installed" class="flex items-center gap-2 text-sm">
                <span class="w-2 h-2 rounded-full bg-blue-500"></span>
                <span class="text-gray-700 dark:text-gray-300">Docker {{ capabilities.docker.version }}</span>
              </div>
              <div v-if="capabilities.lvm?.available" class="flex items-center gap-2 text-sm">
                <span class="w-2 h-2 rounded-full bg-purple-500"></span>
                <span class="text-gray-700 dark:text-gray-300">LVM Snapshots</span>
              </div>
              <div v-if="capabilities.borg?.installed" class="flex items-center gap-2 text-sm">
                <span class="w-2 h-2 rounded-full bg-amber-500"></span>
                <span class="text-gray-700 dark:text-gray-300">Borg {{ capabilities.borg.version }}</span>
              </div>
            </div>
          </div>

          <!-- System Info -->
          <div class="card">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">Informations système</h2>

            <dl class="space-y-3 text-sm">
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">Hostname</dt>
                <dd class="text-gray-900 dark:text-white font-mono">{{ system?.os?.hostname || server.hostname }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">Architecture</dt>
                <dd class="text-gray-900 dark:text-white">{{ system?.os?.architecture || 'N/A' }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">CPU</dt>
                <dd class="text-gray-900 dark:text-white text-right text-xs">{{ system?.cpu?.model || 'N/A' }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">Swap</dt>
                <dd class="text-gray-900 dark:text-white">
                  {{ formatMB(system?.swap?.used_mb) }} / {{ formatMB(system?.swap?.total_mb) }}
                </dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">Point de montage</dt>
                <dd class="text-gray-900 dark:text-white font-mono">{{ system?.disk?.mount_point || '/' }}</dd>
              </div>
              <div class="flex justify-between">
                <dt class="text-gray-500 dark:text-gray-400">Créé le</dt>
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
import { useAuthStore } from '@/stores/auth'
import { serverService } from '@/services/server'
import ServerFormModal from '@/components/ServerFormModal.vue'
import DeleteConfirmModal from '@/components/DeleteConfirmModal.vue'
import RetentionModal from '@/components/RetentionModal.vue'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()

// State
const loading = ref(true)
const serverData = ref(null)
const statsHistory = ref([])
const chartHours = ref(24)
const showEditModal = ref(false)
const showDeleteModal = ref(false)
const showRetentionModal = ref(false)
const selectedRepository = ref(null)

// Computed
const server = computed(() => serverData.value?.server || {})
const system = computed(() => serverData.value?.system || null)
const backupStats = computed(() => serverData.value?.backup_statistics || {})
const repositories = computed(() => serverData.value?.repositories || [])
const storagePools = computed(() => serverData.value?.storage_pools || [])
const recentBackups = computed(() => serverData.value?.recent_backups || [])
const capabilities = computed(() => serverData.value?.capabilities || null)

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

function getChartPoints(metric) {
  if (statsHistory.value.length === 0) return ''

  const width = 800
  const height = 180
  const padding = 10

  return statsHistory.value.map((stat, index) => {
    const x = padding + (index / (statsHistory.value.length - 1)) * (width - padding * 2)
    const value = stat[metric] || 0
    const y = height - padding - (value / 100) * (height - padding * 2)
    return `${x},${y}`
  }).join(' ')
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

// Lifecycle
onMounted(async () => {
  await fetchServerData()
  await fetchStatsHistory()
})

watch(() => route.params.id, async () => {
  await fetchServerData()
  await fetchStatsHistory()
})
</script>
