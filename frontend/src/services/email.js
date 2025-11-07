import api from './api'

export const emailService = {
  async sendTestEmail(toEmail) {
    const response = await api.post('/email/test', { to: toEmail })
    return response.data
  },
}
