import api from './api'

export const authService = {
  /**
   * Login with username and password
   */
  async login(username, password) {
    const response = await api.post('/auth/login', {
      username,
      password,
    })
    return response.data.data
  },

  /**
   * Logout (revoke refresh token)
   */
  async logout() {
    const refreshToken = localStorage.getItem('refresh_token')
    if (refreshToken) {
      try {
        await api.post('/auth/logout', { refresh_token: refreshToken })
      } catch (error) {
        console.error('Logout error:', error)
      }
    }

    // Clear local storage
    localStorage.removeItem('access_token')
    localStorage.removeItem('refresh_token')
    localStorage.removeItem('user')
  },

  /**
   * Logout from all devices
   */
  async logoutAll() {
    await api.post('/auth/logout-all')

    // Clear local storage
    localStorage.removeItem('access_token')
    localStorage.removeItem('refresh_token')
    localStorage.removeItem('user')
  },

  /**
   * Get current user
   */
  async getCurrentUser() {
    const response = await api.get('/auth/me')
    return response.data.data
  },

  /**
   * Refresh access token
   */
  async refreshToken(refreshToken) {
    const response = await api.post('/auth/refresh', {
      refresh_token: refreshToken,
    })
    return response.data.data
  },

  /**
   * Check if user is authenticated
   */
  isAuthenticated() {
    return !!localStorage.getItem('access_token')
  },

  /**
   * Get stored user
   */
  getStoredUser() {
    const userStr = localStorage.getItem('user')
    return userStr ? JSON.parse(userStr) : null
  },
}
