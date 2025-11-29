<template>
  <div class="space-y-6">
    <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100">{{ $t('settings.ssl.title') }}</h3>

    <!-- Current Status Card -->
    <div class="bg-gray-50 dark:bg-slate-800 rounded-lg p-6 border border-gray-200 dark:border-slate-700">
      <div class="flex items-center justify-between mb-4">
        <h4 class="font-medium text-gray-900 dark:text-white">{{ $t('settings.ssl.current_status') }}</h4>
        <button @click="loadStatus" class="text-sm text-primary-500 hover:text-primary-600">
          <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
          </svg>
          {{ $t('settings.ssl.refresh') }}
        </button>
      </div>

      <div v-if="loading" class="flex items-center justify-center py-8">
        <svg class="animate-spin h-8 w-8 text-primary-500" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
      </div>

      <div v-else-if="status">
        <!-- SSL Not Enabled -->
        <div v-if="!status.enabled" class="text-center py-4">
          <svg class="w-16 h-16 mx-auto text-yellow-500 mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
          </svg>
          <p class="text-gray-600 dark:text-gray-400 mb-4">{{ $t('settings.ssl.not_enabled') }}</p>
          <p class="text-sm text-gray-500 dark:text-gray-500">{{ $t('settings.ssl.not_enabled_desc') }}</p>
        </div>

        <!-- SSL Enabled -->
        <div v-else class="space-y-4">
          <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-full flex items-center justify-center"
              :class="status.expiry_critical ? 'bg-red-500' : status.expiry_warning ? 'bg-yellow-500' : 'bg-green-500'">
              <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
              </svg>
            </div>
            <div>
              <p class="font-semibold text-gray-900 dark:text-white">{{ status.domain }}</p>
              <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ getCertTypeLabel(status.type) }} - {{ $t('settings.ssl.issued_by') }} {{ status.issuer }}
              </p>
            </div>
          </div>

          <!-- Expiry Warning -->
          <div v-if="status.expiry_critical" class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
            <div class="flex items-center gap-2 text-red-700 dark:text-red-400">
              <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
              </svg>
              <span class="font-medium">{{ $t('settings.ssl.expires_critical', { days: status.days_remaining }) }}</span>
            </div>
          </div>

          <div v-else-if="status.expiry_warning" class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
            <div class="flex items-center gap-2 text-yellow-700 dark:text-yellow-400">
              <svg class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
              </svg>
              <span class="font-medium">{{ $t('settings.ssl.expires_warning', { days: status.days_remaining }) }}</span>
            </div>
          </div>

          <!-- Certificate Details -->
          <div class="grid grid-cols-2 gap-4 text-sm">
            <div>
              <span class="text-gray-500 dark:text-gray-400">{{ $t('settings.ssl.valid_from') }}:</span>
              <span class="ml-2 text-gray-900 dark:text-white">{{ status.valid_from }}</span>
            </div>
            <div>
              <span class="text-gray-500 dark:text-gray-400">{{ $t('settings.ssl.valid_until') }}:</span>
              <span class="ml-2 text-gray-900 dark:text-white">{{ status.valid_to }}</span>
            </div>
            <div>
              <span class="text-gray-500 dark:text-gray-400">{{ $t('settings.ssl.days_remaining') }}:</span>
              <span class="ml-2 font-medium" :class="status.expiry_critical ? 'text-red-600' : status.expiry_warning ? 'text-yellow-600' : 'text-green-600'">
                {{ status.days_remaining }} {{ $t('settings.ssl.days') }}
              </span>
            </div>
            <div v-if="status.type === 'letsencrypt'">
              <span class="text-gray-500 dark:text-gray-400">{{ $t('settings.ssl.auto_renew') }}:</span>
              <span class="ml-2" :class="status.auto_renew ? 'text-green-600' : 'text-red-600'">
                {{ status.auto_renew ? $t('settings.ssl.enabled') : $t('settings.ssl.disabled') }}
              </span>
            </div>
          </div>

          <!-- Let's Encrypt Actions -->
          <div v-if="status.type === 'letsencrypt'" class="flex gap-3 pt-4 border-t border-gray-200 dark:border-slate-700">
            <button @click="testRenewal" :disabled="testing" class="btn btn-secondary text-sm">
              <svg v-if="testing" class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ $t('settings.ssl.test_renewal') }}
            </button>
            <button @click="forceRenewal" :disabled="renewing" class="btn btn-primary text-sm">
              {{ $t('settings.ssl.force_renew') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Setup New Certificate -->
    <div class="bg-white dark:bg-slate-800 rounded-lg border border-gray-200 dark:border-slate-700">
      <div class="p-4 border-b border-gray-200 dark:border-slate-700">
        <h4 class="font-medium text-gray-900 dark:text-white">{{ $t('settings.ssl.setup_certificate') }}</h4>
      </div>

      <!-- Certificate Type Selection -->
      <div class="p-4 grid grid-cols-1 md:grid-cols-3 gap-4">
        <!-- Self-Signed -->
        <button
          @click="selectedType = 'self-signed'"
          class="p-4 border-2 rounded-lg text-left transition"
          :class="selectedType === 'self-signed' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-gray-200 dark:border-slate-700 hover:border-gray-300'"
        >
          <div class="w-10 h-10 rounded-full bg-gray-100 dark:bg-slate-700 flex items-center justify-center mb-3">
            <svg class="w-5 h-5 text-gray-600 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
            </svg>
          </div>
          <h5 class="font-medium text-gray-900 dark:text-white">{{ $t('settings.ssl.type_self_signed') }}</h5>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.ssl.type_self_signed_desc') }}</p>
        </button>

        <!-- Let's Encrypt -->
        <button
          @click="selectedType = 'letsencrypt'"
          class="p-4 border-2 rounded-lg text-left transition"
          :class="selectedType === 'letsencrypt' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-gray-200 dark:border-slate-700 hover:border-gray-300'"
        >
          <div class="w-10 h-10 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center mb-3">
            <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
          </div>
          <h5 class="font-medium text-gray-900 dark:text-white">Let's Encrypt</h5>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.ssl.type_letsencrypt_desc') }}</p>
        </button>

        <!-- Custom Certificate -->
        <button
          @click="selectedType = 'custom'"
          class="p-4 border-2 rounded-lg text-left transition"
          :class="selectedType === 'custom' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-gray-200 dark:border-slate-700 hover:border-gray-300'"
        >
          <div class="w-10 h-10 rounded-full bg-blue-100 dark:bg-blue-900/30 flex items-center justify-center mb-3">
            <svg class="w-5 h-5 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
            </svg>
          </div>
          <h5 class="font-medium text-gray-900 dark:text-white">{{ $t('settings.ssl.type_custom') }}</h5>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.ssl.type_custom_desc') }}</p>
        </button>
      </div>

      <!-- Configuration Forms -->
      <div class="p-4 border-t border-gray-200 dark:border-slate-700">
        <!-- Self-Signed Form -->
        <div v-if="selectedType === 'self-signed'" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.ssl.domain') }}</label>
            <input v-model="selfSignedForm.domain" type="text" class="input w-full" :placeholder="$t('settings.ssl.domain_placeholder')" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.ssl.validity_days') }}</label>
            <input v-model.number="selfSignedForm.days" type="number" class="input w-full" min="30" max="3650" />
          </div>
          <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-3">
            <p class="text-sm text-yellow-700 dark:text-yellow-400">
              <svg class="w-4 h-4 inline mr-1" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
              </svg>
              {{ $t('settings.ssl.self_signed_warning') }}
            </p>
          </div>
          <button @click="generateSelfSigned" :disabled="generating" class="btn btn-primary">
            <svg v-if="generating" class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ $t('settings.ssl.generate') }}
          </button>
        </div>

        <!-- Let's Encrypt Form -->
        <div v-if="selectedType === 'letsencrypt'" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.ssl.domain') }}</label>
            <input v-model="letsencryptForm.domain" type="text" class="input w-full" :placeholder="$t('settings.ssl.domain_placeholder')" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.ssl.email') }}</label>
            <input v-model="letsencryptForm.email" type="email" class="input w-full" placeholder="admin@example.com" />
            <p class="text-xs text-gray-500 mt-1">{{ $t('settings.ssl.email_hint') }}</p>
          </div>

          <!-- Validation Method -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.ssl.validation_method') }}</label>
            <div class="space-y-2">
              <label class="flex items-center p-3 border border-gray-200 dark:border-slate-700 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-700/50">
                <input v-model="letsencryptForm.method" type="radio" value="http" class="mr-3" />
                <div>
                  <span class="font-medium text-gray-900 dark:text-white">HTTP-01</span>
                  <p class="text-sm text-gray-500 dark:text-gray-400">{{ $t('settings.ssl.http01_desc') }}</p>
                </div>
              </label>
              <label class="flex items-center p-3 border border-gray-200 dark:border-slate-700 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-700/50">
                <input v-model="letsencryptForm.method" type="radio" value="dns-manual" class="mr-3" />
                <div>
                  <span class="font-medium text-gray-900 dark:text-white">DNS-01 ({{ $t('settings.ssl.manual') }})</span>
                  <p class="text-sm text-gray-500 dark:text-gray-400">{{ $t('settings.ssl.dns_manual_desc') }}</p>
                </div>
              </label>
              <label class="flex items-center p-3 border border-gray-200 dark:border-slate-700 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-slate-700/50">
                <input v-model="letsencryptForm.method" type="radio" value="cloudflare" class="mr-3" />
                <div>
                  <span class="font-medium text-gray-900 dark:text-white">DNS-01 (Cloudflare)</span>
                  <p class="text-sm text-gray-500 dark:text-gray-400">{{ $t('settings.ssl.dns_cloudflare_desc') }}</p>
                </div>
              </label>
            </div>
          </div>

          <!-- Manual DNS-01 Instructions -->
          <div v-if="letsencryptForm.method === 'dns-manual'" class="space-y-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
            <div v-if="!dnsChallenge">
              <p class="text-sm text-blue-700 dark:text-blue-400 mb-3">{{ $t('settings.ssl.dns_manual_info') }}</p>
              <button @click="getDnsChallenge" :disabled="gettingChallenge" class="btn btn-secondary">
                <svg v-if="gettingChallenge" class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                  <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                  <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                </svg>
                {{ $t('settings.ssl.get_dns_challenge') }}
              </button>
            </div>
            <div v-else class="space-y-3">
              <h5 class="font-medium text-blue-800 dark:text-blue-300">{{ $t('settings.ssl.dns_instructions') }}</h5>
              <div class="bg-white dark:bg-slate-800 rounded p-3 border border-blue-200 dark:border-slate-700">
                <p class="text-sm text-gray-600 dark:text-gray-400 mb-2">{{ $t('settings.ssl.add_txt_record') }}:</p>
                <div class="font-mono text-sm space-y-1">
                  <p><span class="text-gray-500">{{ $t('settings.ssl.record_name') }}:</span> <code class="bg-gray-100 dark:bg-slate-700 px-2 py-0.5 rounded">{{ dnsChallenge.record_name }}</code></p>
                  <p><span class="text-gray-500">{{ $t('settings.ssl.record_type') }}:</span> <code class="bg-gray-100 dark:bg-slate-700 px-2 py-0.5 rounded">TXT</code></p>
                </div>
              </div>
              <div class="text-sm text-blue-700 dark:text-blue-400">
                <p v-for="(instruction, idx) in dnsChallenge.instructions" :key="idx">{{ instruction }}</p>
              </div>
            </div>
          </div>

          <!-- Cloudflare Settings -->
          <div v-if="letsencryptForm.method === 'cloudflare'" class="space-y-4 p-4 bg-gray-50 dark:bg-slate-700/50 rounded-lg">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.ssl.cloudflare_token') }}</label>
              <div class="flex gap-2">
                <input v-model="letsencryptForm.cloudflareToken" type="password" class="input flex-1" placeholder="API Token" />
                <button @click="testCloudflare" :disabled="testingCloudflare" class="btn btn-secondary">
                  {{ testingCloudflare ? '...' : $t('settings.ssl.test') }}
                </button>
              </div>
              <p class="text-xs text-gray-500 mt-1">{{ $t('settings.ssl.cloudflare_token_hint') }}</p>
            </div>
            <div v-if="cloudflareStatus" class="text-sm" :class="cloudflareStatus.success ? 'text-green-600' : 'text-red-600'">
              {{ cloudflareStatus.success ? $t('settings.ssl.cloudflare_valid') : cloudflareStatus.error }}
            </div>
          </div>

          <button @click="requestLetsEncrypt" :disabled="generating || (letsencryptForm.method === 'dns-manual')" class="btn btn-primary">
            <svg v-if="generating" class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ $t('settings.ssl.request_certificate') }}
          </button>
        </div>

        <!-- Custom Certificate Form -->
        <div v-if="selectedType === 'custom'" class="space-y-4">
          <!-- Certificate -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.ssl.certificate') }} (PEM)</label>
            <div class="flex gap-2 mb-2">
              <input type="file" ref="certFileInput" @change="handleCertFile" accept=".pem,.crt,.cer" class="hidden" />
              <button @click="$refs.certFileInput.click()" class="btn btn-secondary text-sm">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
                {{ $t('settings.ssl.upload_file') }}
              </button>
              <span v-if="customForm.certFileName" class="text-sm text-gray-500 self-center">{{ customForm.certFileName }}</span>
            </div>
            <textarea v-model="customForm.cert" rows="4" class="input w-full font-mono text-xs" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
          </div>

          <!-- Private Key -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.ssl.private_key') }} (PEM)</label>
            <div class="flex gap-2 mb-2">
              <input type="file" ref="keyFileInput" @change="handleKeyFile" accept=".pem,.key" class="hidden" />
              <button @click="$refs.keyFileInput.click()" class="btn btn-secondary text-sm">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
                {{ $t('settings.ssl.upload_file') }}
              </button>
              <span v-if="customForm.keyFileName" class="text-sm text-gray-500 self-center">{{ customForm.keyFileName }}</span>
            </div>
            <textarea v-model="customForm.key" rows="4" class="input w-full font-mono text-xs" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
          </div>

          <!-- Chain -->
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.ssl.chain') }} ({{ $t('settings.ssl.optional') }})</label>
            <div class="flex gap-2 mb-2">
              <input type="file" ref="chainFileInput" @change="handleChainFile" accept=".pem,.crt,.cer" class="hidden" />
              <button @click="$refs.chainFileInput.click()" class="btn btn-secondary text-sm">
                <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                </svg>
                {{ $t('settings.ssl.upload_file') }}
              </button>
              <span v-if="customForm.chainFileName" class="text-sm text-gray-500 self-center">{{ customForm.chainFileName }}</span>
            </div>
            <textarea v-model="customForm.chain" rows="3" class="input w-full font-mono text-xs" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
          </div>

          <!-- Validation Results -->
          <div v-if="validationResult" class="rounded-lg p-4" :class="validationResult.valid ? 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' : 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800'">
            <div class="flex items-center gap-2 mb-2">
              <svg v-if="validationResult.valid" class="w-5 h-5 text-green-600" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd" />
              </svg>
              <svg v-else class="w-5 h-5 text-red-600" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd" />
              </svg>
              <span class="font-medium" :class="validationResult.valid ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400'">
                {{ validationResult.valid ? $t('settings.ssl.validation_success') : $t('settings.ssl.validation_failed') }}
              </span>
            </div>

            <!-- Certificate Info -->
            <div v-if="validationResult.info" class="text-sm space-y-1 mb-2">
              <p><span class="text-gray-500">{{ $t('settings.ssl.domain') }}:</span> {{ validationResult.info.domain }}</p>
              <p><span class="text-gray-500">{{ $t('settings.ssl.issued_by') }}:</span> {{ validationResult.info.issuer }}</p>
              <p><span class="text-gray-500">{{ $t('settings.ssl.valid_until') }}:</span> {{ validationResult.info.valid_to }}</p>
              <p><span class="text-gray-500">{{ $t('settings.ssl.days_remaining') }}:</span>
                <span :class="validationResult.info.days_remaining <= 30 ? 'text-yellow-600 font-medium' : ''">{{ validationResult.info.days_remaining }} {{ $t('settings.ssl.days') }}</span>
              </p>
            </div>

            <!-- Errors -->
            <div v-if="validationResult.errors?.length" class="text-sm text-red-600 dark:text-red-400">
              <p v-for="err in validationResult.errors" :key="err" class="flex items-center gap-1">
                <span>•</span> {{ err }}
              </p>
            </div>

            <!-- Warnings -->
            <div v-if="validationResult.warnings?.length" class="text-sm text-yellow-600 dark:text-yellow-400 mt-2">
              <p v-for="warn in validationResult.warnings" :key="warn" class="flex items-center gap-1">
                <span>⚠</span> {{ warn }}
              </p>
            </div>
          </div>

          <!-- Action Buttons -->
          <div class="flex gap-3">
            <button @click="validateCustomCert" :disabled="validating || !customForm.cert || !customForm.key" class="btn btn-secondary">
              <svg v-if="validating" class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ $t('settings.ssl.validate_first') }}
            </button>
            <button @click="uploadCertificate" :disabled="generating || !validationResult?.valid" class="btn btn-primary">
              <svg v-if="generating" class="animate-spin w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ $t('settings.ssl.apply_certificate') }}
            </button>
          </div>

          <p v-if="!validationResult?.valid && customForm.cert && customForm.key" class="text-sm text-gray-500">
            {{ $t('settings.ssl.validate_hint') }}
          </p>
        </div>
      </div>
    </div>

    <!-- Disable SSL -->
    <div v-if="status?.enabled" class="bg-red-50 dark:bg-red-900/20 rounded-lg border border-red-200 dark:border-red-800 p-4">
      <div class="flex items-center justify-between">
        <div>
          <h4 class="font-medium text-red-700 dark:text-red-400">{{ $t('settings.ssl.disable_ssl') }}</h4>
          <p class="text-sm text-red-600 dark:text-red-500">{{ $t('settings.ssl.disable_ssl_desc') }}</p>
        </div>
        <button @click="disableSsl" class="btn bg-red-600 hover:bg-red-700 text-white">
          {{ $t('settings.ssl.disable') }}
        </button>
      </div>
    </div>

    <!-- Result Message -->
    <div v-if="message" class="p-4 rounded-lg" :class="messageType === 'success' ? 'bg-green-50 border border-green-200 text-green-700' : 'bg-red-50 border border-red-200 text-red-700'">
      {{ message }}
    </div>

    <!-- Job Progress Modal -->
    <div v-if="currentJob" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-slate-800 rounded-lg shadow-xl w-full max-w-md mx-4 p-6">
        <div class="text-center">
          <div class="w-16 h-16 mx-auto mb-4 relative">
            <svg class="animate-spin w-16 h-16 text-primary-500" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="absolute inset-0 flex items-center justify-center text-sm font-bold text-primary-600">
              {{ currentJob.progress }}%
            </span>
          </div>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">{{ $t('settings.ssl.operation_in_progress') }}</h3>
          <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">{{ currentJob.message || $t('settings.ssl.please_wait') }}</p>

          <!-- Progress bar -->
          <div class="w-full bg-gray-200 dark:bg-slate-700 rounded-full h-2 mb-4">
            <div class="bg-primary-500 h-2 rounded-full transition-all duration-300" :style="{ width: currentJob.progress + '%' }"></div>
          </div>

          <!-- Status -->
          <p class="text-xs text-gray-500 dark:text-gray-500">
            {{ $t('settings.ssl.job_id') }}: #{{ currentJob.id }} - {{ currentJob.status }}
          </p>
        </div>
      </div>
    </div>

    <!-- HTTPS Redirect Modal -->
    <div v-if="redirecting" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50">
      <div class="bg-white dark:bg-slate-800 rounded-lg shadow-xl w-full max-w-md mx-4 p-6">
        <div class="text-center">
          <div class="w-16 h-16 mx-auto mb-4 rounded-full bg-green-100 dark:bg-green-900/30 flex items-center justify-center">
            <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
            </svg>
          </div>
          <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-2">{{ $t('settings.ssl.ssl_configured') }}</h3>
          <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">{{ $t('settings.ssl.redirecting_https') }}</p>
          <p class="text-2xl font-bold text-primary-600">{{ redirectCountdown }}</p>
          <p class="text-xs text-gray-500 mt-2">{{ $t('settings.ssl.or_click_redirect') }}</p>
          <button @click="redirectToHttps" class="btn btn-primary mt-4">
            {{ $t('settings.ssl.redirect_now') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, reactive, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import api from '../../services/api'
import { useSSE } from '@/composables/useSSE'
import { useConfirmStore } from '@/stores/confirm'

const { t } = useI18n()
const { subscribe, isConnected } = useSSE()
const confirmDialog = useConfirmStore()

const loading = ref(false)
const generating = ref(false)
const testing = ref(false)
const renewing = ref(false)
const testingCloudflare = ref(false)
const validating = ref(false)
const gettingChallenge = ref(false)

const status = ref(null)
const selectedType = ref(null)
const message = ref('')
const messageType = ref('success')
const cloudflareStatus = ref(null)
const validationResult = ref(null)
const dnsChallenge = ref(null)

// Job tracking
const currentJob = ref(null)
const currentJobAction = ref(null)
let jobPollInterval = null
let sseUnsubscribe = null

// HTTPS redirect
const redirecting = ref(false)
const redirectCountdown = ref(5)
let redirectInterval = null

const selfSignedForm = reactive({
  domain: '',
  days: 365
})

const letsencryptForm = reactive({
  domain: '',
  email: '',
  method: 'http',
  cloudflareToken: ''
})

const customForm = reactive({
  cert: '',
  key: '',
  chain: '',
  certFileName: '',
  keyFileName: '',
  chainFileName: ''
})

// File handlers
const handleCertFile = async (event) => {
  const file = event.target.files[0]
  if (file) {
    customForm.cert = await file.text()
    customForm.certFileName = file.name
    validationResult.value = null
  }
}

const handleKeyFile = async (event) => {
  const file = event.target.files[0]
  if (file) {
    customForm.key = await file.text()
    customForm.keyFileName = file.name
    validationResult.value = null
  }
}

const handleChainFile = async (event) => {
  const file = event.target.files[0]
  if (file) {
    customForm.chain = await file.text()
    customForm.chainFileName = file.name
    validationResult.value = null
  }
}

// Validate certificate before applying
const validateCustomCert = async () => {
  if (!customForm.cert || !customForm.key) {
    showMessage(t('settings.ssl.cert_key_required'), 'error')
    return
  }

  validating.value = true
  validationResult.value = null

  try {
    const response = await api.post('/ssl/validate', {
      cert: customForm.cert,
      key: customForm.key,
      chain: customForm.chain || null
    })

    // Response contains job_id
    if (response.data.data.job_id) {
      startJobTracking(response.data.data.job_id, 'validate')
    }
  } catch (error) {
    validating.value = false
    validationResult.value = {
      valid: false,
      errors: [error.response?.data?.error?.message || error.message],
      warnings: [],
      info: null
    }
  }
}

const loadStatus = async () => {
  loading.value = true
  try {
    const response = await api.get('/ssl/status')
    status.value = response.data.data
  } catch (error) {
    console.error('Failed to load SSL status:', error)
  } finally {
    loading.value = false
  }
}

const getCertTypeLabel = (type) => {
  const labels = {
    'self-signed': t('settings.ssl.type_self_signed'),
    'letsencrypt': "Let's Encrypt",
    'custom': t('settings.ssl.type_custom')
  }
  return labels[type] || type
}

const generateSelfSigned = async () => {
  if (!selfSignedForm.domain) {
    showMessage(t('settings.ssl.domain_required'), 'error')
    return
  }

  generating.value = true
  try {
    const response = await api.post('/ssl/self-signed', {
      domain: selfSignedForm.domain,
      days: selfSignedForm.days
    })

    if (response.data.data.job_id) {
      startJobTracking(response.data.data.job_id, 'self_signed')
    }
  } catch (error) {
    generating.value = false
    showMessage(error.response?.data?.error?.message || error.message, 'error')
  }
}

const requestLetsEncrypt = async () => {
  if (!letsencryptForm.domain || !letsencryptForm.email) {
    showMessage(t('settings.ssl.domain_email_required'), 'error')
    return
  }

  generating.value = true
  try {
    let response
    if (letsencryptForm.method === 'http') {
      response = await api.post('/ssl/letsencrypt/http', {
        domain: letsencryptForm.domain,
        email: letsencryptForm.email
      })
      if (response.data.data.job_id) {
        startJobTracking(response.data.data.job_id, 'letsencrypt_http')
      }
    } else if (letsencryptForm.method === 'cloudflare') {
      if (!letsencryptForm.cloudflareToken) {
        showMessage(t('settings.ssl.cloudflare_token_required'), 'error')
        generating.value = false
        return
      }
      response = await api.post('/ssl/letsencrypt/cloudflare', {
        domain: letsencryptForm.domain,
        email: letsencryptForm.email,
        cloudflare_token: letsencryptForm.cloudflareToken
      })
      if (response.data.data.job_id) {
        startJobTracking(response.data.data.job_id, 'letsencrypt_dns')
      }
    }
  } catch (error) {
    generating.value = false
    showMessage(error.response?.data?.error?.message || error.message, 'error')
  }
}

const uploadCertificate = async () => {
  if (!customForm.cert || !customForm.key) {
    showMessage(t('settings.ssl.cert_key_required'), 'error')
    return
  }

  generating.value = true
  try {
    const response = await api.post('/ssl/upload', {
      cert: customForm.cert,
      key: customForm.key,
      chain: customForm.chain || null
    })

    if (response.data.data.job_id) {
      startJobTracking(response.data.data.job_id, 'apply')
    }
  } catch (error) {
    generating.value = false
    showMessage(error.response?.data?.error?.message || error.message, 'error')
  }
}

const testCloudflare = async () => {
  if (!letsencryptForm.cloudflareToken) return

  testingCloudflare.value = true
  try {
    const response = await api.post('/ssl/cloudflare/test', {
      token: letsencryptForm.cloudflareToken
    })
    cloudflareStatus.value = response.data.data
  } catch (error) {
    cloudflareStatus.value = { success: false, error: error.response?.data?.error?.message || error.message }
  } finally {
    testingCloudflare.value = false
  }
}

const testRenewal = async () => {
  testing.value = true
  try {
    const response = await api.post('/ssl/test-renewal')
    if (response.data.data.success) {
      showMessage(t('settings.ssl.renewal_test_success'), 'success')
    } else {
      showMessage(t('settings.ssl.renewal_test_failed') + ': ' + (response.data.data.output || ''), 'error')
    }
  } catch (error) {
    showMessage(error.response?.data?.error?.message || error.message, 'error')
  } finally {
    testing.value = false
  }
}

const forceRenewal = async () => {
  renewing.value = true
  try {
    const response = await api.post('/ssl/renew')

    if (response.data.data.job_id) {
      startJobTracking(response.data.data.job_id, 'renew')
    }
  } catch (error) {
    renewing.value = false
    showMessage(error.response?.data?.error?.message || error.message, 'error')
  }
}

const getDnsChallenge = async () => {
  if (!letsencryptForm.domain || !letsencryptForm.email) {
    showMessage(t('settings.ssl.domain_email_required'), 'error')
    return
  }

  gettingChallenge.value = true
  dnsChallenge.value = null

  try {
    const response = await api.post('/ssl/letsencrypt/dns-challenge', {
      domain: letsencryptForm.domain,
      email: letsencryptForm.email
    })
    dnsChallenge.value = response.data.data
  } catch (error) {
    showMessage(error.response?.data?.error?.message || error.message, 'error')
  } finally {
    gettingChallenge.value = false
  }
}

const disableSsl = async () => {
  const confirmed = await confirmDialog.show({
    title: t('settings.ssl.disable_ssl'),
    message: t('settings.ssl.disable_confirm'),
    confirmText: t('common.disable'),
    cancelText: t('common.cancel'),
    type: 'danger'
  })
  if (!confirmed) return

  generating.value = true
  try {
    const response = await api.post('/ssl/disable')

    if (response.data.data.job_id) {
      startJobTracking(response.data.data.job_id, 'disable')
    }
  } catch (error) {
    generating.value = false
    showMessage(error.response?.data?.error?.message || error.message, 'error')
  }
}

const showMessage = (msg, type) => {
  message.value = msg
  messageType.value = type
  setTimeout(() => { message.value = '' }, 5000)
}

// Job tracking - SSE primary with polling fallback
const startJobTracking = (jobId, action) => {
  currentJobAction.value = action
  currentJob.value = {
    id: jobId,
    progress: 0,
    status: 'pending',
    message: t('settings.ssl.starting_operation')
  }

  // Subscribe to SSE for real-time updates
  sseUnsubscribe = subscribe('jobs', (data) => {
    // Check if this update is for our job
    if (data.job_id === jobId && data.progress_info) {
      currentJob.value = {
        ...currentJob.value,
        progress: data.progress_info.progress ?? currentJob.value.progress,
        message: data.progress_info.message ?? currentJob.value.message
      }
    }

    // Check for job completion in jobs list
    if (data.jobs) {
      const job = data.jobs.find(j => j.id === jobId)
      if (job) {
        currentJob.value = {
          ...currentJob.value,
          status: job.status,
          progress: job.progress ?? currentJob.value.progress
        }

        if (job.status === 'completed') {
          stopJobTracking()
          handleJobSuccess(action, job)
        } else if (job.status === 'failed') {
          stopJobTracking()
          handleJobFailure(job)
        }
      }
    }
  })

  // Start polling as fallback (slower interval since SSE is primary)
  startPollingFallback(jobId, action)
}

// Polling fallback - runs less frequently when SSE is connected
const startPollingFallback = (jobId, action) => {
  const pollInterval = isConnected.value ? 5000 : 1000 // 5s with SSE, 1s without

  jobPollInterval = setInterval(async () => {
    try {
      const response = await api.get(`/jobs/${jobId}`)
      const data = response.data.data
      const job = data.job
      const progressInfo = data.progress_info

      // Use progress_info for real-time progress when running
      const progress = progressInfo?.progress ?? job.progress ?? 0
      const message = progressInfo?.message ?? job.output ?? t('settings.ssl.processing')

      currentJob.value = {
        id: jobId,
        progress: progress,
        status: job.status,
        message: message
      }

      if (job.status === 'completed') {
        stopJobTracking()
        handleJobSuccess(action, job)
      } else if (job.status === 'failed') {
        stopJobTracking()
        handleJobFailure(job)
      }
    } catch (error) {
      console.error('Error polling job:', error)
      // Only stop on repeated errors, not on single failure
      if (!isConnected.value) {
        stopJobTracking()
        showMessage(error.response?.data?.error?.message || error.message, 'error')
        generating.value = false
        validating.value = false
        renewing.value = false
      }
    }
  }, pollInterval)
}

const stopJobTracking = () => {
  // Unsubscribe from SSE
  if (sseUnsubscribe) {
    sseUnsubscribe()
    sseUnsubscribe = null
  }

  // Stop polling
  if (jobPollInterval) {
    clearInterval(jobPollInterval)
    jobPollInterval = null
  }

  currentJob.value = null
  currentJobAction.value = null
}

const handleJobSuccess = async (action, job) => {
  generating.value = false
  validating.value = false
  renewing.value = false

  // For validation, parse the result
  if (action === 'validate') {
    try {
      const result = JSON.parse(job.output)
      validationResult.value = {
        valid: result.valid,
        info: {
          domain: result.subject,
          issuer: result.issuer,
          valid_from: result.valid_from,
          valid_to: result.valid_to,
          days_remaining: result.days_remaining
        },
        errors: [],
        warnings: result.days_remaining <= 30 ? [t('settings.ssl.expires_warning', { days: result.days_remaining })] : []
      }
      showMessage(t('settings.ssl.validation_success'), 'success')
    } catch (e) {
      validationResult.value = { valid: true, info: null, errors: [], warnings: [] }
      showMessage(t('settings.ssl.validation_success'), 'success')
    }
    return
  }

  // For SSL-enabling actions, trigger HTTPS redirect
  const sslEnablingActions = ['apply', 'self_signed', 'letsencrypt_http', 'letsencrypt_dns']
  if (sslEnablingActions.includes(action)) {
    showMessage(t('settings.ssl.operation_success'), 'success')
    await loadStatus()
    selectedType.value = null

    // Only redirect if currently on HTTP
    if (window.location.protocol === 'http:') {
      startHttpsRedirect()
    }
  } else if (action === 'renew') {
    showMessage(t('settings.ssl.renewed_success'), 'success')
    await loadStatus()
  } else if (action === 'disable') {
    showMessage(t('settings.ssl.disabled_success'), 'success')
    await loadStatus()
  }
}

const handleJobFailure = (job) => {
  generating.value = false
  validating.value = false
  renewing.value = false

  const errorMsg = job.error || t('settings.ssl.operation_failed')
  showMessage(errorMsg, 'error')

  // For validation failure, show in result
  if (currentJob.value?.action === 'validate') {
    validationResult.value = {
      valid: false,
      errors: [errorMsg],
      warnings: [],
      info: null
    }
  }
}

// HTTPS redirect
const startHttpsRedirect = () => {
  redirecting.value = true
  redirectCountdown.value = 5

  redirectInterval = setInterval(() => {
    redirectCountdown.value--
    if (redirectCountdown.value <= 0) {
      redirectToHttps()
    }
  }, 1000)
}

const redirectToHttps = () => {
  if (redirectInterval) {
    clearInterval(redirectInterval)
    redirectInterval = null
  }
  redirecting.value = false

  // Redirect to HTTPS
  const httpsUrl = window.location.href.replace('http:', 'https:')
  window.location.href = httpsUrl
}

// Cleanup on unmount
onUnmounted(() => {
  stopJobTracking()
  if (redirectInterval) {
    clearInterval(redirectInterval)
  }
})

onMounted(() => {
  loadStatus()
})
</script>
