import api from './api'

export const instantRecoveryService = {
  /**
   * List all instant recovery sessions
   */
  async list() {
    const response = await api.get('/instant-recovery')
    return response.data.data?.sessions || response.data.sessions || []
  },

  /**
   * List active instant recovery sessions
   */
  async listActive() {
    const response = await api.get('/instant-recovery/active')
    return response.data.data?.sessions || response.data.sessions || []
  },

  /**
   * Get instant recovery session by ID
   */
  async get(id) {
    const response = await api.get(`/instant-recovery/${id}`)
    return response.data.data || response.data
  },

  /**
   * Start instant recovery session (creates a job)
   * @param {number} archiveId - Archive ID
   * @param {string} deploymentLocation - 'remote' or 'local'
   * @returns {Promise<{job_id: number, archive_id: number, deployment_location: string}>}
   */
  async start(archiveId, deploymentLocation = 'remote') {
    const response = await api.post('/instant-recovery/start', {
      archive_id: archiveId,
      deployment_location: deploymentLocation
    })
    return response.data.data || response.data
  },

  /**
   * Stop instant recovery session
   */
  async stop(id) {
    const response = await api.post(`/instant-recovery/stop/${id}`)
    return response.data
  },

  /**
   * Delete instant recovery session
   */
  async delete(id) {
    const response = await api.delete(`/instant-recovery/${id}`)
    return response.data
  }
}
