import api from './api'

export const storageService = {
  async getStoragePools() {
    const response = await api.get('/storage-pools')
    return response.data.data
  },

  async getStoragePool(id) {
    const response = await api.get(`/storage-pools/${id}`)
    return response.data.data
  },

  async analyzePath(path) {
    const response = await api.post('/storage-pools/analyze', { path })
    return response.data.data
  },

  async createStoragePool(poolData) {
    const response = await api.post('/storage-pools', poolData)
    return response.data.data
  },

  async updateStoragePool(id, poolData) {
    const response = await api.put(`/storage-pools/${id}`, poolData)
    return response.data.data
  },

  async deleteStoragePool(id) {
    const response = await api.delete(`/storage-pools/${id}`)
    return response.data
  },
}
