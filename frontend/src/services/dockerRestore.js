import api from './api'

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
   * @returns {Promise}
   */
  detectConflicts(serverId, selectedItems) {
    return api.post('/docker-restore/detect-conflicts', {
      server_id: serverId,
      selected_items: selectedItems
    })
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
