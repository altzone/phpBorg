import { defineStore } from 'pinia'
import { ref } from 'vue'
import { dashboardService } from '@/services/dashboard'

export const useDashboardStore = defineStore('dashboard', () => {
  // State
  const statistics = ref({
    total_servers: 0,
    active_servers: 0,
    total_backups: 0,
    active_jobs: 0,
    failed_jobs: 0,
    storage_used: 0,
    original_size: 0,
    compressed_size: 0,
    compression_ratio: 0,
    deduplication_ratio: 0,
  })
  const recentBackups = ref([])
  const recentJobs = ref([])
  const loading = ref(false)
  const error = ref(null)

  // Actions
  async function fetchStats() {
    try {
      loading.value = true
      error.value = null

      const data = await dashboardService.getStats()

      statistics.value = data.statistics || statistics.value
      recentBackups.value = data.recent_backups || []
      recentJobs.value = data.recent_jobs || []
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to load dashboard statistics'
      console.error('Fetch dashboard stats error:', err)
    } finally {
      loading.value = false
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    // State
    statistics,
    recentBackups,
    recentJobs,
    loading,
    error,

    // Actions
    fetchStats,
    clearError,
  }
})
