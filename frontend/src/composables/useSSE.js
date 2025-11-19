import { onMounted, onUnmounted } from 'vue'
import { useSSEStore } from '@/stores/sse'

/**
 * Composable to easily subscribe to SSE events in components
 *
 * @example
 * // In WorkersView.vue
 * const { subscribe, isConnected } = useSSE()
 *
 * onMounted(() => {
 *   subscribe('workers', (data) => {
 *     workerStore.workers = data.workers
 *   })
 * })
 */
export function useSSE() {
  const sseStore = useSSEStore()
  const unsubscribers = []

  /**
   * Subscribe to a topic
   * Automatically unsubscribes on component unmount
   *
   * @param {string} topic - 'workers', 'jobs', 'backups', 'stats', 'all'
   * @param {Function} callback - Handler for events
   */
  function subscribe(topic, callback) {
    const unsubscribe = sseStore.subscribe(topic, callback)
    unsubscribers.push(unsubscribe)
    return unsubscribe
  }

  /**
   * Auto-cleanup on unmount
   */
  onUnmounted(() => {
    unsubscribers.forEach(unsub => unsub())
    unsubscribers.length = 0
  })

  return {
    subscribe,
    isConnected: sseStore.isConnected,
    connectionType: sseStore.connectionType
  }
}
