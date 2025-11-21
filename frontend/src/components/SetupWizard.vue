<template>
  <Teleport to="body">
    <div v-if="show" class="fixed inset-0 z-50 overflow-hidden">
      <!-- Backdrop -->
      <div class="absolute inset-0 bg-gradient-to-br from-blue-900 via-purple-900 to-indigo-900"></div>

      <!-- Content -->
      <div class="relative h-full flex flex-col">
        <!-- Header -->
        <div class="flex-shrink-0 px-8 py-6">
          <div class="flex items-center justify-between">
            <div class="flex items-center space-x-4">
              <div class="w-12 h-12 bg-white/20 rounded-xl flex items-center justify-center">
                <svg class="w-7 h-7 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>
                </svg>
              </div>
              <div>
                <h1 class="text-2xl font-bold text-white">{{ $t('setup.title') }}</h1>
                <p class="text-blue-200 text-sm">{{ $t('setup.subtitle') }}</p>
              </div>
            </div>

            <!-- Step indicator -->
            <div class="flex items-center space-x-2">
              <template v-for="(step, index) in steps" :key="index">
                <div
                  :class="[
                    'w-10 h-10 rounded-full flex items-center justify-center text-sm font-medium transition-all',
                    currentStep === index
                      ? 'bg-white text-blue-900'
                      : currentStep > index
                        ? 'bg-green-500 text-white'
                        : 'bg-white/20 text-white/60'
                  ]"
                >
                  <svg v-if="currentStep > index" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"/>
                  </svg>
                  <span v-else>{{ index + 1 }}</span>
                </div>
                <div
                  v-if="index < steps.length - 1"
                  :class="[
                    'w-16 h-1 rounded',
                    currentStep > index ? 'bg-green-500' : 'bg-white/20'
                  ]"
                ></div>
              </template>
            </div>
          </div>
        </div>

        <!-- Main content -->
        <div class="flex-1 overflow-y-auto px-8 py-4">
          <div class="max-w-2xl mx-auto">
            <!-- Step 1: Identity -->
            <div v-if="currentStep === 0" class="space-y-6">
              <div class="text-center mb-8">
                <div class="w-20 h-20 bg-white/10 rounded-2xl flex items-center justify-center mx-auto mb-4">
                  <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
                  </svg>
                </div>
                <h2 class="text-xl font-semibold text-white">{{ $t('setup.step1.title') }}</h2>
                <p class="text-blue-200 mt-2">{{ $t('setup.step1.description') }}</p>
              </div>

              <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 space-y-5">
                <!-- App Name -->
                <div>
                  <label class="block text-sm font-medium text-white mb-2">
                    {{ $t('setup.step1.app_name') }} <span class="text-red-400">*</span>
                  </label>
                  <input
                    v-model="form.app_name"
                    type="text"
                    class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-white/50 focus:border-white/40 focus:ring-0"
                    :placeholder="$t('setup.step1.app_name_placeholder')"
                  />
                </div>

                <!-- Timezone -->
                <div>
                  <label class="block text-sm font-medium text-white mb-2">
                    {{ $t('setup.step1.timezone') }} <span class="text-red-400">*</span>
                  </label>
                  <select
                    v-model="form.timezone"
                    class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white focus:border-white/40 focus:ring-0"
                  >
                    <option v-for="tz in timezones" :key="tz" :value="tz" class="text-gray-900">{{ tz }}</option>
                  </select>
                </div>

                <!-- Language -->
                <div>
                  <label class="block text-sm font-medium text-white mb-2">
                    {{ $t('setup.step1.language') }} <span class="text-red-400">*</span>
                  </label>
                  <div class="grid grid-cols-2 gap-3">
                    <button
                      @click="form.language = 'fr'"
                      :class="[
                        'px-4 py-3 rounded-xl border-2 transition-all flex items-center justify-center space-x-2',
                        form.language === 'fr'
                          ? 'bg-white text-blue-900 border-white'
                          : 'bg-white/10 text-white border-white/20 hover:border-white/40'
                      ]"
                    >
                      <span class="text-xl">🇫🇷</span>
                      <span>Français</span>
                    </button>
                    <button
                      @click="form.language = 'en'"
                      :class="[
                        'px-4 py-3 rounded-xl border-2 transition-all flex items-center justify-center space-x-2',
                        form.language === 'en'
                          ? 'bg-white text-blue-900 border-white'
                          : 'bg-white/10 text-white border-white/20 hover:border-white/40'
                      ]"
                    >
                      <span class="text-xl">🇬🇧</span>
                      <span>English</span>
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Step 2: Network -->
            <div v-if="currentStep === 1" class="space-y-6">
              <div class="text-center mb-8">
                <div class="w-20 h-20 bg-white/10 rounded-2xl flex items-center justify-center mx-auto mb-4">
                  <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>
                  </svg>
                </div>
                <h2 class="text-xl font-semibold text-white">{{ $t('setup.step2.title') }}</h2>
                <p class="text-blue-200 mt-2">{{ $t('setup.step2.description') }}</p>
              </div>

              <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 space-y-5">
                <!-- Loading -->
                <div v-if="loadingNetwork" class="text-center py-8">
                  <div class="animate-spin w-8 h-8 border-2 border-white/30 border-t-white rounded-full mx-auto mb-4"></div>
                  <p class="text-white/70">{{ $t('setup.step2.detecting') }}</p>
                </div>

                <template v-else>
                  <!-- Internal IP -->
                  <div>
                    <label class="block text-sm font-medium text-white mb-2">
                      {{ $t('setup.step2.internal_ip') }} <span class="text-red-400">*</span>
                    </label>
                    <p class="text-blue-200 text-xs mb-2">{{ $t('setup.step2.internal_ip_hint') }}</p>
                    <div v-if="networkInfo.private_ips.length > 0" class="space-y-2 mb-2">
                      <button
                        v-for="ip in networkInfo.private_ips"
                        :key="ip.ip"
                        @click="form.internal_ip = ip.ip"
                        :class="[
                          'w-full px-4 py-3 rounded-xl border-2 transition-all text-left flex items-center justify-between',
                          form.internal_ip === ip.ip
                            ? 'bg-white text-blue-900 border-white'
                            : 'bg-white/10 text-white border-white/20 hover:border-white/40'
                        ]"
                      >
                        <span>{{ ip.ip }}</span>
                        <span class="text-sm opacity-60">{{ ip.interface }}</span>
                      </button>
                    </div>
                    <input
                      v-model="form.internal_ip"
                      type="text"
                      class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-white/50 focus:border-white/40 focus:ring-0"
                      :placeholder="$t('setup.step2.ip_placeholder')"
                    />
                  </div>

                  <!-- External IP -->
                  <div>
                    <label class="block text-sm font-medium text-white mb-2">
                      {{ $t('setup.step2.external_ip') }} <span class="text-red-400">*</span>
                    </label>
                    <p class="text-blue-200 text-xs mb-2">{{ $t('setup.step2.external_ip_hint') }}</p>
                    <div v-if="networkInfo.public_ip" class="mb-2">
                      <button
                        @click="form.external_ip = networkInfo.public_ip"
                        :class="[
                          'w-full px-4 py-3 rounded-xl border-2 transition-all text-left flex items-center justify-between',
                          form.external_ip === networkInfo.public_ip
                            ? 'bg-white text-blue-900 border-white'
                            : 'bg-white/10 text-white border-white/20 hover:border-white/40'
                        ]"
                      >
                        <span>{{ networkInfo.public_ip }}</span>
                        <span class="text-sm opacity-60">{{ $t('setup.step2.detected') }}</span>
                      </button>
                    </div>
                    <input
                      v-model="form.external_ip"
                      type="text"
                      class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-white/50 focus:border-white/40 focus:ring-0"
                      :placeholder="$t('setup.step2.ip_placeholder')"
                    />
                  </div>
                </template>
              </div>
            </div>

            <!-- Step 3: Email (Optional) -->
            <div v-if="currentStep === 2" class="space-y-6">
              <div class="text-center mb-8">
                <div class="w-20 h-20 bg-white/10 rounded-2xl flex items-center justify-center mx-auto mb-4">
                  <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                  </svg>
                </div>
                <h2 class="text-xl font-semibold text-white">{{ $t('setup.step3.title') }}</h2>
                <p class="text-blue-200 mt-2">{{ $t('setup.step3.description') }}</p>
                <span class="inline-block mt-2 px-3 py-1 bg-yellow-500/20 text-yellow-300 text-xs rounded-full">
                  {{ $t('setup.optional') }}
                </span>
              </div>

              <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 space-y-5">
                <!-- Enable Email -->
                <div class="flex items-center justify-between">
                  <div>
                    <label class="block text-sm font-medium text-white">{{ $t('setup.step3.enable_email') }}</label>
                    <p class="text-blue-200 text-xs">{{ $t('setup.step3.enable_email_hint') }}</p>
                  </div>
                  <button
                    @click="form.email_enabled = !form.email_enabled"
                    :class="[
                      'relative inline-flex h-6 w-11 items-center rounded-full transition-colors',
                      form.email_enabled ? 'bg-green-500' : 'bg-white/20'
                    ]"
                  >
                    <span
                      :class="[
                        'inline-block h-4 w-4 transform rounded-full bg-white transition-transform',
                        form.email_enabled ? 'translate-x-6' : 'translate-x-1'
                      ]"
                    />
                  </button>
                </div>

                <template v-if="form.email_enabled">
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-sm font-medium text-white mb-2">{{ $t('setup.step3.smtp_host') }}</label>
                      <input
                        v-model="form.smtp_host"
                        type="text"
                        class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-white/50 focus:border-white/40 focus:ring-0"
                        placeholder="smtp.gmail.com"
                      />
                    </div>
                    <div>
                      <label class="block text-sm font-medium text-white mb-2">{{ $t('setup.step3.smtp_port') }}</label>
                      <input
                        v-model="form.smtp_port"
                        type="number"
                        class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-white/50 focus:border-white/40 focus:ring-0"
                        placeholder="587"
                      />
                    </div>
                  </div>

                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-sm font-medium text-white mb-2">{{ $t('setup.step3.smtp_username') }}</label>
                      <input
                        v-model="form.smtp_username"
                        type="text"
                        class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-white/50 focus:border-white/40 focus:ring-0"
                      />
                    </div>
                    <div>
                      <label class="block text-sm font-medium text-white mb-2">{{ $t('setup.step3.smtp_password') }}</label>
                      <input
                        v-model="form.smtp_password"
                        type="password"
                        class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-white/50 focus:border-white/40 focus:ring-0"
                      />
                    </div>
                  </div>

                  <div>
                    <label class="block text-sm font-medium text-white mb-2">{{ $t('setup.step3.smtp_encryption') }}</label>
                    <div class="grid grid-cols-3 gap-3">
                      <button
                        v-for="enc in ['tls', 'ssl', 'none']"
                        :key="enc"
                        @click="form.smtp_encryption = enc"
                        :class="[
                          'px-4 py-2 rounded-xl border-2 transition-all',
                          form.smtp_encryption === enc
                            ? 'bg-white text-blue-900 border-white'
                            : 'bg-white/10 text-white border-white/20 hover:border-white/40'
                        ]"
                      >
                        {{ enc.toUpperCase() }}
                      </button>
                    </div>
                  </div>

                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-sm font-medium text-white mb-2">{{ $t('setup.step3.email_from') }}</label>
                      <input
                        v-model="form.email_from"
                        type="email"
                        class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-white/50 focus:border-white/40 focus:ring-0"
                        placeholder="backup@example.com"
                      />
                    </div>
                    <div>
                      <label class="block text-sm font-medium text-white mb-2">{{ $t('setup.step3.email_from_name') }}</label>
                      <input
                        v-model="form.email_from_name"
                        type="text"
                        class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-white/50 focus:border-white/40 focus:ring-0"
                        :placeholder="form.app_name || 'phpBorg'"
                      />
                    </div>
                  </div>
                </template>
              </div>
            </div>

            <!-- Step 4: Backup Settings (Optional) -->
            <div v-if="currentStep === 3" class="space-y-6">
              <div class="text-center mb-8">
                <div class="w-20 h-20 bg-white/10 rounded-2xl flex items-center justify-center mx-auto mb-4">
                  <svg class="w-10 h-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/>
                  </svg>
                </div>
                <h2 class="text-xl font-semibold text-white">{{ $t('setup.step4.title') }}</h2>
                <p class="text-blue-200 mt-2">{{ $t('setup.step4.description') }}</p>
                <span class="inline-block mt-2 px-3 py-1 bg-yellow-500/20 text-yellow-300 text-xs rounded-full">
                  {{ $t('setup.optional') }}
                </span>
              </div>

              <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-6 space-y-5">
                <!-- Retention Days -->
                <div>
                  <label class="block text-sm font-medium text-white mb-2">{{ $t('setup.step4.retention_days') }}</label>
                  <p class="text-blue-200 text-xs mb-2">{{ $t('setup.step4.retention_days_hint') }}</p>
                  <input
                    v-model="form.backup_retention_days"
                    type="number"
                    min="1"
                    max="365"
                    class="w-full px-4 py-3 bg-white/10 border border-white/20 rounded-xl text-white placeholder-white/50 focus:border-white/40 focus:ring-0"
                  />
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Footer -->
        <div class="flex-shrink-0 px-8 py-6 bg-black/20">
          <div class="max-w-2xl mx-auto flex items-center justify-between">
            <button
              v-if="currentStep > 0"
              @click="previousStep"
              class="px-6 py-3 bg-white/10 text-white rounded-xl hover:bg-white/20 transition-colors"
            >
              {{ $t('setup.previous') }}
            </button>
            <div v-else></div>

            <div class="flex items-center space-x-3">
              <button
                v-if="currentStep >= 2"
                @click="skipStep"
                class="px-6 py-3 text-white/70 hover:text-white transition-colors"
              >
                {{ $t('setup.skip') }}
              </button>

              <button
                @click="nextStep"
                :disabled="!canProceed || saving"
                :class="[
                  'px-8 py-3 rounded-xl font-medium transition-all flex items-center space-x-2',
                  canProceed && !saving
                    ? 'bg-white text-blue-900 hover:bg-blue-50'
                    : 'bg-white/20 text-white/50 cursor-not-allowed'
                ]"
              >
                <span v-if="saving" class="animate-spin w-4 h-4 border-2 border-blue-900/30 border-t-blue-900 rounded-full"></span>
                <span>{{ currentStep === steps.length - 1 ? $t('setup.finish') : $t('setup.next') }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Toast notifications -->
      <div class="fixed top-4 right-4 z-[60] space-y-2">
        <div
          v-for="toast in toasts"
          :key="toast.id"
          :class="[
            'flex items-center gap-3 px-4 py-3 rounded-xl shadow-lg max-w-sm animate-slide-in',
            toast.type === 'success' ? 'bg-green-500 text-white' : 'bg-red-500 text-white'
          ]"
        >
          <div class="flex-1">
            <p class="font-semibold">{{ toast.title }}</p>
            <p v-if="toast.message" class="text-sm mt-1 opacity-90">{{ toast.message }}</p>
          </div>
          <button @click="removeToast(toast.id)" class="flex-shrink-0 hover:opacity-75">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import setupService from '@/services/setup'

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  }
})

const emit = defineEmits(['complete', 'close'])

const { t, locale } = useI18n()

// Simple toast system
const toasts = ref([])
let toastIdCounter = 0

function showToast(title, message = '', type = 'success', duration = 5000) {
  const id = ++toastIdCounter
  toasts.value.push({ id, title, message, type })
  setTimeout(() => removeToast(id), duration)
}

function removeToast(id) {
  const index = toasts.value.findIndex(t => t.id === id)
  if (index !== -1) toasts.value.splice(index, 1)
}

const currentStep = ref(0)
const saving = ref(false)
const loadingNetwork = ref(false)

const steps = ['identity', 'network', 'email', 'backup']

const networkInfo = ref({
  private_ips: [],
  public_ip: null
})

const form = ref({
  app_name: 'phpBorg',
  timezone: 'Europe/Paris',
  language: 'fr',
  internal_ip: '',
  external_ip: '',
  email_enabled: false,
  smtp_host: '',
  smtp_port: 587,
  smtp_username: '',
  smtp_password: '',
  smtp_encryption: 'tls',
  email_from: '',
  email_from_name: '',
  backup_retention_days: 30,
})

const timezones = [
  'Europe/Paris',
  'Europe/London',
  'Europe/Berlin',
  'Europe/Brussels',
  'Europe/Zurich',
  'America/New_York',
  'America/Chicago',
  'America/Denver',
  'America/Los_Angeles',
  'America/Toronto',
  'America/Montreal',
  'Asia/Tokyo',
  'Asia/Shanghai',
  'Asia/Singapore',
  'Australia/Sydney',
  'Pacific/Auckland',
  'UTC',
]

const canProceed = computed(() => {
  if (currentStep.value === 0) {
    return form.value.app_name && form.value.timezone && form.value.language
  }
  if (currentStep.value === 1) {
    return form.value.internal_ip && form.value.external_ip
  }
  return true // Optional steps
})

// Watch language change to update UI locale
watch(() => form.value.language, (newLang) => {
  locale.value = newLang
})

onMounted(async () => {
  await loadDefaults()
})

async function loadDefaults() {
  try {
    const status = await setupService.getStatus()
    if (status.defaults) {
      Object.assign(form.value, status.defaults)
    }
  } catch (error) {
    console.error('Failed to load defaults:', error)
  }
}

async function loadNetworkInfo() {
  loadingNetwork.value = true
  try {
    const data = await setupService.detectNetwork()
    networkInfo.value = data

    // Pre-select first private IP if available
    if (data.private_ips.length > 0 && !form.value.internal_ip) {
      form.value.internal_ip = data.private_ips[0].ip
    }

    // Pre-select public IP if available
    if (data.public_ip && !form.value.external_ip) {
      form.value.external_ip = data.public_ip
    }
  } catch (error) {
    console.error('Failed to detect network:', error)
    showToast(t('setup.errors.network_detection'), '', 'error')
  } finally {
    loadingNetwork.value = false
  }
}

async function nextStep() {
  if (!canProceed.value) return

  if (currentStep.value === 0) {
    // Moving to network step, load network info
    currentStep.value++
    await loadNetworkInfo()
  } else if (currentStep.value < steps.length - 1) {
    currentStep.value++
  } else {
    // Final step - save
    await completeSetup()
  }
}

function previousStep() {
  if (currentStep.value > 0) {
    currentStep.value--
  }
}

function skipStep() {
  if (currentStep.value < steps.length - 1) {
    currentStep.value++
  } else {
    completeSetup()
  }
}

async function completeSetup() {
  saving.value = true
  try {
    await setupService.complete(form.value)
    showToast(t('setup.success'), '', 'success')
    emit('complete')
  } catch (error) {
    console.error('Setup failed:', error)
    showToast(t('setup.errors.save_failed'), error.response?.data?.error?.message || '', 'error')
  } finally {
    saving.value = false
  }
}
</script>
