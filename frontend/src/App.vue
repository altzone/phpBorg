<template>
  <RouterView />

  <!-- Setup Wizard (shown on first run) -->
  <SetupWizard
    :show="showSetupWizard"
    @complete="onSetupComplete"
  />
</template>

<script setup>
import { ref, onMounted, onUnmounted, watch } from 'vue'
import { RouterView } from 'vue-router'
import { useThemeStore } from '@/stores/theme'
import { useSSEStore } from '@/stores/sse'
import { useAuthStore } from '@/stores/auth'
import SetupWizard from '@/components/SetupWizard.vue'
import setupService from '@/services/setup'

// Initialize theme store to apply saved theme on load
const themeStore = useThemeStore()

// Initialize SSE store for global real-time updates
const sseStore = useSSEStore()
const authStore = useAuthStore()

// Setup wizard state
const showSetupWizard = ref(false)

// Check if setup is required after login
async function checkSetupStatus() {
  if (!authStore.isAuthenticated) return

  try {
    console.log('[App] Checking setup status...')
    const status = await setupService.getStatus()
    console.log('[App] Setup status:', status)
    if (status.setup_required) {
      console.log('[App] Setup required, showing wizard')
      showSetupWizard.value = true
    } else {
      console.log('[App] Setup already completed')
    }
  } catch (error) {
    console.error('[App] Failed to check setup status:', error)
  }
}

function onSetupComplete() {
  showSetupWizard.value = false
  // Reload page to apply new settings (language, app name, etc.)
  window.location.reload()
}

// Setup SSE when app mounts (if authenticated)
onMounted(async () => {
  if (authStore.isAuthenticated) {
    sseStore.setupSSE()
    await checkSetupStatus()
  }
})

// Cleanup SSE when app unmounts
onUnmounted(() => {
  sseStore.disconnect()
})

// Watch for token changes and reconnect SSE
watch(() => authStore.accessToken, (newToken, oldToken) => {
  if (newToken && oldToken && newToken !== oldToken) {
    console.log('[App] Token refreshed, reconnecting SSE...')
    sseStore.reconnect()
  }
})

// Watch for authentication changes
watch(() => authStore.isAuthenticated, async (isAuth) => {
  if (isAuth) {
    console.log('[App] User authenticated, setting up SSE...')
    sseStore.setupSSE()
    await checkSetupStatus()
  } else {
    console.log('[App] User logged out, disconnecting SSE...')
    sseStore.disconnect()
    showSetupWizard.value = false
  }
})
</script>
