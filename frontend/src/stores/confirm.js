import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Global confirm dialog store
 *
 * Usage:
 *   import { useConfirmStore } from '@/stores/confirm'
 *   const confirmStore = useConfirmStore()
 *
 *   // In async function:
 *   const confirmed = await confirmStore.show({
 *     title: 'Delete Item',
 *     message: 'Are you sure you want to delete this item?',
 *     confirmText: 'Delete',
 *     cancelText: 'Cancel',
 *     type: 'danger' // 'danger' | 'warning' | 'info'
 *   })
 *   if (confirmed) {
 *     // do something
 *   }
 */
export const useConfirmStore = defineStore('confirm', () => {
  const isOpen = ref(false)
  const config = ref({
    title: '',
    message: '',
    confirmText: 'Confirm',
    cancelText: 'Cancel',
    type: 'warning' // 'danger' | 'warning' | 'info'
  })

  let resolvePromise = null

  /**
   * Show confirm dialog and return promise
   * @param {Object} options - Dialog options
   * @returns {Promise<boolean>} - Resolves to true if confirmed, false if cancelled
   */
  function show(options = {}) {
    config.value = {
      title: options.title || 'Confirm',
      message: options.message || 'Are you sure?',
      confirmText: options.confirmText || 'Confirm',
      cancelText: options.cancelText || 'Cancel',
      type: options.type || 'warning'
    }
    isOpen.value = true

    return new Promise((resolve) => {
      resolvePromise = resolve
    })
  }

  /**
   * Confirm action
   */
  function confirm() {
    isOpen.value = false
    if (resolvePromise) {
      resolvePromise(true)
      resolvePromise = null
    }
  }

  /**
   * Cancel action
   */
  function cancel() {
    isOpen.value = false
    if (resolvePromise) {
      resolvePromise(false)
      resolvePromise = null
    }
  }

  return {
    isOpen,
    config,
    show,
    confirm,
    cancel
  }
})
