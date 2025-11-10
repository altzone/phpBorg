import apiClient from './api'

export const wizardService = {
  // Get server capabilities - returns a job ID for polling
  async getCapabilities(serverId) {
    const response = await apiClient.get(`/backup-wizard/capabilities/${serverId}`)
    return response.data
  },

  // Check job status and get results
  async getJobStatus(jobId) {
    const response = await apiClient.get(`/backup-wizard/job-status/${jobId}`)
    return response.data
  },

  // Get capabilities with automatic polling
  async getCapabilitiesWithPolling(serverId, progressCallback = null) {
    // Step 1: Create detection job
    const jobResponse = await apiClient.get(`/backup-wizard/capabilities/${serverId}`)
    
    if (!jobResponse.data.success || !jobResponse.data.data?.job_id) {
      throw new Error('Failed to create detection job')
    }
    
    const jobId = jobResponse.data.data.job_id
    
    // Step 2: Poll for job completion
    let attempts = 0
    const maxAttempts = 30 // 30 seconds max
    
    while (attempts < maxAttempts) {
      await new Promise(resolve => setTimeout(resolve, 1000)) // Wait 1 second
      
      const statusResponse = await apiClient.get(`/backup-wizard/job-status/${jobId}`)
      
      if (statusResponse.data.success && statusResponse.data.data) {
        const jobData = statusResponse.data.data
        
        if (progressCallback && jobData.progress_message) {
          progressCallback(jobData.progress_message, jobData.progress)
        }
        
        if (jobData.status === 'completed' && jobData.data) {
          return { success: true, data: jobData.data }
        } else if (jobData.status === 'failed') {
          throw new Error(jobData.error || 'Detection failed')
        }
      }
      
      attempts++
    }
    
    throw new Error('Detection timed out')
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