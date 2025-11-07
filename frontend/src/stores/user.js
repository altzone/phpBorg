import { defineStore } from 'pinia'
import { ref } from 'vue'
import { userService } from '@/services/user'

export const useUserStore = defineStore('user', () => {
  // State
  const users = ref([])
  const currentUser = ref(null)
  const loading = ref(false)
  const error = ref(null)

  // Actions
  async function fetchUsers() {
    try {
      loading.value = true
      error.value = null
      const data = await userService.getUsers()
      users.value = data.users || []
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to load users'
      console.error('Fetch users error:', err)
    } finally {
      loading.value = false
    }
  }

  async function fetchUser(id) {
    try {
      loading.value = true
      error.value = null
      const data = await userService.getUser(id)
      currentUser.value = data.user
      return data.user
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to load user'
      console.error('Fetch user error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function createUser(userData) {
    try {
      loading.value = true
      error.value = null
      const data = await userService.createUser(userData)

      // Add to local list
      users.value.push(data.user)

      return data.user
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to create user'
      console.error('Create user error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateUser(id, userData) {
    try {
      loading.value = true
      error.value = null
      const data = await userService.updateUser(id, userData)

      // Update in local list
      const index = users.value.findIndex(u => u.id === id)
      if (index !== -1) {
        users.value[index] = data.user
      }

      return data.user
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to update user'
      console.error('Update user error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function resetPassword(id, password) {
    try {
      loading.value = true
      error.value = null
      await userService.resetPassword(id, password)
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to reset password'
      console.error('Reset password error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function deleteUser(id) {
    try {
      loading.value = true
      error.value = null
      await userService.deleteUser(id)

      // Remove from local list
      users.value = users.value.filter(u => u.id !== id)

      return true
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to delete user'
      console.error('Delete user error:', err)
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
    users,
    currentUser,
    loading,
    error,

    // Actions
    fetchUsers,
    fetchUser,
    createUser,
    updateUser,
    resetPassword,
    deleteUser,
    clearError,
  }
})
