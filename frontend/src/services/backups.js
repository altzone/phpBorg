import api from './api'

export const backupService = {
  /**
   * Get list of backups
   * @param {Object} params - Query parameters (server_id, repo_id, limit)
   * @returns {Promise<Array>}
   */
  async list(params = {}) {
    const response = await api.get('/backups', { params })
    return response.data.data?.backups || response.data.backups || []
  },

  /**
   * Get backup statistics
   * @returns {Promise<Object>}
   */
  async stats() {
    const response = await api.get('/backups/stats')
    return response.data.data?.stats || response.data.stats || {
      total_backups: 0,
      total_original_size: 0,
      total_compressed_size: 0,
      total_deduplicated_size: 0,
      compression_ratio: 0,
      deduplication_ratio: 0,
      last_backup: null
    }
  },

  /**
   * Get backup details
   * @param {number} id - Backup ID
   * @returns {Promise<Object>}
   */
  async get(id) {
    const response = await api.get(`/backups/${id}`)
    return response.data.data?.backup || response.data.backup
  },

  /**
   * Create a new backup
   * @param {Object} backupData - {server_id, type}
   * @returns {Promise<Object>}
   */
  async create(backupData) {
    const response = await api.post('/backups', backupData)
    return response.data.data || response.data
  },

  /**
   * Delete a backup
   * @param {number} id - Backup ID
   * @returns {Promise<Object>}
   */
  async delete(id) {
    const response = await api.delete(`/backups/${id}`)
    return {
      success: response.data.success,
      job_id: response.data.data?.job_id,
      archive_name: response.data.data?.archive_name,
      message: response.data.message || response.data.data?.message
    }
  },
}
