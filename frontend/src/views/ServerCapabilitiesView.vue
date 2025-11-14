<template>
  <div>
    <!-- Header -->
    <div class="mb-8">
      <div class="flex items-center gap-4 mb-4">
        <button
          @click="$router.back()"
          class="p-2 text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white rounded-lg hover:bg-gray-100 dark:hover:bg-gray-800 transition-colors"
        >
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
          </svg>
        </button>
        <div class="flex-1">
          <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">
            Server Capabilities: {{ serverName }}
          </h1>
          <p class="mt-2 text-gray-600 dark:text-gray-400">
            Databases, Docker containers, and snapshot capabilities detected on this server
          </p>
        </div>
        <button
          @click="detectCapabilities"
          :disabled="detecting"
          class="btn btn-primary flex items-center gap-2"
        >
          <svg v-if="!detecting" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
          <svg v-else class="w-5 h-5 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          {{ detecting ? 'Detecting...' : 'Detect Now' }}
        </button>
      </div>

      <!-- Last Detection Time -->
      <div v-if="capabilities && capabilities.capabilities_detected_at" class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        Last detected: {{ formatDateTime(capabilities.capabilities_detected_at) }}
      </div>
    </div>

    <!-- Loading State -->
    <div v-if="loading" class="card">
      <div class="text-center py-12">
        <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
        <p class="mt-4 text-gray-600 dark:text-gray-400">Loading capabilities...</p>
      </div>
    </div>

    <!-- Not Detected State -->
    <div v-else-if="!capabilities || !capabilities.capabilities_detected" class="card">
      <div class="text-center py-16">
        <svg class="w-16 h-16 mx-auto mb-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z" />
        </svg>
        <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">No Capabilities Detected</h3>
        <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
          Click "Detect Now" to scan this server for databases, Docker containers, and snapshot capabilities
        </p>
      </div>
    </div>

    <!-- Capabilities Display -->
    <div v-else class="space-y-6">

      <!-- Snapshot Capabilities -->
      <div class="card">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
          <svg class="w-6 h-6 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12" />
          </svg>
          Snapshot Capabilities
        </h2>

        <div v-if="capabilities.capabilities.snapshots && capabilities.capabilities.snapshots.length > 0" class="space-y-3">
          <div
            v-for="(snapshot, index) in capabilities.capabilities.snapshots"
            :key="index"
            class="p-4 rounded-lg border"
            :class="snapshot.available ? 'bg-green-50 border-green-200 dark:bg-green-900/20 dark:border-green-800' : 'bg-gray-50 border-gray-200 dark:bg-gray-800 dark:border-gray-700'"
          >
            <div class="flex items-start justify-between">
              <div class="flex-1">
                <div class="flex items-center gap-3">
                  <span class="text-lg font-semibold text-gray-900 dark:text-white">{{ snapshot.name }}</span>
                  <span
                    class="px-2 py-1 text-xs font-medium rounded-full"
                    :class="snapshot.available ? 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                  >
                    {{ snapshot.available ? 'Available' : 'Not Available' }}
                  </span>
                </div>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">{{ snapshot.description }}</p>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-500">{{ snapshot.details }}</p>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
          No snapshot capabilities detected
        </div>
      </div>

      <!-- Databases -->
      <div class="card">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
          <svg class="w-6 h-6 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4" />
          </svg>
          Databases
        </h2>

        <div v-if="capabilities.capabilities.databases && capabilities.capabilities.databases.length > 0" class="space-y-4">
          <div
            v-for="(db, index) in capabilities.capabilities.databases"
            :key="index"
            class="p-4 rounded-lg border bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700"
          >
            <!-- DB Header -->
            <div class="flex items-start justify-between mb-3">
              <div class="flex-1">
                <div class="flex items-center gap-3 mb-1">
                  <span class="text-lg font-semibold text-gray-900 dark:text-white">{{ db.name }}</span>
                  <span
                    class="px-2 py-1 text-xs font-medium rounded-full"
                    :class="db.running ? 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                  >
                    {{ db.running ? 'Running' : 'Stopped' }}
                  </span>
                  <span
                    v-if="db.datadir_detected"
                    class="px-2 py-1 text-xs font-medium rounded-full"
                    :class="{
                      'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300': db.datadir_confidence === 'high',
                      'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/50 dark:text-yellow-300': db.datadir_confidence === 'medium',
                      'bg-orange-100 text-orange-800 dark:bg-orange-900/50 dark:text-orange-300': db.datadir_confidence === 'low'
                    }"
                  >
                    {{ db.datadir_confidence }} confidence
                  </span>
                </div>
                <p v-if="db.version" class="text-xs text-gray-500 dark:text-gray-400">{{ db.version }}</p>
              </div>
            </div>

            <!-- DB Details (only if running) -->
            <div v-if="db.running && db.datadir" class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
              <div>
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Data Directory</p>
                <p class="text-sm font-mono text-gray-900 dark:text-white break-all">{{ db.datadir }}</p>
              </div>
              <div v-if="db.datadir_size_human">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Size</p>
                <p class="text-sm font-semibold text-gray-900 dark:text-white">{{ db.datadir_size_human }}</p>
              </div>
              <div v-if="db.volume">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Volume Type</p>
                <div class="flex items-center gap-2">
                  <p class="text-sm font-semibold text-gray-900 dark:text-white uppercase">{{ db.volume.type }}</p>
                  <span
                    v-if="db.snapshot_capable"
                    class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300"
                  >
                    Snapshot capable
                  </span>
                  <span
                    v-else
                    class="px-2 py-0.5 text-xs font-medium rounded-full bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300"
                  >
                    No snapshot
                  </span>
                </div>
              </div>
              <div v-if="db.snapshot_recommended_size">
                <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-1">Recommended Snapshot Size</p>
                <p class="text-sm font-semibold text-primary-600 dark:text-primary-400">{{ db.snapshot_recommended_size.recommended_human }}</p>
              </div>
            </div>

            <!-- Datadir Candidates (if not detected with high confidence) -->
            <div v-if="db.datadir_candidates && db.datadir_candidates.length > 1 && db.datadir_confidence !== 'high'" class="mt-3 pt-3 border-t border-gray-200 dark:border-gray-700">
              <p class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">Alternative Data Directory Candidates:</p>
              <div class="space-y-1">
                <div
                  v-for="(candidate, idx) in db.datadir_candidates"
                  :key="idx"
                  class="flex items-center gap-2 text-xs"
                >
                  <span class="font-mono text-gray-700 dark:text-gray-300">{{ candidate.path }}</span>
                  <span class="text-gray-500 dark:text-gray-500">({{ candidate.method }})</span>
                </div>
              </div>
            </div>

            <!-- WARNING: Not Snapshot Capable (DB running but no snapshot support) -->
            <div v-if="db.running && db.datadir && !db.snapshot_capable" class="mt-3 pt-3 border-t border-red-200 dark:border-red-800">
              <div class="p-3 rounded-lg bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800">
                <div class="flex items-start gap-3">
                  <svg class="w-5 h-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                  </svg>
                  <div class="flex-1">
                    <h4 class="text-sm font-semibold text-red-900 dark:text-red-200 mb-1">⚠️ Backup atomique impossible</h4>
                    <p class="text-xs text-red-800 dark:text-red-300 mb-2">
                      Cette base de données n'est pas sur un volume compatible snapshot (LVM/Btrfs/ZFS).
                      Le backup atomique cohérent n'est pas possible.
                    </p>
                    <div class="text-xs text-red-700 dark:text-red-400">
                      <p class="font-semibold mb-1">Solutions recommandées :</p>
                      <ul class="list-disc list-inside space-y-0.5 ml-2">
                        <li>Migrer le datadir vers un Logical Volume (LVM)</li>
                        <li>Configurer Btrfs ou ZFS sur le volume de données</li>
                        <li>Configurer la réplication pour sauvegarder depuis un replica</li>
                      </ul>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
          No databases detected
        </div>
      </div>

      <!-- Docker -->
      <div class="card">
        <h2 class="text-xl font-bold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
          <svg class="w-6 h-6 text-primary-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
          </svg>
          Docker Environment
        </h2>

        <div v-if="capabilities.capabilities.docker && capabilities.capabilities.docker.installed">
          <!-- Docker Info -->
          <div class="mb-4 p-4 rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3 mb-2">
              <span class="text-sm font-semibold text-gray-900 dark:text-white">{{ capabilities.capabilities.docker.version }}</span>
              <span
                class="px-2 py-1 text-xs font-medium rounded-full"
                :class="capabilities.capabilities.docker.running ? 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
              >
                {{ capabilities.capabilities.docker.running ? 'Running' : 'Stopped' }}
              </span>
            </div>
            <div class="flex gap-6 text-sm text-gray-600 dark:text-gray-400">
              <span>{{ capabilities.capabilities.docker.container_count || 0 }} containers</span>
              <span>{{ capabilities.capabilities.docker.compose_project_count || 0 }} compose projects</span>
              <span>{{ capabilities.capabilities.docker.volume_count || 0 }} volumes</span>
              <span>{{ capabilities.capabilities.docker.network_count || 0 }} networks</span>
            </div>
          </div>

          <!-- Compose Projects -->
          <div v-if="capabilities.capabilities.docker.compose_projects && Object.keys(capabilities.capabilities.docker.compose_projects).length > 0" class="space-y-3">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide">Compose Projects</h3>
            <div
              v-for="(project, projectName) in capabilities.capabilities.docker.compose_projects"
              :key="projectName"
              class="p-4 rounded-lg border bg-white dark:bg-gray-800 border-gray-200 dark:border-gray-700"
            >
              <div class="mb-2">
                <span class="text-base font-semibold text-gray-900 dark:text-white">{{ project.name }}</span>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-3 text-sm mb-3">
                <div>
                  <p class="text-xs text-gray-500 dark:text-gray-400">Working Directory</p>
                  <p class="font-mono text-gray-900 dark:text-white break-all">{{ project.working_dir }}</p>
                </div>
                <div>
                  <p class="text-xs text-gray-500 dark:text-gray-400">Compose File</p>
                  <p class="font-mono text-gray-900 dark:text-white break-all">{{ project.compose_file }}</p>
                </div>
              </div>
              <div>
                <p class="text-xs text-gray-500 dark:text-gray-400 mb-1">Containers ({{ project.containers.length }})</p>
                <div class="flex flex-wrap gap-2">
                  <span
                    v-for="(container, idx) in project.containers"
                    :key="idx"
                    class="px-2 py-1 text-xs font-mono bg-primary-50 text-primary-700 dark:bg-primary-900/30 dark:text-primary-300 rounded"
                  >
                    {{ container }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <!-- All Containers List -->
          <div v-if="capabilities.capabilities.docker.containers && capabilities.capabilities.docker.containers.length > 0" class="mt-4">
            <h3 class="text-sm font-semibold text-gray-700 dark:text-gray-300 uppercase tracking-wide mb-3">All Containers</h3>
            <div class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 text-sm">
                <thead class="bg-gray-50 dark:bg-gray-800">
                  <tr>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Name</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Image</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">State</th>
                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Volumes</th>
                  </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                  <tr
                    v-for="(container, idx) in capabilities.capabilities.docker.containers"
                    :key="idx"
                  >
                    <td class="px-4 py-2 font-mono text-gray-900 dark:text-white">{{ container.name }}</td>
                    <td class="px-4 py-2 font-mono text-gray-600 dark:text-gray-400 text-xs">{{ container.image }}</td>
                    <td class="px-4 py-2">
                      <span
                        class="px-2 py-0.5 text-xs font-medium rounded-full"
                        :class="container.state === 'running' ? 'bg-green-100 text-green-800 dark:bg-green-900/50 dark:text-green-300' : 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300'"
                      >
                        {{ container.state }}
                      </span>
                    </td>
                    <td class="px-4 py-2">
                      <span class="text-xs text-gray-600 dark:text-gray-400">{{ container.volumes ? container.volumes.length : 0 }} volume(s)</span>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-8 text-gray-500 dark:text-gray-400">
          Docker is not installed on this server
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, computed } from 'vue'
import { useRoute } from 'vue-router'
import { serverService } from '../services/server'

const route = useRoute()
const serverId = computed(() => parseInt(route.params.id))

const loading = ref(true)
const detecting = ref(false)
const capabilities = ref(null)
const serverName = ref('')

const loadCapabilities = async () => {
  try {
    loading.value = true
    const data = await serverService.getCapabilities(serverId.value)
    capabilities.value = data
    serverName.value = data.server_name
  } catch (error) {
    console.error('Failed to load capabilities:', error)
  } finally {
    loading.value = false
  }
}

const detectCapabilities = async () => {
  try {
    detecting.value = true
    await serverService.detectCapabilities(serverId.value)

    // Wait a few seconds for detection to complete
    setTimeout(async () => {
      await loadCapabilities()
      detecting.value = false
    }, 5000)
  } catch (error) {
    console.error('Failed to detect capabilities:', error)
    detecting.value = false
  }
}

const formatDateTime = (dateStr) => {
  if (!dateStr) return 'Never'
  const date = new Date(dateStr)
  return date.toLocaleString()
}

onMounted(() => {
  loadCapabilities()
})
</script>
