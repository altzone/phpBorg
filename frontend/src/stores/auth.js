import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authService } from '@/services/auth'

export const useAuthStore = defineStore('auth', () => {
  // State
  const user = ref(authService.getStoredUser())
  const accessToken = ref(localStorage.getItem('access_token'))
  const refreshToken = ref(localStorage.getItem('refresh_token'))
  const loading = ref(false)
  const error = ref(null)

  // Getters
  const isAuthenticated = computed(() => !!accessToken.value)
  const isAdmin = computed(() => user.value?.roles?.includes('ROLE_ADMIN') ?? false)
  const isOperator = computed(() => user.value?.roles?.includes('ROLE_OPERATOR') ?? false)
  const isViewer = computed(() => user.value?.roles?.includes('ROLE_VIEWER') ?? false)

  // Actions
  async function login(username, password) {
    try {
      loading.value = true
      error.value = null

      const data = await authService.login(username, password)

      // Store tokens and user
      accessToken.value = data.access_token
      refreshToken.value = data.refresh_token
      user.value = data.user

      localStorage.setItem('access_token', data.access_token)
      localStorage.setItem('refresh_token', data.refresh_token)
      localStorage.setItem('user', JSON.stringify(data.user))

      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Login failed'
      return false
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    try {
      await authService.logout()
    } catch (err) {
      console.error('Logout error:', err)
    } finally {
      // Clear state
      user.value = null
      accessToken.value = null
      refreshToken.value = null
      error.value = null
    }
  }

  async function logoutAll() {
    try {
      await authService.logoutAll()
    } catch (err) {
      console.error('Logout all error:', err)
    } finally {
      // Clear state
      user.value = null
      accessToken.value = null
      refreshToken.value = null
      error.value = null
    }
  }

  async function fetchCurrentUser() {
    try {
      loading.value = true
      const userData = await authService.getCurrentUser()
      user.value = userData
      localStorage.setItem('user', JSON.stringify(userData))
      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to fetch user'
      return false
    } finally {
      loading.value = false
    }
  }

  function hasRole(role) {
    return user.value?.roles?.includes(role) ?? false
  }

  function hasAnyRole(roles) {
    return roles.some((role) => hasRole(role))
  }

  return {
    // State
    user,
    accessToken,
    refreshToken,
    loading,
    error,
    // Getters
    isAuthenticated,
    isAdmin,
    isOperator,
    isViewer,
    // Actions
    login,
    logout,
    logoutAll,
    fetchCurrentUser,
    hasRole,
    hasAnyRole,
  }
})
