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
   */
  async deleteServer(id) {
    const response = await api.delete(`/servers/${id}`)
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
}
