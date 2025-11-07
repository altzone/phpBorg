import { defineStore } from 'pinia'
import { ref } from 'vue'
import { roleService } from '@/services/role'

export const useRoleStore = defineStore('role', () => {
  // State
  const roles = ref([])
  const currentRole = ref(null)
  const allPermissions = ref([])
  const permissionsGrouped = ref({})
  const loading = ref(false)
  const error = ref(null)

  // Actions
  async function fetchRoles() {
    try {
      loading.value = true
      error.value = null
      const data = await roleService.getRoles()
      roles.value = data.roles || []
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to load roles'
      console.error('Fetch roles error:', err)
    } finally {
      loading.value = false
    }
  }

  async function fetchRole(roleName) {
    try {
      loading.value = true
      error.value = null
      const data = await roleService.getRole(roleName)
      currentRole.value = data
      return data
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to load role'
      console.error('Fetch role error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateRolePermissions(roleName, permissions) {
    try {
      loading.value = true
      error.value = null
      const data = await roleService.updateRolePermissions(roleName, permissions)

      // Update in roles list
      const roleIndex = roles.value.findIndex(r => r.name === roleName)
      if (roleIndex !== -1) {
        roles.value[roleIndex].permissions = data.permissions
        roles.value[roleIndex].enabled_permissions = Object.values(data.permissions).filter(Boolean).length
      }

      // Update current role if it's the one being edited
      if (currentRole.value?.role === roleName) {
        currentRole.value.permissions = data.permissions
      }

      return data
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to update role permissions'
      console.error('Update role permissions error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function fetchAllPermissions() {
    try {
      loading.value = true
      error.value = null
      const data = await roleService.getAllPermissions()
      allPermissions.value = data.permissions || []
      permissionsGrouped.value = data.grouped || {}
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to load permissions'
      console.error('Fetch permissions error:', err)
    } finally {
      loading.value = false
    }
  }

  function getPermissionLabel(permission) {
    // Convert permission key to human-readable label
    // e.g., "users.create" => "Create Users"
    const parts = permission.split('.')
    if (parts.length !== 2) return permission

    const [module, action] = parts
    const actionLabel = action.charAt(0).toUpperCase() + action.slice(1)
    const moduleLabel = module.charAt(0).toUpperCase() + module.slice(1)

    return `${actionLabel} ${moduleLabel}`
  }

  function getModuleLabel(module) {
    // Convert module key to human-readable label
    // e.g., "users" => "Users", "storage" => "Storage Pools"
    const labels = {
      users: 'Users',
      servers: 'Servers',
      backups: 'Backups',
      jobs: 'Jobs',
      settings: 'Settings',
      storage: 'Storage Pools',
    }
    return labels[module] || module.charAt(0).toUpperCase() + module.slice(1)
  }

  function clearError() {
    error.value = null
  }

  return {
    // State
    roles,
    currentRole,
    allPermissions,
    permissionsGrouped,
    loading,
    error,

    // Actions
    fetchRoles,
    fetchRole,
    updateRolePermissions,
    fetchAllPermissions,
    getPermissionLabel,
    getModuleLabel,
    clearError,
  }
})
