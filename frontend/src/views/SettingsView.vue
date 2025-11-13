<template>
  <div>
    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">Settings</h1>
      <p class="mt-2 text-gray-600 dark:text-gray-400 dark:text-gray-500">Configure your phpBorg application</p>
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
              'whitespace-nowrap py-4 px-6 border-b-2 font-medium text-sm transition-colors',
              activeTab === tab.id
                ? 'border-primary-500 text-primary-600'
                : 'border-transparent text-gray-500 dark:text-gray-400 dark:text-gray-500 hover:text-gray-700 dark:text-gray-300 hover:border-gray-300 dark:border-gray-600'
            ]"
          >
            {{ tab.label }}
          </button>
        </nav>
      </div>

      <!-- Tab Content -->
      <div class="p-6">
        <!-- General Settings -->
        <div v-show="activeTab === 'general'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">General Settings</h3>
          <form @submit.prevent="saveSettings('general')" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Application Name</label>
              <input v-model="generalForm['app.name']" type="text" class="input w-full" required />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Timezone</label>
              <select v-model="generalForm['app.timezone']" class="input w-full">
                <option value="UTC">UTC</option>
                <option value="Europe/Paris">Europe/Paris</option>
                <option value="America/New_York">America/New_York</option>
                <option value="Asia/Tokyo">Asia/Tokyo</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Language</label>
              <select v-model="generalForm['app.language']" class="input w-full">
                <option value="en">English</option>
                <option value="fr">Français</option>
              </select>
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                Save General Settings
              </button>
            </div>
          </form>
        </div>

        <!-- Email/SMTP Settings -->
        <div v-show="activeTab === 'email'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Email / SMTP Settings</h3>
          <form @submit.prevent="saveSettings('email')" class="space-y-4">
            <div class="flex items-center">
              <input v-model="emailForm['smtp.enabled']" type="checkbox" class="mr-2" />
              <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Enable SMTP Notifications</label>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">SMTP Host</label>
                <input v-model="emailForm['smtp.host']" type="text" class="input w-full" placeholder="smtp.example.com" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">SMTP Port</label>
                <input v-model.number="emailForm['smtp.port']" type="number" class="input w-full" placeholder="587" />
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Encryption</label>
              <select v-model="emailForm['smtp.encryption']" class="input w-full">
                <option value="tls">TLS</option>
                <option value="ssl">SSL</option>
                <option value="none">None</option>
              </select>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">SMTP Username</label>
                <input v-model="emailForm['smtp.username']" type="text" class="input w-full" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">SMTP Password</label>
                <input v-model="emailForm['smtp.password']" type="password" class="input w-full" />
              </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">From Email</label>
                <input v-model="emailForm['smtp.from_email']" type="email" class="input w-full" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">From Name</label>
                <input v-model="emailForm['smtp.from_name']" type="text" class="input w-full" />
              </div>
            </div>
            <div class="flex justify-end space-x-3">
              <button type="button" @click="openTestEmailModal" class="btn btn-secondary" :disabled="settingsStore.loading">
                <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                </svg>
                Test Email
              </button>
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                Save Email Settings
              </button>
            </div>
          </form>
        </div>

        <!-- Backup Defaults -->
        <div v-show="activeTab === 'backup'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Backup Default Settings</h3>
          <form @submit.prevent="saveSettings('backup')" class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-4">Default retention policy for new repositories</p>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Daily Backups</label>
                <input v-model.number="backupForm['backup.retention.daily']" type="number" min="0" max="365" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Number of daily backups to keep (0-365)</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Weekly Backups</label>
                <input v-model.number="backupForm['backup.retention.weekly']" type="number" min="0" max="52" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Number of weekly backups to keep (0-52)</p>
              </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Monthly Backups</label>
                <input v-model.number="backupForm['backup.retention.monthly']" type="number" min="0" max="60" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Number of monthly backups to keep (0-60)</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Yearly Backups</label>
                <input v-model.number="backupForm['backup.retention.yearly']" type="number" min="0" max="10" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Number of yearly backups to keep (0-10)</p>
              </div>
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                Save Backup Settings
              </button>
            </div>
          </form>
        </div>

        <!-- Borg Settings -->
        <div v-show="activeTab === 'borg'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Borg Settings</h3>
          <form @submit.prevent="saveSettings('borg')" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Default Repository Path</label>
              <input v-model="borgForm['borg.default_path']" type="text" class="input w-full" placeholder="/backup/borg" />
              <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Base path for borg repositories</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Default Compression</label>
              <select v-model="borgForm['borg.compression']" class="input w-full">
                <option value="none">None</option>
                <option value="lz4">LZ4 (fast)</option>
                <option value="zstd">ZSTD (balanced)</option>
                <option value="zlib">ZLIB (high)</option>
                <option value="lzma">LZMA (highest)</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Default Encryption</label>
              <select v-model="borgForm['borg.encryption']" class="input w-full">
                <option value="repokey-blake2">Repokey BLAKE2</option>
                <option value="repokey">Repokey (AES-CTR)</option>
                <option value="keyfile-blake2">Keyfile BLAKE2</option>
                <option value="keyfile">Keyfile (AES-CTR)</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Rate Limit (KB/s)</label>
              <input v-model.number="borgForm['borg.ratelimit']" type="number" min="0" class="input w-full" placeholder="0" />
              <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">0 = unlimited</p>
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                Save Borg Settings
              </button>
            </div>
          </form>
        </div>

        <!-- Security Settings -->
        <div v-show="activeTab === 'security'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Security Settings</h3>
          <form @submit.prevent="saveSettings('security')" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Access Token TTL (seconds)</label>
                <input v-model.number="securityForm['security.jwt.access_ttl']" type="number" min="300" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Default: 3600 = 1 hour</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Refresh Token TTL (seconds)</label>
                <input v-model.number="securityForm['security.jwt.refresh_ttl']" type="number" min="3600" class="input w-full" />
                <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Default: 2592000 = 30 days</p>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Session Timeout (seconds)</label>
              <input v-model.number="securityForm['security.session_timeout']" type="number" min="300" class="input w-full" />
              <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Default: 1800 = 30 minutes</p>
            </div>
            <div class="space-y-2">
              <div class="flex items-center">
                <input v-model="securityForm['security.force_https']" type="checkbox" class="mr-2" />
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Force HTTPS Connections</label>
              </div>
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                Save Security Settings
              </button>
            </div>
          </form>
        </div>

        <!-- Network Settings -->
        <div v-show="activeTab === 'network'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">Network Settings</h3>
          <form @submit.prevent="saveSettings('network')" class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">External IP Address</label>
                <input v-model="networkForm['network.external_ip']" type="text" class="input w-full" placeholder="192.168.1.100" />
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Internal IP Address</label>
                <input v-model="networkForm['network.internal_ip']" type="text" class="input w-full" placeholder="10.0.0.100" />
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">API Server Port</label>
              <input v-model.number="networkForm['network.api_port']" type="number" min="1" max="65535" class="input w-full" />
            </div>
            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                Save Network Settings
              </button>
            </div>
          </form>
        </div>

        <!-- System Settings -->
        <div v-show="activeTab === 'system'">
          <h3 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-4">System Settings</h3>
          <form @submit.prevent="saveSettings('system')" class="space-y-4">
            <!-- Logging Settings -->
            <div class="border-b pb-4 mb-4">
              <h4 class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-3">Logging Configuration</h4>
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Log File Path</label>
                  <input 
                    v-model="systemForm['log_path']" 
                    type="text" 
                    class="input w-full" 
                    placeholder="/var/log/phpborg.log"
                    required 
                  />
                  <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Full path to the application log file</p>
                </div>
                
                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Log Level</label>
                  <select v-model="systemForm['log_level']" class="input w-full">
                    <option value="debug">Debug - All messages including debug info</option>
                    <option value="info">Info - Informational messages and above</option>
                    <option value="warning">Warning - Warnings and errors only</option>
                    <option value="error">Error - Errors and critical only</option>
                    <option value="critical">Critical - Critical errors only</option>
                  </select>
                  <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Minimum severity level to log</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Log Rotation</label>
                  <div class="grid grid-cols-2 gap-4">
                    <div>
                      <label class="block text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">Max File Size (MB)</label>
                      <input 
                        v-model.number="systemForm['log_max_size']" 
                        type="number" 
                        min="1" 
                        class="input w-full" 
                        placeholder="100"
                      />
                    </div>
                    <div>
                      <label class="block text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">Files to Keep</label>
                      <input 
                        v-model.number="systemForm['log_max_files']" 
                        type="number" 
                        min="1" 
                        class="input w-full" 
                        placeholder="10"
                      />
                    </div>
                  </div>
                  <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Automatic log rotation settings</p>
                </div>
              </div>
            </div>

            <!-- Temporary Files -->
            <div class="border-b pb-4 mb-4">
              <h4 class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-3">Temporary Files</h4>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Temp Directory</label>
                <input
                  v-model="systemForm['temp_path']"
                  type="text"
                  class="input w-full"
                  placeholder="/tmp/phpborg"
                />
                <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Directory for temporary files during backup operations</p>
              </div>
            </div>

            <!-- Statistics Collection -->
            <div>
              <h4 class="text-md font-semibold text-gray-800 dark:text-gray-200 mb-3">Statistics Collection</h4>
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Collection Interval (seconds)</label>
                <input
                  v-model.number="systemForm['system.stats_collection_interval']"
                  type="number"
                  min="60"
                  step="60"
                  class="input w-full"
                  placeholder="900"
                />
                <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                  Intervalle de collecte automatique des statistiques pour les serveurs et storage pools (minimum: 60 secondes, recommandé: 900 secondes = 15 minutes)
                </p>
                <div class="mt-2 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
                  <p class="text-xs text-blue-900 dark:text-blue-200">
                    <strong>Note:</strong> Après modification, redémarrez le scheduler depuis la page Workers pour appliquer la nouvelle valeur.
                  </p>
                </div>
              </div>
            </div>

            <div class="flex justify-end">
              <button type="submit" class="btn btn-primary" :disabled="settingsStore.loading">
                Save System Settings
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Test Email Modal -->
    <div v-if="showTestEmailModal" class="fixed inset-0 bg-gray-600 dark:bg-gray-900 bg-opacity-50 overflow-y-auto h-full w-full z-50" @click.self="closeTestEmailModal">
      <div class="relative top-20 mx-auto p-5 border w-[500px] shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
          <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100">Send Test Email</h3>
          <button @click="closeTestEmailModal" class="text-gray-400 dark:text-gray-500 hover:text-gray-500 dark:text-gray-400">
            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>

        <!-- Success/Error Message -->
        <div v-if="testEmailMessage" :class="[
          'mb-4 p-3 rounded-lg',
          testEmailSuccess ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'
        ]">
          <p class="text-sm">{{ testEmailMessage }}</p>
        </div>

        <form @submit.prevent="sendTestEmail">
          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Recipient Email *</label>
              <input
                v-model="testEmailForm.to"
                type="email"
                class="input w-full"
                placeholder="your.email@example.com"
                required
              />
              <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Enter the email address where you want to receive the test message</p>
            </div>
          </div>

          <div class="flex gap-3 mt-6">
            <button type="button" @click="closeTestEmailModal" class="btn btn-secondary flex-1">
              Cancel
            </button>
            <button type="submit" class="btn btn-primary flex-1" :disabled="testEmailLoading">
              <span v-if="testEmailLoading">Sending...</span>
              <span v-else>Send Test Email</span>
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, reactive } from 'vue'
import { useSettingsStore } from '@/stores/settings'
import { emailService } from '@/services/email'

const settingsStore = useSettingsStore()

const activeTab = ref('general')

// Test Email Modal
const showTestEmailModal = ref(false)
const testEmailLoading = ref(false)
const testEmailMessage = ref('')
const testEmailSuccess = ref(false)
const testEmailForm = reactive({
  to: '',
})

const tabs = [
  { id: 'general', label: 'General' },
  { id: 'email', label: 'Email / SMTP' },
  { id: 'backup', label: 'Backup Defaults' },
  { id: 'borg', label: 'Borg' },
  { id: 'security', label: 'Security' },
  { id: 'network', label: 'Network' },
  { id: 'system', label: 'System' },
]

const generalForm = reactive({})
const emailForm = reactive({})
const backupForm = reactive({})
const borgForm = reactive({})
const securityForm = reactive({})
const networkForm = reactive({})
const systemForm = reactive({})

onMounted(async () => {
  await settingsStore.fetchSettings()
  loadForms()
})

function loadForms() {
  const settings = settingsStore.settings

  if (settings.general) Object.assign(generalForm, settings.general)
  if (settings.email) {
    Object.assign(emailForm, settings.email)
    emailForm['smtp.enabled'] = emailForm['smtp.enabled'] === true || emailForm['smtp.enabled'] === 'true'
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
    alert('Settings saved successfully!')
  } catch (err) {
    // Error handled by store
  }
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
    testEmailMessage.value = result.message || 'Test email sent successfully!'

    // Auto-close modal after 3 seconds on success
    setTimeout(() => {
      closeTestEmailModal()
    }, 3000)
  } catch (error) {
    testEmailSuccess.value = false
    testEmailMessage.value = error.response?.data?.error || 'Failed to send test email. Please check your SMTP settings.'
  } finally {
    testEmailLoading.value = false
  }
}
</script>
