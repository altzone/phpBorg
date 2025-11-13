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
   * Update repository retention policy
   */
  async updateRetention(id, retention) {
    const response = await api.put(`/repositories/${id}/retention`, retention)
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
