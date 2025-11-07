<template>
  <TransitionRoot :show="isOpen" as="template">
    <Dialog as="div" class="relative z-50" @close="closeModal">
      <TransitionChild
        as="template"
        enter="ease-out duration-300"
        enter-from="opacity-0"
        enter-to="opacity-100"
        leave="ease-in duration-200"
        leave-from="opacity-100"
        leave-to="opacity-0"
      >
        <div class="fixed inset-0 bg-black/30 backdrop-blur-sm" />
      </TransitionChild>

      <div class="fixed inset-0 overflow-y-auto">
        <div class="flex min-h-full items-center justify-center p-4">
          <TransitionChild
            as="template"
            enter="ease-out duration-300"
            enter-from="opacity-0 scale-95"
            enter-to="opacity-100 scale-100"
            leave="ease-in duration-200"
            leave-from="opacity-100 scale-100"
            leave-to="opacity-0 scale-95"
          >
            <DialogPanel class="w-full max-w-2xl transform overflow-hidden rounded-2xl bg-white shadow-2xl transition-all">
              <!-- Header -->
              <div class="bg-gradient-to-r from-blue-600 to-blue-700 px-6 py-5">
                <div class="flex items-center justify-between">
                  <div class="flex items-center space-x-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-white/20">
                      <svg class="h-6 w-6 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                    </div>
                    <div>
                      <DialogTitle class="text-xl font-bold text-white">
                        Retention Policy
                      </DialogTitle>
                      <p class="text-sm text-blue-100">
                        {{ repository.type }} repository - {{ serverName }}
                      </p>
                    </div>
                  </div>
                  <button
                    @click="closeModal"
                    class="rounded-lg p-2 text-white/80 transition hover:bg-white/10 hover:text-white"
                  >
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                  </button>
                </div>
              </div>

              <!-- Content -->
              <div class="px-6 py-6 space-y-6">
                <!-- Info Banner -->
                <div class="rounded-lg bg-blue-50 border border-blue-200 p-4">
                  <div class="flex items-start space-x-3">
                    <svg class="h-5 w-5 text-blue-600 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <div class="text-sm text-blue-900">
                      <p class="font-medium mb-1">Retention Policy Explained</p>
                      <p class="text-blue-800">
                        Borg will keep the specified number of backups for each period.
                        Set to 0 to disable a period. At least one value must be greater than 0.
                      </p>
                    </div>
                  </div>
                </div>

                <!-- Retention Settings -->
                <div class="space-y-5">
                  <!-- Daily -->
                  <div class="flex items-center justify-between space-x-4">
                    <div class="flex-1">
                      <label class="block text-sm font-semibold text-gray-900 mb-1">
                        Daily Backups
                      </label>
                      <p class="text-xs text-gray-600">
                        Keep last N daily backups
                      </p>
                    </div>
                    <div class="flex items-center space-x-3">
                      <input
                        v-model.number="form.keep_daily"
                        type="range"
                        min="0"
                        max="365"
                        class="w-32 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
                      />
                      <input
                        v-model.number="form.keep_daily"
                        type="number"
                        min="0"
                        max="365"
                        class="w-20 px-3 py-2 text-center border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      />
                      <span class="text-sm font-medium text-gray-700 w-12">days</span>
                    </div>
                  </div>

                  <!-- Weekly -->
                  <div class="flex items-center justify-between space-x-4">
                    <div class="flex-1">
                      <label class="block text-sm font-semibold text-gray-900 mb-1">
                        Weekly Backups
                      </label>
                      <p class="text-xs text-gray-600">
                        Keep last N weekly backups
                      </p>
                    </div>
                    <div class="flex items-center space-x-3">
                      <input
                        v-model.number="form.keep_weekly"
                        type="range"
                        min="0"
                        max="52"
                        class="w-32 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
                      />
                      <input
                        v-model.number="form.keep_weekly"
                        type="number"
                        min="0"
                        max="52"
                        class="w-20 px-3 py-2 text-center border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      />
                      <span class="text-sm font-medium text-gray-700 w-12">weeks</span>
                    </div>
                  </div>

                  <!-- Monthly -->
                  <div class="flex items-center justify-between space-x-4">
                    <div class="flex-1">
                      <label class="block text-sm font-semibold text-gray-900 mb-1">
                        Monthly Backups
                      </label>
                      <p class="text-xs text-gray-600">
                        Keep last N monthly backups
                      </p>
                    </div>
                    <div class="flex items-center space-x-3">
                      <input
                        v-model.number="form.keep_monthly"
                        type="range"
                        min="0"
                        max="60"
                        class="w-32 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
                      />
                      <input
                        v-model.number="form.keep_monthly"
                        type="number"
                        min="0"
                        max="60"
                        class="w-20 px-3 py-2 text-center border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      />
                      <span class="text-sm font-medium text-gray-700 w-12">months</span>
                    </div>
                  </div>

                  <!-- Yearly -->
                  <div class="flex items-center justify-between space-x-4">
                    <div class="flex-1">
                      <label class="block text-sm font-semibold text-gray-900 mb-1">
                        Yearly Backups
                      </label>
                      <p class="text-xs text-gray-600">
                        Keep last N yearly backups (0 = disabled)
                      </p>
                    </div>
                    <div class="flex items-center space-x-3">
                      <input
                        v-model.number="form.keep_yearly"
                        type="range"
                        min="0"
                        max="10"
                        class="w-32 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
                      />
                      <input
                        v-model.number="form.keep_yearly"
                        type="number"
                        min="0"
                        max="10"
                        class="w-20 px-3 py-2 text-center border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      />
                      <span class="text-sm font-medium text-gray-700 w-12">years</span>
                    </div>
                  </div>
                </div>

                <!-- Preview -->
                <div class="rounded-lg bg-gray-50 border border-gray-200 p-4">
                  <h4 class="text-sm font-semibold text-gray-900 mb-3">Policy Preview</h4>
                  <div class="space-y-2 text-sm text-gray-700">
                    <div v-if="form.keep_daily > 0" class="flex items-center justify-between">
                      <span>Last <strong>{{ form.keep_daily }}</strong> daily backups</span>
                      <span class="text-xs text-gray-500">≈ {{ Math.ceil(form.keep_daily) }} days</span>
                    </div>
                    <div v-if="form.keep_weekly > 0" class="flex items-center justify-between">
                      <span>Last <strong>{{ form.keep_weekly }}</strong> weekly backups</span>
                      <span class="text-xs text-gray-500">≈ {{ Math.ceil(form.keep_weekly * 7 / 30) }} months</span>
                    </div>
                    <div v-if="form.keep_monthly > 0" class="flex items-center justify-between">
                      <span>Last <strong>{{ form.keep_monthly }}</strong> monthly backups</span>
                      <span class="text-xs text-gray-500">≈ {{ Math.ceil(form.keep_monthly / 12) }} years</span>
                    </div>
                    <div v-if="form.keep_yearly > 0" class="flex items-center justify-between">
                      <span>Last <strong>{{ form.keep_yearly }}</strong> yearly backups</span>
                      <span class="text-xs text-gray-500">{{ form.keep_yearly }} years</span>
                    </div>
                    <div v-if="totalPeriods === 0" class="text-amber-600 font-medium">
                      ⚠️ At least one retention value must be greater than 0
                    </div>
                  </div>
                </div>

                <!-- Error Message -->
                <div v-if="errorMessage" class="rounded-lg bg-red-50 border border-red-200 p-4">
                  <div class="flex items-start space-x-3">
                    <svg class="h-5 w-5 text-red-600 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                    <p class="text-sm text-red-800">{{ errorMessage }}</p>
                  </div>
                </div>
              </div>

              <!-- Footer -->
              <div class="bg-gray-50 px-6 py-4 flex items-center justify-between">
                <button
                  @click="closeModal"
                  class="px-4 py-2 text-sm font-medium text-gray-700 hover:text-gray-900 transition"
                >
                  Cancel
                </button>
                <button
                  @click="saveRetention"
                  :disabled="saving || totalPeriods === 0"
                  class="px-6 py-2 bg-blue-600 text-white text-sm font-semibold rounded-lg hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition shadow-sm hover:shadow"
                >
                  <span v-if="saving">Saving...</span>
                  <span v-else>Save Policy</span>
                </button>
              </div>
            </DialogPanel>
          </TransitionChild>
        </div>
      </div>
    </Dialog>
  </TransitionRoot>
</template>

<script setup>
import { ref, computed, watch } from 'vue'
import { Dialog, DialogPanel, DialogTitle, TransitionRoot, TransitionChild } from '@headlessui/vue'
import { useRepositoryStore } from '../stores/repository'

const props = defineProps({
  isOpen: {
    type: Boolean,
    required: true
  },
  repository: {
    type: Object,
    required: true
  },
  serverName: {
    type: String,
    default: 'Unknown Server'
  }
})

const emit = defineEmits(['close', 'updated'])

const repositoryStore = useRepositoryStore()

const form = ref({
  keep_daily: 0,
  keep_weekly: 0,
  keep_monthly: 0,
  keep_yearly: 0
})

const saving = ref(false)
const errorMessage = ref(null)

const totalPeriods = computed(() => {
  return (form.value.keep_daily > 0 ? 1 : 0) +
         (form.value.keep_weekly > 0 ? 1 : 0) +
         (form.value.keep_monthly > 0 ? 1 : 0) +
         (form.value.keep_yearly > 0 ? 1 : 0)
})

// Watch for modal open and repository changes - populate form with current values
watch(
  [() => props.isOpen, () => props.repository],
  ([isOpen, repository]) => {
    if (isOpen && repository?.retention) {
      form.value = {
        keep_daily: repository.retention.keep_daily || 0,
        keep_weekly: repository.retention.keep_weekly || 0,
        keep_monthly: repository.retention.keep_monthly || 0,
        keep_yearly: repository.retention.keep_yearly || 0
      }
      errorMessage.value = null
    }
  },
  { immediate: true }
)

function closeModal() {
  emit('close')
}

async function saveRetention() {
  if (totalPeriods.value === 0) {
    errorMessage.value = 'At least one retention value must be greater than 0'
    return
  }

  saving.value = true
  errorMessage.value = null

  try {
    await repositoryStore.updateRetention(props.repository.id, form.value)
    emit('updated')
    closeModal()
  } catch (error) {
    errorMessage.value = error.response?.data?.error?.message || 'Failed to update retention policy'
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
/* Slider styling */
input[type="range"]::-webkit-slider-thumb {
  appearance: none;
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: rgb(37, 99, 235);
  cursor: pointer;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

input[type="range"]::-moz-range-thumb {
  width: 16px;
  height: 16px;
  border-radius: 50%;
  background: rgb(37, 99, 235);
  cursor: pointer;
  border: none;
  box-shadow: 0 2px 4px rgba(0, 0, 0, 0.2);
}

input[type="number"]::-webkit-inner-spin-button,
input[type="number"]::-webkit-outer-spin-button {
  opacity: 1;
}
</style>
