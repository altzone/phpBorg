import api from './api'

export const roleService = {
  async getRoles() {
    const response = await api.get('/roles')
    return response.data.data
  },

  async getRole(role) {
    const response = await api.get(`/roles/${role}`)
    return response.data.data
  },

  async updateRolePermissions(role, permissions) {
    const response = await api.put(`/roles/${role}/permissions`, { permissions })
    return response.data.data
  },

  async getAllPermissions() {
    const response = await api.get('/permissions')
    return response.data.data
  },
}
