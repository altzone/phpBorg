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
  /**
   * Merge an incoming (possibly partial or transiently-empty) live progress payload
   * into the last known one. A live SSE update must NEVER blank fields or reset the
   * counters to 0 — that made the UI flash and reflow. Cumulative counters
   * (files/sizes) are monotonic within a running job, so we keep the previous value
   * when the incoming one is missing, zero, or smaller.
   */
  function mergeProgress(existing, incoming) {
    const merged = { ...(existing || {}), ...(incoming || {}) }
    const cumulative = ['files_count', 'original_size', 'compressed_size', 'deduplicated_size']
    for (const key of cumulative) {
      const inc = Number(incoming?.[key])
      const prev = Number(existing?.[key])
      const prevOk = Number.isFinite(prev) && prev > 0
      if (!Number.isFinite(inc) || inc <= 0) {
        if (prevOk) merged[key] = existing[key]   // don't blank / reset to 0
      } else if (prevOk && prev > inc) {
        merged[key] = existing[key]               // never go backwards
      }
    }
    return merged
  }

  async function fetchProgressForRunningJobs() {
    const running = jobs.value.filter(j => j.status === 'running')

    // Fetch progress for all running jobs in parallel
    await Promise.all(
      running.map(async (job) => {
        try {
          const progress = await jobService.getProgress(job.id)
          if (progress && (progress.files_count > 0 || progress.original_size > 0)) {
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

            progressInfo.value.set(job.id, mergeProgress(progressInfo.value.get(job.id), progress))
          }
          // If no progress or empty progress, keep the last known values (don't delete)
        } catch (err) {
          console.error(`Failed to fetch progress for job ${job.id}:`, err)
          // On error, keep last known progress (don't delete)
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
    // Merge into the last known progress so a partial/empty live SSE event never blanks
    // fields or resets counters to 0 (prevents the flash + layout reflow).
    const merged = mergeProgress(progressInfo.value.get(jobId), progress)

    // Get previous progress for rate calculation
    const prev = previousProgress.value.get(jobId)

    if (prev && merged.timestamp && merged.original_size) {
      // Calculate rate (bytes per second)
      const deltaBytes = merged.original_size - prev.bytes
      const deltaTime = merged.timestamp - prev.timestamp

      if (deltaTime > 0 && deltaBytes > 0) {
        // Add transfer rate to progress data (bytes/sec)
        merged.transfer_rate = deltaBytes / deltaTime
      }
    }

    // Store current as previous for next iteration
    if (merged.timestamp && merged.original_size) {
      previousProgress.value.set(jobId, {
        bytes: merged.original_size,
        timestamp: merged.timestamp
      })
    }

    progressInfo.value.set(jobId, merged)
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
