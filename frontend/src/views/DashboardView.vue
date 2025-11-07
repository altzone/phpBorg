<template>
  <div>
    <!-- Welcome Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900">Dashboard</h1>
      <p class="mt-2 text-gray-600">Welcome back, {{ authStore.user?.username }}!</p>
    </div>

    <!-- Stats Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <!-- Total Servers -->
      <div class="card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600">Total Servers</p>
            <p class="mt-2 text-3xl font-bold text-gray-900">
              {{ dashboardStore.loading ? '-' : dashboardStore.statistics.total_servers }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ dashboardStore.statistics.active_servers }} active
            </p>
          </div>
          <div class="p-3 bg-primary-100 rounded-lg">
            <svg class="w-8 h-8 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Total Backups -->
      <div class="card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600">Total Backups</p>
            <p class="mt-2 text-3xl font-bold text-gray-900">
              {{ dashboardStore.loading ? '-' : dashboardStore.statistics.total_backups }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ dashboardStore.statistics.compression_ratio }}% compression
            </p>
          </div>
          <div class="p-3 bg-green-100 rounded-lg">
            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Active Jobs -->
      <div class="card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600">Active Jobs</p>
            <p class="mt-2 text-3xl font-bold text-gray-900">
              {{ dashboardStore.loading ? '-' : dashboardStore.statistics.active_jobs }}
            </p>
            <p v-if="dashboardStore.statistics.failed_jobs > 0" class="mt-1 text-xs text-red-600">
              {{ dashboardStore.statistics.failed_jobs }} failed
            </p>
            <p v-else class="mt-1 text-xs text-gray-500">
              All jobs running smoothly
            </p>
          </div>
          <div class="p-3 bg-yellow-100 rounded-lg">
            <svg class="w-8 h-8 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
          </div>
        </div>
      </div>

      <!-- Storage Used -->
      <div class="card">
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm font-medium text-gray-600">Storage Used</p>
            <p class="mt-2 text-3xl font-bold text-gray-900">
              {{ dashboardStore.loading ? '-' : formatBytes(dashboardStore.statistics.storage_used) }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
              {{ dashboardStore.statistics.deduplication_ratio }}% deduplicated
            </p>
          </div>
          <div class="p-3 bg-purple-100 rounded-lg">
            <svg class="w-8 h-8 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
            </svg>
          </div>
        </div>
      </div>
    </div>

    <!-- Recent Activity -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- Recent Backups -->
      <div class="card">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-lg font-semibold text-gray-900">Recent Backups</h2>
          <router-link to="/backups" class="text-sm text-primary-600 hover:text-primary-700">
            View all →
          </router-link>
        </div>

        <!-- Loading State -->
        <div v-if="dashboardStore.loading" class="text-center py-8 text-gray-500">
          <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-gray-200 border-t-primary-600"></div>
          <p class="mt-2 text-sm">Loading backups...</p>
        </div>

        <!-- Empty State -->
        <div v-else-if="dashboardStore.recentBackups.length === 0" class="text-center py-8 text-gray-500">
          <svg class="w-12 h-12 mx-auto mb-3 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
          </svg>
          <p class="text-sm">No backups yet</p>
        </div>

        <!-- Backups List -->
        <div v-else class="space-y-3">
          <div
            v-for="backup in dashboardStore.recentBackups"
            :key="backup.id"
            class="flex items-center justify-between p-3 bg-gray-50 rounded-lg hover:bg-gray-100 transition"
          >
            <div class="flex-1">
              <p class="text-sm font-medium text-gray-900">{{ backup.name }}</p>
              <p class="text-xs text-gray-500">{{ formatDate(backup.end) }}</p>
            </div>
            <div class="text-right">
              <p class="text-sm font-medium text-gray-900">{{ formatBytes(backup.original_size) }}</p>
              <p class="text-xs text-gray-500">{{ backup.compression_ratio }}% saved</p>
            </div>
          </div>
        </div>
      </div>

      <!-- System Status -->
      <div class="card">
        <div class="flex justify-between items-center mb-4">
          <h2 class="text-lg font-semibold text-gray-900">System Status</h2>
          <router-link to="/jobs" class="text-sm text-primary-600 hover:text-primary-700">
            View jobs →
          </router-link>
        </div>
        <div class="space-y-3">
          <!-- Active Servers -->
          <div class="flex items-center justify-between p-3 bg-green-50 rounded-lg">
            <div class="flex items-center">
              <svg class="w-5 h-5 text-green-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
              </svg>
              <span class="text-sm font-medium text-gray-700">Active Servers</span>
            </div>
            <span class="text-xs font-semibold text-green-700 bg-green-200 px-2 py-1 rounded">
              {{ dashboardStore.statistics.active_servers }} / {{ dashboardStore.statistics.total_servers }}
            </span>
          </div>

          <!-- Running Jobs -->
          <div class="flex items-center justify-between p-3 bg-blue-50 rounded-lg">
            <div class="flex items-center">
              <svg class="w-5 h-5 text-blue-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span class="text-sm font-medium text-gray-700">Running Jobs</span>
            </div>
            <span class="text-xs font-semibold text-blue-700 bg-blue-200 px-2 py-1 rounded">
              {{ dashboardStore.statistics.active_jobs }} active
            </span>
          </div>

          <!-- Failed Jobs -->
          <div v-if="dashboardStore.statistics.failed_jobs > 0" class="flex items-center justify-between p-3 bg-red-50 rounded-lg">
            <div class="flex items-center">
              <svg class="w-5 h-5 text-red-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <span class="text-sm font-medium text-gray-700">Failed Jobs</span>
            </div>
            <span class="text-xs font-semibold text-red-700 bg-red-200 px-2 py-1 rounded">
              {{ dashboardStore.statistics.failed_jobs }} failed
            </span>
          </div>

          <!-- Storage Efficiency -->
          <div class="flex items-center justify-between p-3 bg-purple-50 rounded-lg">
            <div class="flex items-center">
              <svg class="w-5 h-5 text-purple-600 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
              </svg>
              <span class="text-sm font-medium text-gray-700">Storage Efficiency</span>
            </div>
            <span class="text-xs font-semibold text-purple-700 bg-purple-200 px-2 py-1 rounded">
              {{ dashboardStore.statistics.deduplication_ratio }}% saved
            </span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import { useDashboardStore } from '@/stores/dashboard'

const authStore = useAuthStore()
const dashboardStore = useDashboardStore()

onMounted(async () => {
  await dashboardStore.fetchStats()
})

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
  return date.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  })
}
</script>
