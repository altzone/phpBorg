import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import phpborgUpdateService from '@/services/phpborgUpdate'

/**
 * Store centralisé pour la gestion des mises à jour phpBorg
 * - Check automatique à la connexion
 * - Check périodique toutes les 10 minutes
 * - Expose le nombre de MAJ disponibles pour les badges
 */
export const useUpdateStore = defineStore('update', () => {
  // State
  const updateInfo = ref(null)
  const changelog = ref(null)
  const versionInfo = ref(null)
  const checking = ref(false)
  const lastCheck = ref(null)
  const checkInterval = ref(null)
  const initialized = ref(false)

  // Getters
  const hasUpdate = computed(() => updateInfo.value?.available === true)
  const updateCount = computed(() => updateInfo.value?.commits_behind || 0)
  const commits = computed(() => changelog.value?.commits || [])

  /**
   * Check rapide pour les badges (synchrone, pas de job)
   */
  async function checkQuickStatus() {
    try {
      const result = await phpborgUpdateService.getQuickStatus()
      if (result.success && result.data) {
        updateInfo.value = result.data
        lastCheck.value = new Date()
      }
      return result
    } catch (error) {
      console.error('[Update] Quick status check failed:', error)
      return null
    }
  }

  /**
   * Check complet avec changelog (via job asynchrone)
   */
  async function checkForUpdates() {
    if (checking.value) return null

    checking.value = true
    try {
      const result = await phpborgUpdateService.checkForUpdates()

      if (result.success && result.data.job_id) {
        // Attendre le résultat du job (polling)
        const jobResult = await waitForJob(result.data.job_id)

        if (jobResult) {
          updateInfo.value = jobResult.update_info || null
          changelog.value = jobResult.changelog || null
          versionInfo.value = jobResult.version_info || null
          lastCheck.value = new Date()
        }

        return jobResult
      }

      return null
    } catch (error) {
      console.error('[Update] Full check failed:', error)
      return null
    } finally {
      checking.value = false
    }
  }

  /**
   * Attendre qu'un job se termine
   */
  async function waitForJob(jobId, maxAttempts = 30) {
    for (let i = 0; i < maxAttempts; i++) {
      await new Promise(resolve => setTimeout(resolve, 2000))

      try {
        const result = await phpborgUpdateService.getJobResult(jobId)

        if (result.success && result.data.job) {
          const job = result.data.job

          if (job.status === 'completed') {
            return JSON.parse(job.output || '{}')
          } else if (job.status === 'failed') {
            console.error('[Update] Job failed:', job.error)
            return null
          }
        }
      } catch (error) {
        console.error('[Update] Error polling job:', error)
      }
    }

    console.warn('[Update] Job timeout after', maxAttempts, 'attempts')
    return null
  }

  /**
   * Initialiser le store (appelé après login)
   */
  async function init() {
    if (initialized.value) return

    console.log('[Update] Initializing update store...')
    initialized.value = true

    // Check immédiat au démarrage (rapide pour les badges)
    await checkQuickStatus()

    // Puis check complet en arrière-plan pour le changelog
    checkForUpdates()

    // Check périodique toutes les 10 minutes
    if (checkInterval.value) {
      clearInterval(checkInterval.value)
    }
    checkInterval.value = setInterval(() => {
      console.log('[Update] Periodic check...')
      checkQuickStatus()
    }, 10 * 60 * 1000) // 10 minutes
  }

  /**
   * Nettoyer le store (appelé au logout)
   */
  function cleanup() {
    if (checkInterval.value) {
      clearInterval(checkInterval.value)
      checkInterval.value = null
    }
    initialized.value = false
    updateInfo.value = null
    changelog.value = null
    versionInfo.value = null
    lastCheck.value = null
  }

  /**
   * Forcer un refresh (après une action utilisateur)
   */
  async function refresh() {
    return await checkForUpdates()
  }

  return {
    // State
    updateInfo,
    changelog,
    versionInfo,
    checking,
    lastCheck,

    // Getters
    hasUpdate,
    updateCount,
    commits,

    // Actions
    init,
    cleanup,
    refresh,
    checkQuickStatus,
    checkForUpdates
  }
})
