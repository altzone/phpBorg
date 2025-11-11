<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900">
    <!-- Navbar -->
    <nav class="bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700">
      <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
          <!-- Logo and Navigation -->
          <div class="flex">
            <!-- Logo -->
            <div class="flex-shrink-0 flex items-center">
              <h1 class="text-xl font-bold text-primary-600 dark:text-primary-400">phpBorg 2.0</h1>
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
                to="/backup-jobs"
                class="nav-link"
                :class="{ 'nav-link-active': $route.name === 'backup-jobs' }"
              >
                Backup Jobs
              </RouterLink>
              <RouterLink
                to="/backup-wizard"
                class="nav-link flex items-center gap-1"
                :class="{ 'nav-link-active': $route.name === 'backup-wizard' }"
              >
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                Wizard
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
            <!-- Dark Mode Toggle -->
            <button
              @click="themeStore.toggleTheme()"
              class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
              :title="themeStore.isDark ? 'Switch to Light Mode' : 'Switch to Dark Mode'"
            >
              <!-- Sun Icon (show in Dark Mode - click to go light) -->
              <svg v-if="themeStore.isDark" class="w-5 h-5 text-yellow-400" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l.707.707a1 1 0 001.414-1.414l-.707-.707a1 1 0 00-1.414 1.414zm2.12-10.607a1 1 0 010 1.414l-.706.707a1 1 0 11-1.414-1.414l.707-.707a1 1 0 011.414 0zM17 11a1 1 0 100-2h-1a1 1 0 100 2h1zm-7 4a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM5.05 6.464A1 1 0 106.465 5.05l-.708-.707a1 1 0 00-1.414 1.414l.707.707zm1.414 8.486l-.707.707a1 1 0 01-1.414-1.414l.707-.707a1 1 0 011.414 1.414zM4 11a1 1 0 100-2H3a1 1 0 000 2h1z" clip-rule="evenodd" />
              </svg>
              <!-- Moon Icon (show in Light Mode - click to go dark) -->
              <svg v-else class="w-5 h-5 text-gray-700 dark:text-gray-300" fill="currentColor" viewBox="0 0 20 20">
                <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z" />
              </svg>
            </button>

            <!-- User Info -->
            <div class="text-sm text-gray-700 dark:text-gray-300">
              <span class="font-medium">{{ authStore.user?.username }}</span>
              <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">
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
import { useThemeStore } from '@/stores/theme'

const router = useRouter()
const authStore = useAuthStore()
const themeStore = useThemeStore()

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
  @apply inline-flex items-center px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:text-primary-600 dark:hover:text-primary-400 hover:bg-gray-50 dark:hover:bg-gray-700 rounded-md transition-colors;
}

.nav-link-active {
  @apply text-primary-600 dark:text-primary-400 bg-primary-50 dark:bg-gray-700;
}
</style>
