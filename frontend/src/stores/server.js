import { defineStore } from 'pinia'
import { ref } from 'vue'
import { serverService } from '@/services/server'

export const useServerStore = defineStore('server', () => {
  // State
  const servers = ref([])
  const currentServer = ref(null)
  const loading = ref(false)
  const error = ref(null)

  // Actions
  async function fetchServers() {
    try {
      loading.value = true
      error.value = null
      const data = await serverService.getServers()
      // getServers() now returns array directly, not {servers: []}
      servers.value = Array.isArray(data) ? data : (data.servers || [])
      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to fetch servers'
      return false
    } finally {
      loading.value = false
    }
  }

  async function fetchServer(id) {
    try {
      loading.value = true
      error.value = null
      const data = await serverService.getServer(id)
      currentServer.value = data
      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to fetch server'
      return false
    } finally {
      loading.value = false
    }
  }

  async function createServer(serverData) {
    try {
      loading.value = true
      error.value = null
      const data = await serverService.createServer(serverData)
      servers.value.push(data.server)
      // Return full data including setup_job_id for auto-setup
      return data
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to create server'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateServer(id, serverData) {
    try {
      loading.value = true
      error.value = null
      const data = await serverService.updateServer(id, serverData)

      // Update in list
      const index = servers.value.findIndex((s) => s.id === id)
      if (index !== -1) {
        servers.value[index] = data.server
      }

      // Update current server if it's the one being updated
      if (currentServer.value?.id === id) {
        currentServer.value.server = data.server
      }

      return data.server
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to update server'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteServer(id) {
    try {
      loading.value = true
      error.value = null
      await serverService.deleteServer(id)

      // Remove from list
      servers.value = servers.value.filter((s) => s.id !== id)

      // Clear current server if it's the one being deleted
      if (currentServer.value?.id === id) {
        currentServer.value = null
      }

      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to delete server'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function collectStats(id) {
    try {
      loading.value = true
      error.value = null
      const data = await serverService.collectStats(id)
      return data
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to collect server stats'
      throw err
    } finally {
      loading.value = false
    }
  }

  function clearError() {
    error.value = null
  }

  return {
    // State
    servers,
    currentServer,
    loading,
    error,
    // Actions
    fetchServers,
    fetchServer,
    createServer,
    updateServer,
    deleteServer,
    collectStats,
    clearError,
  }
})
