import api from './api'

/**
 * phpBorg Self-Backup API Service
 */
export default {
  /**
   * Get all backups
   */
  async getAll() {
    const response = await api.get('/phpborg-backups')
    return response.data
  },

  /**
   * Get backup by ID
   */
  async getById(id) {
    const response = await api.get(`/phpborg-backups/${id}`)
    return response.data
  },

  /**
   * Get backup statistics
   */
  async getStats() {
    const response = await api.get('/phpborg-backups/stats')
    return response.data
  },

  /**
   * Create manual backup
   */
  async create(notes = null) {
    const response = await api.post('/phpborg-backups', { notes })
    return response.data
  },

  /**
   * Restore from backup
   */
  async restore(id, createPreRestoreBackup = true) {
    const response = await api.post(`/phpborg-backups/${id}/restore`, {
      create_pre_restore_backup: createPreRestoreBackup
    })
    return response.data
  },

  /**
   * Trigger cleanup of old backups
   */
  async cleanup() {
    const response = await api.post('/phpborg-backups/cleanup')
    return response.data
  },

  /**
   * Delete backup
   */
  async delete(id) {
    const response = await api.delete(`/phpborg-backups/${id}`)
    return response.data
  },

  /**
   * Get download URL for backup
   */
  getDownloadUrl(id) {
    return `/api/phpborg-backups/${id}/download`
  }
}
