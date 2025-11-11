<template>
  <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
    <div class="bg-white dark:bg-gray-800 rounded-lg max-w-md w-full p-6">
      <!-- Icon -->
      <div class="flex items-center justify-center w-12 h-12 rounded-full bg-red-100 mx-auto mb-4">
        <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
        </svg>
      </div>

      <!-- Title -->
      <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 text-center mb-2">
        Delete Server?
      </h3>

      <!-- Message -->
      <p class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500 text-center mb-6">
        Are you sure you want to delete <strong>{{ server?.name }}</strong>?
        This action cannot be undone and will remove all associated backups and repositories.
      </p>

      <!-- Actions -->
      <div class="flex gap-3">
        <button
          @click="$emit('close')"
          class="flex-1 btn btn-secondary"
        >
          Cancel
        </button>
        <button
          @click="$emit('confirm')"
          class="flex-1 btn bg-red-600 text-white hover:bg-red-700"
        >
          Delete
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  server: {
    type: Object,
    required: true,
  },
})

defineEmits(['close', 'confirm'])
</script>
