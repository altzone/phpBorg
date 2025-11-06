import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { backupService } from '@/services/backups'

export const useBackupStore = defineStore('backups', () => {
  // State
  const backups = ref([])
  const stats = ref({
    total_backups: 0,
    total_original_size: 0,
    total_compressed_size: 0,
    total_deduplicated_size: 0,
    compression_ratio: 0,
    deduplication_ratio: 0,
    last_backup: null
  })
  const loading = ref(false)
  const error = ref(null)

  // Getters
  const recentBackups = computed(() => backups.value.slice(0, 10))

  // Actions
  async function fetchBackups(params = {}) {
    try {
      loading.value = true
      error.value = null
      const result = await backupService.list(params)
      backups.value = result || []
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to load backups'
      console.error('Fetch backups error:', err)
      backups.value = []
    } finally {
      loading.value = false
    }
  }

  async function fetchStats() {
    try {
      const result = await backupService.stats()
      stats.value = result || {
        total_backups: 0,
        total_original_size: 0,
        total_compressed_size: 0,
        total_deduplicated_size: 0,
        compression_ratio: 0,
        deduplication_ratio: 0,
        last_backup: null
      }
    } catch (err) {
      console.error('Fetch stats error:', err)
      // Keep default stats values on error
    }
  }

  async function createBackup(backupData) {
    try {
      error.value = null
      const result = await backupService.create(backupData)

      // Refresh lists
      await Promise.all([fetchBackups(), fetchStats()])

      return result
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to create backup'
      console.error('Create backup error:', err)
      throw err
    }
  }

  async function deleteBackup(id) {
    try {
      error.value = null
      await backupService.delete(id)

      // Remove from local list
      backups.value = backups.value.filter(b => b.id !== id)

      // Refresh stats
      await fetchStats()

      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to delete backup'
      console.error('Delete backup error:', err)
      return false
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    // State
    backups,
    stats,
    loading,
    error,

    // Getters
    recentBackups,

    // Actions
    fetchBackups,
    fetchStats,
    createBackup,
    deleteBackup,
    clearError,
  }
})
