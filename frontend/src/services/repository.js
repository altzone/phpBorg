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
}
