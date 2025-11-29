<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="confirmStore.isOpen"
        class="fixed inset-0 z-[9998] flex items-center justify-center"
      >
        <!-- Backdrop -->
        <div
          class="absolute inset-0 bg-black/50"
          @click="confirmStore.cancel()"
        ></div>

        <!-- Dialog -->
        <div class="relative bg-white dark:bg-slate-800 rounded-lg shadow-xl w-full max-w-md mx-4 overflow-hidden">
          <!-- Header -->
          <div class="p-6 pb-4">
            <div class="flex items-start gap-4">
              <!-- Icon -->
              <div
                class="flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center"
                :class="iconBgClass"
              >
                <svg v-if="confirmStore.config.type === 'danger'" class="w-6 h-6" :class="iconClass" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <svg v-else-if="confirmStore.config.type === 'warning'" class="w-6 h-6" :class="iconClass" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <svg v-else class="w-6 h-6" :class="iconClass" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
              </div>

              <!-- Text -->
              <div class="flex-1">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-white">
                  {{ confirmStore.config.title }}
                </h3>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                  {{ confirmStore.config.message }}
                </p>
              </div>
            </div>
          </div>

          <!-- Actions -->
          <div class="px-6 py-4 bg-gray-50 dark:bg-slate-700/50 flex justify-end gap-3">
            <button
              @click="confirmStore.cancel()"
              class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-slate-600 border border-gray-300 dark:border-slate-500 rounded-lg hover:bg-gray-50 dark:hover:bg-slate-500 transition-colors"
            >
              {{ confirmStore.config.cancelText }}
            </button>
            <button
              @click="confirmStore.confirm()"
              class="px-4 py-2 text-sm font-medium text-white rounded-lg transition-colors"
              :class="confirmBtnClass"
            >
              {{ confirmStore.config.confirmText }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue'
import { useConfirmStore } from '@/stores/confirm'

const confirmStore = useConfirmStore()

const iconBgClass = computed(() => {
  switch (confirmStore.config.type) {
    case 'danger':
      return 'bg-red-100 dark:bg-red-900/30'
    case 'warning':
      return 'bg-yellow-100 dark:bg-yellow-900/30'
    default:
      return 'bg-blue-100 dark:bg-blue-900/30'
  }
})

const iconClass = computed(() => {
  switch (confirmStore.config.type) {
    case 'danger':
      return 'text-red-600 dark:text-red-400'
    case 'warning':
      return 'text-yellow-600 dark:text-yellow-400'
    default:
      return 'text-blue-600 dark:text-blue-400'
  }
})

const confirmBtnClass = computed(() => {
  switch (confirmStore.config.type) {
    case 'danger':
      return 'bg-red-600 hover:bg-red-700'
    case 'warning':
      return 'bg-yellow-600 hover:bg-yellow-700'
    default:
      return 'bg-blue-600 hover:bg-blue-700'
  }
})
</script>

<style scoped>
.modal-enter-active {
  animation: modal-in 0.2s ease-out;
}

.modal-leave-active {
  animation: modal-out 0.15s ease-in forwards;
}

.modal-enter-active > div:last-child {
  animation: modal-scale-in 0.2s ease-out;
}

.modal-leave-active > div:last-child {
  animation: modal-scale-out 0.15s ease-in forwards;
}

@keyframes modal-in {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes modal-out {
  from { opacity: 1; }
  to { opacity: 0; }
}

@keyframes modal-scale-in {
  from {
    transform: scale(0.95);
    opacity: 0;
  }
  to {
    transform: scale(1);
    opacity: 1;
  }
}

@keyframes modal-scale-out {
  from {
    transform: scale(1);
    opacity: 1;
  }
  to {
    transform: scale(0.95);
    opacity: 0;
  }
}
</style>
