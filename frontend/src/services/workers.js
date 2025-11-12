import api from './api'

export const workerService = {
  /**
   * Get all workers status
   */
  async getWorkers() {
    const response = await api.get('/workers')
    return response.data.data
  },

  /**
   * Get specific worker status
   */
  async getWorker(name) {
    const response = await api.get(`/workers/${name}`)
    return response.data.data
  },

  /**
   * Start a worker
   */
  async startWorker(name) {
    const response = await api.post(`/workers/${name}/start`)
    return response.data.data
  },

  /**
   * Stop a worker
   */
  async stopWorker(name) {
    const response = await api.post(`/workers/${name}/stop`)
    return response.data.data
  },

  /**
   * Restart a worker
   */
  async restartWorker(name) {
    const response = await api.post(`/workers/${name}/restart`)
    return response.data.data
  },

  /**
   * Get worker logs
   * @param {string} name - Worker name
   * @param {number} lines - Number of lines to fetch (default 100)
   * @param {string} since - Time range (default '1 hour ago')
   */
  async getWorkerLogs(name, lines = 100, since = '1 hour ago') {
    const response = await api.get(`/workers/${name}/logs`, {
      params: { lines, since },
    })
    return response.data.data
  },
}
