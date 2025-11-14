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
          <h2 class="text-2xl font-bold text-gray-900 dark:text-white">{{ $t('restore_modal.title') }}</h2>
          <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
            {{ $t('restore_modal.step_progress', { current: currentStep, total: 3, title: stepTitles[currentStep - 1] }) }}
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
              <h3 class="text-lg font-semibold text-blue-900 dark:text-blue-200 mb-3">{{ $t('restore_modal.selected_files') }}</h3>
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
                <strong>{{ selectedFiles.length }}</strong> {{ $t('restore_modal.files_selected_suffix') }}
              </p>
            </div>

            <!-- Destination Server -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                {{ $t('restore_modal.destination_server') }} <span class="text-red-500">*</span>
              </label>
              <select
                v-model="formData.server_id"
                class="input w-full"
                required
              >
                <option value="">{{ $t('restore_modal.select_destination') }}</option>
                <option
                  v-for="server in servers"
                  :key="server.id"
                  :value="server.id"
                >
                  {{ server.name }} ({{ server.hostname }}){{ server.id === sourceServerId ? ` - ${$t('restore_modal.source_server')}` : '' }}
                </option>
              </select>
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                {{ $t('restore_modal.destination_help') }}
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
                {{ $t('restore_modal.restore_mode') }} <span class="text-red-500">*</span>
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
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $t('restore_modal.mode_inplace') }}</span>
                    <span class="text-2xl">⚠️</span>
                  </div>
                  <p class="text-xs text-gray-600 dark:text-gray-400" v-html="$t('restore_modal.mode_inplace_desc')"></p>
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
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $t('restore_modal.mode_alternate') }}</span>
                    <span class="text-2xl">📁</span>
                  </div>
                  <p class="text-xs text-gray-600 dark:text-gray-400" v-html="$t('restore_modal.mode_alternate_desc')"></p>
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
                    <span class="font-semibold text-gray-900 dark:text-white">{{ $t('restore_modal.mode_suffix') }}</span>
                    <span class="text-2xl">🔄</span>
                  </div>
                  <p class="text-xs text-gray-600 dark:text-gray-400">{{ $t('restore_modal.mode_suffix_desc') }}</p>
                </label>
              </div>
            </div>

            <!-- Alternate Destination Path -->
            <div v-if="formData.restore_mode === 'alternate'">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                {{ $t('restore_modal.destination_path') }} <span class="text-red-500">*</span>
              </label>
              <input
                v-model="formData.destination"
                type="text"
                class="input w-full font-mono"
                :placeholder="$t('restore_modal.destination_placeholder')"
                required
              />
              <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                {{ $t('restore_modal.destination_path_help') }}
              </p>
            </div>

            <!-- Overwrite Mode -->
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                {{ $t('restore_modal.overwrite_policy') }}
              </label>
              <select v-model="formData.overwrite_mode" class="input w-full">
                <option value="newer">{{ $t('restore_modal.overwrite_newer') }}</option>
                <option value="always">{{ $t('restore_modal.overwrite_always') }}</option>
                <option value="never">{{ $t('restore_modal.overwrite_never') }}</option>
                <option value="rename">{{ $t('restore_modal.overwrite_rename') }}</option>
              </select>
            </div>

            <!-- Advanced Options -->
            <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
              <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">{{ $t('restore_modal.advanced_options') }}</h4>
              <div class="space-y-3">
                <label class="flex items-center gap-2 cursor-pointer">
                  <input v-model="formData.preserve_permissions" type="checkbox" class="rounded" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">{{ $t('restore_modal.preserve_permissions') }}</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input v-model="formData.preserve_owner" type="checkbox" class="rounded" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">{{ $t('restore_modal.preserve_owner') }}</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input v-model="formData.verify_checksums" type="checkbox" class="rounded" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">{{ $t('restore_modal.verify_checksums') }}</span>
                </label>
                <label class="flex items-center gap-2 cursor-pointer">
                  <input v-model="formData.dry_run" type="checkbox" class="rounded" />
                  <span class="text-sm text-gray-700 dark:text-gray-300">
                    {{ $t('restore_modal.dry_run') }}
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
                  <h3 class="text-lg font-bold text-red-900 dark:text-red-200 mb-2">{{ $t('restore_modal.danger_title') }}</h3>
                  <p class="text-sm text-red-800 dark:text-red-300 mb-3" v-html="$t('restore_modal.danger_description')"></p>
                  <div class="bg-white dark:bg-gray-800 rounded p-4 mb-4">
                    <p class="text-sm text-red-900 dark:text-red-200 font-semibold mb-2">{{ $t('restore_modal.risks_title') }}</p>
                    <ul class="text-sm text-red-800 dark:text-red-300 space-y-1 list-disc list-inside">
                      <li>{{ $t('restore_modal.risk_1') }}</li>
                      <li>{{ $t('restore_modal.risk_2') }}</li>
                      <li>{{ $t('restore_modal.risk_3') }}</li>
                      <li>{{ $t('restore_modal.risk_4') }}</li>
                    </ul>
                  </div>
                  <div class="text-sm text-red-800 dark:text-red-300 mb-4" v-html="$t('restore_modal.recommendations')"></div>
                  <label class="flex items-start gap-3 cursor-pointer bg-white dark:bg-gray-800 p-4 rounded">
                    <input
                      v-model="formData.confirm_overwrite"
                      type="checkbox"
                      class="mt-1 rounded"
                      required
                    />
                    <span class="text-sm text-red-900 dark:text-red-200 font-semibold">
                      {{ $t('restore_modal.confirm_responsibility') }}
                    </span>
                  </label>
                </div>
              </div>
            </div>

            <!-- Summary -->
            <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-6">
              <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">{{ $t('restore_modal.summary_title') }}</h3>
              <dl class="space-y-3">
                <div>
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $t('restore_modal.summary_archive') }}</dt>
                  <dd class="text-sm text-gray-900 dark:text-white font-mono mt-1">{{ archiveName }}</dd>
                </div>
                <div>
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $t('restore_modal.summary_files') }}</dt>
                  <dd class="text-sm text-gray-900 dark:text-white mt-1">
                    <strong>{{ selectedFiles.length }}</strong> {{ $t('restore_modal.files_selected_suffix') }}
                  </dd>
                </div>
                <div>
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $t('restore_modal.summary_server') }}</dt>
                  <dd class="text-sm text-gray-900 dark:text-white mt-1">{{ selectedServerName }}</dd>
                </div>
                <div>
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $t('restore_modal.summary_mode') }}</dt>
                  <dd class="text-sm text-gray-900 dark:text-white mt-1">
                    <span
                      :class="formData.restore_mode === 'in_place' ? 'text-red-600 dark:text-red-400 font-bold' : ''"
                    >
                      {{ restoreModeLabel }}
                    </span>
                  </dd>
                </div>
                <div v-if="formData.restore_mode === 'alternate'">
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $t('restore_modal.summary_path') }}</dt>
                  <dd class="text-sm text-gray-900 dark:text-white font-mono mt-1">{{ formData.destination }}</dd>
                </div>
                <div>
                  <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">{{ $t('restore_modal.summary_overwrite') }}</dt>
                  <dd class="text-sm text-gray-900 dark:text-white mt-1">{{ overwriteModeLabel }}</dd>
                </div>
                <div v-if="formData.dry_run">
                  <dt class="text-sm font-medium text-blue-500 dark:text-blue-400">{{ $t('restore_modal.summary_mode_label') }}</dt>
                  <dd class="text-sm text-blue-900 dark:text-blue-200 font-semibold mt-1">{{ $t('restore_modal.dry_run_label') }}</dd>
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
          ← {{ $t('restore_modal.previous') }}
        </button>
        <div v-else></div>

        <div class="flex gap-3">
          <button
            @click="handleCancel"
            class="btn btn-secondary"
            :disabled="loading"
          >
            {{ $t('restore_modal.cancel') }}
          </button>
          <button
            v-if="currentStep < 3"
            @click="nextStep"
            class="btn btn-primary"
            :disabled="!canProceed || loading"
          >
            {{ $t('restore_modal.next') }} →
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
              {{ formData.dry_run ? $t('restore_modal.simulating') : $t('restore_modal.restoring') }}
            </span>
            <span v-else>
              {{ formData.dry_run ? $t('restore_modal.button_simulate') : formData.restore_mode === 'in_place' ? $t('restore_modal.button_restore_inplace') : $t('restore_modal.button_restore') }}
            </span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { backupService } from '@/services/backups'
import { serverService } from '@/services/server'

const { t } = useI18n()

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

const stepTitles = computed(() => [
  t('restore_modal.select_server'),
  t('restore_modal.configure_options'),
  t('restore_modal.confirm_restore')
])

const currentStep = ref(1)
const loading = ref(false)
const servers = ref([])
const sourceServerId = ref(null)

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
    'in_place': t('restore_modal.mode_inplace_label'),
    'alternate': t('restore_modal.mode_alternate_label'),
    'suffix': t('restore_modal.mode_suffix_label')
  }
  return labels[formData.value.restore_mode] || ''
})

const overwriteModeLabel = computed(() => {
  const labels = {
    'always': t('restore_modal.overwrite_always'),
    'newer': t('restore_modal.overwrite_newer'),
    'never': t('restore_modal.overwrite_never'),
    'rename': t('restore_modal.overwrite_rename')
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

// Function to load servers and pre-select source
async function loadServersAndPreselect() {
  try {
    console.log('Loading servers for RestoreWizard, archiveId:', props.archiveId)

    // Load servers and archive details in parallel
    const [serversList, archive] = await Promise.all([
      serverService.list(),
      props.archiveId ? backupService.get(props.archiveId) : Promise.resolve(null)
    ])

    console.log('Loaded servers:', serversList.length, 'Archive:', archive)

    // Filter and set active servers
    servers.value = serversList.filter(s => s.active)

    // Pre-select source server if archive was loaded
    if (archive && archive.server_id) {
      sourceServerId.value = archive.server_id
      // Pre-select source server if it's in the active servers list
      const sourceServer = servers.value.find(s => s.id === archive.server_id)
      if (sourceServer) {
        formData.value.server_id = archive.server_id
        console.log('✅ Pre-selected source server:', sourceServer.name, '(ID:', archive.server_id, ')')
      } else {
        console.log('⚠️ Source server ID', archive.server_id, 'not found in active servers list')
      }
    }
  } catch (error) {
    console.error('Failed to load data:', error)
  }
}

// Watch for modal show/hide to reload data on each open
watch(() => props.show, (newShow) => {
  if (newShow) {
    console.log('RestoreWizard modal opened')
    // Reset step and form when modal opens
    currentStep.value = 1
    formData.value.server_id = ''
    formData.value.restore_mode = 'alternate'
    formData.value.destination = '/tmp/restore-' + new Date().toISOString().split('T')[0] + '/'
    formData.value.overwrite_mode = 'newer'
    formData.value.confirm_overwrite = false

    // Load servers and pre-select
    loadServersAndPreselect()
  }
})

onMounted(() => {
  if (props.show) {
    loadServersAndPreselect()
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
