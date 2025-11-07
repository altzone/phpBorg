import { defineStore } from 'pinia'
import { ref } from 'vue'
import { settingsService } from '@/services/settings'

export const useSettingsStore = defineStore('settings', () => {
  // State
  const settings = ref({})
  const loading = ref(false)
  const error = ref(null)

  // Actions
  async function fetchSettings() {
    try {
      loading.value = true
      error.value = null
      const data = await settingsService.getAllSettings()
      settings.value = data.settings || {}
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to load settings'
      console.error('Fetch settings error:', err)
    } finally {
      loading.value = false
    }
  }

  async function fetchCategorySettings(category) {
    try {
      loading.value = true
      error.value = null
      const data = await settingsService.getSettingsByCategory(category)

      // Merge category settings into global settings
      if (!settings.value[category]) {
        settings.value[category] = {}
      }
      settings.value[category] = data.settings || {}

      return data.settings
    } catch (err) {
      error.value = err.response?.data?.error?.message || `Failed to load ${category} settings`
      console.error('Fetch category settings error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateSettings(settingsData) {
    try {
      loading.value = true
      error.value = null
      const data = await settingsService.updateSettings(settingsData)
      settings.value = data.settings || {}
      return data.settings
    } catch (err) {
      error.value = err.response?.data?.error?.message || 'Failed to update settings'
      console.error('Update settings error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  async function updateCategorySettings(category, settingsData) {
    try {
      loading.value = true
      error.value = null
      const data = await settingsService.updateCategorySettings(category, settingsData)

      // Update category in global settings
      if (!settings.value[category]) {
        settings.value[category] = {}
      }
      settings.value[category] = data.settings || {}

      return data.settings
    } catch (err) {
      error.value = err.response?.data?.error?.message || `Failed to update ${category} settings`
      console.error('Update category settings error:', err)
      throw err
    } finally {
      loading.value = false
    }
  }

  function getSetting(key, defaultValue = null) {
    // key format: "category.key" or just "key"
    if (key.includes('.')) {
      const [category, settingKey] = key.split('.')
      return settings.value[category]?.[`${category}.${settingKey}`] ?? defaultValue
    }

    // Search in all categories
    for (const category of Object.values(settings.value)) {
      if (category[key] !== undefined) {
        return category[key]
      }
    }

    return defaultValue
  }

  function clearError() {
    error.value = null
  }

  return {
    // State
    settings,
    loading,
    error,

    // Actions
    fetchSettings,
    fetchCategorySettings,
    updateSettings,
    updateCategorySettings,
    getSetting,
    clearError,
  }
})
