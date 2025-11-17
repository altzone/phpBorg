import { defineStore } from 'pinia'
import { ref } from 'vue'
import { instantRecoveryService } from '@/services/instantRecovery'

export const useInstantRecoveryStore = defineStore('instantRecovery', () => {
  // State
  const activeSessions = ref([])
  const taskBarVisible = ref(false)
  const taskBarExpanded = ref(true)
  const pollingInterval = ref(null)
  const loading = ref(false)

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
    if (pollingInterval.value) {
      return // Already polling
    }

    // Fetch immediately
    fetchActiveSessions()

    // Then poll every intervalMs
    pollingInterval.value = setInterval(() => {
      fetchActiveSessions()
    }, intervalMs)
  }

  function stopPolling() {
    if (pollingInterval.value) {
      clearInterval(pollingInterval.value)
      pollingInterval.value = null
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
