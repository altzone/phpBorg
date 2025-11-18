<template>
  <div class="min-h-screen bg-gray-50 dark:bg-gray-900 py-8">
    <div class="max-w-6xl mx-auto px-4">
      <!-- Loading State -->
      <div v-if="loading" class="flex items-center justify-center min-h-[400px]">
        <div class="text-center">
          <div class="inline-block animate-spin rounded-full h-12 w-12 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600 mb-4"></div>
          <p class="text-gray-600 dark:text-gray-400">Loading...</p>
        </div>
      </div>

      <!-- Wizard Content -->
      <div v-else>
        <!-- Header -->
        <div class="mb-8">
          <h1 class="text-3xl font-bold text-gray-900 dark:text-white mb-2">
            {{ $t('docker_restore.title') }}
          </h1>
          <p class="text-gray-600 dark:text-gray-400">
            {{ $t('docker_restore.subtitle') }}
          </p>
        </div>

      <!-- Stepper -->
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-6 mb-6">
        <div class="flex items-center justify-between">
          <div
            v-for="(step, index) in steps"
            :key="index"
            class="flex items-center"
            :class="{ 'flex-1': index < steps.length - 1 }"
          >
            <!-- Step Circle -->
            <div class="flex flex-col items-center">
              <div
                class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-semibold transition-all"
                :class="getStepClass(index + 1)"
              >
                <span v-if="store.currentStep > index + 1">✓</span>
                <span v-else>{{ index + 1 }}</span>
              </div>
              <span
                class="text-xs mt-2 text-center max-w-[100px]"
                :class="store.currentStep === index + 1 ? 'text-primary-600 dark:text-primary-400 font-semibold' : 'text-gray-500 dark:text-gray-400'"
              >
                {{ $t(step.label) }}
              </span>
            </div>

            <!-- Connector Line -->
            <div
              v-if="index < steps.length - 1"
              class="flex-1 h-0.5 mx-4 transition-all"
              :class="store.currentStep > index + 1 ? 'bg-primary-600 dark:bg-primary-400' : 'bg-gray-300 dark:bg-gray-600'"
            ></div>
          </div>
        </div>
      </div>

      <!-- Main Content -->
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm p-8">
        <!-- Step 1: Mode Selection -->
        <div v-if="store.currentStep === 1" class="space-y-6">
          <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">
            {{ $t('docker_restore.mode.title') }}
          </h2>

          <div class="grid md:grid-cols-2 gap-6">
            <!-- Express Recovery -->
            <div
              class="border-2 rounded-lg p-6 cursor-pointer transition-all hover:shadow-lg"
              :class="store.mode === 'express'
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                : 'border-gray-200 dark:border-gray-700 hover:border-primary-300'"
              @click="store.mode = 'express'"
            >
              <div class="flex items-start justify-between mb-4">
                <div>
                  <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                    ⚡ {{ $t('docker_restore.mode.express.title') }}
                  </h3>
                  <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $t('docker_restore.mode.express.subtitle') }}
                  </p>
                </div>
                <input
                  type="radio"
                  :checked="store.mode === 'express'"
                  class="w-5 h-5 text-primary-600 focus:ring-primary-500"
                />
              </div>
              <p class="text-gray-700 dark:text-gray-300 mb-4">
                {{ $t('docker_restore.mode.express.description') }}
              </p>
              <ul class="space-y-2">
                <li
                  v-for="(feature, i) in $tm('docker_restore.mode.express.features')"
                  :key="i"
                  class="flex items-start text-sm text-gray-600 dark:text-gray-400"
                >
                  <span class="text-green-500 mr-2">✓</span>
                  {{ feature }}
                </li>
              </ul>
            </div>

            <!-- Pro Safe -->
            <div
              class="border-2 rounded-lg p-6 cursor-pointer transition-all hover:shadow-lg"
              :class="store.mode === 'pro_safe'
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                : 'border-gray-200 dark:border-gray-700 hover:border-primary-300'"
              @click="store.mode = 'pro_safe'"
            >
              <div class="flex items-start justify-between mb-4">
                <div>
                  <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                    🛡️ {{ $t('docker_restore.mode.pro_safe.title') }}
                  </h3>
                  <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $t('docker_restore.mode.pro_safe.subtitle') }}
                  </p>
                </div>
                <input
                  type="radio"
                  :checked="store.mode === 'pro_safe'"
                  class="w-5 h-5 text-primary-600 focus:ring-primary-500"
                />
              </div>
              <p class="text-gray-700 dark:text-gray-300 mb-4">
                {{ $t('docker_restore.mode.pro_safe.description') }}
              </p>
              <ul class="space-y-2">
                <li
                  v-for="(feature, i) in $tm('docker_restore.mode.pro_safe.features')"
                  :key="i"
                  class="flex items-start text-sm text-gray-600 dark:text-gray-400"
                >
                  <span class="text-green-500 mr-2">✓</span>
                  {{ feature }}
                </li>
              </ul>
            </div>
          </div>

          <!-- Archive Info Preview -->
          <div v-if="store.analysis" class="mt-8 p-4 bg-gray-50 dark:bg-gray-900 rounded-lg">
            <h4 class="font-semibold text-gray-900 dark:text-white mb-3">
              📦 {{ store.archive?.name }}
            </h4>
            <div class="grid grid-cols-3 gap-4 text-sm">
              <div>
                <span class="text-gray-600 dark:text-gray-400">🗄️ Volumes:</span>
                <span class="ml-2 font-semibold text-gray-900 dark:text-white">
                  {{ store.analysis.volumes?.length || 0 }}
                </span>
              </div>
              <div>
                <span class="text-gray-600 dark:text-gray-400">📂 Projets:</span>
                <span class="ml-2 font-semibold text-gray-900 dark:text-white">
                  {{ store.analysis.compose_projects?.length || 0 }}
                </span>
              </div>
              <div>
                <span class="text-gray-600 dark:text-gray-400">⚙️ Configs:</span>
                <span class="ml-2 font-semibold text-gray-900 dark:text-white">
                  {{ store.analysis.configs?.length || 0 }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 2: Restore Type -->
        <div v-if="store.currentStep === 2" class="space-y-6">
          <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">
            {{ $t('docker_restore.type.title') }}
          </h2>

          <div class="space-y-4">
            <!-- Full Restore -->
            <div
              class="border-2 rounded-lg p-4 cursor-pointer transition-all"
              :class="store.restoreType === 'full'
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                : 'border-gray-200 dark:border-gray-700 hover:border-primary-300'"
              @click="store.restoreType = 'full'"
            >
              <div class="flex items-start">
                <input
                  type="radio"
                  :checked="store.restoreType === 'full'"
                  class="w-5 h-5 mt-0.5 text-primary-600 focus:ring-primary-500"
                />
                <div class="ml-4">
                  <h3 class="font-semibold text-gray-900 dark:text-white">
                    {{ $t('docker_restore.type.full.title') }}
                  </h3>
                  <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    {{ $t('docker_restore.type.full.description') }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Volumes Only -->
            <div
              class="border-2 rounded-lg p-4 cursor-pointer transition-all"
              :class="store.restoreType === 'volumes_only'
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                : 'border-gray-200 dark:border-gray-700 hover:border-primary-300'"
              @click="store.restoreType = 'volumes_only'"
            >
              <div class="flex items-start">
                <input
                  type="radio"
                  :checked="store.restoreType === 'volumes_only'"
                  class="w-5 h-5 mt-0.5 text-primary-600 focus:ring-primary-500"
                />
                <div class="ml-4">
                  <h3 class="font-semibold text-gray-900 dark:text-white">
                    {{ $t('docker_restore.type.volumes_only.title') }}
                  </h3>
                  <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    {{ $t('docker_restore.type.volumes_only.description') }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Compose Only -->
            <div
              class="border-2 rounded-lg p-4 cursor-pointer transition-all"
              :class="store.restoreType === 'compose_only'
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                : 'border-gray-200 dark:border-gray-700 hover:border-primary-300'"
              @click="store.restoreType = 'compose_only'"
            >
              <div class="flex items-start">
                <input
                  type="radio"
                  :checked="store.restoreType === 'compose_only'"
                  class="w-5 h-5 mt-0.5 text-primary-600 focus:ring-primary-500"
                />
                <div class="ml-4">
                  <h3 class="font-semibold text-gray-900 dark:text-white">
                    {{ $t('docker_restore.type.compose_only.title') }}
                  </h3>
                  <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    {{ $t('docker_restore.type.compose_only.description') }}
                  </p>
                </div>
              </div>
            </div>

            <!-- Custom Selection -->
            <div
              class="border-2 rounded-lg p-4 cursor-pointer transition-all"
              :class="store.restoreType === 'custom'
                ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                : 'border-gray-200 dark:border-gray-700 hover:border-primary-300'"
              @click="store.restoreType = 'custom'"
            >
              <div class="flex items-start">
                <input
                  type="radio"
                  :checked="store.restoreType === 'custom'"
                  class="w-5 h-5 mt-0.5 text-primary-600 focus:ring-primary-500"
                />
                <div class="ml-4">
                  <h3 class="font-semibold text-gray-900 dark:text-white">
                    {{ $t('docker_restore.type.custom.title') }}
                  </h3>
                  <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    {{ $t('docker_restore.type.custom.description') }}
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Custom Selection Details -->
          <div v-if="store.restoreType === 'custom' && store.analysis" class="mt-6 space-y-6">
            <!-- Volumes -->
            <div v-if="store.analysis.volumes?.length" class="border dark:border-gray-700 rounded-lg p-4">
              <div class="flex items-center justify-between mb-3">
                <h4 class="font-semibold text-gray-900 dark:text-white">
                  🗄️ {{ $t('docker_restore.custom_selection.volumes') }}
                </h4>
                <div class="space-x-2">
                  <button
                    @click="store.selectedVolumes = store.analysis.volumes.map(v => v.name)"
                    class="text-xs text-primary-600 hover:text-primary-700"
                  >
                    {{ $t('docker_restore.custom_selection.select_all') }}
                  </button>
                  <button
                    @click="store.selectedVolumes = []"
                    class="text-xs text-gray-600 hover:text-gray-700"
                  >
                    {{ $t('docker_restore.custom_selection.deselect_all') }}
                  </button>
                </div>
              </div>
              <div class="space-y-2 max-h-64 overflow-y-auto">
                <label
                  v-for="volume in store.analysis.volumes"
                  :key="volume.name"
                  class="flex items-center p-2 hover:bg-gray-50 dark:hover:bg-gray-700 rounded cursor-pointer"
                >
                  <input
                    v-model="store.selectedVolumes"
                    :value="volume.name"
                    type="checkbox"
                    class="w-4 h-4 text-primary-600 focus:ring-primary-500 rounded"
                  />
                  <span class="ml-3 text-sm text-gray-900 dark:text-white font-mono">
                    {{ volume.name }}
                  </span>
                  <span v-if="volume.files" class="ml-auto text-xs text-gray-500">
                    {{ volume.files }} files
                  </span>
                </label>
              </div>
            </div>

            <!-- Compose Projects -->
            <div v-if="store.analysis.compose_projects?.length" class="border dark:border-gray-700 rounded-lg p-4">
              <div class="flex items-center justify-between mb-3">
                <h4 class="font-semibold text-gray-900 dark:text-white">
                  📂 {{ $t('docker_restore.custom_selection.projects') }}
                </h4>
                <div class="space-x-2">
                  <button
                    @click="store.selectedProjects = store.analysis.compose_projects.map(p => p.name)"
                    class="text-xs text-primary-600 hover:text-primary-700"
                  >
                    {{ $t('docker_restore.custom_selection.select_all') }}
                  </button>
                  <button
                    @click="store.selectedProjects = []"
                    class="text-xs text-gray-600 hover:text-gray-700"
                  >
                    {{ $t('docker_restore.custom_selection.deselect_all') }}
                  </button>
                </div>
              </div>
              <div class="space-y-2">
                <label
                  v-for="project in store.analysis.compose_projects"
                  :key="project.name"
                  class="flex items-center p-2 hover:bg-gray-50 dark:hover:bg-gray-700 rounded cursor-pointer"
                >
                  <input
                    v-model="store.selectedProjects"
                    :value="project.name"
                    type="checkbox"
                    class="w-4 h-4 text-primary-600 focus:ring-primary-500 rounded"
                  />
                  <div class="ml-3">
                    <div class="text-sm text-gray-900 dark:text-white font-mono">
                      {{ project.name }}
                    </div>
                    <div v-if="project.path" class="text-xs text-gray-500">
                      {{ project.path }}
                    </div>
                  </div>
                </label>
              </div>
            </div>

            <!-- Configs -->
            <div v-if="store.analysis.configs?.length" class="border dark:border-gray-700 rounded-lg p-4">
              <div class="flex items-center justify-between mb-3">
                <h4 class="font-semibold text-gray-900 dark:text-white">
                  ⚙️ {{ $t('docker_restore.custom_selection.configs') }}
                </h4>
                <div class="space-x-2">
                  <button
                    @click="store.selectedConfigs = store.analysis.configs.map(c => c.path)"
                    class="text-xs text-primary-600 hover:text-primary-700"
                  >
                    {{ $t('docker_restore.custom_selection.select_all') }}
                  </button>
                  <button
                    @click="store.selectedConfigs = []"
                    class="text-xs text-gray-600 hover:text-gray-700"
                  >
                    {{ $t('docker_restore.custom_selection.deselect_all') }}
                  </button>
                </div>
              </div>
              <div class="space-y-2">
                <label
                  v-for="config in store.analysis.configs"
                  :key="config.path"
                  class="flex items-center p-2 hover:bg-gray-50 dark:hover:bg-gray-700 rounded cursor-pointer"
                >
                  <input
                    v-model="store.selectedConfigs"
                    :value="config.path"
                    type="checkbox"
                    class="w-4 h-4 text-primary-600 focus:ring-primary-500 rounded"
                  />
                  <span class="ml-3 text-sm text-gray-900 dark:text-white font-mono">
                    {{ config.path }}
                  </span>
                </label>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 3: Destination -->
        <div v-if="store.currentStep === 3" class="space-y-6">
          <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">
            {{ $t('docker_restore.destination.title') }}
          </h2>

          <div class="grid md:grid-cols-2 gap-6">
            <!-- Alternative Location -->
            <div
              class="border-2 rounded-lg p-6 cursor-pointer transition-all"
              :class="store.destination === 'alternative'
                ? 'border-green-500 bg-green-50 dark:bg-green-900/20'
                : 'border-gray-200 dark:border-gray-700 hover:border-green-300'"
              @click="store.destination = 'alternative'"
            >
              <div class="flex items-start justify-between mb-4">
                <div>
                  <div class="flex items-center gap-2 mb-2">
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                      {{ $t('docker_restore.destination.alternative.title') }}
                    </h3>
                    <span class="px-2 py-1 text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 rounded">
                      {{ $t('docker_restore.destination.alternative.badge') }}
                    </span>
                  </div>
                  <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $t('docker_restore.destination.alternative.description') }}
                  </p>
                </div>
                <input
                  type="radio"
                  :checked="store.destination === 'alternative'"
                  class="w-5 h-5 text-green-600 focus:ring-green-500"
                />
              </div>
              <ul class="space-y-2">
                <li
                  v-for="(adv, i) in $tm('docker_restore.destination.alternative.advantages')"
                  :key="i"
                  class="flex items-start text-sm text-gray-600 dark:text-gray-400"
                >
                  <span class="text-green-500 mr-2">✓</span>
                  {{ adv }}
                </li>
              </ul>
            </div>

            <!-- In-Place Restore -->
            <div
              class="border-2 rounded-lg p-6 cursor-pointer transition-all"
              :class="store.destination === 'in_place'
                ? 'border-red-500 bg-red-50 dark:bg-red-900/20'
                : 'border-gray-200 dark:border-gray-700 hover:border-red-300'"
              @click="store.destination = 'in_place'"
            >
              <div class="flex items-start justify-between mb-4">
                <div>
                  <div class="flex items-center gap-2 mb-2">
                    <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
                      {{ $t('docker_restore.destination.in_place.title') }}
                    </h3>
                    <span class="px-2 py-1 text-xs font-semibold bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200 rounded">
                      {{ $t('docker_restore.destination.in_place.badge') }}
                    </span>
                  </div>
                  <p class="text-sm text-gray-600 dark:text-gray-400">
                    {{ $t('docker_restore.destination.in_place.description') }}
                  </p>
                </div>
                <input
                  type="radio"
                  :checked="store.destination === 'in_place'"
                  class="w-5 h-5 text-red-600 focus:ring-red-500"
                />
              </div>
            </div>
          </div>

          <!-- Alternative Path Input -->
          <div v-if="store.destination === 'alternative'" class="mt-6">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
              {{ $t('docker_restore.destination.alternative_path_label') }}
            </label>
            <input
              v-model="store.alternativePath"
              type="text"
              class="input"
              :placeholder="$t('docker_restore.destination.alternative_path_placeholder')"
            />
          </div>

          <!-- In-Place Warning -->
          <div v-if="store.destination === 'in_place'" class="mt-6 border-2 border-red-500 rounded-lg p-6 bg-red-50 dark:bg-red-900/20">
            <h4 class="text-lg font-semibold text-red-900 dark:text-red-200 mb-3">
              {{ $t('docker_restore.destination.in_place.warning_title') }}
            </h4>
            <p class="text-red-800 dark:text-red-300 mb-4">
              {{ $t('docker_restore.destination.in_place.warning_msg') }}
            </p>

            <div class="space-y-3 mb-4">
              <div>
                <h5 class="font-semibold text-red-900 dark:text-red-200 mb-2">
                  {{ $t('docker_restore.destination.in_place.protections_title') }}
                </h5>
                <label class="flex items-center mb-2">
                  <input
                    v-model="store.createLvmSnapshot"
                    type="checkbox"
                    class="w-4 h-4 text-primary-600 focus:ring-primary-500 rounded"
                  />
                  <span class="ml-2 text-sm text-red-800 dark:text-red-300">
                    {{ $t('docker_restore.destination.in_place.protection_lvm') }}
                  </span>
                </label>
                <label class="flex items-center">
                  <input
                    v-model="store.createPreRestoreBackup"
                    type="checkbox"
                    class="w-4 h-4 text-primary-600 focus:ring-primary-500 rounded"
                  />
                  <span class="ml-2 text-sm text-red-800 dark:text-red-300">
                    {{ $t('docker_restore.destination.in_place.protection_backup') }}
                  </span>
                </label>
              </div>

              <div v-if="!store.createLvmSnapshot && !store.createPreRestoreBackup" class="p-3 bg-red-100 dark:bg-red-900/40 rounded">
                <p class="text-sm font-semibold text-red-900 dark:text-red-200">
                  {{ $t('docker_restore.destination.in_place.no_protection_warning') }}
                </p>
              </div>
            </div>

            <label class="flex items-center">
              <input
                v-model="confirmInPlace"
                type="checkbox"
                class="w-4 h-4 text-red-600 focus:ring-red-500 rounded"
              />
              <span class="ml-2 text-sm font-semibold text-red-900 dark:text-red-200">
                {{ $t('docker_restore.destination.in_place.confirm_checkbox') }}
              </span>
            </label>
          </div>
        </div>

        <!-- Step 4: Advanced Options -->
        <div v-if="store.currentStep === 4" class="space-y-6">
          <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">
            {{ $t('docker_restore.advanced.title') }}
          </h2>

          <!-- Compose Path Adaptation (only if alternative location + compose files) -->
          <div v-if="store.destination === 'alternative' && (store.restoreType === 'full' || store.restoreType === 'compose_only' || (store.restoreType === 'custom' && store.selectedProjects.length))" class="border dark:border-gray-700 rounded-lg p-6">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-2">
              {{ $t('docker_restore.advanced.compose_adaptation.title') }}
            </h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">
              {{ $t('docker_restore.advanced.compose_adaptation.description') }}
            </p>

            <div class="space-y-3">
              <label
                v-for="option in ['none', 'auto_modify', 'generate_new']"
                :key="option"
                class="flex items-start p-3 border dark:border-gray-700 rounded cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700"
                :class="store.composePathAdaptation === option ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : ''"
              >
                <input
                  v-model="store.composePathAdaptation"
                  :value="option"
                  type="radio"
                  class="w-4 h-4 mt-0.5 text-primary-600 focus:ring-primary-500"
                />
                <div class="ml-3">
                  <div class="flex items-center gap-2">
                    <span class="font-medium text-gray-900 dark:text-white">
                      {{ $t(`docker_restore.advanced.compose_adaptation.${option}.title`) }}
                    </span>
                    <span v-if="option === 'auto_modify'" class="px-2 py-0.5 text-xs font-semibold bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200 rounded">
                      {{ $t('docker_restore.advanced.compose_adaptation.auto_modify.badge') }}
                    </span>
                  </div>
                  <p class="text-sm text-gray-600 dark:text-gray-400 mt-1">
                    {{ $t(`docker_restore.advanced.compose_adaptation.${option}.description`) }}
                  </p>
                </div>
              </label>
            </div>
          </div>

          <!-- Data Protection -->
          <div class="border dark:border-gray-700 rounded-lg p-6">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">
              {{ $t('docker_restore.advanced.protections.title') }}
            </h3>

            <div class="space-y-4">
              <label class="flex items-center">
                <input
                  v-model="store.createLvmSnapshot"
                  type="checkbox"
                  class="w-4 h-4 text-primary-600 focus:ring-primary-500 rounded"
                />
                <span class="ml-3 text-gray-900 dark:text-white">
                  {{ $t('docker_restore.advanced.protections.lvm_snapshot') }}
                </span>
              </label>

              <div v-if="store.createLvmSnapshot" class="ml-7 space-y-3">
                <div>
                  <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">
                    {{ $t('docker_restore.advanced.protections.lvm_path') }}
                  </label>
                  <input
                    v-model="store.lvmPath"
                    type="text"
                    class="input text-sm"
                  />
                </div>
                <div>
                  <label class="block text-sm text-gray-700 dark:text-gray-300 mb-1">
                    {{ $t('docker_restore.advanced.protections.snapshot_size') }}
                  </label>
                  <input
                    v-model="store.snapshotSize"
                    type="text"
                    class="input text-sm"
                    placeholder="20G"
                  />
                </div>
              </div>

              <label class="flex items-center">
                <input
                  v-model="store.createPreRestoreBackup"
                  type="checkbox"
                  class="w-4 h-4 text-primary-600 focus:ring-primary-500 rounded"
                />
                <span class="ml-3 text-gray-900 dark:text-white">
                  {{ $t('docker_restore.advanced.protections.pre_restore_backup') }}
                </span>
              </label>

              <label class="flex items-center">
                <input
                  v-model="store.autoRollbackOnFailure"
                  type="checkbox"
                  class="w-4 h-4 text-primary-600 focus:ring-primary-500 rounded"
                />
                <span class="ml-3 text-gray-900 dark:text-white">
                  {{ $t('docker_restore.advanced.protections.auto_rollback') }}
                </span>
              </label>
            </div>
          </div>

          <!-- Container Management -->
          <div class="border dark:border-gray-700 rounded-lg p-6">
            <h3 class="font-semibold text-gray-900 dark:text-white mb-4">
              {{ $t('docker_restore.advanced.containers.title') }}
            </h3>

            <label class="flex items-center">
              <input
                v-model="store.autoRestart"
                type="checkbox"
                class="w-4 h-4 text-primary-600 focus:ring-primary-500 rounded"
              />
              <span class="ml-3 text-gray-900 dark:text-white">
                {{ $t('docker_restore.advanced.containers.auto_restart') }}
              </span>
            </label>
          </div>
        </div>

        <!-- Step 5: Conflicts & Safety Checks -->
        <div v-if="store.currentStep === 5" class="space-y-6">
          <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">
            {{ $t('docker_restore.conflicts.title') }}
          </h2>

          <!-- Loading -->
          <div v-if="store.detectingConflicts" class="text-center py-8">
            <div class="animate-spin rounded-full h-12 w-12 border-b-2 border-primary-600 mx-auto mb-4"></div>
            <p class="text-gray-600 dark:text-gray-400">
              {{ $t('docker_restore.conflicts.detecting') }}
            </p>
          </div>

          <!-- Results -->
          <div v-else-if="store.conflicts" class="space-y-6">
            <!-- No Conflicts -->
            <div v-if="!store.conflicts.conflicts?.length && !store.conflicts.warnings?.length" class="p-6 bg-green-50 dark:bg-green-900/20 border border-green-500 rounded-lg">
              <div class="flex items-center text-green-800 dark:text-green-200">
                <span class="text-2xl mr-3">✓</span>
                <span class="font-semibold">{{ $t('docker_restore.conflicts.no_conflicts') }}</span>
              </div>
            </div>

            <!-- Conflicts Detected -->
            <div v-if="store.conflicts.conflicts?.length" class="border dark:border-gray-700 rounded-lg p-6">
              <h3 class="font-semibold text-gray-900 dark:text-white mb-4">
                {{ $t('docker_restore.conflicts.conflicts_detected') }}
              </h3>
              <div class="space-y-3">
                <div
                  v-for="(conflict, i) in store.conflicts.conflicts"
                  :key="i"
                  class="p-3 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-500 rounded"
                >
                  <p class="text-sm text-yellow-900 dark:text-yellow-200">
                    {{ conflict.volume ? $t('docker_restore.conflicts.volume_used_by', { volume: conflict.volume }) : $t('docker_restore.conflicts.project_containers', { project: conflict.project }) }}
                  </p>
                  <p class="text-xs text-yellow-800 dark:text-yellow-300 mt-1">
                    {{ conflict.container }}
                  </p>
                </div>
              </div>
              <p class="text-sm text-gray-600 dark:text-gray-400 mt-4">
                {{ $t('docker_restore.conflicts.containers_stopped') }}
              </p>
            </div>

            <!-- Disk Space -->
            <div class="border dark:border-gray-700 rounded-lg p-6">
              <h3 class="font-semibold text-gray-900 dark:text-white mb-3">
                {{ $t('docker_restore.conflicts.disk_space.title') }}
              </h3>
              <div class="flex items-center justify-between">
                <div>
                  <span
                    class="text-sm"
                    :class="store.conflicts.disk_space_ok ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400'"
                  >
                    {{ store.conflicts.disk_space_ok ? $t('docker_restore.conflicts.disk_space.sufficient') : $t('docker_restore.conflicts.disk_space.insufficient') }}
                  </span>
                </div>
              </div>
            </div>

            <!-- Warnings -->
            <div v-if="store.conflicts.warnings?.length" class="space-y-3">
              <h3 class="font-semibold text-gray-900 dark:text-white">
                {{ $t('docker_restore.conflicts.warnings.title') }}
              </h3>
              <div
                v-for="(warning, i) in store.conflicts.warnings"
                :key="i"
                class="p-4 bg-orange-50 dark:bg-orange-900/20 border border-orange-500 rounded-lg"
              >
                <p class="text-sm text-orange-900 dark:text-orange-200">
                  {{ warning }}
                </p>
              </div>
            </div>

            <!-- High Risk Warning -->
            <div v-if="store.destination === 'in_place' && !store.createLvmSnapshot && !store.createPreRestoreBackup" class="p-6 bg-red-50 dark:bg-red-900/20 border-2 border-red-500 rounded-lg">
              <h4 class="text-lg font-semibold text-red-900 dark:text-red-200 mb-2">
                {{ $t('docker_restore.conflicts.warnings.high_risk') }}
              </h4>
              <p class="text-sm text-red-800 dark:text-red-300 mb-2">
                {{ $t('docker_restore.conflicts.warnings.no_protection') }}
              </p>
              <p class="text-sm text-red-700 dark:text-red-400">
                {{ $t('docker_restore.conflicts.warnings.enable_protection') }}
              </p>
            </div>
          </div>
        </div>

        <!-- Step 6: Review & Confirm -->
        <div v-if="store.currentStep === 6" class="space-y-6">
          <h2 class="text-2xl font-bold text-gray-900 dark:text-white mb-6">
            {{ $t('docker_restore.review.title') }}
          </h2>

          <!-- Summary -->
          <div class="border-2 border-primary-500 rounded-lg p-6 bg-primary-50 dark:bg-primary-900/20">
            <h3 class="text-xl font-semibold text-gray-900 dark:text-white mb-4">
              {{ $t('docker_restore.review.summary.title') }}
            </h3>

            <div class="grid md:grid-cols-2 gap-4 text-sm">
              <div>
                <span class="font-medium text-gray-700 dark:text-gray-300">
                  {{ $t('docker_restore.review.summary.archive') }}:
                </span>
                <span class="ml-2 text-gray-900 dark:text-white">
                  {{ store.archive?.name }}
                </span>
              </div>

              <div>
                <span class="font-medium text-gray-700 dark:text-gray-300">
                  {{ $t('docker_restore.review.summary.mode') }}:
                </span>
                <span class="ml-2 text-gray-900 dark:text-white">
                  {{ $t(`docker_restore.mode.${store.mode}.title`) }}
                </span>
              </div>

              <div>
                <span class="font-medium text-gray-700 dark:text-gray-300">
                  {{ $t('docker_restore.review.summary.type') }}:
                </span>
                <span class="ml-2 text-gray-900 dark:text-white">
                  {{ $t(`docker_restore.type.${store.restoreType}.title`) }}
                </span>
              </div>

              <div>
                <span class="font-medium text-gray-700 dark:text-gray-300">
                  {{ $t('docker_restore.review.summary.destination') }}:
                </span>
                <span class="ml-2 text-gray-900 dark:text-white">
                  {{ store.destination === 'alternative' ? store.alternativePath : 'In-place' }}
                </span>
              </div>
            </div>

            <div class="mt-4 pt-4 border-t dark:border-gray-700">
              <h4 class="font-semibold text-gray-900 dark:text-white mb-2">
                {{ $t('docker_restore.review.summary.items') }}:
              </h4>
              <div class="flex gap-4 text-sm">
                <span class="text-gray-700 dark:text-gray-300">
                  {{ $t('docker_restore.review.summary.volumes', { count: store.getSelectedItems.volumes?.length || 0 }) }}
                </span>
                <span class="text-gray-700 dark:text-gray-300">
                  {{ $t('docker_restore.review.summary.projects', { count: store.getSelectedItems.projects?.length || 0 }) }}
                </span>
                <span class="text-gray-700 dark:text-gray-300">
                  {{ $t('docker_restore.review.summary.configs', { count: store.getSelectedItems.configs?.length || 0 }) }}
                </span>
              </div>
            </div>

            <div v-if="store.conflicts?.must_stop?.length" class="mt-4 pt-4 border-t dark:border-gray-700">
              <h4 class="font-semibold text-gray-900 dark:text-white mb-2">
                {{ $t('docker_restore.review.summary.containers_stop') }}:
              </h4>
              <div class="flex flex-wrap gap-2">
                <span
                  v-for="container in store.conflicts.must_stop"
                  :key="container"
                  class="px-2 py-1 text-xs bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200 rounded"
                >
                  {{ container }}
                </span>
              </div>
            </div>

            <div class="mt-4 pt-4 border-t dark:border-gray-700">
              <h4 class="font-semibold text-gray-900 dark:text-white mb-2">
                {{ $t('docker_restore.review.summary.protections') }}:
              </h4>
              <div class="space-y-1 text-sm">
                <p v-if="store.createLvmSnapshot" class="text-green-600 dark:text-green-400">
                  {{ $t('docker_restore.review.summary.lvm_snapshot') }}
                </p>
                <p v-if="store.createPreRestoreBackup" class="text-green-600 dark:text-green-400">
                  {{ $t('docker_restore.review.summary.pre_restore_backup') }}
                </p>
                <p v-if="store.autoRestart" class="text-green-600 dark:text-green-400">
                  {{ $t('docker_restore.review.summary.auto_restart') }}
                </p>
              </div>
            </div>
          </div>

          <!-- Script Preview Button -->
          <div class="text-center">
            <button
              @click="showScriptModal = true"
              class="btn-secondary"
            >
              📜 {{ $t('docker_restore.review.script.preview') }}
            </button>
          </div>
        </div>

        <!-- Navigation Buttons -->
        <div class="flex justify-between mt-8 pt-6 border-t dark:border-gray-700">
          <button
            v-if="store.currentStep > 1"
            @click="store.prevStep()"
            class="btn-secondary"
          >
            ← {{ $t('common.previous') }}
          </button>
          <div v-else></div>

          <button
            v-if="store.currentStep < 6"
            @click="store.nextStep()"
            :disabled="!canProceed"
            class="btn-primary"
            :class="{ 'opacity-50 cursor-not-allowed': !canProceed }"
          >
            {{ $t('common.next') }} →
          </button>

          <button
            v-else
            @click="handleStartRestore"
            :disabled="store.restoring"
            class="btn-primary bg-red-600 hover:bg-red-700 text-white"
          >
            {{ store.restoring ? $t('docker_restore.review.actions.starting') : $t('docker_restore.review.actions.start_restore') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Script Preview Modal -->
    <div
      v-if="showScriptModal"
      class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4"
      @click.self="showScriptModal = false"
    >
      <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl max-w-4xl w-full max-h-[90vh] overflow-hidden">
        <div class="p-6 border-b dark:border-gray-700 flex items-center justify-between">
          <h3 class="text-xl font-semibold text-gray-900 dark:text-white">
            {{ $t('docker_restore.review.script.title') }}
          </h3>
          <button
            @click="showScriptModal = false"
            class="text-gray-500 hover:text-gray-700 dark:hover:text-gray-300"
          >
            ✕
          </button>
        </div>

        <div class="p-6 space-y-4 overflow-y-auto max-h-[calc(90vh-200px)]">
          <!-- Mode Toggle -->
          <div class="flex gap-2">
            <button
              @click="scriptAdvanced = false"
              class="px-3 py-1 text-sm rounded"
              :class="!scriptAdvanced ? 'bg-primary-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
            >
              {{ $t('docker_restore.review.script.mode_explained') }}
            </button>
            <button
              @click="scriptAdvanced = true"
              class="px-3 py-1 text-sm rounded"
              :class="scriptAdvanced ? 'bg-primary-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
            >
              {{ $t('docker_restore.review.script.mode_advanced') }}
            </button>
          </div>

          <!-- Script Content -->
          <pre class="bg-gray-900 text-gray-100 p-4 rounded-lg text-sm overflow-x-auto font-mono">{{ scriptContent }}</pre>
        </div>

        <div class="p-6 border-t dark:border-gray-700 flex gap-3">
          <button @click="downloadScript" class="btn-primary">
            {{ $t('docker_restore.review.script.download') }}
          </button>
          <button @click="copyScript" class="btn-secondary">
            {{ $t('docker_restore.review.script.copy') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Toast Notifications -->
    <div class="fixed bottom-4 right-4 z-50 space-y-3">
      <div
        v-for="toast in toasts"
        :key="toast.id"
        :class="[
          'max-w-md rounded-lg shadow-lg p-4 transform transition-all duration-300',
          toast.type === 'success' ? 'bg-green-500 text-white' : toast.type === 'warning' ? 'bg-orange-500 text-white' : 'bg-red-500 text-white',
          'animate-slide-in'
        ]"
      >
        <div class="flex items-start gap-3">
          <div class="flex-shrink-0">
            <svg v-if="toast.type === 'success'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
            <svg v-else-if="toast.type === 'warning'" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <svg v-else class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </div>
          <div class="flex-1 min-w-0">
            <p class="font-semibold">{{ toast.title }}</p>
            <p v-if="toast.message" class="text-sm mt-1 opacity-90">{{ toast.message }}</p>
          </div>
          <button @click="removeToast(toast.id)" class="flex-shrink-0 hover:opacity-75">
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
      </div>
    </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useDockerRestoreStore } from '../stores/dockerRestore'
import { backupService } from '@/services/backups'
import { serverService } from '@/services/server'

const route = useRoute()
const router = useRouter()
const { t } = useI18n()
const store = useDockerRestoreStore()

const loading = ref(true)

// Toast notifications
const toasts = ref([])
let toastIdCounter = 0

function showToast(title, message = '', type = 'success', duration = 5000) {
  const id = ++toastIdCounter
  toasts.value.push({ id, title, message, type })

  setTimeout(() => {
    removeToast(id)
  }, duration)
}

function removeToast(id) {
  const index = toasts.value.findIndex(t => t.id === id)
  if (index > -1) {
    toasts.value.splice(index, 1)
  }
}

const confirmInPlace = ref(false)
const showScriptModal = ref(false)
const scriptAdvanced = ref(false)
const scriptContent = ref('')

const steps = [
  { label: 'docker_restore.steps.mode' },
  { label: 'docker_restore.steps.type' },
  { label: 'docker_restore.steps.destination' },
  { label: 'docker_restore.steps.options' },
  { label: 'docker_restore.steps.conflicts' },
  { label: 'docker_restore.steps.review' }
]

const getStepClass = (step) => {
  if (store.currentStep === step) {
    return 'bg-primary-600 text-white dark:bg-primary-500'
  }
  if (store.currentStep > step) {
    return 'bg-green-600 text-white dark:bg-green-500'
  }
  return 'bg-gray-300 text-gray-600 dark:bg-gray-700 dark:text-gray-400'
}

const canProceed = computed(() => {
  if (store.currentStep === 3 && store.destination === 'in_place') {
    return confirmInPlace.value && store.canProceed
  }
  return store.canProceed
})

const handleStartRestore = async () => {
  try {
    const result = await store.startRestore()
    showToast(t('docker_restore.success_title'), `${result.message} - Job #${result.job_id}`, 'success')
    // Redirect to job monitoring or operations list
    router.push({ name: 'jobs' })
  } catch (error) {
    // Error already handled by store
  }
}

const generateScriptPreview = async () => {
  if (!store.operation) {
    // Need to create operation first (without executing)
    // This is a preview-only call
    return
  }

  scriptContent.value = 'Loading script...'
  await store.generateScript(scriptAdvanced.value)
  scriptContent.value = store.script || 'Failed to generate script'
}

const downloadScript = () => {
  const blob = new Blob([scriptContent.value], { type: 'text/plain' })
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = `docker_restore_${store.archive?.name || 'script'}.sh`
  a.click()
  URL.revokeObjectURL(url)
}

const copyScript = async () => {
  try {
    await navigator.clipboard.writeText(scriptContent.value)
    showToast(t('docker_restore.review.script.copied'), '', 'success')
  } catch (error) {
    showToast(t('docker_restore.review.script.copy_failed'), '', 'error')
  }
}

watch(() => showScriptModal.value, (show) => {
  if (show) {
    generateScriptPreview()
  }
})

watch(() => scriptAdvanced.value, () => {
  if (showScriptModal.value) {
    generateScriptPreview()
  }
})

onMounted(async () => {
  // Initialize wizard with archive from route params
  const archiveId = parseInt(route.params.archiveId)
  const serverId = parseInt(route.query.serverId)

  if (!archiveId || !serverId) {
    showToast(t('docker_restore.error_missing_data'), '', 'error')
    router.push({ name: 'backups' })
    return
  }

  try {
    loading.value = true

    // Load archive data
    const archiveResponse = await backupService.get(archiveId)
    const archive = archiveResponse

    // Load server data
    const servers = await serverService.list()
    const server = servers.find(s => s.id === serverId)

    if (!server) {
      showToast(t('docker_restore.error_server_not_found'), '', 'error')
      router.push({ name: 'backups' })
      return
    }

    // Initialize wizard with loaded data
    store.initWizard(archive, server)
  } catch (error) {
    console.error('Failed to load archive/server:', error)
    showToast(t('docker_restore.error_load_data'), error.response?.data?.message || error.message, 'error')
    router.push({ name: 'backups' })
  } finally {
    loading.value = false
  }
})
</script>
