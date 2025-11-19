import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import { useAuthStore } from './auth'

/**
 * Global SSE Store
 * Manages a single EventSource connection shared across all views
 *
 * Topics:
 * - workers: Worker status updates
 * - jobs: Job progress and status
 * - backups: Backup creation/deletion events
 * - stats: Server/pool statistics
 */
export const useSSEStore = defineStore('sse', () => {
  const authStore = useAuthStore()

  // State
  const eventSource = ref(null)
  const connected = ref(false)
  const connectionType = ref('disconnected') // 'sse', 'polling', 'disconnected', 'error'
  const reconnectAttempts = ref(0)
  const maxReconnectAttempts = 3
  const lastUsedToken = ref(null)
  const reconnectTimeout = ref(null)
  const pollingInterval = ref(null)

  // Subscribers by topic
  const subscribers = ref({
    workers: [],
    jobs: [],
    backups: [],
    stats: [],
    all: []
  })

  // Getters
  const isConnected = computed(() => connected.value)
  const isSSE = computed(() => connectionType.value === 'sse')
  const isPolling = computed(() => connectionType.value === 'polling')

  /**
   * Subscribe to SSE events for a specific topic
   * @param {string} topic - 'workers', 'jobs', 'backups', 'stats', 'all'
   * @param {Function} callback - Called with event data
   * @returns {Function} Unsubscribe function
   */
  function subscribe(topic, callback) {
    if (!subscribers.value[topic]) {
      subscribers.value[topic] = []
    }

    subscribers.value[topic].push(callback)
    console.log(`[SSE] Subscribed to topic: ${topic}`)

    // Return unsubscribe function
    return () => {
      const index = subscribers.value[topic].indexOf(callback)
      if (index > -1) {
        subscribers.value[topic].splice(index, 1)
        console.log(`[SSE] Unsubscribed from topic: ${topic}`)
      }
    }
  }

  /**
   * Notify all subscribers of a topic
   */
  function notifySubscribers(topic, data) {
    // Notify topic-specific subscribers
    if (subscribers.value[topic]) {
      subscribers.value[topic].forEach(callback => callback(data))
    }

    // Notify 'all' subscribers
    if (subscribers.value.all) {
      subscribers.value.all.forEach(callback => callback({ topic, data }))
    }
  }

  /**
   * Setup SSE connection
   */
  function setupSSE() {
    try {
      const token = authStore.accessToken
      if (!token) {
        console.error('[SSE] No access token available')
        return
      }

      // If token changed, reset reconnect attempts
      if (lastUsedToken.value !== token) {
        console.log('[SSE] New token detected, resetting reconnect attempts')
        reconnectAttempts.value = 0
        lastUsedToken.value = token
      }

      // Check reconnect attempts limit
      if (reconnectAttempts.value >= maxReconnectAttempts) {
        console.warn('[SSE] Max reconnect attempts reached, stopping')
        connectionType.value = 'error'
        return
      }

      // Clean up existing connection
      if (eventSource.value) {
        eventSource.value.close()
        eventSource.value = null
      }

      console.log(`[SSE] Connecting (attempt ${reconnectAttempts.value + 1}/${maxReconnectAttempts})...`)

      // Create new EventSource with all topics
      eventSource.value = new EventSource(`/api/sse/stream?token=${token}`)

      // Workers updates
      eventSource.value.addEventListener('workers', (event) => {
        const data = JSON.parse(event.data)
        notifySubscribers('workers', data)
        connected.value = true
        connectionType.value = 'sse'
        reconnectAttempts.value = 0
      })

      // Jobs updates
      eventSource.value.addEventListener('jobs', (event) => {
        const data = JSON.parse(event.data)
        notifySubscribers('jobs', data)
        connected.value = true
        connectionType.value = 'sse'
        reconnectAttempts.value = 0
      })

      // Backups updates
      eventSource.value.addEventListener('backups', (event) => {
        const data = JSON.parse(event.data)
        notifySubscribers('backups', data)
        connected.value = true
        connectionType.value = 'sse'
        reconnectAttempts.value = 0
      })

      // Stats updates
      eventSource.value.addEventListener('stats', (event) => {
        const data = JSON.parse(event.data)
        notifySubscribers('stats', data)
        connected.value = true
        connectionType.value = 'sse'
        reconnectAttempts.value = 0
      })

      // Heartbeat
      eventSource.value.addEventListener('heartbeat', () => {
        connected.value = true
        connectionType.value = 'sse'
        reconnectAttempts.value = 0
      })

      // Error handling
      eventSource.value.onerror = (error) => {
        console.error('[SSE] Connection error:', error)

        reconnectAttempts.value++
        connected.value = false

        // Close immediately to prevent auto-reconnect with old token
        if (eventSource.value) {
          eventSource.value.close()
          eventSource.value = null
        }

        // Clear any pending reconnect
        if (reconnectTimeout.value) {
          clearTimeout(reconnectTimeout.value)
          reconnectTimeout.value = null
        }

        // Try to reconnect
        if (reconnectAttempts.value < maxReconnectAttempts) {
          console.log(`[SSE] Reconnecting in 3s... (${reconnectAttempts.value}/${maxReconnectAttempts})`)
          reconnectTimeout.value = setTimeout(() => {
            const currentToken = authStore.accessToken
            if (currentToken !== lastUsedToken.value) {
              console.log('[SSE] Token was refreshed, reconnecting')
            }
            setupSSE()
          }, 3000)
        } else {
          console.warn('[SSE] Max reconnect attempts reached, switching to polling')
          setupPolling()
        }
      }

      // Open event
      eventSource.value.addEventListener('open', () => {
        console.log('[SSE] Connection established')
        connected.value = true
        connectionType.value = 'sse'
      })

    } catch (error) {
      console.error('[SSE] Setup failed:', error)
      connectionType.value = 'error'
    }
  }

  /**
   * Disconnect SSE
   */
  function disconnect() {
    console.log('[SSE] Disconnecting...')

    if (reconnectTimeout.value) {
      clearTimeout(reconnectTimeout.value)
      reconnectTimeout.value = null
    }

    if (eventSource.value) {
      eventSource.value.close()
      eventSource.value = null
    }

    connected.value = false
    connectionType.value = 'disconnected'
  }

  /**
   * Setup polling fallback (when SSE fails)
   */
  async function setupPolling() {
    console.log('[SSE] Setting up polling fallback...')

    // Clear any existing polling
    if (pollingInterval.value) {
      clearInterval(pollingInterval.value)
      pollingInterval.value = null
    }

    connectionType.value = 'polling'
    connected.value = true

    let pollCount = 0

    // Poll every 5 seconds
    const pollData = async () => {
      try {
        // Import API services dynamically to avoid circular deps
        const { default: api } = await import('@/services/api')

        // Fetch jobs
        const jobsResponse = await api.get('/jobs')
        if (jobsResponse.data?.data?.jobs) {
          notifySubscribers('jobs', {
            jobs: jobsResponse.data.data.jobs,
            stats: jobsResponse.data.data.stats
          })
        }

        // Fetch workers
        const workersResponse = await api.get('/workers')
        if (workersResponse.data?.data?.workers) {
          notifySubscribers('workers', {
            workers: workersResponse.data.data.workers
          })
        }

        // Every 30 seconds (6 polls), try to reconnect to SSE
        pollCount++
        if (pollCount >= 6) {
          console.log('[SSE] Attempting to reconnect to SSE from polling mode...')
          pollCount = 0
          reconnectAttempts.value = 0 // Reset attempts for fresh try

          // Stop polling temporarily
          if (pollingInterval.value) {
            clearInterval(pollingInterval.value)
            pollingInterval.value = null
          }

          // Try SSE again
          setupSSE()
        }

      } catch (error) {
        console.error('[SSE] Polling error:', error)
      }
    }

    // Initial poll
    await pollData()

    // Setup interval
    pollingInterval.value = setInterval(pollData, 5000)
  }

  /**
   * Reconnect (after token refresh)
   */
  function reconnect() {
    console.log('[SSE] Manual reconnect requested')
    disconnect()

    // Stop polling if active
    if (pollingInterval.value) {
      clearInterval(pollingInterval.value)
      pollingInterval.value = null
    }

    // Reset reconnect attempts for new try
    reconnectAttempts.value = 0

    setupSSE()
  }

  /**
   * Disconnect SSE and polling
   */
  function disconnect() {
    console.log('[SSE] Disconnecting...')

    if (reconnectTimeout.value) {
      clearTimeout(reconnectTimeout.value)
      reconnectTimeout.value = null
    }

    if (eventSource.value) {
      eventSource.value.close()
      eventSource.value = null
    }

    if (pollingInterval.value) {
      clearInterval(pollingInterval.value)
      pollingInterval.value = null
    }

    connected.value = false
    connectionType.value = 'disconnected'
  }

  return {
    // State
    connected,
    connectionType,
    isConnected,
    isSSE,
    isPolling,

    // Methods
    subscribe,
    setupSSE,
    setupPolling,
    disconnect,
    reconnect
  }
})
