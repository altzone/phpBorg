import api from './api'

/**
 * phpBorg Self-Update API Service
 */
export default {
  /**
   * Check for available updates
   * Returns job_id that needs to be monitored via SSE or polling
   */
  async checkForUpdates() {
    const response = await api.get('/phpborg-update/check')
    return response.data
  },

  /**
   * Get job result/progress
   */
  async getJobResult(jobId) {
    const response = await api.get(`/jobs/${jobId}`)
    return response.data
  },

  /**
   * Get current version info
   */
  async getCurrentVersion() {
    const response = await api.get('/phpborg-update/version')
    return response.data
  },

  /**
   * Get changelog (commits between current and latest)
   */
  async getChangelog() {
    const response = await api.get('/phpborg-update/changelog')
    return response.data
  },

  /**
   * Start update process
   */
  async startUpdate(targetCommit = null) {
    const response = await api.post('/phpborg-update/start', {
      target_commit: targetCommit
    })
    return response.data
  }
}
