<template>
  <div
    v-if="show"
    class="fixed inset-0 bg-black bg-opacity-50 dark:bg-opacity-70 flex items-center justify-center z-50 p-4"
    @click.self="handleCancel"
  >
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-2xl w-full max-w-4xl max-h-[90vh] flex flex-col">
      <!-- Header -->
      <div class="flex items-center justify-between p-6 border-b border-gray-200 dark:border-gray-700">
        <div>
          <h2 class="text-2xl font-bold text-gray-900 dark:text-white">Restore Files</h2>
          <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
            Step {{ currentStep }} of 3: {{ stepTitles[currentStep - 1] }}
          </p>
        </div>
        <button
          @click="handleCancel"
          class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Progress Steps -->
      <div class="px-6 py-4 border-b border-gray-200 dark:border-gray-700">
        <div class="flex items-center justify-between">
          <div
            v-for="step in 3"
            :key="step"
            class="flex items-center"
            :class="{ 'flex-1': step < 3 }"
          >
            <div class="flex items-center">
              <div
                :class="[
                  'flex items-center justify-center w-10 h-10 rounded-full border-2 font-semibold',
                  step < currentStep
                    ? 'bg-green-500 border-green-500 text-white'
                    : step === currentStep
                    ? 'bg-primary-500 border-primary-500 text-white'
                    : 'bg-gray-200 dark:bg-gray-700 border-gray-300 dark:border-gray-600 text-gray-500 dark:text-gray-400'
                ]"
              >
                <svg v-if="step < currentStep" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
                <span v-else>{{ step }}</span>
              </div>
              <span
                class="ml-3 text-sm font-medium"
                :class="step <= currentStep ? 'text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400'"
              >
                {{ stepTitles[step - 1] }}
              </span>
            </div>
            <div
              v-if="step < 3"
              :class="[
                'flex-1 h-0.5 mx-4',
                step < currentStep ? 'bg-green-500' : 'bg-gray-300 dark:bg-gray-600'
              ]"
            ></div>
          </div>
        </div>
      </div>

      <!-- Content -->
      <div class="flex-1 overflow-y-auto p-6">
        <!-- Step 1: Select Server & Review Files -->
        <div v-if="currentStep === 1">
          <div class="space-y-6">
            <!-- Selected Files Summary -->
            <div class="bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4">
              <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-200 mb-3">Selected Files</h3>
              <div class="space-y-2 max-h-60 overflow-y-auto">
                <div
                  v-for="(file, index) in selectedFiles"
                  :key="index"
                  class="flex items-center gap-2 text-sm text-blue-800 dark:text-blue-300 bg-white dark:bg-gray-700 px-3 py-2 rounded"
                >
                  <svg class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                  </svg>
                  <span class="font-mono">{{ file }}</span>
                </div>
              </div>
              <p class="text-sm text-blue-700 dark:text-blue-300 mt-3">
                <strong>{{ selectedFiles.length }}</strong> file(s) / folder(s) selected for restore
              </p>
            </div>

            <!-- Destination Server -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Destination Server <span class="text-red-500">*</span>
              </label>
              <select
                v-model="formData.server_id"
                class="input w-full"
                required
              >
                <option value="">Select destination server...</option>
                <option
                  v-for="server in servers"
                  :key="server.id"
                  :value="server.id"
                >
                  {{ server.name }} ({{ server.hostname }})
                </option>
              </select>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Choose the server where files will be restored
              </p>
            </div>
          </div>
        </div>

        <!-- Step 2: Configure Restore Options -->
        <div v-else-if="currentStep === 2">
          <div class="space-y-6">
            <!-- Restore Mode -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">
                Restore Mode <span class="text-red-500">*</span>
              </label>
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <!-- In-Place Mode -->
                <label
                  class="relative flex flex-col p-4 border-2 rounded-lg cursor-pointer transition-all"
                  :class="formData.restore_mode === 'in_place'
                    ? 'border-red-500 bg-red-50 dark:bg-red-900/20'
                    : 'border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500'"
                >
                  <input
                    type="radio"
                    v-model="formData.restore_mode"
                    value="in_place"
                    class="sr-only"
                  />
                  <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold text-gray-900 dark:text-white">In-Place</span>
                    <span class="text-2xl">⚠️</span>
                  </div>
                  <p class="text-xs text-gray-600 dark:text-gray-400">
                    Restore to original location. <strong class="text-red-600 dark:text-red-400">DANGEROUS!</strong> This will overwrite production files.
                  </p>
                </label>

                <!-- Alternate Location Mode -->
                <label
                  class="relative flex flex-col p-4 border-2 rounded-lg cursor-pointer transition-all"
                  :class="formData.restore_mode === 'alternate'
                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                    : 'border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500'"
                >
                  <input
                    type="radio"
                    v-model="formData.restore_mode"
                    value="alternate"
                    class="sr-only"
                  />
                  <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold text-gray-900 dark:text-white">Alternate</span>
                    <span class="text-2xl">📁</span>
                  </div>
                  <p class="text-xs text-gray-600 dark:text-gray-400">
                    Restore to a custom location. <strong class="text-green-600 dark:text-green-400">SAFE!</strong> Recommended option.
                  </p>
                </label>

                <!-- Suffix Mode -->
                <label
                  class="relative flex flex-col p-4 border-2 rounded-lg cursor-pointer transition-all"
                  :class="formData.restore_mode === 'suffix'
                    ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                    : 'border-gray-300 dark:border-gray-600 hover:border-gray-400 dark:hover:border-gray-500'"
                >
                  <input
                    type="radio"
                    v-model="formData.restore_mode"
                    value="suffix"
                    class="sr-only"
                  />
                  <div class="flex items-center justify-between mb-2">
                    <span class="font-semibold text-gray-900 dark:text-white">With Suffix</span>
                    <span class="text-2xl">🔄</span>
                  </div>
                  <p class="text-xs text-gray-600 dark:text-gray-400">
                    Restore with timestamp suffix (.restored-YYMMDDHHMMSS). Safe for testing.
                  </p>
                </label>
              </div>
            </div>

            <!-- Alternate Destination Path -->
            <div v-if="formData.restore_mode === 'alternate'">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Destination Path <span class="text-red-500">*</span>
              </label>
              <input
                v-model="formData.destination"
                type="text"
                class="input w-full font-mono"
                placeholder="/tmp/restore-YYYY-MM-DD/"
                required
              />
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                Custom directory where files will be restored
              </p>
            </div>

            <!-- Overwrite Mode -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                Overwrite Policy
              </label>
              <select v-model="formData.overwrite_mode" class="input w-full">
                <option value="newer">Update if newer (recommended)</option>
                <option value="always">Always overwrite</option>
                <option value="never">Never overwrite (skip existing)</option>
                <option value="rename">Rename existing (.before-restore)</option>
              </select>
            </div>

            <!-- Advanced Options -->
            <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
              <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">Advanced Options</h4>
              <div class="space-y-3">
                <label class="flex items-center gap-2 cursor-pointer">
                  <input v-model="formData.preserve_permissions" type="checkbox" class="rounded" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">Preserve file permissions</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input v-model="formData.preserve_owner" type="checkbox" class="rounded" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">Preserve file ownership (user/group)</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input v-model="formData.verify_checksums" type="checkbox" class="rounded" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">Verify checksums after restore</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input v-model="formData.dry_run" type="checkbox" class="rounded" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">
                    Dry run (simulate without actual restore)
                  </span>
                </label>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 3: Confirmation -->
        <div v-else-if="currentStep === 3">
          <div class="space-y-6">
            <!-- Warning for In-Place Mode -->
            <div
              v-if="formData.restore_mode === 'in_place'"
              class="bg-red-50 dark:bg-red-900/20 border-2 border-red-500 dark:border-red-700 rounded-lg p-6"
            >
              <div class="flex items-start gap-4">
                <svg class="w-12 h-12 text-red-600 dark:text-red-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <div class="flex-1">
                  <h3 class="text-lg font-bold text-red-900 dark:text-red-200 mb-2">⚠️  DANGER - In-Place Restore</h3>
                  <p class="text-sm text-red-800 dark:text-red-300 mb-3">
                    This operation will <strong>REPLACE files in their original location</strong> on the production server.
                  </p>
                  <div class="bg-white dark:bg-gray-800 rounded p-4 mb-4">
                    <p class="text-sm text-red-900 dark:text-red-200 font-semibold mb-2">Risks:</p>
                    <ul class="text-sm text-red-800 dark:text-red-300 space-y-1 list-disc list-inside">
                      <li>Loss of current data if restoring wrong version</li>
                      <li>Overwriting recent changes</li>
                      <li>Potential service downtime</li>
                      <li>Difficulty reverting changes</li>
                    </ul>
                  </div>
                  <p class="text-sm text-red-800 dark:text-red-300 mb-4">
                    <strong>Recommendations:</strong><br />
                    1. Test with "Alternate location" first<br />
                    2. Verify archive date and contents<br />
                    3. Consider creating a backup before proceeding
                  </p>
                  <label class="flex items-start gap-3 cursor-pointer bg-white dark:bg-gray-800 p-4 rounded">
                    <input
                      v-model="formData.confirm_overwrite"
                      type="checkbox"
                      class="mt-1 rounded"
                      required
                    />
                    <span class="text-sm text-red-900 dark:text-red-200 font-semibold">
                      I understand the risks and I accept full responsibility for this in-place restore operation
                    </span>
                  </label>
                </div>
              </div>
            </div>

            <!-- Summary -->
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-6">
              <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">📋 Restore Summary</h3>
              <dl class="space-y-3">
                <div>
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Archive</dt>
                  <dd class="text-sm text-gray-900 dark:text-white font-mono mt-1">{{ archiveName }}</dd>
                </div>
                <div>
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Files to Restore</dt>
                  <dd class="text-sm text-gray-900 dark:text-white mt-1"><strong>{{ selectedFiles.length }}</strong> file(s) / folder(s)</dd>
                </div>
                <div>
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Destination Server</dt>
                  <dd class="text-sm text-gray-900 dark:text-white mt-1">{{ selectedServerName }}</dd>
                </div>
                <div>
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Restore Mode</dt>
                  <dd class="text-sm text-gray-900 dark:text-white mt-1">
                    <span
                      :class="formData.restore_mode === 'in_place' ? 'text-red-600 dark:text-red-400 font-bold' : ''"
                    >
                      {{ restoreModeLabel }}
                    </span>
                  </dd>
                </div>
                <div v-if="formData.restore_mode === 'alternate'">
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Destination Path</dt>
                  <dd class="text-sm text-gray-900 dark:text-white font-mono mt-1">{{ formData.destination }}</dd>
                </div>
                <div>
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Overwrite Policy</dt>
                  <dd class="text-sm text-gray-900 dark:text-white mt-1">{{ overwriteModeLabel }}</dd>
                </div>
                <div v-if="formData.dry_run">
                  <dt class="text-sm font-medium text-blue-500 dark:text-blue-400">Mode</dt>
                  <dd class="text-sm text-blue-900 dark:text-blue-200 font-semibold mt-1">Dry Run (Simulation Only)</dd>
                </div>
              </dl>
            </div>
          </div>
        </div>
      </div>

      <!-- Footer -->
      <div class="flex items-center justify-between gap-4 px-6 py-4 border-t border-gray-200 dark:border-gray-700">
        <button
          v-if="currentStep > 1"
          @click="previousStep"
          class="btn btn-secondary"
          :disabled="loading"
        >
          ← Previous
        </button>
        <div v-else></div>

        <div class="flex gap-3">
          <button
            @click="handleCancel"
            class="btn btn-secondary"
            :disabled="loading"
          >
            Cancel
          </button>
          <button
            v-if="currentStep < 3"
            @click="nextStep"
            class="btn btn-primary"
            :disabled="!canProceed || loading"
          >
            Next →
          </button>
          <button
            v-else
            @click="handleSubmit"
            class="btn"
            :class="formData.restore_mode === 'in_place' ? 'bg-red-600 hover:bg-red-700' : 'btn-primary'"
            :disabled="!canSubmit || loading"
          >
            <span v-if="loading">
              <svg class="animate-spin h-5 w-5 inline mr-2" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ formData.dry_run ? 'Simulating...' : 'Restoring...' }}
            </span>
            <span v-else>
              {{ formData.dry_run ? '🧪 Simulate Restore' : formData.restore_mode === 'in_place' ? '⚠️  Restore In-Place' : '✅ Start Restore' }}
            </span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { backupService } from '@/services/backups'
import { serverService } from '@/services/server'

const props = defineProps({
  show: Boolean,
  archiveId: Number,
  archiveName: String,
  selectedFiles: {
    type: Array,
    default: () => []
  }
})

const emit = defineEmits(['close', 'success'])

const stepTitles = ['Select Server', 'Configure Options', 'Confirm & Restore']

const currentStep = ref(1)
const loading = ref(false)
const servers = ref([])

const formData = ref({
  server_id: '',
  restore_mode: 'alternate',
  destination: '/tmp/restore-' + new Date().toISOString().split('T')[0] + '/',
  overwrite_mode: 'newer',
  preserve_permissions: true,
  preserve_owner: true,
  verify_checksums: false,
  dry_run: false,
  confirm_overwrite: false
})

const selectedServerName = computed(() => {
  const server = servers.value.find(s => s.id === parseInt(formData.value.server_id))
  return server ? `${server.name} (${server.hostname})` : ''
})

const restoreModeLabel = computed(() => {
  const labels = {
    'in_place': 'In-Place (Overwrite Production) ⚠️',
    'alternate': 'Alternate Location (Safe) ✅',
    'suffix': 'With Suffix (Safe) 🔄'
  }
  return labels[formData.value.restore_mode] || ''
})

const overwriteModeLabel = computed(() => {
  const labels = {
    'always': 'Always overwrite',
    'newer': 'Update if newer only (recommended)',
    'never': 'Never overwrite (skip existing)',
    'rename': 'Rename existing files (.before-restore)'
  }
  return labels[formData.value.overwrite_mode] || ''
})

const canProceed = computed(() => {
  if (currentStep.value === 1) {
    return formData.value.server_id !== ''
  }
  if (currentStep.value === 2) {
    if (formData.value.restore_mode === 'alternate') {
      return formData.value.destination !== ''
    }
    return true
  }
  return true
})

const canSubmit = computed(() => {
  if (formData.value.restore_mode === 'in_place') {
    return formData.value.confirm_overwrite
  }
  return true
})

onMounted(async () => {
  try {
    const data = await serverService.getServers()
    servers.value = data.servers || []
  } catch (error) {
    console.error('Failed to load servers:', error)
  }
})

// Reset confirmation when restore mode changes
watch(() => formData.value.restore_mode, () => {
  formData.value.confirm_overwrite = false
})

function nextStep() {
  if (canProceed.value && currentStep.value < 3) {
    currentStep.value++
  }
}

function previousStep() {
  if (currentStep.value > 1) {
    currentStep.value--
  }
}

function handleCancel() {
  if (!loading.value) {
    emit('close')
  }
}

async function handleSubmit() {
  try {
    loading.value = true

    const restoreData = {
      server_id: parseInt(formData.value.server_id),
      files: props.selectedFiles,
      restore_mode: formData.value.restore_mode,
      destination: formData.value.restore_mode === 'alternate' ? formData.value.destination : undefined,
      overwrite_mode: formData.value.overwrite_mode,
      preserve_permissions: formData.value.preserve_permissions,
      preserve_owner: formData.value.preserve_owner,
      verify_checksums: formData.value.verify_checksums,
      dry_run: formData.value.dry_run,
      confirm_overwrite: formData.value.restore_mode === 'in_place' ? formData.value.confirm_overwrite : undefined
    }

    const result = await backupService.restore(props.archiveId, restoreData)

    emit('success', result)
    emit('close')
  } catch (error) {
    console.error('Restore failed:', error)
    alert('Restore failed: ' + (error.response?.data?.error?.message || error.message))
  } finally {
    loading.value = false
  }
}
</script>
