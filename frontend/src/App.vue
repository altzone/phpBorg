<template>
  <RouterView />
</template>

<script setup>
import { onMounted, onUnmounted, watch } from 'vue'
import { RouterView } from 'vue-router'
import { useThemeStore } from '@/stores/theme'
import { useSSEStore } from '@/stores/sse'
import { useAuthStore } from '@/stores/auth'

// Initialize theme store to apply saved theme on load
const themeStore = useThemeStore()

// Initialize SSE store for global real-time updates
const sseStore = useSSEStore()
const authStore = useAuthStore()

// Setup SSE when app mounts (if authenticated)
onMounted(() => {
  if (authStore.isAuthenticated) {
    sseStore.setupSSE()
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
watch(() => authStore.isAuthenticated, (isAuth) => {
  if (isAuth) {
    console.log('[App] User authenticated, setting up SSE...')
    sseStore.setupSSE()
  } else {
    console.log('[App] User logged out, disconnecting SSE...')
    sseStore.disconnect()
  }
})
</script>
