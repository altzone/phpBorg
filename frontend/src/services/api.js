import axios from 'axios'
import router from '@/router'

// Create axios instance
const api = axios.create({
  baseURL: '/api',
  headers: {
    'Content-Type': 'application/json',
  },
})

// Request interceptor - Add JWT token to requests
api.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('access_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// --- Single-flight token refresh -------------------------------------------------
// The backend ROTATES refresh tokens (the old one is revoked on first use). On a page
// reload with an expired access token, MANY requests fire concurrently and all get a
// 401; previously each one called /auth/refresh with the SAME old token — the first
// won, every other refresh failed on the now-revoked token, wiped the session and
// bounced the user to the login page. Systematic logout on refresh.
// All concurrent 401s now share ONE refresh promise.
let refreshInFlight = null

export function refreshTokensSingleFlight() {
  if (!refreshInFlight) {
    refreshInFlight = (async () => {
      const refreshToken = localStorage.getItem('refresh_token')
      if (!refreshToken) {
        throw new Error('No refresh token')
      }

      const response = await axios.post('/api/auth/refresh', {
        refresh_token: refreshToken,
      })

      const { access_token, refresh_token: newRefreshToken } = response.data.data
      localStorage.setItem('access_token', access_token)
      localStorage.setItem('refresh_token', newRefreshToken)
      return access_token
    })().finally(() => {
      refreshInFlight = null
    })
  }
  return refreshInFlight
}

// Clear the session and go to the login page, remembering where the user was so the
// login can send them back (session expiry must ALWAYS land on the login page).
function redirectToLogin() {
  localStorage.removeItem('access_token')
  localStorage.removeItem('refresh_token')
  localStorage.removeItem('user')

  const current = router.currentRoute.value
  if (current.name !== 'login') {
    router.push({ name: 'login', query: { redirect: current.fullPath } })
  }
}

// Response interceptor - Handle token refresh and errors
api.interceptors.response.use(
  (response) => {
    return response
  },
  async (error) => {
    const originalRequest = error.config

    // If 401 and not already retried
    if (error.response?.status === 401 && !originalRequest._retry) {
      originalRequest._retry = true

      try {
        // All concurrent 401s wait for the SAME refresh (single-flight)
        const accessToken = await refreshTokensSingleFlight()

        // Retry original request with new token
        originalRequest.headers.Authorization = `Bearer ${accessToken}`
        return api(originalRequest)
      } catch (refreshError) {
        // Refresh failed => the session is really over: back to login (with return path)
        redirectToLogin()
        return Promise.reject(refreshError)
      }
    }

    return Promise.reject(error)
  }
)

export default api
