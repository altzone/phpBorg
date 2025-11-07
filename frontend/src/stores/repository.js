import { defineStore } from 'pinia'
import { ref } from 'vue'
import { repositoryService } from '@/services/repository'

export const useRepositoryStore = defineStore('repository', () => {
  const repositories = ref([])
  const loading = ref(false)
  const error = ref(null)

  /**
   * Fetch all repositories
   */
  async function fetchRepositories() {
    loading.value = true
    error.value = null

    try {
      const data = await repositoryService.getRepositories()
      repositories.value = data || []
      return repositories.value
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to fetch repositories'
      console.error('Error fetching repositories:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Fetch repository by ID
   */
  async function fetchRepository(id) {
    loading.value = true
    error.value = null

    try {
      const data = await repositoryService.getRepository(id)
      return data
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to fetch repository'
      console.error('Error fetching repository:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Update repository retention policy
   */
  async function updateRetention(id, retention) {
    loading.value = true
    error.value = null

    try {
      const data = await repositoryService.updateRetention(id, retention)

      // Update local cache
      const index = repositories.value.findIndex(r => r.id === id)
      if (index !== -1) {
        repositories.value[index].retention = data.retention
      }

      return data
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to update retention policy'
      console.error('Error updating retention:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  /**
   * Get repositories for a specific server
   */
  function getRepositoriesByServer(serverId) {
    return repositories.value.filter(repo => repo.server_id === serverId)
  }

  return {
    repositories,
    loading,
    error,
    fetchRepositories,
    fetchRepository,
    updateRetention,
    getRepositoriesByServer,
  }
})
