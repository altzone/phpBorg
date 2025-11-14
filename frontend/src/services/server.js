import api from './api'

export const serverService = {
  /**
   * Get all servers
   */
  async list() {
    const response = await api.get('/servers')
    return response.data.data?.servers || response.data.servers || []
  },

  /**
   * Get all servers (alias for backward compatibility)
   */
  async getServers() {
    return this.list()
  },

  /**
   * Get server by ID
   */
  async getServer(id) {
    const response = await api.get(`/servers/${id}`)
    return response.data.data
  },

  /**
   * Create new server
   */
  async createServer(serverData) {
    const response = await api.post('/servers', serverData)
    return response.data.data
  },

  /**
   * Update server
   */
  async updateServer(id, serverData) {
    const response = await api.put(`/servers/${id}`, serverData)
    return response.data.data
  },

  /**
   * Delete server
   * @param {number} id - Server ID
   * @param {string} deleteType - 'archive' or 'full'
   */
  async deleteServer(id, deleteType = 'archive') {
    const response = await api.delete(`/servers/${id}`, {
      params: { type: deleteType }
    })
    return response.data.data
  },

  /**
   * Get repositories for a server
   */
  async getServerRepositories(id) {
    const response = await api.get(`/servers/${id}/repositories`)
    return response.data.data
  },

  /**
   * Trigger stats collection for a server
   */
  async collectStats(id) {
    const response = await api.post(`/servers/${id}/collect-stats`)
    return response.data.data
  },

  /**
   * Get server capabilities (databases, docker, snapshots, etc.)
   */
  async getCapabilities(id) {
    const response = await api.get(`/servers/${id}/capabilities`)
    return response.data.data
  },

  /**
   * Trigger capabilities detection for a server
   */
  async detectCapabilities(id) {
    const response = await api.post(`/servers/${id}/detect-capabilities`)
    return response.data.data
  },

  /**
   * Get deletion statistics for a server
   */
  async getDeleteStats(id) {
    const response = await api.get(`/servers/${id}/delete-stats`)
    return response.data.data
  },
}
