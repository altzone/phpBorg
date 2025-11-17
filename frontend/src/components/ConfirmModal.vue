<template>
  <Teleport to="body">
    <Transition name="modal">
      <div
        v-if="modelValue"
        class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50 backdrop-blur-sm"
        @click.self="onCancel"
      >
        <div
          class="bg-white dark:bg-gray-800 rounded-lg shadow-2xl max-w-md w-full overflow-hidden transform transition-all"
          @click.stop
        >
          <!-- Header -->
          <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
              <!-- Icon -->
              <div
                class="flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center"
                :class="iconColorClass"
              >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    v-if="variant === 'danger'"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                  />
                  <path
                    v-else-if="variant === 'warning'"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"
                  />
                  <path
                    v-else
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                  />
                </svg>
              </div>

              <!-- Title -->
              <div class="flex-1">
                <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                  {{ title }}
                </h3>
              </div>

              <!-- Close button -->
              <button
                @click="onCancel"
                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
              >
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </button>
            </div>
          </div>

          <!-- Body -->
          <div class="px-6 py-4">
            <div class="text-gray-700 dark:text-gray-300 whitespace-pre-line">
              {{ message }}
            </div>
          </div>

          <!-- Footer -->
          <div class="px-6 py-4 bg-gray-50 dark:bg-gray-900/50 flex gap-3 justify-end">
            <button
              @click="onCancel"
              class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 border border-gray-300 dark:border-gray-600 rounded-lg hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
            >
              {{ cancelText }}
            </button>
            <button
              @click="onConfirm"
              class="px-4 py-2 text-sm font-medium text-white rounded-lg transition-colors"
              :class="confirmButtonClass"
            >
              {{ confirmText }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
  modelValue: {
    type: Boolean,
    required: true
  },
  title: {
    type: String,
    required: true
  },
  message: {
    type: String,
    required: true
  },
  confirmText: {
    type: String,
    default: 'Confirmer'
  },
  cancelText: {
    type: String,
    default: 'Annuler'
  },
  variant: {
    type: String,
    default: 'danger', // 'danger', 'warning', 'info'
    validator: (value) => ['danger', 'warning', 'info'].includes(value)
  }
})

const emit = defineEmits(['update:modelValue', 'confirm', 'cancel'])

const iconColorClass = computed(() => {
  switch (props.variant) {
    case 'danger':
      return 'bg-red-100 dark:bg-red-900/20 text-red-600 dark:text-red-400'
    case 'warning':
      return 'bg-yellow-100 dark:bg-yellow-900/20 text-yellow-600 dark:text-yellow-400'
    case 'info':
      return 'bg-blue-100 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400'
    default:
      return 'bg-gray-100 dark:bg-gray-900/20 text-gray-600 dark:text-gray-400'
  }
})

const confirmButtonClass = computed(() => {
  switch (props.variant) {
    case 'danger':
      return 'bg-red-600 hover:bg-red-700 focus:ring-red-500'
    case 'warning':
      return 'bg-yellow-600 hover:bg-yellow-700 focus:ring-yellow-500'
    case 'info':
      return 'bg-blue-600 hover:bg-blue-700 focus:ring-blue-500'
    default:
      return 'bg-gray-600 hover:bg-gray-700 focus:ring-gray-500'
  }
})

function onConfirm() {
  emit('confirm')
  emit('update:modelValue', false)
}

function onCancel() {
  emit('cancel')
  emit('update:modelValue', false)
}
</script>

<style scoped>
.modal-enter-active,
.modal-leave-active {
  transition: opacity 0.2s ease;
}

.modal-enter-from,
.modal-leave-to {
  opacity: 0;
}

.modal-enter-active > div,
.modal-leave-active > div {
  transition: transform 0.2s ease;
}

.modal-enter-from > div,
.modal-leave-to > div {
  transform: scale(0.95);
}
</style>
