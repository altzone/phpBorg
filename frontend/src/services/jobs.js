import api from './api'

export const jobService = {
  /**
   * Get list of jobs
   * @param {Object} params - Query parameters (limit, status, queue)
   * @returns {Promise<Array>}
   */
  async list(params = {}) {
    const response = await api.get('/jobs', { params })
    // API returns { success: true, data: { jobs: [...] } }
    return response.data.data?.jobs || response.data.jobs || []
  },

  /**
   * Get job statistics
   * @returns {Promise<Object>}
   */
  async stats() {
    const response = await api.get('/jobs/stats')
    // API returns { success: true, data: { stats: {...} } }
    return response.data.data?.stats || response.data.stats || {
      total: 0,
      pending: 0,
      running: 0,
      completed: 0,
      failed: 0,
      cancelled: 0
    }
  },

  /**
   * Get job details
   * @param {number} id - Job ID
   * @returns {Promise<Object>}
   */
  async get(id) {
    const response = await api.get(`/jobs/${id}`)
    return response.data.data?.job || response.data.job
  },

  /**
   * Cancel a job
   * @param {number} id - Job ID
   * @returns {Promise<boolean>}
   */
  async cancel(id) {
    const response = await api.post(`/jobs/${id}/cancel`)
    return response.data.success
  },

  /**
   * Retry a failed job
   * @param {number} id - Job ID
   * @returns {Promise<number>} New job ID
   */
  async retry(id) {
    const response = await api.post(`/jobs/${id}/retry`)
    return response.data.data?.new_job_id || response.data.new_job_id
  },
}
