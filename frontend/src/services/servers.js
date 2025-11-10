import apiClient from './api'

export const serverService = {
  async getAll() {
    const response = await apiClient.get('/servers')
    return response.data
  },
  
  async getById(id) {
    const response = await apiClient.get(`/servers/${id}`)
    return response.data
  },
  
  async create(server) {
    const response = await apiClient.post('/servers', server)
    return response.data
  },
  
  async update(id, server) {
    const response = await apiClient.put(`/servers/${id}`, server)
    return response.data
  },
  
  async delete(id) {
    const response = await apiClient.delete(`/servers/${id}`)
    return response.data
  },
  
  async getRepositories(id) {
    const response = await apiClient.get(`/servers/${id}/repositories`)
    return response.data
  }
}