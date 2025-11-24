<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 flex">
    <!-- Mobile menu backdrop -->
    <div
      v-if="mobileMenuOpen"
      @click="mobileMenuOpen = false"
      class="fixed inset-0 bg-black bg-opacity-50 z-40 lg:hidden"
    ></div>

    <!-- Sidebar -->
    <aside
      :class="[
        'fixed inset-y-0 left-0 z-50 w-64 bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 transform transition-transform duration-300 ease-in-out lg:translate-x-0',
        mobileMenuOpen ? 'translate-x-0' : '-translate-x-full'
      ]"
    >
      <!-- Logo -->
      <div class="h-16 flex items-center justify-between px-6 border-b border-gray-200 dark:border-gray-700">
        <h1 class="text-xl font-bold text-primary-600 dark:text-primary-400">{{ appName }}</h1>
        <button
          @click="mobileMenuOpen = false"
          class="lg:hidden text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Navigation -->
      <nav class="flex-1 overflow-y-auto py-3 px-3 scrollbar-hide">
        <!-- Quick Access Section -->
        <div class="mb-4">
          <div class="px-3 mb-1.5 text-xs font-bold text-primary-600 dark:text-primary-400 uppercase tracking-wider flex items-center gap-2 border-b border-primary-200 dark:border-primary-800 pb-1">
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
            </svg>
            {{ $t('nav.quick_access') }}
          </div>
          <RouterLink
            to="/backup-wizard"
            @click="mobileMenuOpen = false"
            class="sidebar-link bg-gradient-to-r from-primary-50 to-blue-50 dark:from-primary-900/20 dark:to-blue-900/20 hover:from-primary-100 hover:to-blue-100 dark:hover:from-primary-900/30 dark:hover:to-blue-900/30"
            :class="{ 'sidebar-link-active': $route.name === 'backup-wizard' }"
          >
            <svg class="w-5 h-5 text-primary-600 dark:text-primary-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 13h6m-3-3v6m5 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            <span class="font-semibold">{{ $t('nav.new_backup') }}</span>
          </RouterLink>
          <RouterLink
            to="/restore-wizard"
            @click="mobileMenuOpen = false"
            class="sidebar-link bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-900/20 dark:to-emerald-900/20 hover:from-green-100 hover:to-emerald-100 dark:hover:from-green-900/30 dark:hover:to-emerald-900/30"
            :class="{ 'sidebar-link-active': $route.name === 'restore-wizard' }"
          >
            <svg class="w-5 h-5 text-green-600 dark:text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
            </svg>
            <span class="font-semibold">{{ $t('nav.restore_files') }}</span>
          </RouterLink>
        </div>

        <!-- Home Link -->
        <RouterLink
          to="/"
          @click="mobileMenuOpen = false"
          class="sidebar-link mb-4"
          :class="{ 'sidebar-link-active': $route.name === 'dashboard' }"
        >
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
          </svg>
          <span>{{ $t('nav.dashboard') }}</span>
        </RouterLink>

        <!-- Backup Operations Section -->
        <div class="mb-4">
          <div class="px-3 mb-1.5 text-xs font-bold text-gray-600 dark:text-gray-300 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 pb-1">
            {{ $t('nav.backup_operations') }}
          </div>
          <RouterLink
            to="/backups"
            @click="mobileMenuOpen = false"
            class="sidebar-link"
            :class="{ 'sidebar-link-active': $route.name === 'backups' || $route.name === 'archive-browser' }"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
            </svg>
            <span>{{ $t('nav.archives') }}</span>
          </RouterLink>
          <RouterLink
            to="/backup-jobs"
            @click="mobileMenuOpen = false"
            class="sidebar-link"
            :class="{ 'sidebar-link-active': $route.name === 'backup-jobs' }"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
            </svg>
            <span>{{ $t('nav.scheduled_jobs') }}</span>
          </RouterLink>
        </div>

        <!-- Infrastructure Section -->
        <div class="mb-4">
          <div class="px-3 mb-1.5 text-xs font-bold text-gray-600 dark:text-gray-300 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 pb-1">
            {{ $t('nav.infrastructure') }}
          </div>
          <RouterLink
            to="/servers"
            @click="mobileMenuOpen = false"
            class="sidebar-link"
            :class="{ 'sidebar-link-active': $route.name === 'servers' || $route.name === 'server-detail' }"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
            </svg>
            <span>{{ $t('nav.servers') }}</span>
          </RouterLink>
          <RouterLink
            to="/repositories"
            @click="mobileMenuOpen = false"
            class="sidebar-link"
            :class="{ 'sidebar-link-active': $route.name === 'repositories' }"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
            </svg>
            <span>{{ $t('nav.repositories') }}</span>
          </RouterLink>
          <RouterLink
            to="/storage-pools"
            @click="mobileMenuOpen = false"
            class="sidebar-link"
            :class="{ 'sidebar-link-active': $route.name === 'storage-pools' }"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
            </svg>
            <span>{{ $t('nav.storage_pools') }}</span>
          </RouterLink>
        </div>

        <!-- System Section -->
        <div class="mb-4">
          <div class="px-3 mb-1.5 text-xs font-bold text-gray-600 dark:text-gray-300 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 pb-1">
            {{ $t('nav.system') }}
          </div>
          <RouterLink
            to="/jobs"
            @click="mobileMenuOpen = false"
            class="sidebar-link"
            :class="{ 'sidebar-link-active': $route.name === 'jobs' }"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
            </svg>
            <span>{{ $t('nav.job_queue') }}</span>
          </RouterLink>
          <RouterLink
            v-if="authStore.isAdmin"
            to="/workers"
            @click="mobileMenuOpen = false"
            class="sidebar-link"
            :class="{ 'sidebar-link-active': $route.name === 'workers' }"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z" />
            </svg>
            <span>{{ $t('nav.workers') }}</span>
          </RouterLink>
        </div>

        <!-- Administration Section (Admin only) -->
        <div v-if="authStore.isAdmin" class="mb-4">
          <div class="px-3 mb-1.5 text-xs font-bold text-gray-600 dark:text-gray-300 uppercase tracking-wider border-b border-gray-200 dark:border-gray-700 pb-1">
            {{ $t('nav.administration') }}
          </div>
          <RouterLink
            to="/users"
            @click="mobileMenuOpen = false"
            class="sidebar-link"
            :class="{ 'sidebar-link-active': $route.name === 'users' }"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" />
            </svg>
            <span>{{ $t('nav.users') }}</span>
          </RouterLink>
          <RouterLink
            to="/roles"
            @click="mobileMenuOpen = false"
            class="sidebar-link"
            :class="{ 'sidebar-link-active': $route.name === 'roles' }"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
            <span>{{ $t('nav.roles') }}</span>
          </RouterLink>
          <RouterLink
            to="/phpborg-backup"
            @click="mobileMenuOpen = false"
            class="sidebar-link"
            :class="{ 'sidebar-link-active': $route.name === 'phpborg-backup' }"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />
            </svg>
            <span>{{ $t('nav.phpborg_backup') }}</span>
          </RouterLink>
          <RouterLink
            to="/settings"
            @click="mobileMenuOpen = false"
            class="sidebar-link"
            :class="{ 'sidebar-link-active': $route.name === 'settings' }"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
            </svg>
            <span>{{ $t('nav.settings') }}</span>
          </RouterLink>
        </div>
      </nav>

      <!-- Language Switcher & User Profile (bottom of sidebar) -->
      <div class="border-t border-gray-200 dark:border-gray-700 p-4 space-y-3">
        <!-- Connection Status Badge -->
        <div class="flex items-center justify-center">
          <div v-if="sseStore.isSSE" class="flex items-center gap-2 px-3 py-1.5 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-md">
            <div class="w-2 h-2 bg-green-500 rounded-full animate-pulse"></div>
            <span class="text-xs font-medium text-green-700 dark:text-green-400">{{ $t('connection.realtime') }}</span>
          </div>
          <div v-else-if="sseStore.isPolling" class="flex items-center gap-2 px-3 py-1.5 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-md">
            <div class="w-2 h-2 bg-yellow-500 rounded-full"></div>
            <span class="text-xs font-medium text-yellow-700 dark:text-yellow-400">{{ $t('connection.polling') }}</span>
          </div>
          <div v-else class="flex items-center gap-2 px-3 py-1.5 bg-gray-50 dark:bg-gray-700 border border-gray-200 dark:border-gray-600 rounded-md">
            <div class="w-2 h-2 bg-gray-400 rounded-full"></div>
            <span class="text-xs font-medium text-gray-600 dark:text-gray-400">{{ $t('connection.disconnected') }}</span>
          </div>
        </div>

        <LanguageSwitcher />
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-3 min-w-0 flex-1">
            <div class="flex-shrink-0 w-8 h-8 bg-primary-600 dark:bg-primary-500 rounded-full flex items-center justify-center">
              <span class="text-white text-sm font-medium">{{ userInitials }}</span>
            </div>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium text-gray-700 dark:text-gray-300 truncate">{{ authStore.user?.username }}</p>
              <p class="text-xs text-gray-500 dark:text-gray-400 truncate">{{ userRole }}</p>
            </div>
          </div>
          <button
            @click="handleLogout"
            class="flex-shrink-0 text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200 ml-2"
            :title="$t('nav.logout')"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
            </svg>
          </button>
        </div>
      </div>
    </aside>

    <!-- Main content area -->
    <div class="flex-1 lg:ml-64">
      <!-- Top bar (mobile) -->
      <header class="sticky top-0 z-30 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 lg:hidden">
        <div class="h-16 px-4 flex items-center justify-between">
          <button
            @click="mobileMenuOpen = true"
            class="text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200"
          >
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
            </svg>
          </button>
          <h1 class="text-xl font-bold text-primary-600 dark:text-primary-400">phpBorg</h1>
          <div class="w-6"></div> <!-- Spacer for center alignment -->
        </div>
      </header>

      <!-- Update Banner -->
      <UpdateBanner />

      <!-- Page content -->
      <main class="p-4 sm:p-6 lg:p-8">
        <RouterView />
      </main>
    </div>

    <!-- Task Bar (Global - All Running Jobs) -->
    <TaskBar />
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useSettingsStore } from '@/stores/settings'
import { useTaskBarStore } from '@/stores/taskbar'
import { useSSEStore } from '@/stores/sse'
import LanguageSwitcher from '@/components/LanguageSwitcher.vue'
import TaskBar from '@/components/TaskBar.vue'
import UpdateBanner from '@/components/UpdateBanner.vue'

const { t } = useI18n()
const router = useRouter()
const authStore = useAuthStore()
const settingsStore = useSettingsStore()
const taskBarStore = useTaskBarStore()
const sseStore = useSSEStore()
const mobileMenuOpen = ref(false)

const appName = computed(() => {
  return settingsStore.settings?.general?.['app.name'] || 'phpBorg'
})

const userInitials = computed(() => {
  const username = authStore.user?.username || 'U'
  return username.substring(0, 2).toUpperCase()
})

const userRole = computed(() => {
  const roles = authStore.user?.roles || []
  if (roles.includes('ROLE_ADMIN')) return t('nav.role_admin')
  if (roles.includes('ROLE_OPERATOR')) return t('nav.role_operator')
  return t('nav.role_viewer')
})

const handleLogout = async () => {
  await authStore.logout()
  router.push('/login')
}

// Load settings on mount
onMounted(() => {
  if (Object.keys(settingsStore.settings).length === 0) {
    settingsStore.fetchSettings()
  }

  // Initialize unified task bar (subscribes to SSE jobs topic)
  taskBarStore.init()
})

// Cleanup on unmount
onUnmounted(() => {
  taskBarStore.cleanup()
})
</script>

<style scoped>
.sidebar-link {
  @apply flex items-center gap-3 px-3 py-1.5 text-sm font-medium rounded-lg transition-colors;
  @apply text-gray-700 dark:text-gray-300;
  @apply hover:bg-gray-100 dark:hover:bg-gray-700;
}

.sidebar-link-active {
  @apply bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400;
  @apply hover:bg-primary-100 dark:hover:bg-primary-900/30;
}

/* Hide scrollbar but keep functionality */
.scrollbar-hide {
  -ms-overflow-style: none;  /* IE and Edge */
  scrollbar-width: none;  /* Firefox */
}

.scrollbar-hide::-webkit-scrollbar {
  display: none;  /* Chrome, Safari and Opera */
}
</style>
