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

  /**
   * Mount an archive for browsing
   * @param {number} id - Backup ID
   * @returns {Promise<Object>}
   */
  async mount(id) {
    const response = await api.post(`/backups/${id}/mount`)
    return {
      success: response.data.success,
      job_id: response.data.data?.job_id,
      mount_id: response.data.data?.mount_id,
      status: response.data.data?.status,
      message: response.data.message || response.data.data?.message
    }
  },

  /**
   * Unmount an archive
   * @param {number} id - Backup ID
   * @returns {Promise<Object>}
   */
  async unmount(id) {
    const response = await api.post(`/backups/${id}/unmount`)
    return {
      success: response.data.success,
      job_id: response.data.data?.job_id,
      message: response.data.message || response.data.data?.message
    }
  },

  /**
   * Get mount job status
   * @param {number} id - Backup ID
   * @returns {Promise<Object>}
   */
  async getMountJob(id) {
    // Get the mount job for this archive
    const backup = await this.get(id)
    if (backup.mount_job_id) {
      const response = await api.get(`/jobs/${backup.mount_job_id}`)
      return response.data.data?.job || response.data.job
    }
    return { status: 'unknown' }
  },

  /**
   * Browse files in a mounted archive
   * @param {number} id - Backup ID
   * @param {string} path - Path to browse (default: '/')
   * @returns {Promise<Object>}
   */
  async browse(id, path = '/') {
    const response = await api.get(`/backups/${id}/browse`, { params: { path } })
    return {
      path: response.data.data?.path || '/',
      items: response.data.data?.items || [],
      total: response.data.data?.total || 0
    }
  },

  /**
   * Get download URL for a file
   * @param {number} id - Backup ID
   * @param {string} path - File path
   * @returns {string} Download URL
   */
  getDownloadUrl(id, path) {
    const baseUrl = api.defaults.baseURL || ''
    const encodedPath = encodeURIComponent(path)
    return `${baseUrl}/backups/${id}/download?path=${encodedPath}`
  },

  /**
   * Restore files from an archive
   * @param {number} id - Backup ID
   * @param {Object} restoreData - Restore configuration
   * @param {number} restoreData.server_id - Destination server ID
   * @param {Array<string>} restoreData.files - Array of file paths to restore
   * @param {string} restoreData.restore_mode - 'in_place' | 'alternate' | 'suffix'
   * @param {string} restoreData.destination - Custom destination path (for alternate mode)
   * @param {string} restoreData.overwrite_mode - 'always' | 'newer' | 'never' | 'rename'
   * @param {boolean} restoreData.preserve_permissions - Preserve file permissions
   * @param {boolean} restoreData.preserve_owner - Preserve file ownership
   * @param {boolean} restoreData.verify_checksums - Verify after restore
   * @param {boolean} restoreData.dry_run - Simulate restore without actual changes
   * @param {boolean} restoreData.confirm_overwrite - Required for in_place mode
   * @returns {Promise<Object>}
   */
  async restore(id, restoreData) {
    const response = await api.post(`/backups/${id}/restore`, restoreData)
    return {
      success: response.data.success,
      job_id: response.data.data?.job_id,
      archive_id: response.data.data?.archive_id,
      server_id: response.data.data?.server_id,
      files_count: response.data.data?.files_count,
      restore_mode: response.data.data?.restore_mode,
      dry_run: response.data.data?.dry_run,
      message: response.data.message || response.data.data?.message
    }
  },
}
