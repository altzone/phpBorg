import { defineStore } from 'pinia'
import { ref, watch } from 'vue'
import { instantRecoveryService } from '@/services/instantRecovery'
import { useSSEStore } from './sse'

export const useInstantRecoveryStore = defineStore('instantRecovery', () => {
  const sseStore = useSSEStore()

  // State
  const activeSessions = ref([])
  const taskBarVisible = ref(false)
  const taskBarExpanded = ref(true)
  const loading = ref(false)

  // Subscribe to SSE instant_recovery topic
  let unsubscribe = null

  function init() {
    // Only subscribe once
    if (unsubscribe) return

    console.log('[InstantRecovery] Subscribing to SSE instant_recovery topic')

    unsubscribe = sseStore.subscribe('instant_recovery', (data) => {
      console.log('[InstantRecovery] SSE update received:', data)

      if (data.sessions) {
        activeSessions.value = data.sessions

        // Auto-show task bar if there are active sessions
        if (data.sessions.length > 0 && !taskBarVisible.value) {
          taskBarVisible.value = true
        }
      }
    })
  }

  // Actions
  async function fetchActiveSessions() {
    try {
      loading.value = true
      const sessions = await instantRecoveryService.listActive()
      activeSessions.value = sessions

      // Auto-show task bar if there are active sessions
      if (sessions.length > 0) {
        taskBarVisible.value = true
      }
    } catch (error) {
      console.error('Failed to fetch active sessions:', error)
    } finally {
      loading.value = false
    }
  }

  function startPolling(intervalMs = 10000) {
    // Deprecated: Now using SSE, but keep for backward compatibility
    console.warn('[InstantRecovery] startPolling is deprecated, using SSE instead')
    init()
    fetchActiveSessions() // Initial fetch
  }

  function stopPolling() {
    // Deprecated: Now using SSE, but keep for backward compatibility
    console.warn('[InstantRecovery] stopPolling is deprecated')
    if (unsubscribe) {
      unsubscribe()
      unsubscribe = null
    }
  }

  function toggleTaskBar() {
    taskBarVisible.value = !taskBarVisible.value
  }

  function toggleExpanded() {
    taskBarExpanded.value = !taskBarExpanded.value
  }

  function showTaskBar() {
    taskBarVisible.value = true
  }

  function hideTaskBar() {
    taskBarVisible.value = false
  }

  async function stopSession(sessionId) {
    try {
      await instantRecoveryService.stop(sessionId)
      // Refresh sessions after stop
      await fetchActiveSessions()
      return true
    } catch (error) {
      console.error('Failed to stop session:', error)
      throw error
    }
  }

  return {
    // State
    activeSessions,
    taskBarVisible,
    taskBarExpanded,
    loading,

    // Actions
    init,
    fetchActiveSessions,
    startPolling,
    stopPolling,
    toggleTaskBar,
    toggleExpanded,
    showTaskBar,
    hideTaskBar,
    stopSession
  }
})
