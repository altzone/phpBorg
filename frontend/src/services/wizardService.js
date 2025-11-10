import apiClient from './api'

export const wizardService = {
  // Get server capabilities (snapshots, databases, filesystem)
  async getCapabilities(serverId) {
    const response = await apiClient.get(`/backup-wizard/capabilities/${serverId}`)
    return response.data
  },

  // Auto-detect MySQL credentials
  async detectMySQL(serverId) {
    const response = await apiClient.post('/backup-wizard/detect-mysql', {
      server_id: serverId
    })
    return response.data
  },

  // Test database connection
  async testDatabaseConnection(data) {
    const response = await apiClient.post('/backup-wizard/test-db-connection', data)
    return response.data
  },

  // Get backup templates
  async getTemplates() {
    const response = await apiClient.get('/backup-wizard/templates')
    return response.data
  },

  // Create complete backup chain (source, repository, job, schedule)
  async createBackupChain(data) {
    const response = await apiClient.post('/backup-wizard/create-backup-chain', data)
    return response.data
  }
}