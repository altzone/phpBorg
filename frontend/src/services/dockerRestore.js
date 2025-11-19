import api from './api'
import { jobService } from './jobs'

/**
 * Poll job until completed
 * @param {number} jobId
 * @param {number} maxAttempts - Maximum polling attempts (default 30)
 * @param {number} interval - Polling interval in ms (default 1000)
 * @returns {Promise<Object>} Job result
 */
async function pollJobUntilComplete(jobId, maxAttempts = 30, interval = 1000) {
  for (let attempt = 0; attempt < maxAttempts; attempt++) {
    const job = await jobService.get(jobId)

    if (job.status === 'completed') {
      // Parse output JSON (stored in job.output, not job.result)
      try {
        return JSON.parse(job.output || job.result || '{}')
      } catch (e) {
        console.error('Failed to parse job output:', e)
        return {}
      }
    }

    if (job.status === 'failed') {
      throw new Error(job.error || job.result || 'Job failed')
    }

    // Wait before next poll
    await new Promise(resolve => setTimeout(resolve, interval))
  }

  throw new Error(`Job ${jobId} timeout after ${maxAttempts} attempts`)
}

/**
 * Docker Restore API Service
 */
export default {
  /**
   * Analyze Docker archive content
   * @param {number} archiveId
   * @returns {Promise}
   */
  async analyzeArchive(archiveId) {
    const response = await api.post('/docker-restore/analyze', { archive_id: archiveId })
    return response.data.data?.analysis || response.data.analysis || {
      volumes: [],
      compose_projects: [],
      configs: [],
      containers: []
    }
  },

  /**
   * Detect conflicts with running containers
   * @param {number} serverId
   * @param {Object} selectedItems - {volumes: [], projects: [], configs: []}
   * @returns {Promise<Object>} Conflicts data {conflicts, must_stop, disk_space_ok, warnings}
   */
  async detectConflicts(serverId, selectedItems) {
    // Start conflict detection job
    const response = await api.post('/docker-restore/detect-conflicts', {
      server_id: serverId,
      selected_items: selectedItems
    })

    const jobId = response.data.data?.job_id || response.data.job_id

    if (!jobId) {
      throw new Error('No job_id returned from conflict detection')
    }

    // Poll job until completed and return conflicts data
    return await pollJobUntilComplete(jobId, 30, 1000)
  },

  /**
   * Generate restore script preview
   * @param {number} operationId
   * @param {boolean} advanced - Advanced mode (full script) or explained mode
   * @param {Object} config - Additional config for script generation
   * @returns {Promise}
   */
  previewScript(operationId, advanced = false, config = {}) {
    return api.post('/docker-restore/preview-script', {
      operation_id: operationId,
      advanced,
      ...config
    })
  },

  /**
   * Start Docker restore operation
   * @param {Object} config - Complete restore configuration
   * @returns {Promise}
   */
  startRestore(config) {
    return api.post('/docker-restore/start', config)
  },

  /**
   * Get restore operation status
   * @param {number} operationId
   * @returns {Promise}
   */
  getOperation(operationId) {
    return api.get(`/docker-restore/${operationId}`)
  },

  /**
   * List all restore operations
   * @param {Object} filters - {server_id, source_type}
   * @returns {Promise}
   */
  listOperations(filters = {}) {
    return api.get('/docker-restore', { params: filters })
  },

  /**
   * Rollback a restore operation
   * @param {number} operationId
   * @returns {Promise}
   */
  rollback(operationId) {
    return api.post(`/docker-restore/${operationId}/rollback`)
  }
}
