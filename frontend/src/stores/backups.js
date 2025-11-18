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
    last_backup: null,
    avg_transfer_rate: null
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
        last_backup: null,
        avg_transfer_rate: null
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
      const result = await backupService.delete(id)

      if (result.success) {
        // Don't remove from local list immediately since deletion is async
        // The user can refresh the page later to see the result
        // Or we could refresh after a delay
        
        // Refresh stats (they may not change immediately but it's good practice)
        await fetchStats()
      }

      return result
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to delete backup'
      console.error('Delete backup error:', err)
      throw err
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
