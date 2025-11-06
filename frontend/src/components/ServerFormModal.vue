<template>
  <div class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center p-4 z-50">
    <div class="bg-white rounded-lg max-w-md w-full p-6">
      <!-- Modal Header -->
      <div class="flex justify-between items-center mb-6">
        <h2 class="text-2xl font-bold text-gray-900">
          {{ isEdit ? 'Edit Server' : 'Add Server' }}
        </h2>
        <button @click="$emit('close')" class="text-gray-400 hover:text-gray-600">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Error Message -->
      <div v-if="error" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
        <p class="text-sm text-red-800">{{ error }}</p>
      </div>

      <!-- Form -->
      <form @submit.prevent="handleSubmit">
        <div class="space-y-4">
          <div>
            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
              Server Name <span class="text-red-500">*</span>
            </label>
            <input
              id="name"
              v-model="form.name"
              type="text"
              required
              class="input"
              placeholder="e.g., web-server-01"
            />
          </div>

          <div>
            <label for="hostname" class="block text-sm font-medium text-gray-700 mb-1">
              Hostname / IP <span class="text-red-500">*</span>
            </label>
            <input
              id="hostname"
              v-model="form.hostname"
              type="text"
              required
              class="input"
              placeholder="e.g., 192.168.1.100 or server.example.com"
            />
          </div>

          <div class="grid grid-cols-2 gap-4">
            <div>
              <label for="port" class="block text-sm font-medium text-gray-700 mb-1">
                SSH Port
              </label>
              <input
                id="port"
                v-model.number="form.port"
                type="number"
                min="1"
                max="65535"
                class="input"
                placeholder="22"
              />
            </div>

            <div>
              <label for="username" class="block text-sm font-medium text-gray-700 mb-1">
                Username
              </label>
              <input
                id="username"
                v-model="form.username"
                type="text"
                class="input"
                placeholder="root"
              />
            </div>
          </div>

          <div>
            <label for="backupType" class="block text-sm font-medium text-gray-700 mb-1">
              Backup Type <span class="text-red-500">*</span>
            </label>
            <select
              id="backupType"
              v-model="form.backupType"
              required
              class="input"
            >
              <option value="internal">Internal (Private Network - 10.10.70.70)</option>
              <option value="external">External (Public Internet - 91.200.205.105)</option>
            </select>
            <p class="mt-1 text-xs text-gray-500">
              Internal: Server on same LAN. External: Server on internet.
            </p>
          </div>

          <div>
            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
              Description
            </label>
            <textarea
              id="description"
              v-model="form.description"
              rows="3"
              class="input"
              placeholder="Optional description..."
            ></textarea>
          </div>

          <div v-if="isEdit">
            <label class="flex items-center">
              <input
                v-model="form.active"
                type="checkbox"
                class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
              />
              <span class="ml-2 text-sm text-gray-700">Active</span>
            </label>
          </div>
        </div>

        <!-- Form Actions -->
        <div class="mt-6 flex gap-3">
          <button
            type="button"
            @click="$emit('close')"
            class="flex-1 btn btn-secondary"
          >
            Cancel
          </button>
          <button
            type="submit"
            :disabled="loading"
            class="flex-1 btn btn-primary"
          >
            <span v-if="loading">Saving...</span>
            <span v-else>{{ isEdit ? 'Save Changes' : 'Add Server' }}</span>
          </button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue'
import { useServerStore } from '@/stores/server'

const props = defineProps({
  server: {
    type: Object,
    default: null,
  },
})

const emit = defineEmits(['close', 'saved'])

const serverStore = useServerStore()

const isEdit = computed(() => !!props.server)
const loading = ref(false)
const error = ref(null)

const form = ref({
  name: '',
  hostname: '',
  port: 22,
  username: 'root',
  backupType: 'internal',
  description: '',
  active: true,
})

onMounted(() => {
  if (props.server) {
    form.value = {
      name: props.server.name,
      hostname: props.server.hostname,
      port: props.server.port,
      username: props.server.username || 'root',
      backupType: props.server.backupType || 'internal',
      description: props.server.description || '',
      active: props.server.active,
    }
  }
})

async function handleSubmit() {
  try {
    loading.value = true
    error.value = null

    let result
    if (isEdit.value) {
      result = await serverStore.updateServer(props.server.id, form.value)
    } else {
      result = await serverStore.createServer(form.value)
    }

    // Pass result to parent (includes setup_job_id for new servers)
    emit('saved', result)
  } catch (err) {
    error.value = err.response?.data?.error?.message || 'Failed to save server'
  } finally {
    loading.value = false
  }
}
</script>
