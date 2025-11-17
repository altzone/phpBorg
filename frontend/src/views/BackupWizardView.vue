<template>
  <div class="backup-wizard">
    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">{{ $t('backup_wizard.title') }}</h1>
      <p class="mt-2 text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.subtitle') }}</p>
    </div>

    <!-- Progress Bar -->
    <div v-if="steps" class="wizard-progress mb-8">
      <div class="flex justify-between relative">
        <div class="absolute top-5 left-0 right-0 h-1 bg-gray-200"></div>
        <div
          class="absolute top-5 left-0 h-1 bg-primary-600 transition-all duration-300"
          :style="{ width: `${(currentStep / (steps.length - 1)) * 100}%` }"
        ></div>

        <div
          v-for="(step, index) in steps"
          :key="index"
          class="relative z-10 flex flex-col items-center"
          :class="{ 'cursor-pointer': index < currentStep }"
          @click="index < currentStep && goToStep(index)"
        >
          <div 
            class="w-10 h-10 rounded-full flex items-center justify-center text-sm font-semibold transition-all"
            :class="{
              'bg-primary-600 text-white': index <= currentStep,
              'bg-gray-200 text-gray-600 dark:text-gray-400 dark:text-gray-500': index > currentStep,
              'ring-4 ring-primary-200': index === currentStep
            }"
          >
            <svg v-if="index < currentStep" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span v-else>{{ index + 1 }}</span>
          </div>
          <span class="mt-2 text-xs text-center max-w-[100px]" 
                :class="index <= currentStep ? 'text-gray-900 dark:text-gray-100 dark:text-gray-100 font-medium' : 'text-gray-500 dark:text-gray-400 dark:text-gray-500 dark:text-gray-500 dark:text-gray-400'">
            {{ step.label }}
          </span>
        </div>
      </div>
    </div>

    <!-- Step Content -->
    <div class="card">
      <div v-if="steps && steps[currentStep]" class="mb-6">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ steps[currentStep].title }}</h2>
        <p class="text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">{{ steps[currentStep].description }}</p>
      </div>

      <div class="wizard-content">
        <!-- Step 1: Server Selection -->
        <div v-if="currentStep === 0" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.server_select.label') }}</label>
            <select v-model="wizardData.serverId" class="input w-full" @change="onServerChange">
              <option value="">{{ $t('backup_wizard.server_select.placeholder') }}</option>
              <option v-for="server in servers" :key="server.id" :value="server.id">
                {{ server.name }} ({{ server.hostname || server.host || '' }})
              </option>
            </select>
          </div>

          <div v-if="wizardData.serverId" class="p-4 bg-blue-50 rounded-lg">
            <div class="flex items-start">
              <svg class="w-5 h-5 text-blue-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
              </svg>
              <div class="ml-3">
                <h3 class="text-sm font-medium text-blue-800">{{ $t('backup_wizard.server_select.details') }}</h3>
                <div class="mt-2 text-sm text-blue-700">
                  <p>{{ $t('backup_wizard.server_select.host') }} {{ selectedServer?.hostname || selectedServer?.host }}</p>
                  <p>{{ $t('backup_wizard.server_select.ssh_port') }} {{ selectedServer?.port }}</p>
                  <p v-if="selectedServer?.description">{{ $t('backup_wizard.server_select.description') }} {{ selectedServer.description }}</p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 2: Backup Type -->
        <div v-else-if="currentStep === 1" class="space-y-4">
          <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
            <label
              v-for="type in backupTypes"
              :key="type.id"
              class="relative flex flex-col items-center p-4 border-2 rounded-lg transition-colors"
              :class="{
                'border-primary-500 bg-primary-50 dark:bg-primary-900/20': wizardData.backupType === type.id && !type.disabled,
                'border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700': wizardData.backupType !== type.id && !type.disabled,
                'border-gray-300 dark:border-gray-600 bg-gray-100 dark:bg-gray-800 opacity-50 cursor-not-allowed': type.disabled
              }"
            >
              <input
                type="radio"
                :value="type.id"
                v-model="wizardData.backupType"
                :disabled="type.disabled"
                class="sr-only"
              />
              <div class="text-3xl mb-2">{{ type.icon }}</div>
              <div class="text-center">
                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ type.name }}</div>
                <div class="text-xs text-gray-600 dark:text-gray-400 mt-1">{{ type.description }}</div>
                <div v-if="type.disabled && type.disabledReason" class="mt-2 text-xs text-red-600 dark:text-red-400 font-medium">
                  ⚠️ {{ type.disabledReason }}
                </div>
              </div>
              <div v-if="wizardData.backupType === type.id && !type.disabled"
                   class="absolute top-2 right-2 w-5 h-5 bg-primary-600 rounded-full flex items-center justify-center">
                <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
              </div>
              <div v-if="type.disabled"
                   class="absolute top-2 right-2 w-5 h-5 bg-red-500 rounded-full flex items-center justify-center">
                <svg class="w-3 h-3 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                </svg>
              </div>
            </label>
          </div>

          <!-- Warning for databases without snapshot support -->
          <div v-if="serverCapabilities?.capabilities_detected" class="mt-6 p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
            <div class="flex items-start gap-3">
              <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <div class="flex-1 text-sm">
                <p class="font-semibold text-blue-900 dark:text-blue-200 mb-1">{{ $t('backup_wizard.backup_type.db_requirements_title') }}</p>
                <p class="text-blue-800 dark:text-blue-300" v-html="$t('backup_wizard.backup_type.db_requirements_desc')"></p>
                <div class="flex items-center gap-3 mt-3">
                  <button
                    @click="reloadCapabilities"
                    :disabled="detectingCapabilities"
                    class="inline-flex items-center px-3 py-1.5 text-xs font-medium rounded-md text-blue-700 dark:text-blue-300 bg-blue-100 dark:bg-blue-800 hover:bg-blue-200 dark:hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
                  >
                    <svg v-if="detectingCapabilities" class="animate-spin -ml-0.5 mr-1.5 h-3.5 w-3.5" fill="none" viewBox="0 0 24 24">
                      <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                      <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    <svg v-else class="w-3.5 h-3.5 mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                    </svg>
                    {{ detectingCapabilities ? $t('backup_wizard.backup_type.reload_detecting') : $t('backup_wizard.backup_type.reload_capabilities') }}
                  </button>
                  <a :href="`/servers/${wizardData.serverId}/capabilities`" target="_blank" class="text-xs text-blue-700 dark:text-blue-400 hover:text-blue-900 dark:hover:text-blue-200 underline">
                    {{ $t('backup_wizard.backup_type.view_capabilities') }}
                  </a>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 3: Source Configuration -->
        <div v-else-if="currentStep === 2" class="space-y-4">
          <!-- Files Configuration -->
          <div v-if="wizardData.backupType === 'files'">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.source_config.paths_label') }}</label>
              <div class="space-y-2">
                <div v-for="(path, index) in wizardData.sourceConfig.paths" :key="index" class="flex gap-2">
                  <input
                    v-model="wizardData.sourceConfig.paths[index]"
                    type="text"
                    class="input flex-1"
                    :placeholder="$t('backup_wizard.source_config.paths_placeholder')"
                  />
                  <button @click="removePath(index)" class="btn btn-secondary">{{ $t('backup_wizard.source_config.remove') }}</button>
                </div>
                <button @click="addPath" class="btn btn-primary btn-sm">+ {{ $t('backup_wizard.source_config.add_path') }}</button>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.source_config.exclude_label') }}</label>
              <textarea
                v-model="wizardData.sourceConfig.excludePatterns"
                rows="3"
                class="input w-full"
                :placeholder="$t('backup_wizard.source_config.exclude_placeholder')"
              ></textarea>
              <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">{{ $t('backup_wizard.source_config.exclude_help') }}</p>
            </div>
          </div>

          <!-- MySQL/MariaDB Configuration -->
          <div v-else-if="wizardData.backupType === 'mysql' || wizardData.backupType === 'mariadb'">
            <div class="space-y-4">
              <!-- Database Information from Capabilities -->
              <div v-if="getDetectedDatabase('mysql')" class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                <div class="flex items-start gap-3">
                  <svg class="w-5 h-5 text-green-600 dark:text-green-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <div class="flex-1 text-sm">
                    <p class="font-semibold text-green-900 dark:text-green-200 mb-2">{{ $t('backup_wizard.source_config.db_detected_title') }}</p>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-green-800 dark:text-green-300">
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_datadir') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('mysql').datadir }}</span>
                        <span v-if="getDetectedDatabase('mysql').datadir_confidence === 'high'" class="ml-2 px-1.5 py-0.5 bg-green-600 text-white text-xs rounded">HIGH</span>
                        <span v-else-if="getDetectedDatabase('mysql').datadir_confidence === 'medium'" class="ml-2 px-1.5 py-0.5 bg-yellow-600 text-white text-xs rounded">MEDIUM</span>
                      </div>
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_volume') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('mysql').volume?.vg_name }}/{{ getDetectedDatabase('mysql').volume?.lv_name }}</span>
                      </div>
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_snapshot_size') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('mysql').snapshot_size?.recommended_size || 'N/A' }}</span>
                      </div>
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_datadir_size') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('mysql').snapshot_size?.datadir_size || 'N/A' }}</span>
                      </div>
                    </div>
                    <p class="text-green-700 dark:text-green-400 mt-2 text-xs">
                      {{ $t('backup_wizard.source_config.db_snapshot_info') }}
                    </p>
                  </div>
                </div>
              </div>

              <div>
                <label class="flex items-center justify-between mb-2">
                  <span class="text-sm font-medium text-gray-700 dark:text-gray-300">{{ $t('backup_wizard.source_config.mysql_credentials') }}</span>
                  <button
                    @click="detectMySQLCredentials"
                    class="inline-flex items-center px-3 py-1 text-xs font-medium text-white bg-primary-600 hover:bg-primary-700 rounded-md transition-colors"
                  >
                    <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                    </svg>
                    {{ $t('backup_wizard.source_config.auto_detect') }}
                  </button>
                </label>

                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">{{ $t('backup_wizard.source_config.host') }}</label>
                    <input v-model="wizardData.sourceConfig.host" type="text" class="input w-full" placeholder="localhost" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">{{ $t('backup_wizard.source_config.port') }}</label>
                    <input v-model="wizardData.sourceConfig.port" type="number" class="input w-full" placeholder="3306" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">{{ $t('backup_wizard.source_config.username') }}</label>
                    <input v-model="wizardData.sourceConfig.username" type="text" class="input w-full" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">{{ $t('backup_wizard.source_config.password') }}</label>
                    <input v-model="wizardData.sourceConfig.password" type="password" class="input w-full" />
                  </div>
                </div>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.source_config.databases_label') }}</label>
                <div class="flex items-center gap-4">
                  <label class="flex items-center">
                    <input type="radio" v-model="wizardData.sourceConfig.databaseSelection" value="all" class="mr-2" />
                    {{ $t('backup_wizard.source_config.all_databases') }}
                  </label>
                  <label class="flex items-center">
                    <input type="radio" v-model="wizardData.sourceConfig.databaseSelection" value="specific" class="mr-2" />
                    {{ $t('backup_wizard.source_config.specific_databases') }}
                  </label>
                </div>

                <div v-if="wizardData.sourceConfig.databaseSelection === 'specific'" class="mt-2">
                  <input
                    v-model="wizardData.sourceConfig.databases"
                    type="text"
                    class="input w-full"
                    :placeholder="$t('backup_wizard.source_config.databases_placeholder')"
                  />
                  <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Comma-separated list of database names</p>
                </div>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.source_config.backup_options') }}</label>
                <div class="space-y-2">
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.singleTransaction" class="mr-2" />
                    {{ $t('backup_wizard.source_config.single_transaction') }}
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.routines" class="mr-2" />
                    {{ $t('backup_wizard.source_config.include_routines') }}
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.triggers" class="mr-2" />
                    {{ $t('backup_wizard.source_config.include_triggers') }}
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.events" class="mr-2" />
                    {{ $t('backup_wizard.source_config.include_events') }}
                  </label>
                </div>
              </div>
            </div>
          </div>

          <!-- PostgreSQL Configuration -->
          <div v-else-if="wizardData.backupType === 'postgresql'">
            <div class="space-y-4">
              <!-- Database Information from Capabilities -->
              <div v-if="getDetectedDatabase('postgresql')" class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                <div class="flex items-start gap-3">
                  <svg class="w-5 h-5 text-green-600 dark:text-green-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <div class="flex-1 text-sm">
                    <div class="flex items-center justify-between mb-2">
                      <p class="font-semibold text-green-900 dark:text-green-200">{{ $t('backup_wizard.source_config.db_detected_title') }}</p>
                      <span v-if="getDetectedDatabase('postgresql').auth?.peer_auth" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-600 text-white">
                        <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        {{ $t('backup_wizard.source_config.pg_peer_auth') }}
                      </span>
                    </div>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-green-800 dark:text-green-300">
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_datadir') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('postgresql').datadir }}</span>
                        <span v-if="getDetectedDatabase('postgresql').datadir_confidence === 'high'" class="ml-2 px-1.5 py-0.5 bg-green-600 text-white text-xs rounded">HIGH</span>
                        <span v-else-if="getDetectedDatabase('postgresql').datadir_confidence === 'medium'" class="ml-2 px-1.5 py-0.5 bg-yellow-600 text-white text-xs rounded">MEDIUM</span>
                      </div>
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_volume') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('postgresql').volume?.vg_name }}/{{ getDetectedDatabase('postgresql').volume?.lv_name }}</span>
                      </div>
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_snapshot_size') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('postgresql').snapshot_size?.recommended_size || 'N/A' }}</span>
                      </div>
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_datadir_size') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('postgresql').snapshot_size?.datadir_size || 'N/A' }}</span>
                      </div>
                    </div>
                    <p class="text-green-700 dark:text-green-400 mt-2 text-xs">
                      {{ $t('backup_wizard.source_config.db_snapshot_info') }}
                    </p>
                  </div>
                </div>
              </div>

              <!-- PostgreSQL Cluster Selection (if peer auth and multiple clusters) -->
              <div v-if="getDetectedDatabase('postgresql')?.auth?.clusters?.length > 1">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                  {{ $t('backup_wizard.source_config.pg_cluster_select') }}
                </label>
                <select v-model="wizardData.sourceConfig.pg_cluster" class="input w-full">
                  <option v-for="cluster in getDetectedDatabase('postgresql').auth.clusters" :key="`${cluster.version}-${cluster.cluster}`" :value="`${cluster.version}/${cluster.cluster}`">
                    {{ cluster.version }}/{{ cluster.cluster }} - Port {{ cluster.port }} ({{ cluster.status }})
                  </option>
                </select>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                  {{ $t('backup_wizard.source_config.pg_cluster_help') }}
                </p>
              </div>

              <!-- PostgreSQL Cluster Info (if peer auth and single cluster) -->
              <div v-else-if="getDetectedDatabase('postgresql')?.auth?.clusters?.length === 1" class="p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                <div class="flex items-center gap-2 text-sm text-blue-800 dark:text-blue-300">
                  <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <span class="font-medium">{{ $t('backup_wizard.source_config.pg_cluster_auto') }}:</span>
                  <span class="font-mono text-xs">
                    {{ getDetectedDatabase('postgresql').auth.clusters[0].version }}/{{ getDetectedDatabase('postgresql').auth.clusters[0].cluster }}
                    (Port {{ getDetectedDatabase('postgresql').auth.clusters[0].port }})
                  </span>
                </div>
              </div>

              <!-- Database Credentials (only if peer auth is NOT working) -->
              <div v-if="!getDetectedDatabase('postgresql')?.auth?.peer_auth">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.source_config.db_credentials') }}</label>
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('backup_wizard.source_config.db_host') }}</label>
                    <input v-model="wizardData.sourceConfig.host" type="text" class="input w-full" placeholder="localhost" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('backup_wizard.source_config.db_port') }}</label>
                    <input v-model="wizardData.sourceConfig.port" type="number" class="input w-full" placeholder="5432" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('backup_wizard.source_config.db_username') }}</label>
                    <input v-model="wizardData.sourceConfig.username" type="text" class="input w-full" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('backup_wizard.source_config.db_password') }}</label>
                    <input v-model="wizardData.sourceConfig.password" type="password" class="input w-full" />
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- MongoDB Configuration -->
          <div v-else-if="wizardData.backupType === 'mongodb'">
            <div class="space-y-4">
              <!-- Database Information from Capabilities -->
              <div v-if="getDetectedDatabase('mongodb')" class="p-4 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg">
                <div class="flex items-start gap-3">
                  <svg class="w-5 h-5 text-green-600 dark:text-green-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <div class="flex-1 text-sm">
                    <p class="font-semibold text-green-900 dark:text-green-200 mb-2">{{ $t('backup_wizard.source_config.db_detected_title') }}</p>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-2 text-green-800 dark:text-green-300">
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_datadir') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('mongodb').datadir }}</span>
                        <span v-if="getDetectedDatabase('mongodb').datadir_confidence === 'high'" class="ml-2 px-1.5 py-0.5 bg-green-600 text-white text-xs rounded">HIGH</span>
                        <span v-else-if="getDetectedDatabase('mongodb').datadir_confidence === 'medium'" class="ml-2 px-1.5 py-0.5 bg-yellow-600 text-white text-xs rounded">MEDIUM</span>
                      </div>
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_volume') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('mongodb').volume?.vg_name }}/{{ getDetectedDatabase('mongodb').volume?.lv_name }}</span>
                      </div>
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_snapshot_size') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('mongodb').snapshot_size?.recommended_size || 'N/A' }}</span>
                      </div>
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.db_datadir_size') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDatabase('mongodb').snapshot_size?.datadir_size || 'N/A' }}</span>
                      </div>
                    </div>
                    <p class="text-green-700 dark:text-green-400 mt-2 text-xs">
                      {{ $t('backup_wizard.source_config.db_snapshot_info') }}
                    </p>
                  </div>
                </div>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.source_config.db_credentials') }}</label>
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('backup_wizard.source_config.db_host') }}</label>
                    <input v-model="wizardData.sourceConfig.host" type="text" class="input w-full" placeholder="localhost" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('backup_wizard.source_config.db_port') }}</label>
                    <input v-model="wizardData.sourceConfig.port" type="number" class="input w-full" placeholder="27017" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('backup_wizard.source_config.db_username') }}</label>
                    <input v-model="wizardData.sourceConfig.username" type="text" class="input w-full" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 mb-1">{{ $t('backup_wizard.source_config.db_password') }}</label>
                    <input v-model="wizardData.sourceConfig.password" type="password" class="input w-full" />
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Docker Environment Configuration -->
          <div v-else-if="wizardData.backupType === 'docker'">
            <div class="space-y-4">
              <!-- Docker Information from Capabilities -->
              <div v-if="getDetectedDocker()" class="p-4 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                <div class="flex items-start gap-3">
                  <svg class="w-5 h-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                  </svg>
                  <div class="flex-1 text-sm">
                    <p class="font-semibold text-blue-900 dark:text-blue-200 mb-2">{{ $t('backup_wizard.source_config.docker_detected_title') }}</p>
                    <div class="grid grid-cols-3 gap-x-4 gap-y-2 text-blue-800 dark:text-blue-300">
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.docker_containers') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDocker().container_count || 0 }}</span>
                      </div>
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.docker_volumes') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDocker().volume_count || 0 }}</span>
                      </div>
                      <div>
                        <span class="font-medium">{{ $t('backup_wizard.source_config.docker_compose') }}</span>
                        <span class="ml-2 font-mono text-xs">{{ getDetectedDocker().compose_project_count || 0 }} {{ $t('backup_wizard.source_config.docker_projects') }}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Global Option: Backup all volumes -->
              <div>
                <label class="flex items-center p-3 bg-gray-50 dark:bg-gray-800 rounded-lg cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700 transition-colors">
                  <input
                    v-model="wizardData.sourceConfig.backupAllVolumes"
                    type="checkbox"
                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                  />
                  <span class="ml-3 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $t('backup_wizard.source_config.docker_all_volumes') }}
                  </span>
                  <span class="ml-2 text-xs text-gray-500 dark:text-gray-400">{{ $t('backup_wizard.source_config.docker_recommended') }}</span>
                </label>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 ml-3">
                  {{ $t('backup_wizard.source_config.docker_all_volumes_desc') }}
                </p>
              </div>

              <!-- Compose Projects -->
              <div v-if="getDetectedDocker()?.compose_projects && Object.keys(getDetectedDocker().compose_projects).length > 0">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                  {{ $t('backup_wizard.source_config.docker_compose_projects') }}
                </label>
                <div class="space-y-2 max-h-64 overflow-y-auto border dark:border-gray-700 rounded-lg p-3">
                  <label
                    v-for="(project, projectName) in getDetectedDocker().compose_projects"
                    :key="projectName"
                    class="flex items-start p-2 rounded hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer"
                  >
                    <input
                      v-model="wizardData.sourceConfig.selectedComposeProjects"
                      :value="projectName"
                      type="checkbox"
                      class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                    />
                    <div class="ml-3 flex-1">
                      <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">{{ projectName }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ project.containers?.length || 0 }} containers</span>
                      </div>
                      <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5" v-if="project.working_dir">
                        📁 {{ project.working_dir }}
                      </p>
                    </div>
                  </label>
                </div>
              </div>

              <!-- Individual Volumes -->
              <div v-if="!wizardData.sourceConfig.backupAllVolumes && getDetectedDocker()?.volumes">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                  {{ $t('backup_wizard.source_config.docker_individual_volumes') }}
                </label>
                <div class="space-y-2 max-h-64 overflow-y-auto border dark:border-gray-700 rounded-lg p-3">
                  <label
                    v-for="volume in getDetectedDocker().volumes"
                    :key="volume.name"
                    class="flex items-start p-2 rounded hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer"
                  >
                    <input
                      v-model="wizardData.sourceConfig.selectedVolumes"
                      :value="volume.name"
                      type="checkbox"
                      class="mt-1 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                    />
                    <div class="ml-3 flex-1">
                      <div class="flex items-center justify-between">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100 font-mono">{{ volume.name }}</span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">{{ volume.driver }}</span>
                      </div>
                      <p class="text-xs text-gray-600 dark:text-gray-400 mt-0.5">
                        {{ volume.mountpoint }}
                      </p>
                    </div>
                  </label>
                </div>
              </div>

              <!-- System Configuration -->
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">
                  {{ $t('backup_wizard.source_config.docker_system_config') }}
                </label>
                <div class="space-y-2">
                  <label class="flex items-center p-2 rounded hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer">
                    <input
                      v-model="wizardData.sourceConfig.backupDockerConfig"
                      type="checkbox"
                      class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                    />
                    <span class="ml-3 text-sm text-gray-900 dark:text-gray-100">
                      {{ $t('backup_wizard.source_config.docker_daemon_config') }}
                    </span>
                    <code class="ml-2 text-xs text-gray-500 dark:text-gray-400">/etc/docker/daemon.json</code>
                  </label>
                  <label class="flex items-center p-2 rounded hover:bg-gray-50 dark:hover:bg-gray-800 cursor-pointer">
                    <input
                      v-model="wizardData.sourceConfig.backupCustomNetworks"
                      type="checkbox"
                      class="rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                    />
                    <span class="ml-3 text-sm text-gray-900 dark:text-gray-100">
                      {{ $t('backup_wizard.source_config.docker_custom_networks') }}
                    </span>
                  </label>
                </div>
              </div>
            </div>
          </div>

          <!-- Full System Backup Configuration -->
          <div v-else-if="wizardData.backupType === 'system'">
            <div class="space-y-4">
              <!-- Information Banner -->
              <div class="p-4 bg-blue-50 rounded-lg">
                <div class="flex items-start">
                  <svg class="w-5 h-5 text-blue-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0118 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                  </svg>
                  <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">{{ $t('backup_wizard.source_config.full_system_backup_title') }}</h3>
                    <p class="mt-1 text-sm text-blue-700">
                      {{ $t('backup_wizard.source_config.full_system_backup_description') }}
                    </p>
                  </div>
                </div>
              </div>

              <!-- Backup Scope -->
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.source_config.backup_scope') }}</label>
                <div class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg">
                  <div class="flex items-center mb-2">
                    <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                    <span class="text-sm">Root filesystem (/)</span>
                  </div>
                  <div class="flex items-center mb-2">
                    <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                    <span class="text-sm">All user data (/home)</span>
                  </div>
                  <div class="flex items-center mb-2">
                    <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                    <span class="text-sm">System configuration (/etc)</span>
                  </div>
                  <div class="flex items-center">
                    <svg class="w-4 h-4 text-green-500 mr-2" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                    </svg>
                    <span class="text-sm">Application data (/opt, /var)</span>
                  </div>
                </div>
              </div>

              <!-- Standard Exclusions -->
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.source_config.standard_exclusions') }}</label>
                <div class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg">
                  <div class="space-y-1 font-mono text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500">
                    <div class="text-gray-700 dark:text-gray-300 font-semibold mb-2"># Critical system directories</div>
                    <div>/proc/*</div>
                    <div>/sys/*</div>
                    <div>/dev/*</div>
                    <div>/run/*</div>
                    <div>/tmp/*</div>
                    <div>/var/tmp/*</div>
                    <div>/mnt/*</div>
                    <div>/media/*</div>
                    <div class="text-gray-700 dark:text-gray-300 font-semibold mt-3 mb-2"># Swap files</div>
                    <div>/swapfile</div>
                    <div>*.swp</div>
                    <div>*.tmp</div>
                    <div>*~</div>
                  </div>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-2">These exclusions are always applied to prevent backing up system runtime files.</p>
              </div>

              <!-- Optional Exclusions -->
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.source_config.optional_exclusions') }}</label>
                <div class="space-y-3">
                  <!-- Docker -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeDocker" class="mt-1 mr-3" checked />
                      <div class="flex-1">
                        <div class="font-medium text-sm">{{ $t('backup_wizard.source_config.exclusions.docker.title') }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.docker.paths') }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.docker.description') }}
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Database Data -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeDatabaseData" class="mt-1 mr-3" checked />
                      <div class="flex-1">
                        <div class="font-medium text-sm">{{ $t('backup_wizard.source_config.exclusions.database.title') }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.database.paths') }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.database.description') }}
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Virtual Machines -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeVMs" class="mt-1 mr-3" checked />
                      <div class="flex-1">
                        <div class="font-medium text-sm">{{ $t('backup_wizard.source_config.exclusions.vms.title') }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.vms.paths') }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.vms.description') }}
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Logs -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeLogs" class="mt-1 mr-3" checked />
                      <div class="flex-1">
                        <div class="font-medium text-sm">{{ $t('backup_wizard.source_config.exclusions.logs.title') }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.logs.paths') }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.logs.description') }}
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Caches -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeCaches" class="mt-1 mr-3" checked />
                      <div class="flex-1">
                        <div class="font-medium text-sm">{{ $t('backup_wizard.source_config.exclusions.caches.title') }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.caches.paths') }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.caches.description') }}
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Downloads & Trash -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeDownloads" class="mt-1 mr-3" />
                      <div class="flex-1">
                        <div class="font-medium text-sm">{{ $t('backup_wizard.source_config.exclusions.downloads.title') }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.downloads.paths') }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.downloads.description') }}
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Build Artifacts -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeBuildArtifacts" class="mt-1 mr-3" />
                      <div class="flex-1">
                        <div class="font-medium text-sm">{{ $t('backup_wizard.source_config.exclusions.build.title') }}</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.build.paths') }}
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          {{ $t('backup_wizard.source_config.exclusions.build.description') }}
                        </div>
                      </div>
                    </label>
                  </div>
                </div>
              </div>

              <!-- Custom Exclusions -->
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.source_config.additional_exclusions') }}</label>

                <!-- Input to add new exclusions -->
                <div class="flex gap-2 mb-3">
                  <div class="relative flex-1">
                    <input
                      v-model="newExclusionPattern"
                      @keydown.enter="addCustomExclusion"
                      type="text"
                      class="input w-full pr-10"
                      :placeholder="$t('backup_wizard.source_config.pattern_placeholder')"
                    />
                    <div class="absolute inset-y-0 right-2 flex items-center pointer-events-none">
                      <svg class="w-4 h-4 text-gray-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                    </div>
                  </div>
                  <button
                    @click="addCustomExclusion"
                    :disabled="!newExclusionPattern.trim()"
                    class="btn btn-primary"
                  >
                    {{ $t('backup_wizard.source_config.add_exclusion') }}
                  </button>
                </div>

                <!-- Quick add buttons for common patterns -->
                <div class="mb-3">
                  <p class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-2">{{ $t('backup_wizard.source_config.quick_add') }}</p>
                  <div class="flex flex-wrap gap-2">
                    <button 
                      v-for="pattern in quickExclusionPatterns" 
                      :key="pattern.value"
                      @click="newExclusionPattern = pattern.value; addCustomExclusion()"
                      class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 text-gray-700 dark:text-gray-300 rounded-md transition-colors"
                      :title="pattern.description"
                    >
                      {{ pattern.label }}
                    </button>
                  </div>
                </div>

                <!-- Display custom exclusions as tags -->
                <div v-if="customExclusions.length > 0" class="bg-gray-50 dark:bg-gray-800 p-3 rounded-lg">
                  <div class="flex flex-wrap gap-2">
                    <div 
                      v-for="(exclusion, index) in customExclusions" 
                      :key="index"
                      class="group inline-flex items-center bg-white border border-gray-300 dark:border-gray-600 rounded-full px-3 py-1 text-sm"
                    >
                      <span class="font-mono text-gray-700 dark:text-gray-300">{{ exclusion }}</span>
                      <button
                        @click="removeCustomExclusion(index)"
                        class="ml-2 text-gray-400 dark:text-gray-500 hover:text-red-500 transition-colors"
                        :title="$t('backup_wizard.source_config.remove_exclusion')"
                      >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                      </button>
                    </div>
                  </div>
                  <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 dark:text-gray-500 dark:text-gray-400 mt-2">{{ $t('backup_wizard.source_config.custom_exclusions_count', { count: customExclusions.length }, customExclusions.length) }}</p>
                </div>
                <div v-else class="text-sm text-gray-500 dark:text-gray-400 dark:text-gray-500 italic">
                  {{ $t('backup_wizard.source_config.no_custom_exclusions') }}
                </div>

                <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-2">
                  {{ $t('backup_wizard.source_config.wildcards_help') }}
                </p>
              </div>

              <!-- Backup Options -->
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.source_config.options_label') }}</label>
                <div class="space-y-2">
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.oneFileSystem" class="mr-2" checked />
                    <span class="text-sm">{{ $t('backup_wizard.source_config.one_file_system') }}</span>
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.preservePermissions" class="mr-2" checked />
                    <span class="text-sm">{{ $t('backup_wizard.source_config.preserve_permissions') }}</span>
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.preserveTimestamps" class="mr-2" checked />
                    <span class="text-sm">{{ $t('backup_wizard.source_config.preserve_timestamps') }}</span>
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.followSymlinks" class="mr-2" />
                    <span class="text-sm">{{ $t('backup_wizard.source_config.follow_symlinks') }}</span>
                  </label>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 4: Storage Pool -->
        <div v-else-if="currentStep === 3" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.storage_pool.select_label') }}</label>
            <div class="space-y-2">
              <label
                v-for="pool in storagePools"
                :key="pool.id"
                class="flex items-start p-4 border rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 dark:bg-gray-800"
                :class="{ 'border-primary-500 bg-primary-50': wizardData.storagePoolId === pool.id }"
              >
                <input
                  type="radio"
                  :value="pool.id"
                  v-model="wizardData.storagePoolId"
                  class="mt-1"
                />
                <div class="ml-3 flex-1">
                  <div class="flex items-center justify-between">
                    <div class="font-medium">{{ pool.name }}</div>
                    <span v-if="pool.default_pool" class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded">{{ $t('backup_wizard.storage_pool.default_badge') }}</span>
                  </div>
                  <div class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ pool.path }}</div>
                  <div class="mt-2">
                    <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">
                      <span>{{ $t('backup_wizard.storage_pool.used') }} {{ formatBytes(pool.capacity_used) }}</span>
                      <span>{{ $t('backup_wizard.storage_pool.total') }} {{ formatBytes(pool.capacity_total) }}</span>
                    </div>
                    <div class="mt-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                      <div 
                        class="h-full bg-primary-600 transition-all"
                        :style="{ width: `${(pool.capacity_used / pool.capacity_total) * 100}%` }"
                      ></div>
                    </div>
                  </div>
                </div>
              </label>
            </div>
          </div>
        </div>

        <!-- Step 5: Repository Setup -->
        <div v-else-if="currentStep === 4" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.repository.name_label') }}</label>
            <input
              v-model="wizardData.repositoryName"
              type="text"
              class="input w-full"
              :placeholder="`${selectedServer?.name}-${wizardData.backupType}`"
            />
            <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">{{ $t('backup_wizard.repository.name_help') }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.repository.encryption_label') }}</label>
            <select v-model="wizardData.encryption" class="input w-full">
              <option value="repokey-blake2">{{ $t('backup_wizard.repository.encryption_options.repokey_blake2') }}</option>
              <option value="repokey">{{ $t('backup_wizard.repository.encryption_options.repokey') }}</option>
              <option value="keyfile-blake2">{{ $t('backup_wizard.repository.encryption_options.keyfile_blake2') }}</option>
              <option value="keyfile">{{ $t('backup_wizard.repository.encryption_options.keyfile') }}</option>
              <option value="none">{{ $t('backup_wizard.repository.encryption_options.none') }}</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.repository.compression_label') }}</label>
            <select v-model="wizardData.compression" class="input w-full">
              <option value="lz4">{{ $t('backup_wizard.repository.compression_options.lz4') }}</option>
              <option value="zstd">{{ $t('backup_wizard.repository.compression_options.zstd') }}</option>
              <option value="zstd,3">{{ $t('backup_wizard.repository.compression_options.zstd_3') }}</option>
              <option value="zlib">{{ $t('backup_wizard.repository.compression_options.zlib') }}</option>
              <option value="none">{{ $t('backup_wizard.repository.compression_options.none') }}</option>
            </select>
          </div>

          <div v-if="wizardData.encryption !== 'none'">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.repository.passphrase_label') }}</label>
            <input
              v-model="wizardData.passphrase"
              type="password"
              class="input w-full"
              :placeholder="$t('backup_wizard.repository.passphrase_placeholder')"
            />
            <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
              <span v-if="!wizardData.passphrase">{{ $t('backup_wizard.repository.passphrase_help_auto') }}</span>
              <span v-else>{{ $t('backup_wizard.repository.passphrase_help_manual') }}</span>
            </p>
          </div>
        </div>

        <!-- Step 6: Retention Policy -->
        <div v-else-if="currentStep === 5" class="space-y-4">
          <!-- Info Banner -->
          <div class="rounded-lg bg-blue-50 border border-blue-200 p-4">
            <div class="flex items-start space-x-3">
              <svg class="h-5 w-5 text-blue-600 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <div class="text-sm text-blue-900">
                <p class="font-medium mb-1">{{ $t('backup_wizard.retention.info_title') }}</p>
                <p class="text-blue-800">
                  {{ $t('backup_wizard.retention.info_description') }}
                </p>
              </div>
            </div>
          </div>

          <!-- Retention Settings with sliders -->
          <div class="space-y-5">
            <!-- Daily -->
            <div class="flex items-center justify-between space-x-4">
              <div class="flex-1">
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">
                  {{ $t('backup_wizard.retention.daily_label') }}
                </label>
                <p class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500">
                  {{ $t('backup_wizard.retention.daily_help') }}
                </p>
              </div>
              <div class="flex items-center space-x-3">
                <input
                  v-model.number="wizardData.retention.keepDaily"
                  type="range"
                  min="0"
                  max="365"
                  class="w-32 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
                />
                <input
                  v-model.number="wizardData.retention.keepDaily"
                  type="number"
                  min="0"
                  max="365"
                  class="w-20 px-3 py-2 text-center border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-12">{{ $t('backup_wizard.retention.unit_days') }}</span>
              </div>
            </div>

            <!-- Weekly -->
            <div class="flex items-center justify-between space-x-4">
              <div class="flex-1">
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">
                  {{ $t('backup_wizard.retention.weekly_label') }}
                </label>
                <p class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500">
                  {{ $t('backup_wizard.retention.weekly_help') }}
                </p>
              </div>
              <div class="flex items-center space-x-3">
                <input
                  v-model.number="wizardData.retention.keepWeekly"
                  type="range"
                  min="0"
                  max="52"
                  class="w-32 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
                />
                <input
                  v-model.number="wizardData.retention.keepWeekly"
                  type="number"
                  min="0"
                  max="52"
                  class="w-20 px-3 py-2 text-center border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-12">{{ $t('backup_wizard.retention.unit_weeks') }}</span>
              </div>
            </div>

            <!-- Monthly -->
            <div class="flex items-center justify-between space-x-4">
              <div class="flex-1">
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">
                  {{ $t('backup_wizard.retention.monthly_label') }}
                </label>
                <p class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500">
                  {{ $t('backup_wizard.retention.monthly_help') }}
                </p>
              </div>
              <div class="flex items-center space-x-3">
                <input
                  v-model.number="wizardData.retention.keepMonthly"
                  type="range"
                  min="0"
                  max="60"
                  class="w-32 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
                />
                <input
                  v-model.number="wizardData.retention.keepMonthly"
                  type="number"
                  min="0"
                  max="60"
                  class="w-20 px-3 py-2 text-center border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-12">{{ $t('backup_wizard.retention.unit_months') }}</span>
              </div>
            </div>

            <!-- Yearly -->
            <div class="flex items-center justify-between space-x-4">
              <div class="flex-1">
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">
                  {{ $t('backup_wizard.retention.yearly_label') }}
                </label>
                <p class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500">
                  {{ $t('backup_wizard.retention.yearly_help') }}
                </p>
              </div>
              <div class="flex items-center space-x-3">
                <input
                  v-model.number="wizardData.retention.keepYearly"
                  type="range"
                  min="0"
                  max="10"
                  class="w-32 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-blue-600"
                />
                <input
                  v-model.number="wizardData.retention.keepYearly"
                  type="number"
                  min="0"
                  max="10"
                  class="w-20 px-3 py-2 text-center border border-gray-300 dark:border-gray-600 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                />
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-12">{{ $t('backup_wizard.retention.unit_years') }}</span>
              </div>
            </div>
          </div>

          <!-- Preview -->
          <div class="rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">{{ $t('backup_wizard.retention.preview_title') }}</h4>
            <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
              <div v-if="wizardData.retention.keepDaily > 0" class="flex items-center justify-between">
                <span>{{ $t('backup_wizard.retention.preview_last') }} <strong>{{ wizardData.retention.keepDaily }}</strong> {{ $t('backup_wizard.retention.preview_daily') }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">≈ {{ Math.ceil(wizardData.retention.keepDaily) }} {{ $t('backup_wizard.retention.preview_days') }}</span>
              </div>
              <div v-if="wizardData.retention.keepWeekly > 0" class="flex items-center justify-between">
                <span>{{ $t('backup_wizard.retention.preview_last') }} <strong>{{ wizardData.retention.keepWeekly }}</strong> {{ $t('backup_wizard.retention.preview_weekly') }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">≈ {{ Math.ceil(wizardData.retention.keepWeekly * 7 / 30) }} {{ $t('backup_wizard.retention.preview_months') }}</span>
              </div>
              <div v-if="wizardData.retention.keepMonthly > 0" class="flex items-center justify-between">
                <span>{{ $t('backup_wizard.retention.preview_last') }} <strong>{{ wizardData.retention.keepMonthly }}</strong> {{ $t('backup_wizard.retention.preview_monthly') }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">≈ {{ Math.ceil(wizardData.retention.keepMonthly / 12) }} {{ $t('backup_wizard.retention.preview_years') }}</span>
              </div>
              <div v-if="wizardData.retention.keepYearly > 0" class="flex items-center justify-between">
                <span>{{ $t('backup_wizard.retention.preview_last') }} <strong>{{ wizardData.retention.keepYearly }}</strong> {{ $t('backup_wizard.retention.preview_yearly') }}</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">{{ wizardData.retention.keepYearly }} {{ $t('backup_wizard.retention.preview_years') }}</span>
              </div>
              <div v-if="totalRetentionPeriods === 0" class="text-amber-600 font-medium">
                {{ $t('backup_wizard.retention.preview_warning') }}
              </div>
            </div>
          </div>
        </div>

        <!-- Step 7: Schedule -->
        <div v-else-if="currentStep === 6" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.schedule_step.schedule_label') }}</label>
            <select v-model="wizardData.scheduleType" class="input w-full">
              <option value="manual">{{ $t('backup_wizard.schedule_step.schedule_options.manual') }}</option>
              <option value="daily">{{ $t('backup_wizard.schedule_step.schedule_options.daily') }}</option>
              <option value="weekly">{{ $t('backup_wizard.schedule_step.schedule_options.weekly') }}</option>
              <option value="monthly">{{ $t('backup_wizard.schedule_step.schedule_options.monthly') }}</option>
            </select>
          </div>

          <div v-if="wizardData.scheduleType !== 'manual'">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.schedule_step.time_label') }}</label>
            <input v-model="wizardData.scheduleTime" type="time" class="input w-full" />
          </div>

          <!-- Multi-day selection for weekly (reuse from BackupJobsView) -->
          <div v-if="wizardData.scheduleType === 'weekly'">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.schedule_step.days_of_week') }}</label>
            <div class="grid grid-cols-7 gap-2">
              <label v-for="(day, index) in weekDays" :key="index"
                     class="flex items-center justify-center p-2 border rounded cursor-pointer hover:bg-blue-50"
                     :class="{ 'bg-blue-100 border-blue-500': wizardData.selectedWeekdays.includes(index + 1) }">
                <input type="checkbox"
                       :value="index + 1"
                       v-model="wizardData.selectedWeekdays"
                       class="sr-only" />
                <span class="text-xs font-medium">{{ day.short }}</span>
              </label>
            </div>
          </div>

          <!-- Multi-day selection for monthly -->
          <div v-if="wizardData.scheduleType === 'monthly'">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">{{ $t('backup_wizard.schedule_step.days_of_month') }}</label>
            <div class="grid grid-cols-7 gap-2 max-h-48 overflow-y-auto">
              <label v-for="day in 31" :key="day"
                     class="flex items-center justify-center p-2 border rounded cursor-pointer hover:bg-blue-50 min-w-[40px]"
                     :class="{ 'bg-blue-100 border-blue-500': wizardData.selectedMonthdays.includes(day) }">
                <input type="checkbox"
                       :value="day"
                       v-model="wizardData.selectedMonthdays"
                       class="sr-only" />
                <span class="text-xs font-medium">{{ day }}</span>
              </label>
            </div>
          </div>
        </div>

        <!-- Step 8: Review -->
        <div v-else-if="currentStep === 7" class="space-y-6">
          <div class="space-y-4">
            <div class="border-b pb-4">
              <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ $t('backup_wizard.review.server_type_title') }}</h3>
              <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.server_label') }}</dt>
                <dd class="font-medium">{{ selectedServer?.name }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.backup_type_label') }}</dt>
                <dd class="font-medium">{{ wizardData.backupType }}</dd>
              </dl>
            </div>

            <div class="border-b pb-4">
              <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ $t('backup_wizard.review.repository_title') }}</h3>
              <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.name_label') }}</dt>
                <dd class="font-medium">{{ wizardData.repositoryName }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.storage_pool_label') }}</dt>
                <dd class="font-medium">{{ selectedPool?.name }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.encryption_label') }}</dt>
                <dd class="font-medium">{{ wizardData.encryption }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.compression_label') }}</dt>
                <dd class="font-medium">{{ wizardData.compression }}</dd>
              </dl>
            </div>

            <div class="border-b pb-4">
              <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ $t('backup_wizard.review.retention_title') }}</h3>
              <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.daily_label') }}</dt>
                <dd class="font-medium">{{ wizardData.retention.keepDaily }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.weekly_label') }}</dt>
                <dd class="font-medium">{{ wizardData.retention.keepWeekly }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.monthly_label') }}</dt>
                <dd class="font-medium">{{ wizardData.retention.keepMonthly }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.yearly_label') }}</dt>
                <dd class="font-medium">{{ wizardData.retention.keepYearly }}</dd>
              </dl>
            </div>

            <div>
              <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">{{ $t('backup_wizard.review.schedule_title') }}</h3>
              <div class="space-y-1">
                <p class="text-sm">
                  <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.schedule_type') }}</span>
                  <span class="font-medium">{{ $t('backup_wizard.schedule_types.' + wizardData.scheduleType) }}</span>
                  <span v-if="wizardData.scheduleType !== 'manual'">
                    {{ $t('backup_wizard.review.schedule_at') }} {{ wizardData.scheduleTime }}
                  </span>
                </p>

                <!-- Show selected days for weekly schedule -->
                <div v-if="wizardData.scheduleType === 'weekly' && wizardData.selectedWeekdays.length > 0" class="text-sm">
                  <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.schedule_days') }}</span>
                  <span class="font-medium ml-1">
                    {{ wizardData.selectedWeekdays.map(d => weekDays.value[d-1].short).join(', ') }}
                  </span>
                </div>

                <!-- Show selected days for monthly schedule -->
                <div v-if="wizardData.scheduleType === 'monthly' && wizardData.selectedMonthdays.length > 0" class="text-sm">
                  <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ $t('backup_wizard.review.schedule_days_of_month') }}</span>
                  <span class="font-medium ml-1">
                    {{ wizardData.selectedMonthdays.sort((a, b) => a - b).join(', ') }}
                  </span>
                </div>
              </div>
            </div>
          </div>

          <div class="flex items-center gap-4 p-4 bg-green-50 rounded-lg">
            <input type="checkbox" v-model="wizardData.runTestBackup" id="test-backup" />
            <label for="test-backup" class="text-sm text-gray-700 dark:text-gray-300">
              {{ $t('backup_wizard.review.test_backup_label') }}
            </label>
          </div>
        </div>
      </div>
    </div>

    <!-- Navigation -->
    <div class="flex justify-between mt-8">
      <button
        @click="previousStep"
        :disabled="currentStep === 0"
        class="btn btn-secondary"
      >
        {{ $t('backup_wizard.buttons.previous') }}
      </button>

      <div v-if="steps" class="flex gap-3">
        <button
          v-if="currentStep === steps.length - 1"
          @click="saveAsTemplate"
          class="btn btn-outline"
        >
          {{ $t('backup_wizard.review.save_template') }}
        </button>

        <button
          v-if="currentStep < steps.length - 1"
          @click="nextStep"
          :disabled="!isCurrentStepValid"
          class="btn btn-primary"
        >
          {{ $t('backup_wizard.buttons.next') }}
        </button>

        <button
          v-if="currentStep === steps.length - 1"
          @click="createBackup"
          :disabled="creating"
          class="btn btn-success"
        >
          {{ creating ? $t('backup_wizard.buttons.creating') : $t('backup_wizard.buttons.create') }}
        </button>
      </div>
    </div>

    <!-- Success Modal with Generated Passphrase -->
    <Teleport to="body">
      <div v-if="showSuccessModal" class="fixed inset-0 z-50 overflow-y-auto">
        <!-- Backdrop -->
        <div class="fixed inset-0 bg-black bg-opacity-50 transition-opacity"></div>
        
        <!-- Modal -->
        <div class="flex min-h-full items-center justify-center p-4">
          <div class="relative bg-white rounded-xl shadow-2xl w-full max-w-lg transform transition-all">
            <!-- Header -->
            <div class="bg-gradient-to-r from-green-500 to-green-600 px-6 py-4 rounded-t-xl">
              <h3 class="text-xl font-bold text-white flex items-center">
                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                Backup Configuration Created Successfully!
              </h3>
            </div>
            
            <!-- Body -->
            <div class="p-6 space-y-4">
              <!-- Success Message -->
              <div class="flex items-start space-x-3 p-4 bg-green-50 rounded-lg border border-green-200">
                <svg class="w-5 h-5 text-green-600 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                </svg>
                <div>
                  <p class="text-sm font-medium text-green-800">Your backup configuration has been created successfully.</p>
                  <p class="text-sm text-green-700 mt-1">The repository and backup job are now configured and ready to use.</p>
                </div>
              </div>

              <!-- Generated Passphrase Section -->
              <div v-if="generatedPassphrase" class="space-y-3">
                <div class="flex items-center space-x-2">
                  <svg class="w-5 h-5 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                  </svg>
                  <h4 class="font-semibold text-gray-900 dark:text-gray-100">Repository Encryption Passphrase</h4>
                </div>
                
                <div class="bg-amber-50 border-2 border-amber-200 rounded-lg p-4">
                  <p class="text-sm text-amber-800 font-medium mb-3">
                    ⚠️ IMPORTANT: Save this passphrase securely! It cannot be recovered if lost.
                  </p>
                  
                  <div class="relative">
                    <div class="bg-white rounded-lg border border-amber-300 p-3 pr-12 font-mono text-sm break-all">
                      {{ generatedPassphrase }}
                    </div>
                    <button 
                      @click="copyPassphrase"
                      class="absolute right-2 top-2 p-2 text-amber-600 hover:text-amber-700 hover:bg-amber-100 rounded-lg transition-colors"
                      :title="passphrasecopied ? 'Copied!' : 'Copy to clipboard'"
                    >
                      <svg v-if="!passphrasecopied" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                      </svg>
                      <svg v-else class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                      </svg>
                    </button>
                  </div>
                  
                  <p v-if="passphrasecopied" class="text-xs text-green-600 mt-2 font-medium">
                    ✓ Passphrase copied to clipboard
                  </p>
                </div>

                <div class="bg-blue-50 border border-blue-200 rounded-lg p-3">
                  <p class="text-sm text-blue-800">
                    <strong>Tip:</strong> Store this passphrase in a password manager or secure location. 
                    You will need it to restore backups from this repository.
                  </p>
                </div>
              </div>

              <!-- Configuration Details -->
              <div class="border-t pt-4">
                <h4 class="font-medium text-gray-900 dark:text-gray-100 mb-2">Configuration Details:</h4>
                <dl class="space-y-1 text-sm">
                  <div class="flex justify-between">
                    <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Repository ID:</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">#{{ createdIds.repository_id }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Backup Job ID:</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">#{{ createdIds.job_id }}</dd>
                  </div>
                  <div class="flex justify-between">
                    <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Source ID:</dt>
                    <dd class="font-medium text-gray-900 dark:text-gray-100">#{{ createdIds.source_id }}</dd>
                  </div>
                </dl>
              </div>
            </div>
            
            <!-- Footer -->
            <div class="bg-gray-50 dark:bg-gray-800 px-6 py-4 rounded-b-xl flex items-center justify-between">
              <button 
                @click="goToBackupJobs"
                class="btn btn-secondary"
              >
                View Backup Jobs
              </button>
              <button 
                @click="closeSuccessModal"
                class="btn btn-primary"
              >
                <span v-if="generatedPassphrase && !passphrasecopied">Copy & Close</span>
                <span v-else>Close</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { serverService } from '../services/server'
import { storageService } from '../services/storage'
import { wizardService } from '../services/wizardService'

const router = useRouter()
const { t } = useI18n()

// Wizard steps
const steps = computed(() => [
  { label: t('backup_wizard.steps.server.label'), title: t('backup_wizard.steps.server.title'), description: t('backup_wizard.steps.server.description') },
  { label: t('backup_wizard.steps.type.label'), title: t('backup_wizard.steps.type.title'), description: t('backup_wizard.steps.type.description') },
  { label: t('backup_wizard.steps.source.label'), title: t('backup_wizard.steps.source.title'), description: t('backup_wizard.steps.source.description') },
  { label: t('backup_wizard.steps.storage.label'), title: t('backup_wizard.steps.storage.title'), description: t('backup_wizard.steps.storage.description') },
  { label: t('backup_wizard.steps.options.label'), title: t('backup_wizard.steps.options.title'), description: t('backup_wizard.steps.options.description') },
  { label: t('backup_wizard.steps.retention.label'), title: t('backup_wizard.steps.retention.title'), description: t('backup_wizard.steps.retention.description') },
  { label: t('backup_wizard.steps.schedule.label'), title: t('backup_wizard.steps.schedule.title'), description: t('backup_wizard.steps.schedule.description') },
  { label: t('backup_wizard.steps.review.label'), title: t('backup_wizard.steps.review.title'), description: t('backup_wizard.steps.review.description') }
])

const currentStep = ref(0)
const creating = ref(false)
const showSuccessModal = ref(false)
const generatedPassphrase = ref('')
const passphrasecopied = ref(false)
const createdIds = ref({
  source_id: null,
  repository_id: null,
  job_id: null
})

// Data
const servers = ref([])
const storagePools = ref([])
const serverCapabilities = ref(null)
const detectingCapabilities = ref(false)

// Custom exclusions
const newExclusionPattern = ref('')
const customExclusions = ref([])

// Wizard data
const wizardData = ref({
  serverId: null,
  backupType: '',
  sourceConfig: {
    // Files
    paths: ['/'],
    excludePatterns: '',
    // Database
    host: 'localhost',
    port: 3306,
    username: '',
    password: '',
    databaseSelection: 'all',
    databases: '',
    singleTransaction: true,
    routines: true,
    triggers: true,
    events: true,
    // System backup options
    oneFileSystem: true,
    preservePermissions: true,
    preserveTimestamps: true,
    followSymlinks: false,
    // Exclusion toggles
    excludeDocker: true,
    excludeDatabaseData: true,
    excludeVMs: true,
    excludeLogs: true,
    excludeCaches: true,
    excludeDownloads: false,
    excludeBuildArtifacts: false,
    // Docker backup options
    backupAllVolumes: true,
    selectedComposeProjects: [],
    selectedVolumes: [],
    backupDockerConfig: true,
    backupCustomNetworks: false
  },
  snapshotMethod: 'none',
  storagePoolId: null,
  repositoryName: '',
  encryption: 'repokey-blake2',
  compression: 'lz4',
  passphrase: '',
  retention: {
    keepDaily: 7,
    keepWeekly: 4,
    keepMonthly: 6,
    keepYearly: 1
  },
  scheduleType: 'daily',
  scheduleTime: '02:00',
  selectedWeekdays: [1],
  selectedMonthdays: [1],
  runTestBackup: false
})

// Helper function to get detected database from capabilities
function getDetectedDatabase(dbType) {
  if (!serverCapabilities.value?.capabilities?.databases) return null

  const db = serverCapabilities.value.capabilities.databases.find(d => d.type === dbType)
  return db || null
}

// Helper function to get detected Docker environment from capabilities
function getDetectedDocker() {
  if (!serverCapabilities.value?.capabilities?.docker) return null
  return serverCapabilities.value.capabilities.docker
}

// Helper function to check if a database type is snapshot-capable
function isDatabaseSnapshotCapable(dbType) {
  if (!serverCapabilities.value?.capabilities?.databases) return { capable: true, reason: null }

  const db = serverCapabilities.value.capabilities.databases.find(d => d.type === dbType)

  if (!db) {
    return { capable: false, reason: 'Database not detected on this server' }
  }

  if (!db.running) {
    return { capable: false, reason: 'Database is not running' }
  }

  if (!db.snapshot_capable) {
    return { capable: false, reason: 'Database is not on a snapshot-capable volume (LVM/Btrfs/ZFS required)' }
  }

  return { capable: true, reason: null }
}

// Backup types
const backupTypes = computed(() => {
  const types = [
    { id: 'files', name: t('backup_wizard.backup_types.files.name'), icon: '📁', description: t('backup_wizard.backup_types.files.description'), disabled: false },
    { id: 'mysql', name: t('backup_wizard.backup_types.mysql.name'), icon: '🗄️', description: t('backup_wizard.backup_types.mysql.description'), disabled: false },
    { id: 'postgresql', name: t('backup_wizard.backup_types.postgresql.name'), icon: '🐘', description: t('backup_wizard.backup_types.postgresql.description'), disabled: false },
    { id: 'mongodb', name: t('backup_wizard.backup_types.mongodb.name'), icon: '🍃', description: t('backup_wizard.backup_types.mongodb.description'), disabled: false },
    { id: 'docker', name: t('backup_wizard.backup_types.docker.name'), icon: '🐳', description: t('backup_wizard.backup_types.docker.description'), disabled: false },
    { id: 'system', name: t('backup_wizard.backup_types.system.name'), icon: '💾', description: t('backup_wizard.backup_types.system.description'), disabled: false }
  ]

  // Check database capabilities if server is selected
  if (serverCapabilities.value?.capabilities_detected) {
    const mysqlCheck = isDatabaseSnapshotCapable('mysql')
    const mysqlType = types.find(t => t.id === 'mysql')
    if (mysqlType) {
      mysqlType.disabled = !mysqlCheck.capable
      mysqlType.disabledReason = mysqlCheck.reason
    }

    const postgresqlCheck = isDatabaseSnapshotCapable('postgresql')
    const postgresqlType = types.find(t => t.id === 'postgresql')
    if (postgresqlType) {
      postgresqlType.disabled = !postgresqlCheck.capable
      postgresqlType.disabledReason = postgresqlCheck.reason
    }

    const mongodbCheck = isDatabaseSnapshotCapable('mongodb')
    const mongodbType = types.find(t => t.id === 'mongodb')
    if (mongodbType) {
      mongodbType.disabled = !mongodbCheck.capable
      mongodbType.disabledReason = mongodbCheck.reason
    }
  }

  return types
})

// Week days
const weekDays = computed(() => [
  { short: t('backup_wizard.schedule_step.weekdays.mon.short'), full: t('backup_wizard.schedule_step.weekdays.mon.full') },
  { short: t('backup_wizard.schedule_step.weekdays.tue.short'), full: t('backup_wizard.schedule_step.weekdays.tue.full') },
  { short: t('backup_wizard.schedule_step.weekdays.wed.short'), full: t('backup_wizard.schedule_step.weekdays.wed.full') },
  { short: t('backup_wizard.schedule_step.weekdays.thu.short'), full: t('backup_wizard.schedule_step.weekdays.thu.full') },
  { short: t('backup_wizard.schedule_step.weekdays.fri.short'), full: t('backup_wizard.schedule_step.weekdays.fri.full') },
  { short: t('backup_wizard.schedule_step.weekdays.sat.short'), full: t('backup_wizard.schedule_step.weekdays.sat.full') },
  { short: t('backup_wizard.schedule_step.weekdays.sun.short'), full: t('backup_wizard.schedule_step.weekdays.sun.full') }
])

// Quick exclusion patterns
const quickExclusionPatterns = [
  { label: '*.bak', value: '*.bak', description: 'All backup files' },
  { label: '*.old', value: '*.old', description: 'All old files' },
  { label: '~*', value: '~*', description: 'Temporary editor files' },
  { label: '.git/', value: '*/.git/*', description: 'Git repositories' },
  { label: '.svn/', value: '*/.svn/*', description: 'SVN repositories' },
  { label: 'thumbs.db', value: '**/Thumbs.db', description: 'Windows thumbnail cache' },
  { label: '.DS_Store', value: '**/.DS_Store', description: 'macOS metadata files' },
  { label: 'lost+found', value: '*/lost+found/*', description: 'Filesystem recovery directories' },
  { label: 'core dumps', value: '*/core.*', description: 'Core dump files' },
  { label: '.idea/', value: '*/.idea/*', description: 'IntelliJ IDEA project files' },
  { label: '.vscode/', value: '*/.vscode/*', description: 'VS Code project files' },
  { label: '*.iso', value: '*.iso', description: 'ISO image files' },
  { label: '*.ova', value: '*.ova', description: 'Virtual appliance files' }
]

// Computed
const selectedServer = computed(() => 
  servers.value.find(s => s.id === wizardData.value.serverId)
)

const selectedPool = computed(() => 
  storagePools.value.find(p => p.id === wizardData.value.storagePoolId)
)

const totalRetentionPeriods = computed(() => {
  return (wizardData.value.retention.keepDaily > 0 ? 1 : 0) +
         (wizardData.value.retention.keepWeekly > 0 ? 1 : 0) +
         (wizardData.value.retention.keepMonthly > 0 ? 1 : 0) +
         (wizardData.value.retention.keepYearly > 0 ? 1 : 0)
})

const isCurrentStepValid = computed(() => {
  switch (currentStep.value) {
    case 0: return !!wizardData.value.serverId
    case 1: return !!wizardData.value.backupType
    case 2: return validateSourceConfig()
    case 3: return true // Snapshot is optional
    case 4: return !!wizardData.value.storagePoolId
    case 5: return !!wizardData.value.repositoryName // Passphrase is now optional (auto-generated if not provided)
    case 6: return totalRetentionPeriods.value > 0 // At least one retention value must be set
    case 7: return true // Schedule is valid
    default: return true
  }
})

// Methods
function validateSourceConfig() {
  const config = wizardData.value.sourceConfig
  const type = wizardData.value.backupType
  
  if (type === 'files') {
    return config.paths.length > 0 && config.paths.every(p => p.trim())
  } else if (type === 'mysql' || type === 'mariadb') {
    return config.username && (config.databaseSelection === 'all' || config.databases)
  }
  
  return true
}

function goToStep(step) {
  if (step < currentStep.value) {
    currentStep.value = step
  }
}

function previousStep() {
  if (currentStep.value > 0) {
    currentStep.value--
    
    // Skip snapshot step when going back for file and system backups
    if (currentStep.value === 3 && ['files', 'system'].includes(wizardData.value.backupType)) {
      currentStep.value-- // Skip back to source config step
    }
  }
}

function nextStep() {
  if (currentStep.value < steps.value.length - 1 && isCurrentStepValid.value) {
    currentStep.value++
  }
}

function addPath() {
  wizardData.value.sourceConfig.paths.push('')
}

function removePath(index) {
  wizardData.value.sourceConfig.paths.splice(index, 1)
}

function addCustomExclusion() {
  const pattern = newExclusionPattern.value.trim()
  if (pattern && !customExclusions.value.includes(pattern)) {
    customExclusions.value.push(pattern)
    newExclusionPattern.value = ''
    updateCustomExclusions()
  }
}

function removeCustomExclusion(index) {
  customExclusions.value.splice(index, 1)
  updateCustomExclusions()
}

function updateCustomExclusions() {
  if (wizardData.value.backupType === 'system') {
    buildSystemExclusions()
  }
}

async function onServerChange() {
  // Reset dependent fields
  serverCapabilities.value = null

  // Load server capabilities
  if (wizardData.value.serverId) {
    try {
      const caps = await serverService.getCapabilities(wizardData.value.serverId)
      serverCapabilities.value = caps
    } catch (error) {
      console.error('Failed to load server capabilities:', error)
    }
  }
}

async function reloadCapabilities() {
  if (!wizardData.value.serverId) return

  try {
    detectingCapabilities.value = true

    // Trigger detection job
    const response = await serverService.detectCapabilities(wizardData.value.serverId)
    const jobId = response.job_id

    // Poll for job completion
    const maxAttempts = 30 // 30 seconds max
    let attempts = 0

    while (attempts < maxAttempts) {
      await new Promise(resolve => setTimeout(resolve, 1000))

      const caps = await serverService.getCapabilities(wizardData.value.serverId)

      // Check if detection is complete (capabilities_detected should be true after job completes)
      if (caps.capabilities_detected) {
        serverCapabilities.value = caps
        break
      }

      attempts++
    }

    if (attempts >= maxAttempts) {
      console.error('Capabilities detection timed out')
      alert('Capabilities detection timed out. Please try again.')
    }
  } catch (error) {
    console.error('Failed to reload capabilities:', error)
    alert('Failed to reload capabilities: ' + (error.response?.data?.error || error.message))
  } finally {
    detectingCapabilities.value = false
  }
}

// Set default exclusions for system backup
watch(() => wizardData.value.backupType, (newType) => {
  if (newType === 'system') {
    // Set root path for system backup
    wizardData.value.sourceConfig.paths = ['/']
    // Build exclusions will be done dynamically based on checkboxes
    buildSystemExclusions()
  }
})

// Build system exclusions based on checkboxes
function buildSystemExclusions() {
  const exclusions = [
    // Always excluded (critical system directories)
    '/proc/*',
    '/sys/*',
    '/dev/*',
    '/run/*',
    '/tmp/*',
    '/var/tmp/*',
    '/mnt/*',
    '/media/*',
    '/swapfile',
    '*.swp',
    '*.tmp',
    '*~'
  ]
  
  // Add optional exclusions based on checkboxes
  if (wizardData.value.sourceConfig.excludeDocker) {
    exclusions.push(
      '/var/lib/docker/*',
      '/var/lib/containerd/*',
      '/var/lib/lxc/*'
    )
  }
  
  if (wizardData.value.sourceConfig.excludeDatabaseData) {
    exclusions.push(
      '/var/lib/mysql/*',
      '/var/lib/postgresql/*',
      '/var/lib/mongodb/*',
      '/var/lib/redis/*',
      '/var/lib/elasticsearch/*'
    )
  }
  
  if (wizardData.value.sourceConfig.excludeVMs) {
    exclusions.push(
      '/var/lib/libvirt/*',
      '*.qcow2',
      '*.vmdk',
      '*.vdi',
      '/var/lib/vz/*'
    )
  }
  
  if (wizardData.value.sourceConfig.excludeLogs) {
    exclusions.push(
      '/var/log/*',
      '*.log',
      '*.log.*',
      '/var/spool/mail/*'
    )
  }
  
  if (wizardData.value.sourceConfig.excludeCaches) {
    exclusions.push(
      '/var/cache/*',
      '*/.cache/*',
      '/var/lib/apt/lists/*',
      '/var/cache/apt/*',
      '/var/cache/yum/*',
      '*/node_modules/*',
      '*/__pycache__/*',
      '*/.npm/*'
    )
  }
  
  if (wizardData.value.sourceConfig.excludeDownloads) {
    exclusions.push(
      '*/Downloads/*',
      '*/.Trash/*',
      '*/Trash/*',
      '*/.local/share/Trash/*'
    )
  }
  
  if (wizardData.value.sourceConfig.excludeBuildArtifacts) {
    exclusions.push(
      '*/target/*',
      '*/dist/*',
      '*/build/*',
      '*/.gradle/*',
      '*/.m2/*',
      '*/vendor/*',
      '*/.cargo/*',
      '*/out/*'
    )
  }
  
  // Add custom exclusions
  if (customExclusions.value.length > 0) {
    exclusions.push(...customExclusions.value)
  }
  
  wizardData.value.sourceConfig.excludePatterns = exclusions.join('\n')
}

// Watch for changes in exclusion checkboxes
watch(() => [
  wizardData.value.sourceConfig.excludeDocker,
  wizardData.value.sourceConfig.excludeDatabaseData,
  wizardData.value.sourceConfig.excludeVMs,
  wizardData.value.sourceConfig.excludeLogs,
  wizardData.value.sourceConfig.excludeCaches,
  wizardData.value.sourceConfig.excludeDownloads,
  wizardData.value.sourceConfig.excludeBuildArtifacts
], () => {
  if (wizardData.value.backupType === 'system') {
    buildSystemExclusions()
  }
})

async function detectMySQLCredentials() {
  // Get MySQL capabilities auth data
  const mysqlDb = getDetectedDatabase('mysql')

  if (!mysqlDb || !mysqlDb.auth) {
    alert('No MySQL authentication detected. Please run "Detect Capabilities" first.')
    return
  }

  const auth = mysqlDb.auth

  if (!auth.working) {
    alert('No working MySQL credentials detected. Please configure manually.')
    return
  }

  // Auto-fill credentials from detected auth
  wizardData.value.sourceConfig.username = auth.user || 'root'
  wizardData.value.sourceConfig.password = auth.password || ''
  wizardData.value.sourceConfig.host = auth.host || 'localhost'
  wizardData.value.sourceConfig.port = auth.port || 3306

  // Show success message with method used
  const methodText = auth.method === 'root_no_password'
    ? 'root without password'
    : auth.method === 'debian_cnf'
    ? 'debian-sys-maint from /etc/mysql/debian.cnf'
    : 'detected credentials'

  alert(`✓ Credentials auto-filled using: ${methodText}`)
}

async function saveAsTemplate() {
  // TODO: Implement template saving
  console.log('Saving as template...')
}

async function createBackup() {
  creating.value = true
  try {
    // Prepare source_config according to backup type
    const sourceConfig = { ...wizardData.value.sourceConfig }
    const dbInfo = getDetectedDatabase(wizardData.value.backupType)

    // Fix PostgreSQL port and cluster info
    if (wizardData.value.backupType === 'postgresql' || wizardData.value.backupType === 'postgres') {
      // Get port from selected cluster or auto-detected cluster
      let clusterPort = 5432 // Default fallback

      if (dbInfo?.auth?.clusters?.length > 0) {
        // If user selected a specific cluster
        if (wizardData.value.sourceConfig.pg_cluster) {
          const selectedCluster = dbInfo.auth.clusters.find(c =>
            `${c.version}/${c.cluster}` === wizardData.value.sourceConfig.pg_cluster
          )
          if (selectedCluster) {
            clusterPort = parseInt(selectedCluster.port)
            sourceConfig.pg_cluster = wizardData.value.sourceConfig.pg_cluster
          }
        } else {
          // Auto-detect from first/only cluster
          clusterPort = parseInt(dbInfo.auth.clusters[0].port)
          sourceConfig.pg_cluster = `${dbInfo.auth.clusters[0].version}/${dbInfo.auth.clusters[0].cluster}`
        }
      }

      sourceConfig.port = clusterPort

      // Use peer auth if detected
      if (dbInfo?.auth?.peer_auth) {
        sourceConfig.username = 'postgres'
        sourceConfig.password = ''
        sourceConfig.use_peer_auth = true
      }
    }

    // MySQL/MariaDB port fix
    if (wizardData.value.backupType === 'mysql' || wizardData.value.backupType === 'mariadb') {
      sourceConfig.port = sourceConfig.port || 3306
    }

    // MongoDB port fix
    if (wizardData.value.backupType === 'mongodb') {
      sourceConfig.port = sourceConfig.port === 3306 ? 27017 : sourceConfig.port
    }

    // Prepare data for API
    const data = {
      server_id: wizardData.value.serverId,
      backup_type: wizardData.value.backupType,
      source_name: wizardData.value.repositoryName,
      source_config: sourceConfig,
      paths: wizardData.value.sourceConfig.paths,
      exclude_patterns: wizardData.value.sourceConfig.excludePatterns?.split('\n').filter(p => p.trim()),
      snapshot_method: wizardData.value.snapshotMethod,
      storage_pool_id: wizardData.value.storagePoolId,
      repository_name: wizardData.value.repositoryName,
      encryption: wizardData.value.encryption,
      passphrase: wizardData.value.passphrase,
      compression: wizardData.value.compression,
      retention: {
        keep_daily: wizardData.value.retention.keepDaily,
        keep_weekly: wizardData.value.retention.keepWeekly,
        keep_monthly: wizardData.value.retention.keepMonthly,
        keep_yearly: wizardData.value.retention.keepYearly
      },
      schedule_type: wizardData.value.scheduleType,
      schedule_time: wizardData.value.scheduleTime,
      weekdays_array: wizardData.value.selectedWeekdays,
      monthdays_array: wizardData.value.selectedMonthdays,
      run_test_backup: wizardData.value.runTestBackup,
      initialize_repo: true,
      enabled: true,
      notify_on_success: false,
      notify_on_failure: true
    }
    
    const response = await wizardService.createBackupChain(data)
    
    // Store the created IDs and passphrase
    if (response.data?.data) {
      createdIds.value = {
        source_id: response.data.data.source_id,
        repository_id: response.data.data.repository_id,
        job_id: response.data.data.job_id
      }
      
      if (response.data.data.generated_passphrase) {
        generatedPassphrase.value = response.data.data.generated_passphrase
      }
    }
    
    // Show success modal
    showSuccessModal.value = true
  } catch (error) {
    console.error('Failed to create backup:', error)
    alert('Failed to create backup configuration: ' + (error.response?.data?.error || error.message))
  } finally {
    creating.value = false
  }
}

function formatBytes(bytes) {
  if (!bytes) return '0 B'
  const k = 1024
  const sizes = ['B', 'KB', 'MB', 'GB', 'TB']
  const i = Math.floor(Math.log(bytes) / Math.log(k))
  return Math.round(bytes / Math.pow(k, i) * 100) / 100 + ' ' + sizes[i]
}

function copyPassphrase() {
  if (!generatedPassphrase.value) return
  
  // Check if clipboard API is available (requires HTTPS)
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(generatedPassphrase.value).then(() => {
      passphrasecopied.value = true
      // Reset after 3 seconds
      setTimeout(() => {
        passphrasecopied.value = false
      }, 3000)
    }).catch(err => {
      console.error('Clipboard API failed:', err)
      // Use fallback method
      copyUsingFallback()
    })
  } else {
    // Use fallback method for HTTP or unsupported browsers
    copyUsingFallback()
  }
}

function copyUsingFallback() {
  const textArea = document.createElement('textarea')
  textArea.value = generatedPassphrase.value
  textArea.style.position = 'fixed'
  textArea.style.left = '-999999px'
  textArea.style.top = '0'
  document.body.appendChild(textArea)
  textArea.focus()
  textArea.select()
  
  try {
    const successful = document.execCommand('copy')
    if (successful) {
      passphrasecopied.value = true
      setTimeout(() => {
        passphrasecopied.value = false
      }, 3000)
    } else {
      console.error('Copy command failed')
      alert('Could not copy passphrase. Please copy it manually:\n\n' + generatedPassphrase.value)
    }
  } catch (err) {
    console.error('Fallback copy failed:', err)
    alert('Could not copy passphrase. Please copy it manually:\n\n' + generatedPassphrase.value)
  }
  
  document.body.removeChild(textArea)
}

function closeSuccessModal() {
  // Copy passphrase if not already copied
  if (generatedPassphrase.value && !passphrasecopied.value) {
    copyPassphrase()
  }
  
  showSuccessModal.value = false
  // Navigate to backup jobs page
  router.push('/backup-jobs')
}

function goToBackupJobs() {
  showSuccessModal.value = false
  router.push('/backup-jobs')
}

// Lifecycle
onMounted(async () => {
  // Load servers and storage pools
  try {
    const [serversData, poolsData] = await Promise.all([
      serverService.getServers(),
      storageService.getStoragePools()
    ])
    
    // Handle the response - it might be an object with servers property or an array
    if (Array.isArray(serversData)) {
      servers.value = serversData
    } else if (serversData?.servers) {
      servers.value = serversData.servers
    } else {
      servers.value = []
    }
    
    // Handle storage pools - ensure it's an array
    if (Array.isArray(poolsData)) {
      storagePools.value = poolsData
    } else if (poolsData?.storage_pools) {
      storagePools.value = poolsData.storage_pools
    } else if (poolsData?.pools) {
      storagePools.value = poolsData.pools
    } else {
      storagePools.value = []
    }
    
    // Auto-select default pool
    if (storagePools.value.length > 0) {
      const defaultPool = storagePools.value.find(p => p.default_pool)
      if (defaultPool) {
        wizardData.value.storagePoolId = defaultPool.id
      }
    }
  } catch (error) {
    console.error('Failed to load data:', error)
  }
})
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

<style scoped>
.wizard-progress {
  @apply relative;
}

.wizard-content {
  min-height: 400px;
}
</style>