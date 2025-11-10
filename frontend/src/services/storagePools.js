import apiClient from './api'

export const storagePoolService = {
  async getAll() {
    const response = await apiClient.get('/storage-pools')
    return response.data
  },
  
  async getById(id) {
    const response = await apiClient.get(`/storage-pools/${id}`)
    return response.data
  },
  
  async create(pool) {
    const response = await apiClient.post('/storage-pools', pool)
    return response.data
  },
  
  async update(id, pool) {
    const response = await apiClient.put(`/storage-pools/${id}`, pool)
    return response.data
  },
  
  async delete(id) {
    const response = await apiClient.delete(`/storage-pools/${id}`)
    return response.data
  },
  
  async analyzePath(path, serverId) {
    const response = await apiClient.post('/storage-pools/analyze', {
      path,
      server_id: serverId
    })
    return response.data
  }
}