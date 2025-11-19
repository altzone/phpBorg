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

  // Real-time progress info for running jobs (jobId => progressData)
  const progressInfo = ref(new Map())

  // Previous progress snapshots for rate calculation (jobId => { bytes, timestamp })
  const previousProgress = ref(new Map())

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

  /**
   * Fetch real-time progress for running jobs
   * This should be called on an interval for jobs with status 'running'
   */
  async function fetchProgressForRunningJobs() {
    const running = jobs.value.filter(j => j.status === 'running')

    // Fetch progress for all running jobs in parallel
    await Promise.all(
      running.map(async (job) => {
        try {
          const progress = await jobService.getProgress(job.id)
          if (progress) {
            // Get previous progress for rate calculation
            const prev = previousProgress.value.get(job.id)

            if (prev && progress.timestamp && progress.original_size) {
              // Calculate rate (bytes per second)
              const deltaBytes = progress.original_size - prev.bytes
              const deltaTime = progress.timestamp - prev.timestamp

              if (deltaTime > 0 && deltaBytes > 0) {
                // Add transfer rate to progress data (bytes/sec)
                progress.transfer_rate = deltaBytes / deltaTime
              }
            }

            // Store current as previous for next iteration
            if (progress.timestamp && progress.original_size) {
              previousProgress.value.set(job.id, {
                bytes: progress.original_size,
                timestamp: progress.timestamp
              })
            }

            progressInfo.value.set(job.id, progress)
          } else {
            progressInfo.value.delete(job.id)
            previousProgress.value.delete(job.id)
          }
        } catch (err) {
          console.error(`Failed to fetch progress for job ${job.id}:`, err)
          progressInfo.value.delete(job.id)
          previousProgress.value.delete(job.id)
        }
      })
    )
  }

  /**
   * Get progress info for a specific job
   * @param {number} jobId - Job ID
   * @returns {Object|null} Progress data or null
   */
  function getProgressInfo(jobId) {
    return progressInfo.value.get(jobId) || null
  }

  /**
   * Set progress info for a specific job (used by SSE)
   * @param {number} jobId - Job ID
   * @param {Object} progress - Progress data
   */
  function setProgressInfo(jobId, progress) {
    // Get previous progress for rate calculation
    const prev = previousProgress.value.get(jobId)

    if (prev && progress.timestamp && progress.original_size) {
      // Calculate rate (bytes per second)
      const deltaBytes = progress.original_size - prev.bytes
      const deltaTime = progress.timestamp - prev.timestamp

      if (deltaTime > 0 && deltaBytes > 0) {
        // Add transfer rate to progress data (bytes/sec)
        progress.transfer_rate = deltaBytes / deltaTime
      }
    }

    // Store current as previous for next iteration
    if (progress.timestamp && progress.original_size) {
      previousProgress.value.set(jobId, {
        bytes: progress.original_size,
        timestamp: progress.timestamp
      })
    }

    progressInfo.value.set(jobId, progress)
  }

  return {
    // State
    jobs,
    stats,
    loading,
    error,
    progressInfo,

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
    fetchProgressForRunningJobs,
    getProgressInfo,
    setProgressInfo,
  }
})
