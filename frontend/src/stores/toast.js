import { defineStore } from 'pinia'
import { ref } from 'vue'

/**
 * Global toast notification store
 *
 * Usage:
 *   import { useToastStore } from '@/stores/toast'
 *   const toast = useToastStore()
 *   toast.success('Title', 'Message')
 *   toast.error('Error', 'Something went wrong')
 *   toast.warning('Warning', 'Be careful')
 *   toast.info('Info', 'Just so you know')
 */
export const useToastStore = defineStore('toast', () => {
  const toasts = ref([])
  let idCounter = 0

  /**
   * Add a toast notification
   * @param {string} title - Toast title
   * @param {string} message - Toast message (optional)
   * @param {string} type - 'success' | 'error' | 'warning' | 'info'
   * @param {number} duration - Auto-dismiss duration in ms (0 = no auto-dismiss)
   */
  function add(title, message = '', type = 'success', duration = 5000) {
    const id = ++idCounter
    toasts.value.push({ id, title, message, type })

    if (duration > 0) {
      setTimeout(() => remove(id), duration)
    }

    return id
  }

  /**
   * Remove a toast by ID
   */
  function remove(id) {
    const index = toasts.value.findIndex(t => t.id === id)
    if (index > -1) {
      toasts.value.splice(index, 1)
    }
  }

  /**
   * Clear all toasts
   */
  function clear() {
    toasts.value = []
  }

  // Convenience methods
  function success(title, message = '', duration = 5000) {
    return add(title, message, 'success', duration)
  }

  function error(title, message = '', duration = 8000) {
    return add(title, message, 'error', duration)
  }

  function warning(title, message = '', duration = 6000) {
    return add(title, message, 'warning', duration)
  }

  function info(title, message = '', duration = 5000) {
    return add(title, message, 'info', duration)
  }

  return {
    toasts,
    add,
    remove,
    clear,
    success,
    error,
    warning,
    info
  }
})
