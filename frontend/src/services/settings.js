import api from './api'

export const settingsService = {
  async getAllSettings() {
    const response = await api.get('/settings')
    return response.data.data
  },

  async getSettingsByCategory(category) {
    const response = await api.get(`/settings/${category}`)
    return response.data.data
  },

  async updateSettings(settings) {
    const response = await api.put('/settings', { settings })
    return response.data.data
  },

  async updateCategorySettings(category, settings) {
    const response = await api.put(`/settings/${category}`, { settings })
    return response.data.data
  },
}
