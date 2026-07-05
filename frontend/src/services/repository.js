import api from './api'

export const repositoryService = {
  /**
   * Get all repositories
   */
  async getRepositories() {
    const response = await api.get('/repositories')
    return response.data.data
  },

  /**
   * Get repositories by server ID
   */
  async listByServer(serverId) {
    const response = await api.get(`/servers/${serverId}/repositories`)
    return response.data.data?.repositories || response.data.repositories || []
  },

  /**
   * Get repository by ID
   */
  async getRepository(id) {
    const response = await api.get(`/repositories/${id}`)
    return response.data.data
  },

  /**
   * Import an existing Borg repository (register it and sync its archives).
   *
   * @param {Object} payload { path, encryption, passphrase, server, type,
   *   compression, keep_daily, keep_weekly, keep_monthly, keep_yearly, fix_ownership, sync }
   */
  async import(payload) {
    const response = await api.post('/repositories/import', payload)
    return response.data.data
  },

  /**
   * Update repository retention policy
   */
  async updateRetention(id, retention) {
    const response = await api.put(`/repositories/${id}/retention`, retention)
    return response.data.data
  },

  /**
   * Update repository backup source config (backup_path, exclude, one_file_system).
   * @param {Object} config { backup_path?, exclude?, one_file_system? }
   */
  async updateBackupConfig(id, config) {
    const response = await api.put(`/repositories/${id}/backup-config`, config)
    return response.data.data
  },

  /**
   * Delete repository (with all safety checks on backend)
   * ⚠️ CRITICAL: This permanently deletes the repository and ALL backups
   *
   * Backend will check:
   * - No active scheduled jobs
   * - No mounted archives
   * - Executes `borg delete` to physically remove repository
   * - Removes all database records
   *
   * @throws Error if safety checks fail or deletion fails
   */
  async delete(id) {
    const response = await api.delete(`/repositories/${id}`)
    return response.data.data
  },
}
