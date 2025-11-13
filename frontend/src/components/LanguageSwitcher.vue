<template>
  <div class="relative">
    <button
      @click="toggleMenu"
      class="flex items-center gap-2 px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors"
      :title="currentLanguage === 'fr' ? 'Changer de langue' : 'Change language'"
    >
      <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129" />
      </svg>
      <span class="text-sm font-medium text-gray-700 dark:text-gray-300 uppercase">{{ currentLanguage }}</span>
    </button>

    <!-- Dropdown Menu -->
    <div
      v-if="showMenu"
      class="absolute right-0 mt-2 w-48 bg-white dark:bg-gray-800 rounded-lg shadow-lg border border-gray-200 dark:border-gray-700 z-50"
    >
      <button
        @click="changeLanguage('fr')"
        :class="[
          'w-full px-4 py-2 text-left flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700 first:rounded-t-lg transition-colors',
          currentLanguage === 'fr' ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-300'
        ]"
      >
        <span class="text-2xl">🇫🇷</span>
        <span class="font-medium">Français</span>
        <svg v-if="currentLanguage === 'fr'" class="w-5 h-5 ml-auto" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
        </svg>
      </button>
      <button
        @click="changeLanguage('en')"
        :class="[
          'w-full px-4 py-2 text-left flex items-center gap-3 hover:bg-gray-50 dark:hover:bg-gray-700 last:rounded-b-lg transition-colors',
          currentLanguage === 'en' ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-600 dark:text-primary-400' : 'text-gray-700 dark:text-gray-300'
        ]"
      >
        <span class="text-2xl">🇬🇧</span>
        <span class="font-medium">English</span>
        <svg v-if="currentLanguage === 'en'" class="w-5 h-5 ml-auto" fill="currentColor" viewBox="0 0 20 20">
          <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'

const { locale } = useI18n()
const showMenu = ref(false)

const currentLanguage = computed(() => locale.value)

function toggleMenu() {
  showMenu.value = !showMenu.value
}

function changeLanguage(lang) {
  locale.value = lang
  localStorage.setItem('phpborg-locale', lang)
  showMenu.value = false
}

// Close menu on click outside
function handleClickOutside(event) {
  if (showMenu.value && !event.target.closest('.relative')) {
    showMenu.value = false
  }
}

onMounted(() => {
  document.addEventListener('click', handleClickOutside)
})

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside)
})
</script>
