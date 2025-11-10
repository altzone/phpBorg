import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { requiresGuest: true },
    },
    {
      path: '/',
      component: () => import('@/layouts/DashboardLayout.vue'),
      meta: { requiresAuth: true },
      children: [
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
        },
        {
          path: 'servers',
          name: 'servers',
          component: () => import('@/views/ServersView.vue'),
        },
        {
          path: 'servers/:id',
          name: 'server-detail',
          component: () => import('@/views/ServerDetailView.vue'),
        },
        {
          path: 'backups',
          name: 'backups',
          component: () => import('@/views/BackupsView.vue'),
        },
        {
          path: 'backups/:id/browse',
          name: 'archive-browser',
          component: () => import('@/views/ArchiveBrowserView.vue'),
        },
        {
          path: 'backup-jobs',
          name: 'backup-jobs',
          component: () => import('@/views/BackupJobsView.vue'),
        },
        {
          path: 'backup-wizard',
          name: 'backup-wizard',
          component: () => import('@/views/BackupWizardView.vue'),
        },
        {
          path: 'jobs',
          name: 'jobs',
          component: () => import('@/views/JobsView.vue'),
        },
        {
          path: 'settings',
          name: 'settings',
          component: () => import('@/views/SettingsView.vue'),
          meta: { requiresRole: 'ROLE_ADMIN' },
        },
        {
          path: 'users',
          name: 'users',
          component: () => import('@/views/UsersView.vue'),
          meta: { requiresRole: 'ROLE_ADMIN' },
        },
        {
          path: 'roles',
          name: 'roles',
          component: () => import('@/views/RolesView.vue'),
          meta: { requiresRole: 'ROLE_ADMIN' },
        },
        {
          path: 'storage-pools',
          name: 'storage-pools',
          component: () => import('@/views/StoragePoolsView.vue'),
          meta: { requiresAuth: true },
        },
      ],
    },
  ],
})

// Navigation guard
router.beforeEach((to, from, next) => {
  const authStore = useAuthStore()

  // Check if route requires authentication
  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    next({ name: 'login', query: { redirect: to.fullPath } })
    return
  }

  // Check if route requires guest (not authenticated)
  if (to.meta.requiresGuest && authStore.isAuthenticated) {
    next({ name: 'dashboard' })
    return
  }

  // Check if route requires specific role
  if (to.meta.requiresRole && !authStore.hasRole(to.meta.requiresRole)) {
    // User doesn't have required role, redirect to dashboard
    next({ name: 'dashboard' })
    return
  }

  // Check if route requires any of multiple roles
  if (to.meta.requiresAnyRole && !authStore.hasAnyRole(to.meta.requiresAnyRole)) {
    next({ name: 'dashboard' })
    return
  }

  next()
})

export default router
