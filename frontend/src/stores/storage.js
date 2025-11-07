import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { storageService } from '@/services/storage'

export const useStorageStore = defineStore('storage', () => {
  // State
  const storagePools = ref([])
  const currentPool = ref(null)
  const loading = ref(false)
  const error = ref(null)

  // Getters
  const activePools = computed(() => storagePools.value.filter(pool => pool.active))
  const defaultPool = computed(() => storagePools.value.find(pool => pool.default_pool))

  // Actions
  async function fetchStoragePools() {
    try {
      loading.value = true
      error.value = null
      const data = await storageService.getStoragePools()
      storagePools.value = data.storage_pools || []
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to load storage pools'
      console.error('Fetch storage pools error:', err)
    } finally {
      loading.value = false
    }
  }

  async function fetchStoragePool(id) {
    try {
      loading.value = true
      error.value = null
      const data = await storageService.getStoragePool(id)
      currentPool.value = data.storage_pool
      return data.storage_pool
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to load storage pool'
      console.error('Fetch storage pool error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createStoragePool(poolData) {
    try {
      loading.value = true
      error.value = null
      const data = await storageService.createStoragePool(poolData)

      // Add to local list
      storagePools.value.push(data.storage_pool)

      // If this is set as default, update other pools
      if (data.storage_pool.default_pool) {
        storagePools.value = storagePools.value.map(pool =>
          pool.id === data.storage_pool.id
            ? data.storage_pool
            : { ...pool, default_pool: false }
        )
      }

      return data.storage_pool
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to create storage pool'
      console.error('Create storage pool error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateStoragePool(id, poolData) {
    try {
      loading.value = true
      error.value = null
      const data = await storageService.updateStoragePool(id, poolData)

      // Update in local list
      const index = storagePools.value.findIndex(pool => pool.id === id)
      if (index !== -1) {
        storagePools.value[index] = data.storage_pool
      }

      // If this is set as default, update other pools
      if (data.storage_pool.default_pool) {
        storagePools.value = storagePools.value.map(pool =>
          pool.id === data.storage_pool.id
            ? data.storage_pool
            : { ...pool, default_pool: false }
        )
      }

      return data.storage_pool
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to update storage pool'
      console.error('Update storage pool error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteStoragePool(id) {
    try {
      loading.value = true
      error.value = null
      await storageService.deleteStoragePool(id)

      // Remove from local list
      storagePools.value = storagePools.value.filter(pool => pool.id !== id)

      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to delete storage pool'
      console.error('Delete storage pool error:', err)
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
    storagePools,
    currentPool,
    loading,
    error,

    // Getters
    activePools,
    defaultPool,

    // Actions
    fetchStoragePools,
    fetchStoragePool,
    createStoragePool,
    updateStoragePool,
    deleteStoragePool,
    clearError,
  }
})
