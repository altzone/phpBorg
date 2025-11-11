<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">Roles & Permissions</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400 dark:text-gray-500">Configure permissions for each role</p>
      </div>
    </div>

    <!-- Error Message -->
    <div v-if="roleStore.error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
      <div class="flex justify-between items-start">
        <p class="text-sm text-red-800">{{ roleStore.error }}</p>
        <button @click="roleStore.clearError()" class="text-red-500 hover:text-red-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Info Card -->
    <div class="card mb-6 bg-blue-50 border-blue-200">
      <div class="flex">
        <div class="flex-shrink-0">
          <svg class="h-5 w-5 text-blue-400" fill="currentColor" viewBox="0 0 20 20">
            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
          </svg>
        </div>
        <div class="ml-3">
          <h3 class="text-sm font-medium text-blue-800">About Roles</h3>
          <div class="mt-2 text-sm text-blue-700">
            <ul class="list-disc pl-5 space-y-1">
              <li><strong>Admin:</strong> Full access to all features and settings</li>
              <li><strong>Operator:</strong> Can manage servers, backups, and jobs but cannot modify users or system settings</li>
              <li><strong>User:</strong> Read-only access to view backups and server information</li>
            </ul>
            <p class="mt-2"><strong>Note:</strong> Changes to permissions require users to log out and log back in to take effect.</p>
          </div>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="roleStore.loading && !roleStore.roles.length" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400 dark:text-gray-500">Loading roles...</p>
      </div>
    </div>

    <!-- Roles List -->
    <div v-else class="space-y-6">
      <div v-for="role in roleStore.roles" :key="role.name" class="card">
        <!-- Role Header -->
        <div class="flex justify-between items-center mb-6 pb-4 border-b">
          <div>
            <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
              {{ getRoleDisplayName(role.name) }}
            </h2>
            <p class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
              {{ role.enabled_permissions || 0 }} of {{ role.total_permissions || 0 }} permissions enabled
            </p>
          </div>
          <span
            :class="[
              'px-3 py-1 text-sm font-semibold rounded',
              getRoleBadgeClass(role.name)
            ]"
          >
            {{ getRoleDisplayName(role.name) }}
          </span>
        </div>

        <!-- Permissions Grid -->
        <div class="space-y-6">
          <div
            v-for="(permissions, module) in getGroupedPermissions(role.permissions)"
            :key="module"
            class="border-b pb-4 last:border-b-0"
          >
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-3">
              {{ roleStore.getModuleLabel(module) }}
            </h3>
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
              <div
                v-for="permission in permissions"
                :key="permission"
                class="flex items-center p-3 bg-gray-50 dark:bg-gray-800 rounded hover:bg-gray-100 dark:hover:bg-gray-700"
              >
                <input
                  v-model="permissionsForms[role.name][permission]"
                  type="checkbox"
                  :id="`${role.name}-${permission}`"
                  class="h-4 w-4 text-primary-600 focus:ring-primary-500 border-gray-300 dark:border-gray-600 rounded"
                />
                <label
                  :for="`${role.name}-${permission}`"
                  class="ml-3 text-sm text-gray-700 dark:text-gray-300 cursor-pointer"
                >
                  {{ roleStore.getPermissionLabel(permission) }}
                </label>
              </div>
            </div>
          </div>
        </div>

        <!-- Save Button -->
        <div class="mt-6 pt-4 border-t flex justify-end">
          <button
            @click="savePermissions(role.name)"
            class="btn btn-primary"
            :disabled="roleStore.loading"
          >
            Save {{ getRoleDisplayName(role.name) }} Permissions
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue'
import { useRoleStore } from '@/stores/role'

const roleStore = useRoleStore()

const permissionsForms = reactive({})

onMounted(async () => {
  await Promise.all([
    roleStore.fetchRoles(),
    roleStore.fetchAllPermissions()
  ])
  initPermissionsForms()
})

function initPermissionsForms() {
  roleStore.roles.forEach(role => {
    permissionsForms[role.name] = {}
    if (role.permissions) {
      Object.keys(role.permissions).forEach(permission => {
        permissionsForms[role.name][permission] = role.permissions[permission]
      })
    }
  })
}

function getRoleDisplayName(roleName) {
  const names = {
    ROLE_ADMIN: 'Admin',
    ROLE_OPERATOR: 'Operator',
    ROLE_USER: 'User',
  }
  return names[roleName] || roleName
}

function getRoleBadgeClass(roleName) {
  const classes = {
    ROLE_ADMIN: 'bg-red-100 text-red-800',
    ROLE_OPERATOR: 'bg-blue-100 text-blue-800',
    ROLE_USER: 'bg-gray-100 text-gray-800 dark:text-gray-200',
  }
  return classes[roleName] || 'bg-gray-100 text-gray-800 dark:text-gray-200'
}

function getGroupedPermissions(permissions) {
  if (!permissions) return {}

  const grouped = {}
  Object.keys(permissions).forEach(permission => {
    const parts = permission.split('.')
    if (parts.length !== 2) return

    const [module] = parts
    if (!grouped[module]) {
      grouped[module] = []
    }
    grouped[module].push(permission)
  })

  return grouped
}

async function savePermissions(roleName) {
  try {
    const permissions = permissionsForms[roleName]

    // Validation: ROLE_ADMIN must have at least one permission
    if (roleName === 'ROLE_ADMIN') {
      const enabledCount = Object.values(permissions).filter(Boolean).length
      if (enabledCount === 0) {
        alert('Admin role must have at least one permission enabled')
        return
      }
    }

    await roleStore.updateRolePermissions(roleName, permissions)
    alert(`Permissions for ${getRoleDisplayName(roleName)} updated successfully`)
  } catch (err) {
    // Error handled by store
  }
}
</script>
