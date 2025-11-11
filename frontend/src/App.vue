<template>
  <RouterView />
</template>

<script setup>
import { RouterView } from 'vue-router'
import { useThemeStore } from '@/stores/theme'

// Initialize theme store to apply saved theme on load
const themeStore = useThemeStore()
</script>
