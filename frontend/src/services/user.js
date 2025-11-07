import api from './api'

export const userService = {
  async getUsers() {
    const response = await api.get('/users')
    return response.data.data
  },

  async getUser(id) {
    const response = await api.get(`/users/${id}`)
    return response.data.data
  },

  async createUser(userData) {
    const response = await api.post('/users', userData)
    return response.data.data
  },

  async updateUser(id, userData) {
    const response = await api.put(`/users/${id}`, userData)
    return response.data.data
  },

  async resetPassword(id, password) {
    const response = await api.put(`/users/${id}/password`, { password })
    return response.data
  },

  async deleteUser(id) {
    const response = await api.delete(`/users/${id}`)
    return response.data
  },
}
