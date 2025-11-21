import api from './api'

/**
 * Setup wizard service
 */
export default {
  /**
   * Check if initial setup is required
   */
  async getStatus() {
    const response = await api.get('/setup/status')
    return response.data.data
  },

  /**
   * Detect available network interfaces and IPs
   */
  async detectNetwork() {
    const response = await api.get('/setup/detect-network')
    return response.data.data
  },

  /**
   * Complete initial setup
   */
  async complete(settings) {
    const response = await api.post('/setup/complete', settings)
    return response.data
  },
}
