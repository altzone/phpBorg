<template>
  <div>
    <!-- Header -->
    <div class="flex justify-between items-center mb-8">
      <div>
        <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $t('users.title') }}</h1>
        <p class="mt-2 text-gray-600 dark:text-gray-400">{{ $t('users.subtitle') }}</p>
      </div>
      <button
        v-if="authStore.isAdmin"
        @click="openUserModal()"
        class="btn btn-primary"
      >
        {{ $t('users.create_user') }}
      </button>
    </div>

    <!-- Error Message -->
    <div v-if="userStore.error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
      <div class="flex justify-between items-start">
        <p class="text-sm text-red-800">{{ userStore.error }}</p>
        <button @click="userStore.clearError()" class="text-red-500 hover:text-red-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="card mb-6">
      <div class="flex gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('users.filter_role') }}</label>
          <select v-model="filterRole" class="input">
            <option value="">{{ $t('users.all_roles') }}</option>
            <option value="ROLE_ADMIN">Admin</option>
            <option value="ROLE_OPERATOR">Operator</option>
            <option value="ROLE_USER">User</option>
          </select>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('users.filter_status') }}</label>
          <select v-model="filterStatus" class="input">
            <option value="">{{ $t('users.all_status') }}</option>
            <option value="active">{{ $t('users.active') }}</option>
            <option value="inactive">{{ $t('users.inactive') }}</option>
          </select>
        </div>
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="userStore.loading && !userStore.users.length" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400">{{ $t('users.loading') }}</p>
      </div>
    </div>

    <!-- Users Table -->
    <div v-else class="card">
      <div class="overflow-x-auto">
        <table class="w-full">
          <thead class="bg-gray-50 dark:bg-gray-800 border-b">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('users.table.username') }}</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('users.table.email') }}</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('users.table.roles') }}</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('users.table.status') }}</th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('users.table.last_login') }}</th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">{{ $t('users.table.actions') }}</th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700">
            <tr v-for="user in filteredUsers" :key="user.id" class="hover:bg-gray-50 dark:hover:bg-gray-700 dark:bg-gray-800">
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ user.username }}</div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="text-sm text-gray-900 dark:text-gray-100">{{ user.email }}</div>
              </td>
              <td class="px-6 py-4">
                <div class="flex flex-wrap gap-1">
                  <span
                    v-for="role in user.roles"
                    :key="role"
                    class="px-2 py-1 text-xs rounded"
                    :class="getRoleBadgeClass(role)"
                  >
                    {{ getRoleLabel(role) }}
                  </span>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span
                  :class="[
                    'px-2 py-1 text-xs font-semibold rounded',
                    user.active
                      ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
                      : 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-200'
                  ]"
                >
                  {{ user.active ? $t('users.active') : $t('users.inactive') }}
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                {{ user.last_login_at ? formatDate(user.last_login_at) : $t('users.never') }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                <div class="flex justify-end gap-2">
                  <button
                    @click="openUserModal(user)"
                    class="text-primary-600 hover:text-primary-900 dark:text-primary-400 dark:hover:text-primary-300"
                  >
                    {{ $t('users.edit') }}
                  </button>
                  <button
                    @click="openPasswordModal(user)"
                    class="text-yellow-600 hover:text-yellow-900 dark:text-yellow-400 dark:hover:text-yellow-300"
                  >
                    {{ $t('users.reset_password') }}
                  </button>
                  <button
                    @click="confirmDelete(user)"
                    class="text-red-600 hover:text-red-900 dark:text-red-400 dark:hover:text-red-300"
                    :disabled="user.id === authStore.user?.id"
                  >
                    {{ $t('users.delete') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- User Modal -->
    <div v-if="showUserModal" class="fixed inset-0 bg-gray-600 dark:bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="closeUserModal">
      <div class="relative top-20 mx-auto p-5 border w-[600px] shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ editingUser ? $t('users.modal_title_edit') : $t('users.modal_title_create') }}
          </h3>
          <button @click="closeUserModal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form @submit.prevent="saveUser">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('users.username_label') }}</label>
              <input v-model="userForm.username" type="text" class="input w-full" required minlength="3" maxlength="50" />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('users.email_label') }}</label>
              <input v-model="userForm.email" type="email" class="input w-full" required />
            </div>
            <div v-if="!editingUser">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('users.password_label') }}</label>
              <input v-model="userForm.password" type="password" class="input w-full" required minlength="8" />
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('users.password_help') }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('users.roles_label') }}</label>
              <div class="space-y-2">
                <div class="flex items-center">
                  <input v-model="userForm.roles" type="checkbox" value="ROLE_ADMIN" class="mr-2" />
                  <label class="text-sm text-gray-700 dark:text-gray-300">{{ $t('users.role_admin_desc') }}</label>
                </div>
                <div class="flex items-center">
                  <input v-model="userForm.roles" type="checkbox" value="ROLE_OPERATOR" class="mr-2" />
                  <label class="text-sm text-gray-700 dark:text-gray-300">{{ $t('users.role_operator_desc') }}</label>
                </div>
                <div class="flex items-center">
                  <input v-model="userForm.roles" type="checkbox" value="ROLE_USER" class="mr-2" />
                  <label class="text-sm text-gray-700 dark:text-gray-300">{{ $t('users.role_user_desc') }}</label>
                </div>
              </div>
            </div>
            <div class="flex items-center">
              <input v-model="userForm.active" type="checkbox" class="mr-2" />
              <label class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $t('users.active_label') }}</label>
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <button type="button" @click="closeUserModal" class="btn btn-secondary flex-1">
              {{ $t('users.cancel') }}
            </button>
            <button type="submit" class="btn btn-primary flex-1" :disabled="userStore.loading">
              {{ editingUser ? $t('users.update') : $t('users.create') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Password Reset Modal -->
    <div v-if="showPasswordModal" class="fixed inset-0 bg-gray-600 dark:bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="closePasswordModal">
      <div class="relative top-20 mx-auto p-5 border w-[500px] shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">
            {{ $t('users.password_modal_title') }} - {{ passwordUser?.username }}
          </h3>
          <button @click="closePasswordModal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <form @submit.prevent="resetPassword">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('users.new_password') }}</label>
              <input v-model="newPassword" type="password" class="input w-full" required minlength="8" />
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('users.password_help') }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('users.confirm_password') }}</label>
              <input v-model="confirmPassword" type="password" class="input w-full" required minlength="8" />
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <button type="button" @click="closePasswordModal" class="btn btn-secondary flex-1">
              {{ $t('users.cancel') }}
            </button>
            <button type="submit" class="btn btn-primary flex-1" :disabled="userStore.loading">
              {{ $t('users.reset_password_button') }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useUserStore } from '@/stores/user'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const userStore = useUserStore()
const authStore = useAuthStore()

const showUserModal = ref(false)
const showPasswordModal = ref(false)
const editingUser = ref(null)
const passwordUser = ref(null)
const filterRole = ref('')
const filterStatus = ref('')
const newPassword = ref('')
const confirmPassword = ref('')

const userForm = reactive({
  username: '',
  email: '',
  password: '',
  roles: [],
  active: true,
})

onMounted(async () => {
  await userStore.fetchUsers()
})

const filteredUsers = computed(() => {
  return userStore.users.filter(user => {
    // Filter by role
    if (filterRole.value && !user.roles.includes(filterRole.value)) {
      return false
    }

    // Filter by status
    if (filterStatus.value === 'active' && !user.active) {
      return false
    }
    if (filterStatus.value === 'inactive' && user.active) {
      return false
    }

    return true
  })
})

function getRoleLabel(role) {
  const labels = {
    ROLE_ADMIN: 'Admin',
    ROLE_OPERATOR: 'Operator',
    ROLE_USER: 'User',
  }
  return labels[role] || role
}

function getRoleBadgeClass(role) {
  const classes = {
    ROLE_ADMIN: 'bg-red-100 text-red-800',
    ROLE_OPERATOR: 'bg-blue-100 text-blue-800',
    ROLE_USER: 'bg-gray-100 text-gray-800 dark:text-gray-200',
  }
  return classes[role] || 'bg-gray-100 text-gray-800 dark:text-gray-200'
}

function openUserModal(user = null) {
  editingUser.value = user
  if (user) {
    userForm.username = user.username
    userForm.email = user.email
    userForm.password = ''
    userForm.roles = [...user.roles]
    userForm.active = user.active
  } else {
    userForm.username = ''
    userForm.email = ''
    userForm.password = ''
    userForm.roles = ['ROLE_USER']
    userForm.active = true
  }
  showUserModal.value = true
}

function closeUserModal() {
  showUserModal.value = false
  editingUser.value = null
}

async function saveUser() {
  try {
    if (userForm.roles.length === 0) {
      alert(t('users.role_required'))
      return
    }

    const data = {
      username: userForm.username,
      email: userForm.email,
      roles: userForm.roles,
      active: userForm.active,
    }

    if (!editingUser.value) {
      data.password = userForm.password
    }

    if (editingUser.value) {
      await userStore.updateUser(editingUser.value.id, data)
    } else {
      await userStore.createUser(data)
    }

    closeUserModal()
  } catch (err) {
    // Error handled by store
  }
}

function openPasswordModal(user) {
  passwordUser.value = user
  newPassword.value = ''
  confirmPassword.value = ''
  showPasswordModal.value = true
}

function closePasswordModal() {
  showPasswordModal.value = false
  passwordUser.value = null
  newPassword.value = ''
  confirmPassword.value = ''
}

async function resetPassword() {
  if (newPassword.value !== confirmPassword.value) {
    alert(t('users.passwords_mismatch'))
    return
  }

  try {
    await userStore.resetPassword(passwordUser.value.id, newPassword.value)
    alert(t('users.password_reset_success'))
    closePasswordModal()
  } catch (err) {
    // Error handled by store
  }
}

async function confirmDelete(user) {
  if (user.id === authStore.user?.id) {
    alert(t('users.delete_self_error'))
    return
  }

  if (!confirm(t('users.delete_confirm', { username: user.username }))) {
    return
  }

  try {
    await userStore.deleteUser(user.id)
  } catch (err) {
    // Error handled by store
  }
}

function formatDate(dateString) {
  if (!dateString) return t('users.never')
  const date = new Date(dateString)
  return date.toLocaleString('fr-FR', {
    year: 'numeric',
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}
</script>
