import { createI18n } from 'vue-i18n'
import fr from './locales/fr.json'
import en from './locales/en.json'
import de from './locales/de.json'

// Get saved language from localStorage or default to French
const savedLocale = localStorage.getItem('phpborg-locale') || 'fr'

const i18n = createI18n({
  legacy: false, // Use Composition API mode
  locale: savedLocale,
  fallbackLocale: 'fr',
  messages: {
    fr,
    en,
    de
  },
  globalInjection: true
})

export default i18n
