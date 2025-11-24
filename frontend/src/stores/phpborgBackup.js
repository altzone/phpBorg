import { defineStore } from 'pinia'
import phpborgBackupService from '@/services/phpborgBackup'

export const usePhpBorgBackupStore = defineStore('phpborgBackup', {
  state: () => ({
    backups: [],
    stats: null,
    loading: false,
    error: null,
    selectedBackup: null
  }),

  getters: {
    /**
     * Get backups sorted by date (newest first)
     */
    sortedBackups: (state) => {
      return [...state.backups].sort((a, b) =>
        new Date(b.created_at) - new Date(a.created_at)
      )
    },

    /**
     * Get backups by type
     */
    backupsByType: (state) => (type) => {
      return state.backups.filter(b => b.backup_type === type)
    },

    /**
     * Get latest backup
     */
    latestBackup: (state) => {
      if (state.backups.length === 0) return null
      return [...state.backups].sort((a, b) =>
        new Date(b.created_at) - new Date(a.created_at)
      )[0]
    },

    /**
     * Get total count by type
     */
    countByType: (state) => {
      return {
        manual: state.backups.filter(b => b.backup_type === 'manual').length,
        pre_update: state.backups.filter(b => b.backup_type === 'pre_update').length,
        pre_restore: state.backups.filter(b => b.backup_type === 'pre_restore').length,
        scheduled: state.backups.filter(b => b.backup_type === 'scheduled').length
      }
    }
  },

  actions: {
    /**
     * Load all backups
     */
    async loadBackups() {
      this.loading = true
      this.error = null

      try {
        const response = await phpborgBackupService.getAll()
        this.backups = response.data || []
      } catch (error) {
        this.error = error.response?.data?.error?.message || error.message
        throw error
      } finally {
        this.loading = false
      }
    },

    /**
     * Load backup statistics
     */
    async loadStats() {
      try {
        const response = await phpborgBackupService.getStats()
        this.stats = response.data || null
      } catch (error) {
        console.error('Failed to load backup stats:', error)
      }
    },

    /**
     * Create manual backup
     */
    async createBackup(notes = null) {
      this.loading = true
      this.error = null

      try {
        const response = await phpborgBackupService.create(notes)
        return response.data
      } catch (error) {
        this.error = error.response?.data?.error?.message || error.message
        throw error
      } finally {
        this.loading = false
      }
    },

    /**
     * Restore from backup
     */
    async restoreBackup(backupId, createPreRestoreBackup = true) {
      this.loading = true
      this.error = null

      try {
        const response = await phpborgBackupService.restore(backupId, createPreRestoreBackup)
        return response.data
      } catch (error) {
        this.error = error.response?.data?.error?.message || error.message
        throw error
      } finally {
        this.loading = false
      }
    },

    /**
     * Trigger cleanup
     */
    async triggerCleanup() {
      this.loading = true
      this.error = null

      try {
        const response = await phpborgBackupService.cleanup()
        return response.data
      } catch (error) {
        this.error = error.response?.data?.error?.message || error.message
        throw error
      } finally {
        this.loading = false
      }
    },

    /**
     * Delete backup
     */
    async deleteBackup(backupId) {
      this.loading = true
      this.error = null

      try {
        await phpborgBackupService.delete(backupId)

        // Remove from local state
        this.backups = this.backups.filter(b => b.id !== backupId)

        // Reload stats
        await this.loadStats()
      } catch (error) {
        this.error = error.response?.data?.error?.message || error.message
        throw error
      } finally {
        this.loading = false
      }
    },

    /**
     * Get download URL
     */
    getDownloadUrl(backupId) {
      return phpborgBackupService.getDownloadUrl(backupId)
    },

    /**
     * Select backup for details view
     */
    selectBackup(backup) {
      this.selectedBackup = backup
    },

    /**
     * Clear selected backup
     */
    clearSelection() {
      this.selectedBackup = null
    }
  }
})
