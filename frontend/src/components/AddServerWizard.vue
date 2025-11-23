<template>
  <div v-if="isOpen" class="fixed inset-0 bg-black/50 backdrop-blur-sm flex items-center justify-center z-50 p-4">
    <div class="bg-white dark:bg-gradient-to-br dark:from-slate-900 dark:to-slate-800 rounded-2xl shadow-2xl border border-gray-200 dark:border-white/10 w-full max-w-4xl max-h-[90vh] overflow-hidden">
      <!-- Header -->
      <div class="bg-gradient-to-r from-blue-600 to-indigo-600 px-6 py-4 flex items-center justify-between">
        <div class="flex items-center gap-3">
          <svg class="w-8 h-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01" />
          </svg>
          <div>
            <h2 class="text-xl font-bold text-white">{{ $t('server_wizard.title') }}</h2>
            <p class="text-blue-100 text-sm">{{ $t('server_wizard.subtitle') }}</p>
          </div>
        </div>
        <button @click="close" class="text-white/80 hover:text-white transition">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Progress Steps -->
      <div class="bg-gray-100 dark:bg-slate-800/50 px-6 py-4 border-b border-gray-200 dark:border-white/5">
        <div class="flex items-center justify-between max-w-2xl mx-auto">
          <div v-for="(step, index) in steps" :key="index" class="flex items-center" :class="{ 'flex-1': index < steps.length - 1 }">
            <div class="flex flex-col items-center">
              <div
                class="w-10 h-10 rounded-full flex items-center justify-center font-semibold transition"
                :class="currentStep > index ? 'bg-green-500 text-white' : currentStep === index ? 'bg-blue-500 text-white' : 'bg-gray-300 dark:bg-slate-700 text-gray-600 dark:text-slate-400'"
              >
                <svg v-if="currentStep > index" class="w-6 h-6" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
                <span v-else>{{ index + 1 }}</span>
              </div>
              <span class="text-xs mt-1 text-gray-600 dark:text-slate-400">{{ step }}</span>
            </div>
            <div v-if="index < steps.length - 1" class="flex-1 h-0.5 mx-2 -mt-6" :class="currentStep > index ? 'bg-green-500' : 'bg-gray-300 dark:bg-slate-700'"></div>
          </div>
        </div>
      </div>

      <!-- Content -->
      <div class="p-6 overflow-y-auto" style="max-height: calc(90vh - 240px)">

        <!-- Step 1: Basic Info -->
        <div v-if="currentStep === 0">
          <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step1.title') }}</h3>
          <p class="text-gray-600 dark:text-slate-400 mb-6">{{ $t('server_wizard.step1.description') }}</p>

          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step1.name') }} *</label>
              <input
                v-model="form.name"
                type="text"
                class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                :placeholder="$t('server_wizard.step1.name_placeholder')"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step1.hostname') }} *</label>
              <input
                v-model="form.hostname"
                type="text"
                class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                :placeholder="$t('server_wizard.step1.hostname_placeholder')"
              />
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step1.port') }}</label>
                <input
                  v-model.number="form.port"
                  type="number"
                  class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step1.username') }}</label>
                <input
                  v-model="form.username"
                  type="text"
                  class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                />
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step1.description') }}</label>
              <textarea
                v-model="form.description"
                rows="3"
                class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                :placeholder="$t('server_wizard.step1.description_placeholder')"
              ></textarea>
            </div>
          </div>
        </div>

        <!-- Step 2: Choose Method -->
        <div v-if="currentStep === 1">
          <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step2.title') }}</h3>
          <p class="text-gray-600 dark:text-slate-400 mb-6">{{ $t('server_wizard.step2.description') }}</p>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <!-- Method 1: Manual -->
            <div
              @click="form.method = 'manual'"
              class="border-2 rounded-xl p-6 cursor-pointer transition hover:shadow-lg"
              :class="form.method === 'manual' ? 'border-blue-500 bg-blue-500/10' : 'border-gray-300 dark:border-slate-600 hover:border-slate-500'"
            >
              <div class="flex items-center justify-center w-12 h-12 rounded-full mb-4 mx-auto"
                :class="form.method === 'manual' ? 'bg-blue-500' : 'bg-gray-400 dark:bg-slate-700'"
              >
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                </svg>
              </div>
              <h4 class="text-lg font-semibold text-gray-900 dark:text-white text-center mb-2">{{ $t('server_wizard.step2.method1_title') }}</h4>
              <p class="text-sm text-gray-600 dark:text-slate-400 text-center">{{ $t('server_wizard.step2.method1_desc') }}</p>
              <div class="mt-4 flex items-center justify-center gap-2">
                <span class="px-3 py-1 bg-green-500/20 text-green-400 text-xs rounded-full">{{ $t('server_wizard.step2.easy') }}</span>
              </div>
            </div>

            <!-- Method 2: Password -->
            <div
              @click="form.method = 'password'"
              class="border-2 rounded-xl p-6 cursor-pointer transition hover:shadow-lg"
              :class="form.method === 'password' ? 'border-blue-500 bg-blue-500/10' : 'border-gray-300 dark:border-slate-600 hover:border-slate-500'"
            >
              <div class="flex items-center justify-center w-12 h-12 rounded-full mb-4 mx-auto"
                :class="form.method === 'password' ? 'bg-blue-500' : 'bg-gray-400 dark:bg-slate-700'"
              >
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
              </div>
              <h4 class="text-lg font-semibold text-gray-900 dark:text-white text-center mb-2">{{ $t('server_wizard.step2.method2_title') }}</h4>
              <p class="text-sm text-gray-600 dark:text-slate-400 text-center">{{ $t('server_wizard.step2.method2_desc') }}</p>
              <div class="mt-4 flex items-center justify-center gap-2">
                <span class="px-3 py-1 bg-blue-500/20 text-blue-400 text-xs rounded-full">{{ $t('server_wizard.step2.automatic') }}</span>
              </div>
            </div>

            <!-- Method 3: One-Liner -->
            <div
              @click="form.method = 'oneliner'"
              class="border-2 rounded-xl p-6 cursor-pointer transition hover:shadow-lg"
              :class="form.method === 'oneliner' ? 'border-blue-500 bg-blue-500/10' : 'border-gray-300 dark:border-slate-600 hover:border-slate-500'"
            >
              <div class="flex items-center justify-center w-12 h-12 rounded-full mb-4 mx-auto"
                :class="form.method === 'oneliner' ? 'bg-blue-500' : 'bg-gray-400 dark:bg-slate-700'"
              >
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              </div>
              <h4 class="text-lg font-semibold text-gray-900 dark:text-white text-center mb-2">{{ $t('server_wizard.step2.method3_title') }}</h4>
              <p class="text-sm text-gray-600 dark:text-slate-400 text-center">{{ $t('server_wizard.step2.method3_desc') }}</p>
              <div class="mt-4 flex items-center justify-center gap-2">
                <span class="px-3 py-1 bg-purple-500/20 text-purple-400 text-xs rounded-full">{{ $t('server_wizard.step2.professional') }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 3: Method-Specific Configuration -->
        <div v-if="currentStep === 2">

          <!-- Method 1: Manual SSH Key -->
          <div v-if="form.method === 'manual'">
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step3.manual.title') }}</h3>
            <p class="text-gray-600 dark:text-slate-400 mb-6">{{ $t('server_wizard.step3.manual.description') }}</p>

            <div class="space-y-4">
              <div class="bg-gray-50 dark:bg-slate-800 rounded-lg p-4 border border-gray-300 dark:border-slate-600">
                <div class="flex items-center justify-between mb-2">
                  <label class="text-sm font-medium text-gray-900 dark:text-white">{{ $t('server_wizard.step3.manual.public_key') }}</label>
                  <button
                    @click="copyToClipboard(publicKey)"
                    class="px-3 py-1 bg-blue-500 hover:bg-blue-600 text-white text-sm rounded transition"
                  >
                    {{ $t('server_wizard.step3.manual.copy') }}
                  </button>
                </div>
                <pre class="bg-slate-900 p-3 rounded text-xs text-green-400 overflow-x-auto">{{ publicKey || $t('server_wizard.step3.manual.loading') }}</pre>
              </div>

              <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-blue-600 dark:text-blue-400 mb-2">{{ $t('server_wizard.step3.manual.instructions_title') }}</h4>
                <ol class="text-sm text-gray-700 dark:text-slate-300 space-y-2 list-decimal list-inside">
                  <li>{{ $t('server_wizard.step3.manual.step1') }}</li>
                  <li>{{ $t('server_wizard.step3.manual.step2') }}</li>
                  <li>{{ $t('server_wizard.step3.manual.step3') }}</li>
                  <li>{{ $t('server_wizard.step3.manual.step4') }}</li>
                </ol>
              </div>

              <button
                @click="testConnection"
                :disabled="testing"
                class="w-full px-4 py-3 bg-green-500 hover:bg-green-600 disabled:bg-gray-400 dark:disabled:bg-slate-600 text-white rounded-lg font-medium transition flex items-center justify-center gap-2"
              >
                <svg v-if="testing" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{ testing ? $t('server_wizard.step3.manual.testing') : $t('server_wizard.step3.manual.test_button') }}
              </button>

              <div v-if="connectionResult"
                class="p-4 rounded-lg"
                :class="connectionResult.success ? 'bg-green-500/10 border border-green-500/30' : 'bg-red-500/10 border border-red-500/30'"
              >
                <p class="text-sm font-medium" :class="connectionResult.success ? 'text-green-400' : 'text-red-400'">
                  {{ connectionResult.message }}
                </p>
                <p v-if="connectionResult.borg_version" class="text-xs text-gray-600 dark:text-slate-400 mt-1">
                  {{ $t('server_wizard.step3.manual.borg_version') }}: {{ connectionResult.borg_version }}
                </p>
              </div>
            </div>
          </div>

          <!-- Method 2: Password -->
          <div v-if="form.method === 'password'">
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step3.password.title') }}</h3>
            <p class="text-gray-600 dark:text-slate-400 mb-6">{{ $t('server_wizard.step3.password.description') }}</p>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step3.password.password') }} *</label>
                <input
                  v-model="form.password"
                  type="password"
                  class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  :placeholder="$t('server_wizard.step3.password.password_placeholder')"
                />
              </div>

              <div class="flex items-center gap-3">
                <input
                  v-model="form.useSudo"
                  type="checkbox"
                  id="useSudo"
                  class="w-5 h-5 text-blue-500 bg-gray-50 dark:bg-slate-800 border-gray-300 dark:border-slate-600 rounded focus:ring-blue-500"
                />
                <label for="useSudo" class="text-sm text-gray-900 dark:text-white">
                  {{ $t('server_wizard.step3.password.use_sudo') }}
                </label>
              </div>

              <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-4">
                <p class="text-sm text-yellow-400">
                  <strong>{{ $t('server_wizard.step3.password.warning_title') }}:</strong> {{ $t('server_wizard.step3.password.warning_text') }}
                </p>
              </div>

              <button
                @click="setupWithPassword"
                :disabled="!form.password || setupInProgress"
                class="w-full px-4 py-3 bg-blue-500 hover:bg-blue-600 disabled:bg-gray-400 dark:disabled:bg-slate-600 text-white rounded-lg font-medium transition flex items-center justify-center gap-2"
              >
                <svg v-if="setupInProgress" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{ setupInProgress ? $t('server_wizard.step3.password.installing') : $t('server_wizard.step3.password.start_button') }}
              </button>

              <div v-if="setupProgress" class="space-y-2">
                <div class="flex items-center justify-between text-sm">
                  <span class="text-gray-600 dark:text-slate-400">{{ setupProgress.message }}</span>
                  <span class="text-blue-600 dark:text-blue-400">{{ setupProgress.percent }}%</span>
                </div>
                <div class="w-full bg-gray-200 dark:bg-slate-700 rounded-full h-2">
                  <div class="bg-blue-500 h-2 rounded-full transition-all" :style="{ width: setupProgress.percent + '%' }"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- Method 3: One-Liner -->
          <div v-if="form.method === 'oneliner'">
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step3.oneliner.title') }}</h3>
            <p class="text-gray-600 dark:text-slate-400 mb-6">{{ $t('server_wizard.step3.oneliner.description') }}</p>

            <div class="space-y-4">
              <button
                v-if="!oneLinerToken"
                @click="generateOneLiner"
                :disabled="generatingToken"
                class="w-full px-4 py-3 bg-blue-500 hover:bg-blue-600 disabled:bg-gray-400 dark:disabled:bg-slate-600 text-white rounded-lg font-medium transition"
              >
                {{ generatingToken ? $t('server_wizard.step3.oneliner.generating') : $t('server_wizard.step3.oneliner.generate_button') }}
              </button>

              <div v-if="oneLinerToken" class="space-y-4">
                <div class="bg-gray-50 dark:bg-slate-800 rounded-lg p-4 border border-gray-300 dark:border-slate-600">
                  <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium text-gray-900 dark:text-white">{{ $t('server_wizard.step3.oneliner.command') }}</label>
                    <button
                      @click="copyToClipboard(oneLinerToken.one_liner)"
                      class="px-3 py-1 bg-blue-500 hover:bg-blue-600 text-white text-sm rounded transition"
                    >
                      {{ $t('server_wizard.step3.oneliner.copy') }}
                    </button>
                  </div>
                  <pre class="bg-slate-900 p-3 rounded text-xs text-green-400 overflow-x-auto">{{ oneLinerToken.one_liner }}</pre>
                </div>

                <div class="bg-purple-500/10 border border-purple-500/30 rounded-lg p-4">
                  <h4 class="text-sm font-semibold text-purple-600 dark:text-purple-400 mb-2">{{ $t('server_wizard.step3.oneliner.instructions_title') }}</h4>
                  <ol class="text-sm text-gray-700 dark:text-slate-300 space-y-2 list-decimal list-inside">
                    <li>{{ $t('server_wizard.step3.oneliner.step1') }}</li>
                    <li>{{ $t('server_wizard.step3.oneliner.step2') }}</li>
                    <li>{{ $t('server_wizard.step3.oneliner.step3') }}</li>
                  </ol>
                </div>

                <div class="flex items-center justify-between text-sm text-gray-600 dark:text-slate-400">
                  <span>{{ $t('server_wizard.step3.oneliner.expires') }}: {{ oneLinerToken.expires_at }}</span>
                  <span v-if="installStatus === 'pending'" class="text-yellow-400 flex items-center gap-2">
                    <svg class="animate-spin h-4 w-4" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    {{ $t('server_wizard.step3.oneliner.waiting') }}
                  </span>
                  <span v-if="installStatus === 'completed'" class="text-green-400 flex items-center gap-2">
                    <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    {{ $t('server_wizard.step3.oneliner.completed') }}
                  </span>
                </div>

                <div v-if="installStatus === 'completed' && serverInfo" class="bg-green-500/10 border border-green-500/30 rounded-lg p-4">
                  <h4 class="text-sm font-semibold text-green-600 dark:text-green-400 mb-2">{{ $t('server_wizard.step3.oneliner.server_info') }}</h4>
                  <div class="text-sm text-gray-700 dark:text-slate-300 space-y-1">
                    <p><strong>{{ $t('server_wizard.step3.oneliner.detected_hostname') }}:</strong> {{ serverInfo.hostname }}</p>
                    <p><strong>{{ $t('server_wizard.step3.oneliner.ip_address') }}:</strong> {{ serverInfo.ip_address }}</p>
                    <p><strong>{{ $t('server_wizard.step3.oneliner.borg_version') }}:</strong> {{ serverInfo.borg_version }}</p>
                    <p><strong>{{ $t('server_wizard.step3.oneliner.os_info') }}:</strong> {{ serverInfo.os_info }}</p>
                  </div>
                </div>
              </div>
            </div>
          </div>

        </div>

        <!-- Step 4: Success -->
        <div v-if="currentStep === 3">
          <div class="text-center py-8">
            <div class="w-20 h-20 bg-green-500 rounded-full flex items-center justify-center mx-auto mb-6">
              <svg class="w-12 h-12 text-white" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
              </svg>
            </div>
            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step4.title') }}</h3>
            <p class="text-gray-600 dark:text-slate-400 mb-8">{{ $t('server_wizard.step4.description') }}</p>

            <div class="bg-gray-50 dark:bg-slate-800 rounded-lg p-6 text-left max-w-md mx-auto mb-6">
              <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step4.summary') }}</h4>
              <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                  <span class="text-gray-600 dark:text-slate-400">{{ $t('server_wizard.step4.name') }}:</span>
                  <span class="text-gray-900 dark:text-white font-medium">{{ form.name }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-gray-600 dark:text-slate-400">{{ $t('server_wizard.step4.hostname') }}:</span>
                  <span class="text-gray-900 dark:text-white font-medium">{{ form.hostname }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-gray-600 dark:text-slate-400">{{ $t('server_wizard.step4.port') }}:</span>
                  <span class="text-gray-900 dark:text-white font-medium">{{ form.port }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-gray-600 dark:text-slate-400">{{ $t('server_wizard.step4.method') }}:</span>
                  <span class="text-gray-900 dark:text-white font-medium">{{ getMethodName(form.method) }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>

      </div>

      <!-- Footer -->
      <div class="bg-gray-50 dark:bg-slate-800/50 px-6 py-4 border-t border-gray-200 dark:border-white/5 flex items-center justify-between">
        <button
          v-if="currentStep > 0"
          @click="previousStep"
          class="px-4 py-2 text-gray-600 dark:text-slate-400 hover:text-gray-900 dark:hover:text-white transition"
        >
          {{ $t('server_wizard.previous') }}
        </button>
        <div v-else></div>

        <div class="flex gap-3">
          <button
            @click="close"
            class="px-6 py-2 bg-gray-400 dark:bg-slate-700 hover:bg-gray-500 dark:hover:bg-slate-600 text-white rounded-lg transition"
          >
            {{ $t('server_wizard.cancel') }}
          </button>
          <button
            v-if="currentStep < 3"
            @click="nextStep"
            :disabled="!canProceed"
            class="px-6 py-2 bg-blue-500 hover:bg-blue-600 disabled:bg-gray-400 dark:disabled:bg-slate-600 text-white rounded-lg transition"
          >
            {{ $t('server_wizard.next') }}
          </button>
          <button
            v-if="currentStep === 3"
            @click="createServer"
            :disabled="creating"
            class="px-6 py-2 bg-green-500 hover:bg-green-600 disabled:bg-gray-400 dark:disabled:bg-slate-600 text-white rounded-lg transition flex items-center gap-2"
          >
            <svg v-if="creating" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ creating ? $t('server_wizard.creating') : $t('server_wizard.create_server') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../services/api'

const { t } = useI18n()

const props = defineProps({
  isOpen: Boolean
})

const emit = defineEmits(['close', 'created'])

const currentStep = ref(0)
const steps = computed(() => [
  t('server_wizard.steps.basic_info'),
  t('server_wizard.steps.choose_method'),
  t('server_wizard.steps.configure'),
  t('server_wizard.steps.complete')
])

const form = ref({
  name: '',
  hostname: '',
  port: 22,
  username: 'root',
  description: '',
  method: 'manual', // manual, password, oneliner
  password: '',
  useSudo: false
})

const publicKey = ref('')
const testing = ref(false)
const connectionResult = ref(null)

const setupInProgress = ref(false)
const setupProgress = ref(null)
const setupJobId = ref(null)

const generatingToken = ref(false)
const oneLinerToken = ref(null)
const installStatus = ref('pending')
const serverInfo = ref(null)
const pollInterval = ref(null)

const creating = ref(false)

const canProceed = computed(() => {
  if (currentStep.value === 0) {
    return form.value.name && form.value.hostname
  }
  if (currentStep.value === 1) {
    return !!form.value.method
  }
  if (currentStep.value === 2) {
    if (form.value.method === 'manual') {
      return connectionResult.value?.success === true
    }
    if (form.value.method === 'password') {
      return setupProgress.value?.percent === 100
    }
    if (form.value.method === 'oneliner') {
      return installStatus.value === 'completed'
    }
  }
  return true
})

// Load public key when wizard opens
watch(() => props.isOpen, async (isOpen) => {
  if (isOpen && !publicKey.value) {
    try {
      const response = await api.get('/server-wizard/public-key')
      publicKey.value = response.data.data.public_key
    } catch (error) {
      console.error('Failed to load public key:', error)
    }
  }
})

const nextStep = () => {
  if (canProceed.value && currentStep.value < 3) {
    currentStep.value++
  }
}

const previousStep = () => {
  if (currentStep.value > 0) {
    currentStep.value--
  }
}

const close = () => {
  // Cleanup
  if (pollInterval.value) {
    clearInterval(pollInterval.value)
  }

  // Reset form
  currentStep.value = 0
  form.value = {
    name: '',
    hostname: '',
    port: 22,
    username: 'root',
    description: '',
    method: 'manual',
    password: '',
    useSudo: false
  }
  publicKey.value = ''
  connectionResult.value = null
  setupProgress.value = null
  oneLinerToken.value = null
  installStatus.value = 'pending'
  serverInfo.value = null

  emit('close')
}

const copyToClipboard = async (text) => {
  try {
    await navigator.clipboard.writeText(text)
    // Could add toast notification here
  } catch (error) {
    console.error('Failed to copy:', error)
  }
}

const testConnection = async () => {
  testing.value = true
  connectionResult.value = null

  try {
    // Start connection test job
    const response = await api.post('/server-wizard/test-connection', {
      hostname: form.value.hostname,
      port: form.value.port,
      username: form.value.username
    })

    console.log('Test connection response:', response.data)
    const jobId = response.data.data?.job_id || response.data.job_id

    if (!jobId) {
      console.error('No job_id in response:', response.data)
      testing.value = false
      connectionResult.value = {
        success: false,
        message: t('server_wizard.step3.manual.connection_failed')
      }
      return
    }

    // Monitor job progress via global SSE stream (pass token in URL for EventSource)
    const token = localStorage.getItem('access_token')
    const eventSource = new EventSource(`/api/sse/stream?token=${token}`)

    eventSource.addEventListener('jobs', (event) => {
      const data = JSON.parse(event.data)

      // Check if this update is for our job
      if (data.job_id && data.job_id === jobId) {
        // This is real-time progress for our specific job
        const progressInfo = data.progress_info

        if (progressInfo) {
          console.log('Job progress:', progressInfo)
          // Progress updates are handled here if needed
        }
      } else if (data.jobs) {
        // This is a jobs list update, find our job
        const ourJob = data.jobs.find(j => j.id === jobId)

        if (ourJob && ourJob.status === 'completed') {
          eventSource.close()
          testing.value = false

          // Parse JSON output if present
          let resultData = {}
          if (ourJob.output) {
            try {
              resultData = JSON.parse(ourJob.output)
            } catch (e) {
              console.error('Failed to parse job output:', e)
            }
          }

          connectionResult.value = {
            success: resultData.success === true,
            message: resultData.success
              ? t('server_wizard.step3.manual.connection_success')
              : (resultData.error || t('server_wizard.step3.manual.connection_failed')),
            borg_version: resultData.borg_version
          }
        } else if (ourJob && ourJob.status === 'failed') {
          eventSource.close()
          testing.value = false

          // Parse JSON output if present
          let resultData = {}
          if (ourJob.output) {
            try {
              resultData = JSON.parse(ourJob.output)
            } catch (e) {
              console.error('Failed to parse job output:', e)
            }
          }

          connectionResult.value = {
            success: false,
            message: resultData.error || t('server_wizard.step3.manual.connection_failed')
          }
        }
      }
    })

    eventSource.onerror = () => {
      eventSource.close()
      testing.value = false
      connectionResult.value = {
        success: false,
        message: t('server_wizard.step3.manual.connection_failed')
      }
    }

  } catch (error) {
    testing.value = false
    connectionResult.value = {
      success: false,
      message: error.response?.data?.error?.message || t('server_wizard.step3.manual.connection_failed')
    }
  }
}

const setupWithPassword = async () => {
  setupInProgress.value = true
  setupProgress.value = { percent: 0, message: t('server_wizard.step3.password.starting') }

  try {
    const response = await api.post('/server-wizard/setup-with-password', {
      hostname: form.value.hostname,
      port: form.value.port,
      username: form.value.username,
      password: form.value.password,
      use_sudo: form.value.useSudo
    })

    setupJobId.value = response.data.data.job_id

    // Monitor job progress via global SSE stream (pass token in URL for EventSource)
    const token = localStorage.getItem('access_token')
    const eventSource = new EventSource(`/api/sse/stream?token=${token}`)

    eventSource.addEventListener('jobs', (event) => {
      const data = JSON.parse(event.data)

      // Check if this update is for our job
      if (data.job_id && data.job_id === setupJobId.value) {
        // This is real-time progress for our specific job
        const progressInfo = data.progress_info

        if (progressInfo) {
          setupProgress.value = {
            percent: progressInfo.percent || 0,
            message: progressInfo.output || ''
          }
        }
      } else if (data.jobs) {
        // This is a jobs list update, find our job
        const ourJob = data.jobs.find(j => j.id === setupJobId.value)

        if (ourJob) {
          // Update progress from job data
          setupProgress.value = {
            percent: ourJob.progress || 0,
            message: ourJob.output || ''
          }

          if (ourJob.status === 'completed') {
            eventSource.close()
            setupInProgress.value = false
            setupProgress.value = {
              percent: 100,
              message: t('server_wizard.step3.password.success')
            }
          } else if (ourJob.status === 'failed') {
            eventSource.close()
            setupInProgress.value = false
            setupProgress.value = {
              percent: 0,
              message: ourJob.result_data?.error || t('server_wizard.step3.password.failed')
            }
          }
        }
      }
    })

    eventSource.onerror = () => {
      eventSource.close()
      setupInProgress.value = false
      setupProgress.value = {
        percent: 0,
        message: t('server_wizard.step3.password.failed')
      }
    }

  } catch (error) {
    setupInProgress.value = false
    setupProgress.value = {
      percent: 0,
      message: error.response?.data?.error?.message || t('server_wizard.step3.password.failed')
    }
  }
}

const generateOneLiner = async () => {
  generatingToken.value = true

  try {
    const response = await api.post('/server-wizard/generate-install-token')
    oneLinerToken.value = response.data.data

    // Start polling for installation status
    pollInterval.value = setInterval(async () => {
      try {
        const statusResponse = await api.get(`/server-wizard/install-status/${oneLinerToken.value.token}`)
        const status = statusResponse.data.data

        installStatus.value = status.status

        if (status.status === 'completed') {
          serverInfo.value = status.server_info
          clearInterval(pollInterval.value)
        } else if (status.status === 'expired') {
          clearInterval(pollInterval.value)
        }
      } catch (error) {
        console.error('Failed to get install status:', error)
      }
    }, 3000)

  } catch (error) {
    console.error('Failed to generate token:', error)
  } finally {
    generatingToken.value = false
  }
}

const getMethodName = (method) => {
  const names = {
    manual: t('server_wizard.step2.method1_title'),
    password: t('server_wizard.step2.method2_title'),
    oneliner: t('server_wizard.step2.method3_title')
  }
  return names[method] || method
}

const createServer = async () => {
  creating.value = true

  try {
    const response = await api.post('/servers', {
      name: form.value.name,
      hostname: form.value.hostname,
      port: form.value.port,
      username: form.value.username,
      description: form.value.description || null,
      backupType: 'external'
    })

    emit('created', response.data.data.server)
    close()
  } catch (error) {
    console.error('Failed to create server:', error)
  } finally {
    creating.value = false
  }
}
</script>

