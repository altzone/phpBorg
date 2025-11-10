import api from './api'

export const backupJobsService = {
  async getBackupJobs() {
    const response = await api.get('/backup-jobs')
    return response.data.data
  },

  async getBackupJob(id) {
    const response = await api.get(`/backup-jobs/${id}`)
    return response.data.data
  },

  async getJobsByRepository(repositoryId) {
    const response = await api.get(`/repositories/${repositoryId}/backup-jobs`)
    return response.data.data
  },

  async createBackupJob(jobData) {
    const response = await api.post('/backup-jobs', jobData)
    return response.data.data
  },

  async updateBackupJob(id, jobData) {
    const response = await api.put(`/backup-jobs/${id}`, jobData)
    return response.data.data
  },

  async toggleBackupJob(id) {
    const response = await api.post(`/backup-jobs/${id}/toggle`)
    return response.data
  },

  async deleteBackupJob(id) {
    const response = await api.delete(`/backup-jobs/${id}`)
    return response.data
  },

  async runBackupJob(id) {
    const response = await api.post(`/backup-jobs/${id}/run`)
    return response.data
  },
}
