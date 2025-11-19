import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useSSEStore } from './sse'

/**
 * Unified Task Bar Store
 * Displays all running jobs + active instant recovery sessions
 * Uses SSE for real-time updates with GET fallback
 * ALWAYS visible (gray when idle, blue when active)
 */
export const useTaskBarStore = defineStore('taskbar', () => {
  const sseStore = useSSEStore()

  // State
  const runningJobs = ref([])
  const activeSessions = ref([])
  const expanded = ref(false) // Collapsed by default
  const loading = ref(false)

  // Subscribe to SSE topics
  let unsubscribeJobs = null
  let unsubscribeInstantRecovery = null

  // System jobs to exclude from taskbar
  const SYSTEM_JOB_TYPES = [
    'server_stats_collect',
    'storage_pool_analyze',
    'capabilities_detection',
    'docker_conflicts_detection'
  ]

  // Computed
  const hasActivity = computed(() => runningJobs.value.length > 0 || activeSessions.value.length > 0)
  const totalCount = computed(() => runningJobs.value.length + activeSessions.value.length)

  function isSystemJob(type) {
    return SYSTEM_JOB_TYPES.includes(type)
  }

  function init() {
    // Only subscribe once
    if (unsubscribeJobs && unsubscribeInstantRecovery) return

    console.log('[TaskBar] Subscribing to SSE topics (jobs + instant_recovery)')

    // Track previous counts to log only on changes
    let lastJobsCount = 0
    let lastSessionsCount = 0

    // Subscribe to jobs topic
    unsubscribeJobs = sseStore.subscribe('jobs', (data) => {
      if (data.jobs) {
        // Filter only running jobs (exclude system jobs)
        const running = data.jobs.filter(job =>
          job.status === 'running' && !isSystemJob(job.type)
        )
        runningJobs.value = running

        // Only log when count changes
        if (running.length !== lastJobsCount) {
          console.log(`[TaskBar] Running jobs: ${running.length}`)
          lastJobsCount = running.length
        }
      }
    })

    // Subscribe to instant_recovery topic
    unsubscribeInstantRecovery = sseStore.subscribe('instant_recovery', (data) => {
      if (data.sessions) {
        activeSessions.value = data.sessions

        // Only log when count changes
        if (data.sessions.length !== lastSessionsCount) {
          console.log(`[TaskBar] Active IR sessions: ${data.sessions.length}`)
          lastSessionsCount = data.sessions.length
        }
      }
    })
  }

  function toggleExpanded() {
    expanded.value = !expanded.value
  }

  function cleanup() {
    if (unsubscribeJobs) {
      unsubscribeJobs()
      unsubscribeJobs = null
    }
    if (unsubscribeInstantRecovery) {
      unsubscribeInstantRecovery()
      unsubscribeInstantRecovery = null
    }
  }

  return {
    // State
    runningJobs,
    activeSessions,
    expanded,
    loading,
    hasActivity,
    totalCount,

    // Actions
    init,
    toggleExpanded,
    cleanup
  }
})
