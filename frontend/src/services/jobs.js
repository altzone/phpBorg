import api from './api'

export const jobService = {
  /**
   * Get list of jobs
   * @param {Object} params - Query parameters (limit, status, queue)
   * @returns {Promise<Array>}
   */
  async list(params = {}) {
    const response = await api.get('/jobs', { params })
    return response.data.jobs
  },

  /**
   * Get job statistics
   * @returns {Promise<Object>}
   */
  async stats() {
    const response = await api.get('/jobs/stats')
    return response.data.stats
  },

  /**
   * Get job details
   * @param {number} id - Job ID
   * @returns {Promise<Object>}
   */
  async get(id) {
    const response = await api.get(`/jobs/${id}`)
    return response.data.job
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
    return response.data.new_job_id
  },
}
