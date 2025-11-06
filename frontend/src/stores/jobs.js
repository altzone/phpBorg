import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { jobService } from '@/services/jobs'

export const useJobStore = defineStore('jobs', () => {
  // State
  const jobs = ref([])
  const stats = ref({
    total: 0,
    pending: 0,
    running: 0,
    completed: 0,
    failed: 0,
    cancelled: 0
  })
  const loading = ref(false)
  const error = ref(null)

  // Getters
  const pendingJobs = computed(() => jobs.value.filter(j => j.status === 'pending'))
  const runningJobs = computed(() => jobs.value.filter(j => j.status === 'running'))
  const completedJobs = computed(() => jobs.value.filter(j => j.status === 'completed'))
  const failedJobs = computed(() => jobs.value.filter(j => j.status === 'failed'))

  // Actions
  async function fetchJobs(params = {}) {
    try {
      loading.value = true
      error.value = null
      const result = await jobService.list(params)
      jobs.value = result || []
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to load jobs'
      console.error('Fetch jobs error:', err)
      jobs.value = []
    } finally {
      loading.value = false
    }
  }

  async function fetchStats() {
    try {
      const result = await jobService.stats()
      stats.value = result || {
        total: 0,
        pending: 0,
        running: 0,
        completed: 0,
        failed: 0,
        cancelled: 0
      }
    } catch (err) {
      console.error('Fetch stats error:', err)
      // Keep default stats values on error
    }
  }

  async function cancelJob(id) {
    try {
      error.value = null
      await jobService.cancel(id)

      // Update local job status
      const job = jobs.value.find(j => j.id === id)
      if (job) {
        job.status = 'cancelled'
      }

      await fetchStats()
      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to cancel job'
      console.error('Cancel job error:', err)
      return false
    }
  }

  async function retryJob(id) {
    try {
      error.value = null
      const newJobId = await jobService.retry(id)

      // Refresh jobs list to show new job
      await fetchJobs()
      await fetchStats()

      return newJobId
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to retry job'
      console.error('Retry job error:', err)
      return null
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    // State
    jobs,
    stats,
    loading,
    error,

    // Getters
    pendingJobs,
    runningJobs,
    completedJobs,
    failedJobs,

    // Actions
    fetchJobs,
    fetchStats,
    cancelJob,
    retryJob,
    clearError,
  }
})
