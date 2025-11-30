<template>
  <div>
    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $t('settings.title') }}</h1>
      <p class="mt-2 text-gray-600 dark:text-gray-400">{{ $t('settings.subtitle') }}</p>
    </div>

    <!-- Error Message -->
    <div v-if="settingsStore.error" class="mb-6 p-4 bg-red-50 border border-red-200 rounded-lg">
      <div class="flex justify-between items-start">
        <p class="text-sm text-red-800">{{ settingsStore.error }}</p>
        <button @click="settingsStore.clearError()" class="text-red-500 hover:text-red-700">
          <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>
    </div>

    <!-- Tabs -->
    <div class="card p-0">
      <!-- Tab Headers -->
      <div class="border-b border-gray-200 dark:border-gray-700">
        <nav class="-mb-px flex overflow-x-auto" aria-label="Tabs">
          <button
            v-for="tab in tabs"
            :key="tab.id"
            @click="activeTab = tab.id"
            :class="[
              'whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm transition-colors relative',
              activeTab === tab.id
                ? 'border-primary-500 text-primary-600'
                : 'border-transparent text-gray-500 dark:text-gray-400 dark:text-gray-500 hover:text-gray-700 dark:text-gray-300 hover:border-gray-300 dark:border-gray-600'
            ]"
          >
            {{ tab.label }}
            <!-- Badge for update tab -->
            <span
              v-if="tab.id === 'update' && updateCommitCount > 0"
              class="ml-2 inline-flex items-center justify-center px-2 py-1 text-xs font-bold leading-none text-white bg-red-500 rounded-full"
            >
              {{ updateCommitCount }}
            </span>
          </button>
        </nav>
      </div>

      <!-- Tab Content -->
      <div class="p-6">
        <!-- General Settings -->
        <div v-show="activeTab === 'general'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $t('settings.general.title') }}</h3>
          <form @submit.prevent="saveSettings('general')" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.general.app_name') }}</label>
              <input v-model="generalForm['app.name']" type="text" class="input w-full" required />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.general.timezone') }}</label>
              <select v-model="generalForm['app.timezone']" class="input w-full">
                <option value="UTC">UTC</option>
                <option value="Europe/Paris">Europe/Paris</option>
                <option value="America/New_York">America/New_York</option>
                <option value="Asia/Tokyo">Asia/Tokyo</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.general.language') }}</label>
              <select v-model="generalForm['app.language']" class="input w-full">
                <option value="en">English</option>
                <option value="fr">Français</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.general.notification_email') }}</label>
              <input v-model="generalForm['notification.email']" type="email" class="input w-full" placeholder="admin@example.com" />
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.general.notification_email_help') }}</p>
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                {{ $t('settings.general.save') }}
              </button>
            </div>
          </form>
        </div>

        <!-- Email/SMTP Settings -->
        <div v-show="activeTab === 'email'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $t('settings.email.title') }}</h3>
          <form @submit.prevent="saveSettings('email')" class="space-y-4">
            <div class="flex items-center">
              <input v-model="emailForm['smtp.enabled']" type="checkbox" class="mr-2" />
              <label class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $t('settings.email.enable') }}</label>
            </div>

            <!-- Provider Selector -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.email.provider') }}</label>
              <div class="grid grid-cols-4 gap-2">
                <button
                  type="button"
                  @click="selectEmailProvider('gmail')"
                  :class="[
                    'flex items-center justify-center gap-2 px-4 py-3 rounded-lg border-2 transition-all',
                    emailProvider === 'gmail'
                      ? 'border-red-500 bg-red-50 dark:bg-red-900/20'
                      : 'border-gray-200 dark:border-gray-700 hover:border-red-300 dark:hover:border-red-700'
                  ]"
                >
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M24 5.457v13.909c0 .904-.732 1.636-1.636 1.636h-3.819V11.73L12 16.64l-6.545-4.91v9.273H1.636A1.636 1.636 0 0 1 0 19.366V5.457c0-2.023 2.309-3.178 3.927-1.964L5.455 4.64 12 9.548l6.545-4.91 1.528-1.145C21.69 2.28 24 3.434 24 5.457z" fill="#EA4335"/>
                  </svg>
                  <span class="text-sm font-medium">Gmail</span>
                </button>
                <button
                  type="button"
                  @click="selectEmailProvider('microsoft365')"
                  :class="[
                    'flex items-center justify-center gap-2 px-4 py-3 rounded-lg border-2 transition-all',
                    emailProvider === 'microsoft365'
                      ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20'
                      : 'border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700'
                  ]"
                >
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M0 0h11.377v11.372H0zm12.623 0H24v11.372H12.623zM0 12.623h11.377V24H0zm12.623 0H24V24H12.623z" fill="#00A4EF"/>
                  </svg>
                  <span class="text-sm font-medium">Microsoft 365</span>
                </button>
                <button
                  type="button"
                  @click="selectEmailProvider('outlook')"
                  :class="[
                    'flex items-center justify-center gap-2 px-4 py-3 rounded-lg border-2 transition-all',
                    emailProvider === 'outlook'
                      ? 'border-blue-600 bg-blue-50 dark:bg-blue-900/20'
                      : 'border-gray-200 dark:border-gray-700 hover:border-blue-300 dark:hover:border-blue-700'
                  ]"
                >
                  <svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M24 7.387v10.478c0 .23-.08.424-.238.576-.158.154-.352.23-.58.23h-8.547v-6.959l1.6 1.229c.102.086.227.13.377.13.14 0 .26-.04.36-.117l.028-.021 6.762-5.252c.096-.063.205-.095.328-.095a.58.58 0 0 1 .378.14.456.456 0 0 1 .165.36l-.001.001-.632 5.25v-5.95zm-.633-1.49L15.682 12l-.003-.002-3.044-2.337v-3.19h10.547c.228 0 .422.077.58.23.158.153.237.348.237.577v.062l-.632-.443zM14.182 7.39V19.2l-9.665 3.2a.53.53 0 0 1-.16.025.543.543 0 0 1-.387-.166.546.546 0 0 1-.166-.392V2.93c0-.17.064-.318.19-.443.128-.126.28-.186.456-.18l9.665 3.2v1.883H14.182zM8.91 15.144c-.127-.473-.19-.986-.19-1.54 0-.543.063-1.052.19-1.527.126-.475.304-.888.533-1.24.228-.35.503-.628.825-.83.32-.203.672-.305 1.05-.305.348 0 .66.087.936.26.275.173.502.41.68.712l-2.857 5.658c-.275-.096-.504-.293-.687-.59-.184-.297-.33-.658-.44-1.084l-.04-.514z" fill="#0078D4"/>
                  </svg>
                  <span class="text-sm font-medium">Outlook.com</span>
                </button>
                <button
                  type="button"
                  @click="selectEmailProvider('custom')"
                  :class="[
                    'flex items-center justify-center gap-2 px-4 py-3 rounded-lg border-2 transition-all',
                    emailProvider === 'custom'
                      ? 'border-gray-500 bg-gray-50 dark:bg-gray-700'
                      : 'border-gray-200 dark:border-gray-700 hover:border-gray-400 dark:hover:border-gray-500'
                  ]"
                >
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                  </svg>
                  <span class="text-sm font-medium">{{ $t('settings.email.custom') }}</span>
                </button>
              </div>
            </div>

            <!-- Provider Help Box -->
            <div v-if="emailProvider && emailProvider !== 'custom'" class="p-4 rounded-lg border" :class="providerHelpClass">
              <div class="flex items-start gap-3">
                <svg class="w-5 h-5 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <div class="flex-1">
                  <p class="text-sm font-medium mb-1">{{ providerHelpTitle }}</p>
                  <p class="text-sm opacity-80 mb-2">{{ providerHelpText }}</p>
                  <a
                    :href="providerHelpLink"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 text-sm font-medium underline hover:no-underline"
                  >
                    {{ $t('settings.email.create_app_password') }}
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                    </svg>
                  </a>
                </div>
              </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.email.host') }}</label>
                <input v-model="emailForm['smtp.host']" type="text" class="input w-full" placeholder="smtp.example.com" :disabled="emailProvider !== 'custom'" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.email.port') }}</label>
                <input v-model.number="emailForm['smtp.port']" type="number" class="input w-full" placeholder="587" :disabled="emailProvider !== 'custom'" />
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.email.encryption') }}</label>
              <select v-model="emailForm['smtp.encryption']" class="input w-full" :disabled="emailProvider !== 'custom'">
                <option value="tls">TLS</option>
                <option value="ssl">SSL</option>
                <option value="none">None</option>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.email.username') }}</label>
                <input v-model="emailForm['smtp.username']" type="text" class="input w-full" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.email.password') }}</label>
                <input v-model="emailForm['smtp.password']" type="password" class="input w-full" />
              </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.email.from_email') }}</label>
                <input v-model="emailForm['smtp.from_email']" type="email" class="input w-full" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.email.from_name') }}</label>
                <input v-model="emailForm['smtp.from_name']" type="text" class="input w-full" />
              </div>
            </div>
            <div class="flex justify-end space-x-3">
              <button type="button" @click="openTestEmailModal" class="btn btn-secondary" :disabled="settingsStore.loading">
                <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                {{ $t('settings.email.test') }}
              </button>
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                {{ $t('settings.email.save') }}
              </button>
            </div>
          </form>
        </div>

        <!-- Backup Defaults -->
        <div v-show="activeTab === 'backup'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $t('settings.backup.title') }}</h3>
          <form @submit.prevent="saveSettings('backup')" class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">{{ $t('settings.backup.description') }}</p>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.backup.daily') }}</label>
                <input v-model.number="backupForm['backup.retention.daily']" type="number" min="0" max="365" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.backup.daily_help') }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.backup.weekly') }}</label>
                <input v-model.number="backupForm['backup.retention.weekly']" type="number" min="0" max="52" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.backup.weekly_help') }}</p>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.backup.monthly') }}</label>
                <input v-model.number="backupForm['backup.retention.monthly']" type="number" min="0" max="60" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.backup.monthly_help') }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.backup.yearly') }}</label>
                <input v-model.number="backupForm['backup.retention.yearly']" type="number" min="0" max="10" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.backup.yearly_help') }}</p>
              </div>
            </div>

            <!-- Backup Timeout -->
            <div class="border-t dark:border-gray-700 pt-4 mt-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.backup.timeout') }}</label>
                <div class="flex items-center gap-2">
                  <input v-model.number="backupForm['backup_timeout']" type="number" min="1800" max="86400" step="1800" class="input w-32" />
                  <span class="text-sm text-gray-600 dark:text-gray-400">{{ $t('settings.backup.timeout_unit') }}</span>
                  <span class="text-xs text-gray-500 dark:text-gray-400">({{ formatTimeoutDisplay(backupForm['backup_timeout']) }})</span>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.backup.timeout_help') }}</p>
              </div>
            </div>

            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                {{ $t('settings.backup.save') }}
              </button>
            </div>
          </form>
        </div>

        <!-- Borg Settings -->
        <div v-show="activeTab === 'borg'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $t('settings.borg.title') }}</h3>
          <form @submit.prevent="saveSettings('borg')" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.borg.default_path') }}</label>
              <input v-model="borgForm['borg.default_path']" type="text" class="input w-full" placeholder="/backup/borg" />
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.borg.default_path_help') }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.borg.compression') }}</label>
              <select v-model="borgForm['borg.compression']" class="input w-full">
                <option value="none">None</option>
                <option value="lz4">LZ4 (fast)</option>
                <option value="zstd">ZSTD (balanced)</option>
                <option value="zlib">ZLIB (high)</option>
                <option value="lzma">LZMA (highest)</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.borg.encryption') }}</label>
              <select v-model="borgForm['borg.encryption']" class="input w-full">
                <option value="repokey-blake2">Repokey BLAKE2</option>
                <option value="repokey">Repokey (AES-CTR)</option>
                <option value="keyfile-blake2">Keyfile BLAKE2</option>
                <option value="keyfile">Keyfile (AES-CTR)</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.borg.ratelimit') }}</label>
              <input v-model.number="borgForm['borg.ratelimit']" type="number" min="0" class="input w-full" placeholder="0" />
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.borg.ratelimit_help') }}</p>
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                {{ $t('settings.borg.save') }}
              </button>
            </div>
          </form>
        </div>

        <!-- Security Settings -->
        <div v-show="activeTab === 'security'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $t('settings.security.title') }}</h3>
          <form @submit.prevent="saveSettings('security')" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.security.access_ttl') }}</label>
                <input v-model.number="securityForm['security.jwt.access_ttl']" type="number" min="300" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.security.access_ttl_help') }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.security.refresh_ttl') }}</label>
                <input v-model.number="securityForm['security.jwt.refresh_ttl']" type="number" min="3600" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.security.refresh_ttl_help') }}</p>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.security.session_timeout') }}</label>
              <input v-model.number="securityForm['security.session_timeout']" type="number" min="300" class="input w-full" />
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.security.session_timeout_help') }}</p>
            </div>
            <div class="space-y-2">
              <div class="flex items-center">
                <input v-model="securityForm['security.force_https']" type="checkbox" class="mr-2" />
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $t('settings.security.force_https') }}</label>
              </div>
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                {{ $t('settings.security.save') }}
              </button>
            </div>
          </form>
        </div>

        <!-- Network Settings -->
        <div v-show="activeTab === 'network'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $t('settings.network.title') }}</h3>
          <form @submit.prevent="saveSettings('network')" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.network.external_ip') }}</label>
                <input v-model="networkForm['network.external_ip']" type="text" class="input w-full" placeholder="192.168.1.100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.network.internal_ip') }}</label>
                <input v-model="networkForm['network.internal_ip']" type="text" class="input w-full" placeholder="10.0.0.100" />
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.network.api_port') }}</label>
              <input v-model.number="networkForm['network.api_port']" type="number" min="1" max="65535" class="input w-full" />
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                {{ $t('settings.network.save') }}
              </button>
            </div>
          </form>
        </div>

        <!-- System Settings -->
        <div v-show="activeTab === 'system'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">{{ $t('settings.system.title') }}</h3>
          <form @submit.prevent="saveSettings('system')" class="space-y-4">
            <!-- Logging Settings -->
            <div class="border-b dark:border-gray-700 pb-4 mb-4">
              <h4 class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-3">{{ $t('settings.system.logging_title') }}</h4>
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.system.log_path') }}</label>
                  <input
                    v-model="systemForm['log_path']"
                    type="text"
                    class="input w-full"
                    placeholder="/var/log/phpborg.log"
                    required
                  />
                  <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.system.log_path_help') }}</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.system.log_level') }}</label>
                  <select v-model="systemForm['log_level']" class="input w-full">
                    <option value="debug">{{ $t('settings.system.log_level_debug') }}</option>
                    <option value="info">{{ $t('settings.system.log_level_info') }}</option>
                    <option value="warning">{{ $t('settings.system.log_level_warning') }}</option>
                    <option value="error">{{ $t('settings.system.log_level_error') }}</option>
                    <option value="critical">{{ $t('settings.system.log_level_critical') }}</option>
                  </select>
                  <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.system.log_level_help') }}</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.system.log_rotation') }}</label>
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('settings.system.log_max_size') }}</label>
                      <input
                        v-model.number="systemForm['log_max_size']"
                        type="number"
                        min="1"
                        class="input w-full"
                        placeholder="100"
                      />
                    </div>
                    <div>
                      <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('settings.system.log_max_files') }}</label>
                      <input
                        v-model.number="systemForm['log_max_files']"
                        type="number"
                        min="1"
                        class="input w-full"
                        placeholder="10"
                      />
                    </div>
                  </div>
                  <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.system.log_rotation_help') }}</p>
                </div>
              </div>
            </div>

            <!-- Temporary Files -->
            <div class="border-b dark:border-gray-700 pb-4 mb-4">
              <h4 class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-3">{{ $t('settings.system.temp_title') }}</h4>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.system.temp_path') }}</label>
                <input
                  v-model="systemForm['temp_path']"
                  type="text"
                  class="input w-full"
                  placeholder="/tmp/phpborg"
                />
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.system.temp_path_help') }}</p>
              </div>
            </div>

            <!-- Statistics Collection -->
            <div>
              <h4 class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-3">{{ $t('settings.system.stats_title') }}</h4>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.system.stats_interval') }}</label>
                <input
                  v-model.number="systemForm['system.stats_collection_interval']"
                  type="number"
                  min="60"
                  step="60"
                  class="input w-full"
                  placeholder="900"
                />
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                  {{ $t('settings.system.stats_interval_help') }}
                </p>
                <div class="mt-2 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                  <p class="text-xs text-blue-900 dark:text-blue-200">
                    <strong>Note:</strong> {{ $t('settings.system.stats_note') }}
                  </p>
                </div>
              </div>
            </div>

            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                {{ $t('settings.system.save') }}
              </button>
            </div>
          </form>
        </div>

        <!-- SSL Settings -->
        <div v-show="activeTab === 'ssl'">
          <SslSettings />
        </div>

        <!-- Maintenance Settings -->
        <div v-show="activeTab === 'maintenance'">
          <MaintenanceSettings />
        </div>

        <!-- Update Settings -->
        <div v-show="activeTab === 'update'">
          <UpdateSettings />
        </div>
      </div>
    </div>

    <!-- Test Email Modal -->
    <div v-if="showTestEmailModal" class="fixed inset-0 bg-gray-600 dark:bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="closeTestEmailModal">
      <div class="relative top-20 mx-auto p-5 border w-[500px] shadow-lg rounded-md bg-white dark:bg-gray-800">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">{{ $t('settings.email.test_modal_title') }}</h3>
          <button @click="closeTestEmailModal" class="text-gray-400 hover:text-gray-500 dark:hover:text-gray-300">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Success/Error Message -->
        <div v-if="testEmailMessage" :class="[
          'mb-4 p-3 rounded-lg',
          testEmailSuccess ? 'bg-green-50 dark:bg-green-900/20 text-green-800 dark:text-green-300' : 'bg-red-50 dark:bg-red-900/20 text-red-800 dark:text-red-300'
        ]">
          <p class="text-sm">{{ testEmailMessage }}</p>
        </div>

        <form @submit.prevent="sendTestEmail">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('settings.email.test_recipient') }}</label>
              <input
                v-model="testEmailForm.to"
                type="email"
                class="input w-full"
                :placeholder="$t('settings.email.test_placeholder')"
                required
              />
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">{{ $t('settings.email.test_help') }}</p>
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <button type="button" @click="closeTestEmailModal" class="btn btn-secondary flex-1">
              {{ $t('settings.email.test_cancel') }}
            </button>
            <button type="submit" class="btn btn-primary flex-1" :disabled="testEmailLoading">
              <span v-if="testEmailLoading">{{ $t('settings.email.test_sending') }}</span>
              <span v-else>{{ $t('settings.email.test_send') }}</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useSettingsStore } from '@/stores/settings'
import { emailService } from '@/services/email'
import UpdateSettings from '@/components/UpdateSettings.vue'
import MaintenanceSettings from '@/components/MaintenanceSettings.vue'
import SslSettings from '@/components/settings/SslSettings.vue'
import phpborgUpdateService from '@/services/phpborgUpdate'
import { useToastStore } from '@/stores/toast'

const { t } = useI18n()
const route = useRoute()
const settingsStore = useSettingsStore()
const toast = useToastStore()

const activeTab = ref(route.query.tab || 'general')
const updateCommitCount = ref(0)

// Test Email Modal
const showTestEmailModal = ref(false)
const testEmailLoading = ref(false)
const testEmailMessage = ref('')
const testEmailSuccess = ref(false)
const testEmailForm = reactive({
  to: '',
})

const tabs = computed(() => [
  { id: 'general', label: t('settings.tabs.general') },
  { id: 'email', label: t('settings.tabs.email') },
  { id: 'backup', label: t('settings.tabs.backup') },
  { id: 'borg', label: t('settings.tabs.borg') },
  { id: 'security', label: t('settings.tabs.security') },
  { id: 'network', label: t('settings.tabs.network') },
  { id: 'ssl', label: t('settings.tabs.ssl') },
  { id: 'system', label: t('settings.tabs.system') },
  { id: 'maintenance', label: t('settings.tabs.maintenance') },
  { id: 'update', label: t('settings.tabs.update') },
])

const generalForm = reactive({})
const emailForm = reactive({})
const backupForm = reactive({})
const borgForm = reactive({})
const securityForm = reactive({})
const networkForm = reactive({})
const systemForm = reactive({})

// Email provider selection
const emailProvider = ref('custom')

// Provider presets
const emailProviderPresets = {
  gmail: {
    host: 'smtp.gmail.com',
    port: 587,
    encryption: 'tls',
  },
  microsoft365: {
    host: 'smtp.office365.com',
    port: 587,
    encryption: 'tls',
  },
  outlook: {
    host: 'smtp-mail.outlook.com',
    port: 587,
    encryption: 'tls',
  },
  custom: {
    host: '',
    port: 587,
    encryption: 'tls',
  },
}

// Provider help information
const providerHelpInfo = {
  gmail: {
    title: 'Configuration Gmail',
    text: 'Gmail requiert un "Mot de passe d\'application" (2FA doit être activé sur votre compte Google).',
    link: 'https://myaccount.google.com/apppasswords',
    class: 'border-red-200 bg-red-50 text-red-900 dark:border-red-800 dark:bg-red-900/20 dark:text-red-200',
  },
  microsoft365: {
    title: 'Configuration Microsoft 365',
    text: 'Microsoft 365 nécessite l\'authentification moderne. Créez un mot de passe d\'application dans les paramètres de sécurité.',
    link: 'https://account.live.com/proofs/AppPassword',
    class: 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-200',
  },
  outlook: {
    title: 'Configuration Outlook.com',
    text: 'Pour les comptes Outlook.com personnels, activez la vérification en deux étapes puis créez un mot de passe d\'application.',
    link: 'https://account.live.com/proofs/AppPassword',
    class: 'border-blue-200 bg-blue-50 text-blue-900 dark:border-blue-800 dark:bg-blue-900/20 dark:text-blue-200',
  },
}

const providerHelpClass = computed(() => providerHelpInfo[emailProvider.value]?.class || '')
const providerHelpTitle = computed(() => providerHelpInfo[emailProvider.value]?.title || '')
const providerHelpText = computed(() => providerHelpInfo[emailProvider.value]?.text || '')
const providerHelpLink = computed(() => providerHelpInfo[emailProvider.value]?.link || '')

function selectEmailProvider(provider) {
  emailProvider.value = provider
  const preset = emailProviderPresets[provider]
  if (preset) {
    emailForm['smtp.host'] = preset.host
    emailForm['smtp.port'] = preset.port
    emailForm['smtp.encryption'] = preset.encryption
  }
}

function detectEmailProvider() {
  const host = emailForm['smtp.host']?.toLowerCase() || ''
  if (host.includes('gmail')) return 'gmail'
  if (host.includes('office365')) return 'microsoft365'
  if (host.includes('outlook') || host.includes('live.com')) return 'outlook'
  return 'custom'
}

onMounted(async () => {
  await settingsStore.fetchSettings()
  loadForms()

  // Fetch update information for badge (using quick status API - no job needed)
  try {
    console.log('[SettingsView] Fetching quick update status...')
    const statusResult = await phpborgUpdateService.getQuickStatus()
    console.log('[SettingsView] Status result:', statusResult)
    if (statusResult.success && statusResult.data?.available) {
      updateCommitCount.value = statusResult.data.commits_behind || 0
      console.log('[SettingsView] Updates available:', updateCommitCount.value)
    }
  } catch (error) {
    console.error('[SettingsView] Failed to get update status:', error)
  }
})

function loadForms() {
  const settings = settingsStore.settings

  if (settings.general) Object.assign(generalForm, settings.general)
  if (settings.email) {
    Object.assign(emailForm, settings.email)
    emailForm['smtp.enabled'] = emailForm['smtp.enabled'] === true || emailForm['smtp.enabled'] === 'true'
    // Detect provider from existing settings
    emailProvider.value = detectEmailProvider()
  }
  if (settings.backup) Object.assign(backupForm, settings.backup)
  if (settings.borg) Object.assign(borgForm, settings.borg)
  if (settings.security) {
    Object.assign(securityForm, settings.security)
    securityForm['security.force_https'] = securityForm['security.force_https'] === true || securityForm['security.force_https'] === 'true'
  }
  if (settings.network) Object.assign(networkForm, settings.network)
  if (settings.system) Object.assign(systemForm, settings.system)
}

async function saveSettings(category) {
  try {
    const formData = {
      general: generalForm,
      email: emailForm,
      backup: backupForm,
      borg: borgForm,
      security: securityForm,
      network: networkForm,
      system: systemForm,
    }[category]

    await settingsStore.updateCategorySettings(category, formData)
    toast.success(t('settings.save_success'))
  } catch (err) {
    // Error handled by store
  }
}

function formatTimeoutDisplay(seconds) {
  if (!seconds) return '0h'
  const hours = Math.floor(seconds / 3600)
  const minutes = Math.floor((seconds % 3600) / 60)
  if (minutes === 0) return `${hours}h`
  return `${hours}h ${minutes}min`
}

function openTestEmailModal() {
  testEmailMessage.value = ''
  testEmailSuccess.value = false
  testEmailForm.to = ''
  showTestEmailModal.value = true
}

function closeTestEmailModal() {
  showTestEmailModal.value = false
  testEmailMessage.value = ''
  testEmailSuccess.value = false
  testEmailForm.to = ''
}

async function sendTestEmail() {
  testEmailLoading.value = true
  testEmailMessage.value = ''
  testEmailSuccess.value = false

  try {
    const result = await emailService.sendTestEmail(testEmailForm.to)
    testEmailSuccess.value = true
    testEmailMessage.value = result.message || t('settings.email.test_success')

    // Auto-close modal after 3 seconds on success
    setTimeout(() => {
      closeTestEmailModal()
    }, 3000)
  } catch (error) {
    testEmailSuccess.value = false
    testEmailMessage.value = error.response?.data?.error || t('settings.email.test_error')
  } finally {
    testEmailLoading.value = false
  }
}
</script>
