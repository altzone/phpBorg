import { defineStore } from 'pinia'
import { ref } from 'vue'
import { workerService } from '@/services/workers'

export const useWorkerStore = defineStore('workers', () => {
  // State
  const workers = ref([])
  const currentWorker = ref(null)
  const logs = ref('')
  const loading = ref(false)
  const error = ref(null)

  // Actions
  async function fetchWorkers() {
    try {
      loading.value = true
      error.value = null
      const data = await workerService.getWorkers()
      workers.value = data.workers || []
      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to fetch workers'
      return false
    } finally {
      loading.value = false
    }
  }

  async function fetchWorker(name) {
    try {
      loading.value = true
      error.value = null
      const data = await workerService.getWorker(name)
      currentWorker.value = data.worker
      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to fetch worker'
      return false
    } finally {
      loading.value = false
    }
  }

  async function startWorker(name) {
    try {
      loading.value = true
      error.value = null
      await workerService.startWorker(name)

      // Refresh workers list
      await fetchWorkers()
      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to start worker'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function stopWorker(name) {
    try {
      loading.value = true
      error.value = null
      await workerService.stopWorker(name)

      // Refresh workers list
      await fetchWorkers()
      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to stop worker'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function restartWorker(name) {
    try {
      loading.value = true
      error.value = null
      await workerService.restartWorker(name)

      // Refresh workers list after a short delay to let service restart
      setTimeout(() => fetchWorkers(), 1000)
      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to restart worker'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchLogs(name, lines = 100, since = '1 hour ago') {
    try {
      loading.value = true
      error.value = null
      const data = await workerService.getWorkerLogs(name, lines, since)
      logs.value = data.logs
      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to fetch worker logs'
      return false
    } finally {
      loading.value = false
    }
  }

  function clearError() {
    error.value = null
  }

  function clearLogs() {
    logs.value = ''
  }

  return {
    // State
    workers,
    currentWorker,
    logs,
    loading,
    error,
    // Actions
    fetchWorkers,
    fetchWorker,
    startWorker,
    stopWorker,
    restartWorker,
    fetchLogs,
    clearError,
    clearLogs,
  }
})
