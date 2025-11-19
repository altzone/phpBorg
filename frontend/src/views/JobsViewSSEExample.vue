<!--
  Example: How to use SSE in JobsView
  Replace the polling logic with SSE subscriptions
-->

<script setup>
import { onMounted } from 'vue'
import { useJobStore } from '@/stores/job'
import { useSSE } from '@/composables/useSSE'

const jobStore = useJobStore()
const { subscribe, isConnected } = useSSE()

onMounted(async () => {
  // Initial data fetch
  await jobStore.fetchJobs()

  // Subscribe to real-time job updates via SSE
  subscribe('jobs', (data) => {
    console.log('[Jobs] SSE update received:', data)

    // Update job list
    if (data.jobs) {
      jobStore.jobs = data.jobs
    }

    // Update job stats
    if (data.stats) {
      jobStore.stats = data.stats
    }

    // Update specific job progress
    if (data.job_id && data.progress) {
      const job = jobStore.jobs.find(j => j.id === data.job_id)
      if (job) {
        job.progress = data.progress
        job.status = data.status
        job.message = data.message
      }
    }

    // Real-time progress info (like current backup stats)
    if (data.job_id && data.progress_info) {
      jobStore.setProgressInfo(data.job_id, data.progress_info)
    }
  })
})
</script>

<template>
  <div>
    <!-- Connection indicator -->
    <div v-if="!isConnected" class="bg-yellow-100 border-l-4 border-yellow-500 p-4 mb-4">
      <p class="text-yellow-700">
        ⚠️ Real-time updates disconnected. Retrying...
      </p>
    </div>

    <!-- Your jobs UI here -->
    <!-- Jobs will update automatically via SSE! -->
  </div>
</template>
