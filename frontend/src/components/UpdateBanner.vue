<template>
  <div
    v-if="showBanner && updateAvailable"
    class="bg-gradient-to-r from-blue-500 to-indigo-600 text-white shadow-lg border-b-2 border-blue-700"
  >
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-3">
      <div class="flex items-center justify-between flex-wrap gap-4">
        <div class="flex items-center gap-3 flex-1 min-w-0">
          <svg class="w-6 h-6 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
          </svg>
          <div class="flex-1 min-w-0">
            <p class="font-semibold">
              {{ $t('update.banner.title') }}
            </p>
            <p class="text-sm text-blue-100 mt-0.5">
              {{ $t('update.banner.description', { commits: updateInfo.commits_behind }) }}
            </p>
          </div>
        </div>

        <div class="flex items-center gap-3">
          <button
            @click="goToSettings"
            class="px-4 py-2 bg-white text-blue-600 rounded-lg font-medium hover:bg-blue-50 transition-colors flex items-center gap-2"
          >
            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
            </svg>
            {{ $t('update.banner.view_update') }}
          </button>
          <button
            @click="dismissBanner"
            class="text-white hover:text-blue-100 transition-colors"
            :title="$t('update.banner.dismiss')"
          >
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { useUpdateStore } from '@/stores/update'

const router = useRouter()
const authStore = useAuthStore()
const updateStore = useUpdateStore()

const showBanner = ref(true)

// Use store's update info
const updateInfo = computed(() => updateStore.updateInfo || {
  available: false,
  commits_behind: 0,
  current_commit_short: '',
  latest_commit_short: ''
})

const updateAvailable = computed(() => {
  return updateStore.hasUpdate && authStore.isAdmin
})

function dismissBanner() {
  showBanner.value = false
  // Store dismissal in localStorage for this session
  localStorage.setItem('update_banner_dismissed', Date.now().toString())
}

function goToSettings() {
  router.push({ name: 'settings', query: { tab: 'update' } })
}

onMounted(() => {
  // Only show banner if user is admin
  if (!authStore.isAdmin) return

  // Check if banner was dismissed recently (within last 6 hours)
  const dismissed = localStorage.getItem('update_banner_dismissed')
  if (dismissed) {
    const dismissedTime = parseInt(dismissed)
    const sixHoursAgo = Date.now() - (6 * 60 * 60 * 1000)
    if (dismissedTime > sixHoursAgo) {
      showBanner.value = false
    }
  }
})
</script>
