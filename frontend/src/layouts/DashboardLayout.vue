<template>
  <div class="min-h-screen bg-gray-50">
    <!-- Navbar -->
    <nav class="bg-white shadow-sm border-b border-gray-200">
      <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <!-- Logo and Navigation -->
          <div class="flex">
            <!-- Logo -->
            <div class="flex-shrink-0 flex items-center">
              <h1 class="text-xl font-bold text-primary-600">phpBorg 2.0</h1>
            </div>

            <!-- Desktop Navigation -->
            <div class="hidden sm:ml-8 sm:flex sm:space-x-4">
              <RouterLink
                to="/"
                class="nav-link"
                :class="{ 'nav-link-active': $route.name === 'dashboard' }"
              >
                Dashboard
              </RouterLink>
              <RouterLink
                to="/servers"
                class="nav-link"
                :class="{ 'nav-link-active': $route.name === 'servers' || $route.name === 'server-detail' }"
              >
                Servers
              </RouterLink>
              <RouterLink
                to="/backups"
                class="nav-link"
                :class="{ 'nav-link-active': $route.name === 'backups' }"
              >
                Backups
              </RouterLink>
              <RouterLink
                to="/jobs"
                class="nav-link"
                :class="{ 'nav-link-active': $route.name === 'jobs' }"
              >
                Jobs
              </RouterLink>
              <RouterLink
                to="/storage-pools"
                class="nav-link"
                :class="{ 'nav-link-active': $route.name === 'storage-pools' }"
              >
                Storage Pools
              </RouterLink>
              <RouterLink
                v-if="authStore.isAdmin"
                to="/users"
                class="nav-link"
                :class="{ 'nav-link-active': $route.name === 'users' }"
              >
                Users
              </RouterLink>
              <RouterLink
                v-if="authStore.isAdmin"
                to="/roles"
                class="nav-link"
                :class="{ 'nav-link-active': $route.name === 'roles' }"
              >
                Roles
              </RouterLink>
              <RouterLink
                v-if="authStore.isAdmin"
                to="/settings"
                class="nav-link"
                :class="{ 'nav-link-active': $route.name === 'settings' }"
              >
                Settings
              </RouterLink>
            </div>
          </div>

          <!-- User Menu -->
          <div class="flex items-center space-x-4">
            <!-- User Info -->
            <div class="text-sm text-gray-700">
              <span class="font-medium">{{ authStore.user?.username }}</span>
              <span class="ml-2 text-xs text-gray-500">
                ({{ roleLabel }})
              </span>
            </div>

            <!-- Logout Button -->
            <button
              @click="handleLogout"
              class="btn btn-secondary text-sm"
            >
              Logout
            </button>
          </div>
        </div>
      </div>
    </nav>

    <!-- Main Content -->
    <main class="py-6 px-4 sm:px-6 lg:px-8">
      <RouterView />
    </main>
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { useRouter, RouterLink, RouterView } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const roleLabel = computed(() => {
  if (authStore.isAdmin) return 'Admin'
  if (authStore.isOperator) return 'Operator'
  if (authStore.isViewer) return 'Viewer'
  return 'User'
})

async function handleLogout() {
  await authStore.logout()
  router.push('/login')
}
</script>

<style scoped>
.nav-link {
  @apply inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 hover:text-primary-600 hover:bg-gray-50 rounded-md transition-colors;
}

.nav-link-active {
  @apply text-primary-600 bg-primary-50;
}
</style>
