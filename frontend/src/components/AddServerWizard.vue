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

        <!-- Step 1: Choose Method FIRST -->
        <div v-if="currentStep === 0">
          <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step1_method.title') }}</h3>
          <p class="text-gray-600 dark:text-slate-400 mb-6">{{ $t('server_wizard.step1_method.description') }}</p>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <!-- Method: Agent Linux (Recommended) -->
            <div
              @click="form.method = 'agent'"
              class="border-2 rounded-xl p-6 cursor-pointer transition hover:shadow-lg relative"
              :class="form.method === 'agent' ? 'border-green-500 bg-green-500/10' : 'border-gray-300 dark:border-slate-600 hover:border-slate-500'"
            >
              <span class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 bg-green-500 text-white text-xs rounded-full font-semibold">
                {{ $t('server_wizard.step1_method.recommended') }}
              </span>
              <div class="flex items-center justify-center w-12 h-12 rounded-full mb-4 mx-auto mt-2"
                :class="form.method === 'agent' ? 'bg-green-500' : 'bg-gray-400 dark:bg-slate-700'"
              >
                <!-- Linux/Terminal icon -->
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 9l3 3-3 3m5 0h3M5 20h14a2 2 0 002-2V6a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              </div>
              <h4 class="text-lg font-semibold text-gray-900 dark:text-white text-center mb-2">{{ $t('server_wizard.step1_method.agent_title') }}</h4>
              <p class="text-sm text-gray-600 dark:text-slate-400 text-center">{{ $t('server_wizard.step1_method.agent_desc') }}</p>
              <ul class="mt-4 text-xs text-gray-500 dark:text-slate-500 space-y-1">
                <li class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                  {{ $t('server_wizard.step1_method.agent_feature1') }}
                </li>
                <li class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                  {{ $t('server_wizard.step1_method.agent_feature2') }}
                </li>
                <li class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                  {{ $t('server_wizard.step1_method.agent_feature3') }}
                </li>
              </ul>
            </div>

            <!-- Method: Agent Windows -->
            <div
              @click="form.method = 'agent_windows'"
              class="border-2 rounded-xl p-6 cursor-pointer transition hover:shadow-lg relative"
              :class="form.method === 'agent_windows' ? 'border-cyan-500 bg-cyan-500/10' : 'border-gray-300 dark:border-slate-600 hover:border-slate-500'"
            >
              <span class="absolute -top-3 left-1/2 -translate-x-1/2 px-3 py-1 bg-cyan-500 text-white text-xs rounded-full font-semibold">
                Windows
              </span>
              <div class="flex items-center justify-center w-12 h-12 rounded-full mb-4 mx-auto mt-2"
                :class="form.method === 'agent_windows' ? 'bg-cyan-500' : 'bg-gray-400 dark:bg-slate-700'"
              >
                <!-- Windows icon -->
                <svg class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M0 3.449L9.75 2.1v9.451H0m10.949-9.602L24 0v11.4H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.9-1.801"/>
                </svg>
              </div>
              <h4 class="text-lg font-semibold text-gray-900 dark:text-white text-center mb-2">{{ $t('server_wizard.step1_method.agent_windows_title') }}</h4>
              <p class="text-sm text-gray-600 dark:text-slate-400 text-center">{{ $t('server_wizard.step1_method.agent_windows_desc') }}</p>
              <ul class="mt-4 text-xs text-gray-500 dark:text-slate-500 space-y-1">
                <li class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-cyan-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                  {{ $t('server_wizard.step1_method.windows_feature1') }}
                </li>
                <li class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-cyan-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                  {{ $t('server_wizard.step1_method.windows_feature2') }}
                </li>
                <li class="flex items-center gap-2">
                  <svg class="w-4 h-4 text-cyan-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                  {{ $t('server_wizard.step1_method.windows_feature3') }}
                </li>
              </ul>
            </div>

            <!-- Method: SSH Manual -->
            <div
              @click="form.method = 'ssh_manual'"
              class="border-2 rounded-xl p-6 cursor-pointer transition hover:shadow-lg"
              :class="form.method === 'ssh_manual' ? 'border-blue-500 bg-blue-500/10' : 'border-gray-300 dark:border-slate-600 hover:border-slate-500'"
            >
              <div class="flex items-center justify-center w-12 h-12 rounded-full mb-4 mx-auto"
                :class="form.method === 'ssh_manual' ? 'bg-blue-500' : 'bg-gray-400 dark:bg-slate-700'"
              >
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                </svg>
              </div>
              <h4 class="text-lg font-semibold text-gray-900 dark:text-white text-center mb-2">{{ $t('server_wizard.step1_method.ssh_manual_title') }}</h4>
              <p class="text-sm text-gray-600 dark:text-slate-400 text-center">{{ $t('server_wizard.step1_method.ssh_manual_desc') }}</p>
              <div class="mt-4 flex items-center justify-center">
                <span class="px-3 py-1 bg-blue-500/20 text-blue-400 text-xs rounded-full">{{ $t('server_wizard.step1_method.legacy') }}</span>
              </div>
            </div>

            <!-- Method: SSH Password -->
            <div
              @click="form.method = 'ssh_password'"
              class="border-2 rounded-xl p-6 cursor-pointer transition hover:shadow-lg"
              :class="form.method === 'ssh_password' ? 'border-blue-500 bg-blue-500/10' : 'border-gray-300 dark:border-slate-600 hover:border-slate-500'"
            >
              <div class="flex items-center justify-center w-12 h-12 rounded-full mb-4 mx-auto"
                :class="form.method === 'ssh_password' ? 'bg-blue-500' : 'bg-gray-400 dark:bg-slate-700'"
              >
                <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                </svg>
              </div>
              <h4 class="text-lg font-semibold text-gray-900 dark:text-white text-center mb-2">{{ $t('server_wizard.step1_method.ssh_password_title') }}</h4>
              <p class="text-sm text-gray-600 dark:text-slate-400 text-center">{{ $t('server_wizard.step1_method.ssh_password_desc') }}</p>
              <div class="mt-4 flex items-center justify-center">
                <span class="px-3 py-1 bg-yellow-500/20 text-yellow-400 text-xs rounded-full">{{ $t('server_wizard.step1_method.legacy') }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 2: Server Info (varies by method) -->
        <div v-if="currentStep === 1">
          <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step2_info.title') }}</h3>
          <p class="text-gray-600 dark:text-slate-400 mb-6">{{ $t('server_wizard.step2_info.description') }}</p>

          <div class="space-y-4">
            <!-- Server Name (always required) -->
            <div>
              <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step2_info.name') }} *</label>
              <input
                v-model="form.name"
                type="text"
                class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                :placeholder="$t('server_wizard.step2_info.name_placeholder')"
              />
            </div>

            <!-- Description (optional) -->
            <div>
              <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step2_info.description') }}</label>
              <textarea
                v-model="form.description"
                rows="2"
                class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent resize-none"
                :placeholder="$t('server_wizard.step2_info.description_placeholder')"
              ></textarea>
            </div>

            <!-- SSH-specific fields (only for SSH methods) -->
            <div v-if="form.method !== 'agent' && form.method !== 'agent_windows'" class="space-y-4 pt-4 border-t border-gray-200 dark:border-slate-700">
              <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-3 mb-4">
                <p class="text-sm text-blue-400">
                  <svg class="w-4 h-4 inline mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" /></svg>
                  {{ $t('server_wizard.step2_info.ssh_info') }}
                </p>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step2_info.hostname') }} *</label>
                <input
                  v-model="form.hostname"
                  type="text"
                  class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  :placeholder="$t('server_wizard.step2_info.hostname_placeholder')"
                />
              </div>

              <div class="grid grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step2_info.port') }}</label>
                  <input
                    v-model.number="form.port"
                    type="number"
                    class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  />
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step2_info.username') }}</label>
                  <input
                    v-model="form.username"
                    type="text"
                    class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  />
                </div>
              </div>
            </div>

            <!-- Agent info message (Linux) -->
            <div v-if="form.method === 'agent'" class="bg-green-500/10 border border-green-500/30 rounded-lg p-4 mt-4">
              <p class="text-sm text-green-400">
                <svg class="w-4 h-4 inline mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                {{ $t('server_wizard.step2_info.agent_info') }}
              </p>
            </div>

            <!-- Agent info message (Windows) -->
            <div v-if="form.method === 'agent_windows'" class="bg-cyan-500/10 border border-cyan-500/30 rounded-lg p-4 mt-4">
              <p class="text-sm text-cyan-400">
                <svg class="w-4 h-4 inline mr-1" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 111.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                {{ $t('server_wizard.step2_info.agent_windows_info') }}
              </p>
            </div>
          </div>
        </div>

        <!-- Step 3: Configuration -->
        <div v-if="currentStep === 2">

          <!-- Agent Method -->
          <div v-if="form.method === 'agent'">
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step3_config.agent_title') }}</h3>
            <p class="text-gray-600 dark:text-slate-400 mb-6">{{ $t('server_wizard.step3_config.agent_description') }}</p>

            <div class="space-y-4">
              <button
                v-if="!oneLinerToken"
                @click="generateOneLiner"
                :disabled="generatingToken"
                class="w-full px-4 py-3 bg-green-500 hover:bg-green-600 disabled:bg-gray-400 dark:disabled:bg-slate-600 text-white rounded-lg font-medium transition flex items-center justify-center gap-2"
              >
                <svg v-if="generatingToken" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                {{ generatingToken ? $t('server_wizard.step3_config.generating') : $t('server_wizard.step3_config.generate_script') }}
              </button>

              <div v-if="oneLinerToken" class="space-y-4">
                <div class="bg-gray-50 dark:bg-slate-800 rounded-lg p-4 border border-gray-300 dark:border-slate-600">
                  <div class="flex items-center justify-between mb-2">
                    <label class="text-sm font-medium text-gray-900 dark:text-white">{{ $t('server_wizard.step3_config.install_command') }}</label>
                    <button
                      @click="copyToClipboard(oneLinerToken.one_liner)"
                      class="px-3 py-1 bg-blue-500 hover:bg-blue-600 text-white text-sm rounded transition"
                    >
                      {{ $t('server_wizard.step3_config.copy') }}
                    </button>
                  </div>
                  <pre class="bg-slate-900 p-3 rounded text-xs text-green-400 overflow-x-auto whitespace-pre-wrap break-all">{{ oneLinerToken.one_liner }}</pre>
                </div>

                <div class="bg-purple-500/10 border border-purple-500/30 rounded-lg p-4">
                  <h4 class="text-sm font-semibold text-purple-600 dark:text-purple-400 mb-2">{{ $t('server_wizard.step3_config.instructions_title') }}</h4>
                  <ol class="text-sm text-gray-700 dark:text-slate-300 space-y-2 list-decimal list-inside">
                    <li>{{ $t('server_wizard.step3_config.agent_step1') }}</li>
                    <li>{{ $t('server_wizard.step3_config.agent_step2') }}</li>
                    <li>{{ $t('server_wizard.step3_config.agent_step3') }}</li>
                  </ol>
                </div>

                <!-- Status display -->
                <div class="flex items-center justify-between p-4 rounded-lg"
                  :class="installStatus === 'completed' ? 'bg-green-500/10 border border-green-500/30' : 'bg-yellow-500/10 border border-yellow-500/30'"
                >
                  <div class="flex items-center gap-3">
                    <svg v-if="installStatus === 'pending'" class="animate-spin h-6 w-6 text-yellow-400" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <svg v-else class="w-6 h-6 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span :class="installStatus === 'completed' ? 'text-green-400' : 'text-yellow-400'" class="font-medium">
                      {{ installStatus === 'completed' ? $t('server_wizard.step3_config.agent_connected') : $t('server_wizard.step3_config.waiting_agent') }}
                    </span>
                  </div>
                  <span class="text-xs text-gray-500">{{ $t('server_wizard.step3_config.expires') }}: {{ oneLinerToken.expires_at }}</span>
                </div>

                <!-- Server info when connected -->
                <div v-if="installStatus === 'completed' && serverInfo" class="bg-slate-800 rounded-lg p-4">
                  <h4 class="text-sm font-semibold text-white mb-3">{{ $t('server_wizard.step3_config.detected_info') }}</h4>
                  <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                      <span class="text-gray-400">{{ $t('server_wizard.step3_config.hostname') }}:</span>
                      <span class="text-white ml-2">{{ serverInfo.hostname }}</span>
                    </div>
                    <div>
                      <span class="text-gray-400">{{ $t('server_wizard.step3_config.ip_address') }}:</span>
                      <span class="text-white ml-2">{{ serverInfo.ip_address }}</span>
                    </div>
                    <div>
                      <span class="text-gray-400">{{ $t('server_wizard.step3_config.os') }}:</span>
                      <span class="text-white ml-2">{{ serverInfo.os_info }}</span>
                    </div>
                    <div>
                      <span class="text-gray-400">{{ $t('server_wizard.step3_config.borg_version') }}:</span>
                      <span class="text-white ml-2">{{ serverInfo.borg_version }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Agent Windows Method -->
          <div v-if="form.method === 'agent_windows'">
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step3_config.agent_windows_title') }}</h3>
            <p class="text-gray-600 dark:text-slate-400 mb-6">{{ $t('server_wizard.step3_config.agent_windows_description') }}</p>

            <div class="space-y-4">
              <!-- Download installer button -->
              <button
                v-if="!windowsInstallerReady"
                @click="prepareWindowsInstaller"
                :disabled="preparingInstaller"
                class="w-full px-4 py-3 bg-cyan-500 hover:bg-cyan-600 disabled:bg-gray-400 dark:disabled:bg-slate-600 text-white rounded-lg font-medium transition flex items-center justify-center gap-2"
              >
                <svg v-if="preparingInstaller" class="animate-spin h-5 w-5" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                <svg v-else class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24">
                  <path d="M0 3.449L9.75 2.1v9.451H0m10.949-9.602L24 0v11.4H10.949M0 12.6h9.75v9.451L0 20.699M10.949 12.6H24V24l-12.9-1.801"/>
                </svg>
                {{ preparingInstaller ? $t('server_wizard.step3_config.preparing') : $t('server_wizard.step3_config.prepare_windows_installer') }}
              </button>

              <div v-if="windowsInstallerReady" class="space-y-4">
                <!-- Download link -->
                <div class="bg-gray-50 dark:bg-slate-800 rounded-lg p-4 border border-gray-300 dark:border-slate-600">
                  <div class="flex items-center justify-between mb-3">
                    <label class="text-sm font-medium text-gray-900 dark:text-white">{{ $t('server_wizard.step3_config.installer_package') }}</label>
                    <a
                      :href="windowsInstallerUrl"
                      download
                      class="px-4 py-2 bg-cyan-500 hover:bg-cyan-600 text-white text-sm rounded-lg transition flex items-center gap-2"
                    >
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                      </svg>
                      {{ $t('server_wizard.step3_config.download_package') }}
                    </a>
                  </div>
                  <p class="text-sm text-gray-600 dark:text-slate-400">
                    {{ $t('server_wizard.step3_config.package_contains') }}
                  </p>
                </div>

                <div class="bg-cyan-500/10 border border-cyan-500/30 rounded-lg p-4">
                  <h4 class="text-sm font-semibold text-cyan-600 dark:text-cyan-400 mb-2">{{ $t('server_wizard.step3_config.instructions_title') }}</h4>
                  <ol class="text-sm text-gray-700 dark:text-slate-300 space-y-2 list-decimal list-inside">
                    <li>{{ $t('server_wizard.step3_config.windows_step1') }}</li>
                    <li>{{ $t('server_wizard.step3_config.windows_step2') }}</li>
                    <li>{{ $t('server_wizard.step3_config.windows_step3') }}</li>
                    <li>{{ $t('server_wizard.step3_config.windows_step4') }}</li>
                  </ol>
                </div>

                <!-- Status display -->
                <div class="flex items-center justify-between p-4 rounded-lg"
                  :class="windowsInstallStatus === 'completed' ? 'bg-green-500/10 border border-green-500/30' : 'bg-yellow-500/10 border border-yellow-500/30'"
                >
                  <div class="flex items-center gap-3">
                    <svg v-if="windowsInstallStatus === 'pending'" class="animate-spin h-6 w-6 text-yellow-400" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <svg v-else class="w-6 h-6 text-green-400" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
                    </svg>
                    <span :class="windowsInstallStatus === 'completed' ? 'text-green-400' : 'text-yellow-400'" class="font-medium">
                      {{ windowsInstallStatus === 'completed' ? $t('server_wizard.step3_config.agent_connected') : $t('server_wizard.step3_config.waiting_windows_agent') }}
                    </span>
                  </div>
                </div>

                <!-- Server info when connected -->
                <div v-if="windowsInstallStatus === 'completed' && windowsServerInfo" class="bg-slate-800 rounded-lg p-4">
                  <h4 class="text-sm font-semibold text-white mb-3">{{ $t('server_wizard.step3_config.detected_info') }}</h4>
                  <div class="grid grid-cols-2 gap-4 text-sm">
                    <div>
                      <span class="text-gray-400">{{ $t('server_wizard.step3_config.hostname') }}:</span>
                      <span class="text-white ml-2">{{ windowsServerInfo.hostname }}</span>
                    </div>
                    <div>
                      <span class="text-gray-400">{{ $t('server_wizard.step3_config.os') }}:</span>
                      <span class="text-white ml-2">{{ windowsServerInfo.os_info }}</span>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- SSH Manual Method -->
          <div v-if="form.method === 'ssh_manual'">
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step3_config.ssh_manual_title') }}</h3>
            <p class="text-gray-600 dark:text-slate-400 mb-6">{{ $t('server_wizard.step3_config.ssh_manual_description') }}</p>

            <div class="space-y-4">
              <div class="bg-gray-50 dark:bg-slate-800 rounded-lg p-4 border border-gray-300 dark:border-slate-600">
                <div class="flex items-center justify-between mb-2">
                  <label class="text-sm font-medium text-gray-900 dark:text-white">{{ $t('server_wizard.step3_config.public_key') }}</label>
                  <button
                    @click="copyToClipboard(publicKey)"
                    class="px-3 py-1 bg-blue-500 hover:bg-blue-600 text-white text-sm rounded transition"
                  >
                    {{ $t('server_wizard.step3_config.copy') }}
                  </button>
                </div>
                <pre class="bg-slate-900 p-3 rounded text-xs text-green-400 overflow-x-auto">{{ publicKey || $t('server_wizard.step3_config.loading') }}</pre>
              </div>

              <div class="bg-blue-500/10 border border-blue-500/30 rounded-lg p-4">
                <h4 class="text-sm font-semibold text-blue-600 dark:text-blue-400 mb-2">{{ $t('server_wizard.step3_config.instructions_title') }}</h4>
                <ol class="text-sm text-gray-700 dark:text-slate-300 space-y-2 list-decimal list-inside">
                  <li>{{ $t('server_wizard.step3_config.ssh_step1') }}</li>
                  <li>{{ $t('server_wizard.step3_config.ssh_step2') }}</li>
                  <li>{{ $t('server_wizard.step3_config.ssh_step3') }}</li>
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
                {{ testing ? $t('server_wizard.step3_config.testing') : $t('server_wizard.step3_config.test_connection') }}
              </button>

              <div v-if="connectionResult"
                class="p-4 rounded-lg"
                :class="connectionResult.success ? 'bg-green-500/10 border border-green-500/30' : 'bg-red-500/10 border border-red-500/30'"
              >
                <p class="text-sm font-medium" :class="connectionResult.success ? 'text-green-400' : 'text-red-400'">
                  {{ connectionResult.message }}
                </p>
                <p v-if="connectionResult.borg_version" class="text-xs text-gray-600 dark:text-slate-400 mt-1">
                  {{ $t('server_wizard.step3_config.borg_version') }}: {{ connectionResult.borg_version }}
                </p>
              </div>
            </div>
          </div>

          <!-- SSH Password Method -->
          <div v-if="form.method === 'ssh_password'">
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step3_config.ssh_password_title') }}</h3>
            <p class="text-gray-600 dark:text-slate-400 mb-6">{{ $t('server_wizard.step3_config.ssh_password_description') }}</p>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step3_config.password') }} *</label>
                <input
                  v-model="form.password"
                  type="password"
                  class="w-full bg-gray-50 dark:bg-slate-800 border border-gray-300 dark:border-slate-600 rounded-lg px-4 py-2.5 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-transparent"
                  :placeholder="$t('server_wizard.step3_config.password_placeholder')"
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
                  {{ $t('server_wizard.step3_config.use_sudo') }}
                </label>
              </div>

              <div class="bg-yellow-500/10 border border-yellow-500/30 rounded-lg p-4">
                <p class="text-sm text-yellow-400">
                  <strong>{{ $t('server_wizard.step3_config.warning') }}:</strong> {{ $t('server_wizard.step3_config.password_warning') }}
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
                {{ setupInProgress ? $t('server_wizard.step3_config.installing') : $t('server_wizard.step3_config.start_setup') }}
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

        </div>

        <!-- Step 4: Success -->
        <div v-if="currentStep === 3">
          <div class="text-center py-8">
            <div class="w-20 h-20 bg-green-500 rounded-full flex items-center justify-center mx-auto mb-6">
              <svg class="w-12 h-12 text-white" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
              </svg>
            </div>
            <h3 class="text-2xl font-bold text-gray-900 dark:text-white mb-2">{{ $t('server_wizard.step4_success.title') }}</h3>
            <p class="text-gray-600 dark:text-slate-400 mb-8">{{ $t('server_wizard.step4_success.description') }}</p>

            <div class="bg-gray-50 dark:bg-slate-800 rounded-lg p-6 text-left max-w-md mx-auto mb-6">
              <h4 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">{{ $t('server_wizard.step4_success.summary') }}</h4>
              <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                  <span class="text-gray-600 dark:text-slate-400">{{ $t('server_wizard.step4_success.name') }}:</span>
                  <span class="text-gray-900 dark:text-white font-medium">{{ createdServer?.name || form.name }}</span>
                </div>
                <div class="flex justify-between">
                  <span class="text-gray-600 dark:text-slate-400">{{ $t('server_wizard.step4_success.connection_mode') }}:</span>
                  <span class="text-gray-900 dark:text-white font-medium">{{ form.method === 'agent' ? 'Agent' : 'SSH' }}</span>
                </div>
                <div v-if="form.method !== 'agent'" class="flex justify-between">
                  <span class="text-gray-600 dark:text-slate-400">{{ $t('server_wizard.step4_success.hostname') }}:</span>
                  <span class="text-gray-900 dark:text-white font-medium">{{ form.hostname }}</span>
                </div>
                <div v-if="createdServer?.hostname" class="flex justify-between">
                  <span class="text-gray-600 dark:text-slate-400">{{ $t('server_wizard.step4_success.hostname') }}:</span>
                  <span class="text-gray-900 dark:text-white font-medium">{{ createdServer.hostname }}</span>
                </div>
              </div>
            </div>

            <p class="text-sm text-gray-500 dark:text-slate-400">
              {{ $t('server_wizard.step4_success.next_step') }}
            </p>
          </div>
        </div>

      </div>

      <!-- Footer -->
      <div class="bg-gray-50 dark:bg-slate-800/50 px-6 py-4 border-t border-gray-200 dark:border-white/5 flex items-center justify-between">
        <button
          v-if="currentStep > 0 && currentStep < 3"
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
            {{ currentStep === 3 ? $t('server_wizard.close') : $t('server_wizard.cancel') }}
          </button>
          <button
            v-if="currentStep < 3"
            @click="nextStep"
            :disabled="!canProceed"
            class="px-6 py-2 bg-blue-500 hover:bg-blue-600 disabled:bg-gray-400 dark:disabled:bg-slate-600 text-white rounded-lg transition"
          >
            {{ $t('server_wizard.next') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../services/api'

const { t } = useI18n()

const props = defineProps({
  isOpen: Boolean
})

const emit = defineEmits(['close', 'created'])

const currentStep = ref(0)
const steps = computed(() => [
  t('server_wizard.steps.method'),
  t('server_wizard.steps.info'),
  t('server_wizard.steps.configure'),
  t('server_wizard.steps.complete')
])

const form = ref({
  name: '',
  hostname: '',
  port: 22,
  username: 'root',
  description: '',
  method: 'agent', // agent, ssh_manual, ssh_password
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

const createdServer = ref(null)

// Windows agent variables
const preparingInstaller = ref(false)
const windowsInstallerReady = ref(false)
const windowsInstallerUrl = ref('')
const windowsInstallStatus = ref('pending')
const windowsServerInfo = ref(null)
const windowsPollInterval = ref(null)

const canProceed = computed(() => {
  if (currentStep.value === 0) {
    return !!form.value.method
  }
  if (currentStep.value === 1) {
    if (form.value.method === 'agent' || form.value.method === 'agent_windows') {
      return !!form.value.name
    }
    return form.value.name && form.value.hostname
  }
  if (currentStep.value === 2) {
    if (form.value.method === 'agent') {
      return installStatus.value === 'completed'
    }
    if (form.value.method === 'agent_windows') {
      return windowsInstallStatus.value === 'completed'
    }
    if (form.value.method === 'ssh_manual') {
      return connectionResult.value?.success === true
    }
    if (form.value.method === 'ssh_password') {
      return setupProgress.value?.percent === 100
    }
  }
  return true
})

// Load public key when wizard opens and SSH method is selected
watch([() => props.isOpen, () => form.value.method], async ([isOpen, method]) => {
  if (isOpen && (method === 'ssh_manual' || method === 'ssh_password') && !publicKey.value) {
    try {
      const response = await api.get('/server-wizard/public-key')
      publicKey.value = response.data.data.public_key
    } catch (error) {
      console.error('Failed to load public key:', error)
    }
  }
})

const nextStep = async () => {
  if (!canProceed.value) return

  // When moving from step 2 to step 3 for SSH methods, create the server first
  if (currentStep.value === 2 && (form.value.method === 'ssh_manual' || form.value.method === 'ssh_password')) {
    try {
      const response = await api.post('/servers', {
        name: form.value.name,
        hostname: form.value.hostname,
        port: form.value.port,
        username: form.value.username,
        description: form.value.description || null,
        backupType: 'external',
        connectionMode: 'ssh'
      })
      createdServer.value = response.data.data.server
      emit('created', createdServer.value)
    } catch (error) {
      console.error('Failed to create server:', error)
      return
    }
  }

  // For agent method, server is already created by callback - just fetch it
  if (currentStep.value === 2 && form.value.method === 'agent' && oneLinerToken.value) {
    try {
      const statusResponse = await api.get(`/server-wizard/install-status/${oneLinerToken.value.token}`)
      const status = statusResponse.data.data
      if (status.server_id) {
        const serverResponse = await api.get(`/servers/${status.server_id}`)
        createdServer.value = serverResponse.data.data.server
        emit('created', createdServer.value)
      }
    } catch (error) {
      console.error('Failed to fetch created server:', error)
    }
  }

  if (currentStep.value < 3) {
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
  if (windowsPollInterval.value) {
    clearInterval(windowsPollInterval.value)
  }

  // Reset form
  currentStep.value = 0
  form.value = {
    name: '',
    hostname: '',
    port: 22,
    username: 'root',
    description: '',
    method: 'agent',
    password: '',
    useSudo: false
  }
  publicKey.value = ''
  connectionResult.value = null
  setupProgress.value = null
  oneLinerToken.value = null
  installStatus.value = 'pending'
  serverInfo.value = null
  createdServer.value = null

  // Reset Windows variables
  preparingInstaller.value = false
  windowsInstallerReady.value = false
  windowsInstallerUrl.value = ''
  windowsInstallStatus.value = 'pending'
  windowsServerInfo.value = null

  emit('close')
}

const copyToClipboard = async (text) => {
  try {
    await navigator.clipboard.writeText(text)
  } catch (error) {
    console.error('Failed to copy:', error)
  }
}

const testConnection = async () => {
  testing.value = true
  connectionResult.value = null

  try {
    const response = await api.post('/server-wizard/test-connection', {
      hostname: form.value.hostname,
      port: form.value.port,
      username: form.value.username
    })

    const jobId = response.data.data?.job_id || response.data.job_id

    if (!jobId) {
      testing.value = false
      connectionResult.value = {
        success: false,
        message: t('server_wizard.step3_config.connection_failed')
      }
      return
    }

    const token = localStorage.getItem('access_token')
    const eventSource = new EventSource(`/api/sse/stream?token=${token}`)

    eventSource.addEventListener('jobs', (event) => {
      const data = JSON.parse(event.data)

      if (data.jobs) {
        const ourJob = data.jobs.find(j => j.id === jobId)

        if (ourJob && ourJob.status === 'completed') {
          eventSource.close()
          testing.value = false

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
              ? t('server_wizard.step3_config.connection_success')
              : (resultData.error || t('server_wizard.step3_config.connection_failed')),
            borg_version: resultData.borg_version
          }
        } else if (ourJob && ourJob.status === 'failed') {
          eventSource.close()
          testing.value = false

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
            message: resultData.error || t('server_wizard.step3_config.connection_failed')
          }
        }
      }
    })

    eventSource.onerror = () => {
      eventSource.close()
      testing.value = false
      connectionResult.value = {
        success: false,
        message: t('server_wizard.step3_config.connection_failed')
      }
    }

  } catch (error) {
    testing.value = false
    connectionResult.value = {
      success: false,
      message: error.response?.data?.error?.message || t('server_wizard.step3_config.connection_failed')
    }
  }
}

const setupWithPassword = async () => {
  setupInProgress.value = true
  setupProgress.value = { percent: 0, message: t('server_wizard.step3_config.starting') }

  try {
    const response = await api.post('/server-wizard/setup-with-password', {
      hostname: form.value.hostname,
      port: form.value.port,
      username: form.value.username,
      password: form.value.password,
      use_sudo: form.value.useSudo
    })

    setupJobId.value = response.data.data.job_id

    const token = localStorage.getItem('access_token')
    const eventSource = new EventSource(`/api/sse/stream?token=${token}`)

    eventSource.addEventListener('jobs', (event) => {
      const data = JSON.parse(event.data)

      if (data.jobs) {
        const ourJob = data.jobs.find(j => j.id === setupJobId.value)

        if (ourJob) {
          setupProgress.value = {
            percent: ourJob.progress || 0,
            message: ourJob.output || ''
          }

          if (ourJob.status === 'completed') {
            eventSource.close()
            setupInProgress.value = false
            setupProgress.value = {
              percent: 100,
              message: t('server_wizard.step3_config.setup_success')
            }
          } else if (ourJob.status === 'failed') {
            eventSource.close()
            setupInProgress.value = false
            setupProgress.value = {
              percent: 0,
              message: ourJob.result_data?.error || t('server_wizard.step3_config.setup_failed')
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
        message: t('server_wizard.step3_config.setup_failed')
      }
    }

  } catch (error) {
    setupInProgress.value = false
    setupProgress.value = {
      percent: 0,
      message: error.response?.data?.error?.message || t('server_wizard.step3_config.setup_failed')
    }
  }
}

const generateOneLiner = async () => {
  generatingToken.value = true

  try {
    const response = await api.post('/server-wizard/generate-install-token', {
      server_name: form.value.name
    })
    oneLinerToken.value = response.data.data

    // Start polling for installation status
    pollInterval.value = setInterval(async () => {
      try {
        const statusResponse = await api.get(`/server-wizard/install-status/${oneLinerToken.value.token}`)
        const status = statusResponse.data.data

        installStatus.value = status.status
        serverInfo.value = status.server_info

        if (status.status === 'completed') {
          clearInterval(pollInterval.value)
        } else if (status.is_expired) {
          clearInterval(pollInterval.value)
          installStatus.value = 'expired'
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

const prepareWindowsInstaller = async () => {
  preparingInstaller.value = true

  try {
    // Generate the download URL for the pre-configured installer package
    const token = localStorage.getItem('access_token')
    const agentName = encodeURIComponent(form.value.name)
    windowsInstallerUrl.value = `/api/agent/installer/package?agent_name=${agentName}`

    // Set as ready
    windowsInstallerReady.value = true

    // Start polling for agent registration
    // The agent will register when the installer runs on Windows
    windowsPollInterval.value = setInterval(async () => {
      try {
        // Check if an agent with this name has been registered
        const response = await api.get('/servers')
        const servers = response.data.data.servers || []

        // Look for a server with matching name and Windows OS
        const windowsServer = servers.find(s =>
          s.name === form.value.name &&
          s.os_info &&
          s.os_info.toLowerCase().includes('windows')
        )

        if (windowsServer) {
          windowsInstallStatus.value = 'completed'
          windowsServerInfo.value = {
            hostname: windowsServer.hostname,
            os_info: windowsServer.os_info
          }
          createdServer.value = windowsServer
          clearInterval(windowsPollInterval.value)
          emit('created', windowsServer)
        }
      } catch (error) {
        console.error('Failed to check Windows agent status:', error)
      }
    }, 5000)

  } catch (error) {
    console.error('Failed to prepare Windows installer:', error)
  } finally {
    preparingInstaller.value = false
  }
}
</script>
