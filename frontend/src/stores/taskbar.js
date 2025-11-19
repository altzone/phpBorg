import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { useSSEStore } from './sse'

/**
 * Unified Task Bar Store
 * Displays all running jobs in a Windows-style taskbar
 * Uses SSE for real-time updates with GET fallback
 */
export const useTaskBarStore = defineStore('taskbar', () => {
  const sseStore = useSSEStore()

  // State
  const runningJobs = ref([])
  const expanded = ref(true)
  const loading = ref(false)

  // Subscribe to SSE jobs topic
  let unsubscribe = null

  // System jobs to exclude from taskbar
  const SYSTEM_JOB_TYPES = [
    'server_stats_collect',
    'storage_pool_analyze',
    'capabilities_detection',
    'docker_conflicts_detection'
  ]

  // Computed
  const visible = computed(() => runningJobs.value.length > 0)
  const runningCount = computed(() => runningJobs.value.length)

  function isSystemJob(type) {
    return SYSTEM_JOB_TYPES.includes(type)
  }

  function init() {
    // Only subscribe once
    if (unsubscribe) return

    console.log('[TaskBar] Subscribing to SSE jobs topic')

    unsubscribe = sseStore.subscribe('jobs', (data) => {
      if (data.jobs) {
        // Filter only running jobs (exclude system jobs)
        const running = data.jobs.filter(job =>
          job.status === 'running' && !isSystemJob(job.type)
        )
        runningJobs.value = running

        console.log(`[TaskBar] SSE update: ${running.length} running jobs (system jobs excluded)`)
      }
    })
  }

  function toggleExpanded() {
    expanded.value = !expanded.value
  }

  function cleanup() {
    if (unsubscribe) {
      unsubscribe()
      unsubscribe = null
    }
  }

  return {
    // State
    runningJobs,
    expanded,
    loading,
    visible,
    runningCount,

    // Actions
    init,
    toggleExpanded,
    cleanup
  }
})
