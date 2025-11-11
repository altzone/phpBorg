<template>
  <div class="backup-wizard">
    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900 dark:text-gray-100">Backup Configuration Wizard</h1>
      <p class="mt-2 text-gray-600 dark:text-gray-400 dark:text-gray-500">Step-by-step configuration for professional backup setup</p>
    </div>

    <!-- Progress Bar -->
    <div class="wizard-progress mb-8">
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
      <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-900 dark:text-gray-100">{{ steps[currentStep].title }}</h2>
        <p class="text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">{{ steps[currentStep].description }}</p>
      </div>

      <div class="wizard-content">
        <!-- Step 1: Server Selection -->
        <div v-if="currentStep === 0" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select Server</label>
            <select v-model="wizardData.serverId" class="input w-full" @change="onServerChange">
              <option value="">-- Select a server --</option>
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
                <h3 class="text-sm font-medium text-blue-800">Server Details</h3>
                <div class="mt-2 text-sm text-blue-700">
                  <p>Host: {{ selectedServer?.hostname || selectedServer?.host }}</p>
                  <p>SSH Port: {{ selectedServer?.port }}</p>
                  <p v-if="selectedServer?.description">{{ selectedServer.description }}</p>
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
              class="relative flex flex-col items-center p-4 border-2 rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 dark:bg-gray-800 transition-colors"
              :class="{
                'border-primary-500 bg-primary-50': wizardData.backupType === type.id,
                'border-gray-200 dark:border-gray-700': wizardData.backupType !== type.id
              }"
            >
              <input 
                type="radio" 
                :value="type.id" 
                v-model="wizardData.backupType"
                class="sr-only"
              />
              <div class="text-3xl mb-2">{{ type.icon }}</div>
              <div class="text-center">
                <div class="font-semibold text-gray-900 dark:text-gray-100">{{ type.name }}</div>
                <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">{{ type.description }}</div>
              </div>
              <div v-if="wizardData.backupType === type.id" 
                   class="absolute top-2 right-2 w-5 h-5 bg-primary-600 rounded-full flex items-center justify-center">
                <svg class="w-3 h-3 text-white" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
                </svg>
              </div>
            </label>
          </div>
        </div>

        <!-- Step 3: Source Configuration -->
        <div v-else-if="currentStep === 2" class="space-y-4">
          <!-- Files Configuration -->
          <div v-if="wizardData.backupType === 'files'">
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Paths to Backup</label>
              <div class="space-y-2">
                <div v-for="(path, index) in wizardData.sourceConfig.paths" :key="index" class="flex gap-2">
                  <input 
                    v-model="wizardData.sourceConfig.paths[index]" 
                    type="text" 
                    class="input flex-1"
                    placeholder="/path/to/backup"
                  />
                  <button @click="removePath(index)" class="btn btn-secondary">Remove</button>
                </div>
                <button @click="addPath" class="btn btn-primary btn-sm">+ Add Path</button>
              </div>
            </div>
            
            <div>
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Exclude Patterns (optional)</label>
              <textarea 
                v-model="wizardData.sourceConfig.excludePatterns" 
                rows="3" 
                class="input w-full"
                placeholder="*.log&#10;/tmp/*&#10;cache/"
              ></textarea>
              <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">One pattern per line</p>
            </div>
          </div>

          <!-- MySQL/MariaDB Configuration -->
          <div v-else-if="wizardData.backupType === 'mysql' || wizardData.backupType === 'mariadb'">
            <div class="space-y-4">
              <div>
                <label class="flex items-center justify-between mb-2">
                  <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Database Credentials</span>
                  <button @click="detectMySQLCredentials" class="text-sm text-primary-600 hover:text-primary-700">
                    Auto-detect
                  </button>
                </label>
                
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">Host</label>
                    <input v-model="wizardData.sourceConfig.host" type="text" class="input w-full" placeholder="localhost" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">Port</label>
                    <input v-model="wizardData.sourceConfig.port" type="number" class="input w-full" placeholder="3306" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">Username</label>
                    <input v-model="wizardData.sourceConfig.username" type="text" class="input w-full" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-1">Password</label>
                    <input v-model="wizardData.sourceConfig.password" type="password" class="input w-full" />
                  </div>
                </div>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Databases to Backup</label>
                <div class="flex items-center gap-4">
                  <label class="flex items-center">
                    <input type="radio" v-model="wizardData.sourceConfig.databaseSelection" value="all" class="mr-2" />
                    All databases
                  </label>
                  <label class="flex items-center">
                    <input type="radio" v-model="wizardData.sourceConfig.databaseSelection" value="specific" class="mr-2" />
                    Specific databases
                  </label>
                </div>
                
                <div v-if="wizardData.sourceConfig.databaseSelection === 'specific'" class="mt-2">
                  <input 
                    v-model="wizardData.sourceConfig.databases" 
                    type="text" 
                    class="input w-full" 
                    placeholder="db1, db2, db3"
                  />
                  <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">Comma-separated list of database names</p>
                </div>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Backup Options</label>
                <div class="space-y-2">
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.singleTransaction" class="mr-2" />
                    Use single transaction (recommended for InnoDB)
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.routines" class="mr-2" />
                    Include stored procedures and functions
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.triggers" class="mr-2" />
                    Include triggers
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.events" class="mr-2" />
                    Include scheduled events
                  </label>
                </div>
              </div>
            </div>
          </div>

          <!-- PostgreSQL Configuration -->
          <div v-else-if="wizardData.backupType === 'postgresql'">
            <!-- Similar to MySQL but with PostgreSQL-specific options -->
            <p class="text-gray-600 dark:text-gray-400 dark:text-gray-500">PostgreSQL configuration...</p>
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
                    <h3 class="text-sm font-medium text-blue-800">Full System Backup</h3>
                    <p class="mt-1 text-sm text-blue-700">
                      This will create a complete backup of the system starting from root (/) with intelligent exclusions for temporary and system files.
                    </p>
                  </div>
                </div>
              </div>

              <!-- Backup Scope -->
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Backup Scope</label>
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
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Standard Exclusions (Always Applied)</label>
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
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Optional Exclusions</label>
                <div class="space-y-3">
                  <!-- Docker -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeDocker" class="mt-1 mr-3" checked />
                      <div class="flex-1">
                        <div class="font-medium text-sm">Docker & Container Data</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Excludes: /var/lib/docker/*, /var/lib/containerd/*, /var/lib/lxc/*
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Container data should be backed up using container-specific tools for consistency.
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Database Data -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeDatabaseData" class="mt-1 mr-3" checked />
                      <div class="flex-1">
                        <div class="font-medium text-sm">Database Data Files</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Excludes: /var/lib/mysql/*, /var/lib/postgresql/*, /var/lib/mongodb/*
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Database files should be backed up using dumps for consistency. Use dedicated database backup instead.
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Virtual Machines -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeVMs" class="mt-1 mr-3" checked />
                      <div class="flex-1">
                        <div class="font-medium text-sm">Virtual Machine Images</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Excludes: /var/lib/libvirt/*, *.qcow2, *.vmdk, *.vdi, /var/lib/vz/*
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          VM images are large and should be backed up while powered off or using VM snapshots.
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Logs -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeLogs" class="mt-1 mr-3" checked />
                      <div class="flex-1">
                        <div class="font-medium text-sm">Log Files</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Excludes: /var/log/*, *.log, *.log.*, /var/spool/mail/*
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Logs can be large and are usually not needed for system restoration.
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Caches -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeCaches" class="mt-1 mr-3" checked />
                      <div class="flex-1">
                        <div class="font-medium text-sm">Cache Directories</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Excludes: /var/cache/*, */.cache/*, /var/lib/apt/lists/*, */node_modules/*
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Caches can be regenerated and excluding them saves significant space.
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Downloads & Trash -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeDownloads" class="mt-1 mr-3" />
                      <div class="flex-1">
                        <div class="font-medium text-sm">Downloads & Trash</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Excludes: */Downloads/*, */.Trash/*, */Trash/*, */.local/share/Trash/*
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Temporary user files that are typically not important for backup.
                        </div>
                      </div>
                    </label>
                  </div>

                  <!-- Build Artifacts -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeBuildArtifacts" class="mt-1 mr-3" />
                      <div class="flex-1">
                        <div class="font-medium text-sm">Build Artifacts & Dependencies</div>
                        <div class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Excludes: */target/*, */dist/*, */build/*, */.gradle/*, */.m2/*, */vendor/*
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                          Development build outputs and downloaded dependencies that can be regenerated.
                        </div>
                      </div>
                    </label>
                  </div>
                </div>
              </div>

              <!-- Custom Exclusions -->
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Additional Exclusions (Optional)</label>
                
                <!-- Input to add new exclusions -->
                <div class="flex gap-2 mb-3">
                  <div class="relative flex-1">
                    <input 
                      v-model="newExclusionPattern"
                      @keydown.enter="addCustomExclusion"
                      type="text" 
                      class="input w-full pr-10"
                      placeholder="Enter path or pattern (e.g., /backup/*, *.bak, /var/www/*/cache/)"
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
                    Add Exclusion
                  </button>
                </div>

                <!-- Quick add buttons for common patterns -->
                <div class="mb-3">
                  <p class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500 mb-2">Quick add:</p>
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
                        title="Remove"
                      >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                      </button>
                    </div>
                  </div>
                  <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 dark:text-gray-500 dark:text-gray-400 mt-2">{{ customExclusions.length }} custom exclusion{{ customExclusions.length !== 1 ? 's' : '' }} added</p>
                </div>
                <div v-else class="text-sm text-gray-500 dark:text-gray-400 dark:text-gray-500 italic">
                  No custom exclusions added yet
                </div>
                
                <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-2">
                  Use * for wildcards, ** for recursive matching. Examples: *.bak, /home/*/.config/**, /var/www/*/temp/
                </p>
              </div>

              <!-- Backup Options -->
              <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Options</label>
                <div class="space-y-2">
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.oneFileSystem" class="mr-2" checked />
                    <span class="text-sm">Stay on same filesystem (don't cross mount points)</span>
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.preservePermissions" class="mr-2" checked />
                    <span class="text-sm">Preserve file permissions and ownership</span>
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.preserveTimestamps" class="mr-2" checked />
                    <span class="text-sm">Preserve file timestamps</span>
                  </label>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="wizardData.sourceConfig.followSymlinks" class="mr-2" />
                    <span class="text-sm">Follow symbolic links</span>
                  </label>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 4: Snapshot Strategy -->
        <div v-else-if="currentStep === 3" class="space-y-4">
          <!-- Show different content based on backup type -->
          <div v-if="!needsSnapshot()" class="p-6 bg-gray-50 dark:bg-gray-800 rounded-lg text-center">
            <svg class="w-12 h-12 text-gray-400 dark:text-gray-500 mx-auto mb-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">Snapshot Not Required</h3>
            <p class="text-gray-600 dark:text-gray-400 dark:text-gray-500 max-w-md mx-auto">
              File and folder backups don't require filesystem snapshots. Borg will handle file consistency during the backup process.
            </p>
            <p class="text-sm text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-4">
              This step is automatically skipped for file-based backups.
            </p>
          </div>
          
          <div v-else-if="loadingCapabilities" class="text-center py-8">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-gray-200 dark:border-gray-700 border-t-primary-600"></div>
            <p class="mt-2 text-gray-600 dark:text-gray-400 dark:text-gray-500">Detecting snapshot capabilities on {{ selectedServer?.name }}...</p>
            <p v-if="progressMessage" class="mt-2 text-sm text-gray-500 dark:text-gray-400 dark:text-gray-500">{{ progressMessage }}</p>
          </div>
          
          <div v-else>
            <div v-if="snapshotCapabilities.length > 0">
              <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Available Snapshot Methods</label>
              <div class="space-y-2">
                <label 
                  v-for="cap in snapshotCapabilities" 
                  :key="cap.type"
                  class="flex items-start p-3 border rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 dark:bg-gray-800"
                  :class="{ 'border-primary-500 bg-primary-50': wizardData.snapshotMethod === cap.type }"
                >
                  <input 
                    type="radio" 
                    :value="cap.type" 
                    v-model="wizardData.snapshotMethod"
                    class="mt-1"
                  />
                  <div class="ml-3 flex-1">
                    <div class="font-medium">{{ cap.name }}</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ cap.description }}</div>
                    <div v-if="cap.details" class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
                      {{ cap.details }}
                    </div>
                  </div>
                </label>
                
                <label class="flex items-start p-3 border rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 dark:bg-gray-800"
                       :class="{ 'border-primary-500 bg-primary-50': wizardData.snapshotMethod === 'none' }">
                  <input type="radio" value="none" v-model="wizardData.snapshotMethod" class="mt-1" />
                  <div class="ml-3">
                    <div class="font-medium">No Snapshot</div>
                    <div class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500">Proceed without filesystem snapshot</div>
                  </div>
                </label>
              </div>
            </div>
            
            <div v-else class="space-y-4">
              <!-- Warning for database backups without snapshots -->
              <div v-if="['mysql', 'mariadb', 'postgresql', 'mongodb'].includes(wizardData.backupType)" class="p-4 bg-yellow-50 rounded-lg">
                <div class="flex items-start">
                  <svg class="w-5 h-5 text-yellow-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                  </svg>
                  <div class="ml-3">
                    <h3 class="text-sm font-medium text-yellow-800">No Snapshot Methods Available</h3>
                    <p class="mt-1 text-sm text-yellow-700">
                      No snapshot capabilities detected on this server. For database backups, snapshots ensure data consistency.
                    </p>
                    <div class="mt-2 text-sm text-yellow-700">
                      <strong>Recommendations:</strong>
                      <ul class="list-disc list-inside mt-1">
                        <li>Install LVM for logical volume snapshots</li>
                        <li>Use ZFS or Btrfs filesystems for built-in snapshot support</li>
                        <li>Consider using database dumps instead for consistency</li>
                      </ul>
                    </div>
                  </div>
                </div>
              </div>
              
              <!-- Info for system backups without snapshots -->
              <div v-else class="p-4 bg-blue-50 rounded-lg">
                <div class="flex items-start">
                  <svg class="w-5 h-5 text-blue-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0118 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd" />
                  </svg>
                  <div class="ml-3">
                    <h3 class="text-sm font-medium text-blue-800">Proceeding Without Snapshots</h3>
                    <p class="mt-1 text-sm text-blue-700">
                      System backup will proceed without filesystem snapshots. Files may change during backup, but Borg will handle most consistency issues.
                    </p>
                  </div>
                </div>
              </div>
              
              <!-- Option to proceed without snapshot -->
              <label class="flex items-start p-3 border rounded-lg cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 dark:bg-gray-800 border-primary-500 bg-primary-50">
                <input type="radio" value="none" v-model="wizardData.snapshotMethod" checked class="mt-1" />
                <div class="ml-3">
                  <div class="font-medium">Continue Without Snapshot</div>
                  <div class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500">Proceed with backup without filesystem snapshot</div>
                </div>
              </label>
            </div>
          </div>
        </div>

        <!-- Step 5: Storage Pool -->
        <div v-else-if="currentStep === 4" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Select Storage Pool</label>
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
                    <span v-if="pool.default_pool" class="text-xs bg-green-100 text-green-800 px-2 py-1 rounded">Default</span>
                  </div>
                  <div class="text-sm text-gray-600 dark:text-gray-400 dark:text-gray-500">{{ pool.path }}</div>
                  <div class="mt-2">
                    <div class="flex justify-between text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">
                      <span>Used: {{ formatBytes(pool.capacity_used) }}</span>
                      <span>Total: {{ formatBytes(pool.capacity_total) }}</span>
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

        <!-- Step 6: Repository Setup -->
        <div v-else-if="currentStep === 5" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Repository Name</label>
            <input 
              v-model="wizardData.repositoryName" 
              type="text" 
              class="input w-full"
              :placeholder="`${selectedServer?.name}-${wizardData.backupType}`"
            />
            <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">A unique name for this repository</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Encryption</label>
            <select v-model="wizardData.encryption" class="input w-full">
              <option value="repokey-blake2">Repokey Blake2 (Recommended)</option>
              <option value="repokey">Repokey SHA256</option>
              <option value="keyfile-blake2">Keyfile Blake2</option>
              <option value="keyfile">Keyfile SHA256</option>
              <option value="none">No Encryption</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Compression</label>
            <select v-model="wizardData.compression" class="input w-full">
              <option value="lz4">LZ4 (Fast)</option>
              <option value="zstd">Zstandard (Balanced)</option>
              <option value="zstd,3">Zstandard Level 3 (Better compression)</option>
              <option value="zlib">Zlib (Compatible)</option>
              <option value="none">No Compression</option>
            </select>
          </div>

          <div v-if="wizardData.encryption !== 'none'">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Passphrase (Optional)</label>
            <input 
              v-model="wizardData.passphrase" 
              type="password" 
              class="input w-full"
              placeholder="Leave empty to auto-generate a secure passphrase"
            />
            <p class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500 mt-1">
              <span v-if="!wizardData.passphrase">A secure passphrase will be automatically generated if not provided.</span>
              <span v-else>Store this passphrase securely - it cannot be recovered!</span>
            </p>
          </div>
        </div>

        <!-- Step 7: Retention Policy -->
        <div v-else-if="currentStep === 6" class="space-y-4">
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

          <!-- Retention Settings with sliders -->
          <div class="space-y-5">
            <!-- Daily -->
            <div class="flex items-center justify-between space-x-4">
              <div class="flex-1">
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">
                  Daily Backups
                </label>
                <p class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500">
                  Keep last N daily backups
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
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-12">days</span>
              </div>
            </div>

            <!-- Weekly -->
            <div class="flex items-center justify-between space-x-4">
              <div class="flex-1">
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">
                  Weekly Backups
                </label>
                <p class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500">
                  Keep last N weekly backups
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
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-12">weeks</span>
              </div>
            </div>

            <!-- Monthly -->
            <div class="flex items-center justify-between space-x-4">
              <div class="flex-1">
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">
                  Monthly Backups
                </label>
                <p class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500">
                  Keep last N monthly backups
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
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-12">months</span>
              </div>
            </div>

            <!-- Yearly -->
            <div class="flex items-center justify-between space-x-4">
              <div class="flex-1">
                <label class="block text-sm font-semibold text-gray-900 dark:text-gray-100 mb-1">
                  Yearly Backups
                </label>
                <p class="text-xs text-gray-600 dark:text-gray-400 dark:text-gray-500">
                  Keep last N yearly backups (0 = disabled)
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
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300 w-12">years</span>
              </div>
            </div>
          </div>

          <!-- Preview -->
          <div class="rounded-lg bg-gray-50 dark:bg-gray-800 border border-gray-200 dark:border-gray-700 p-4">
            <h4 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3">Policy Preview</h4>
            <div class="space-y-2 text-sm text-gray-700 dark:text-gray-300">
              <div v-if="wizardData.retention.keepDaily > 0" class="flex items-center justify-between">
                <span>Last <strong>{{ wizardData.retention.keepDaily }}</strong> daily backups</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">≈ {{ Math.ceil(wizardData.retention.keepDaily) }} days</span>
              </div>
              <div v-if="wizardData.retention.keepWeekly > 0" class="flex items-center justify-between">
                <span>Last <strong>{{ wizardData.retention.keepWeekly }}</strong> weekly backups</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">≈ {{ Math.ceil(wizardData.retention.keepWeekly * 7 / 30) }} months</span>
              </div>
              <div v-if="wizardData.retention.keepMonthly > 0" class="flex items-center justify-between">
                <span>Last <strong>{{ wizardData.retention.keepMonthly }}</strong> monthly backups</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">≈ {{ Math.ceil(wizardData.retention.keepMonthly / 12) }} years</span>
              </div>
              <div v-if="wizardData.retention.keepYearly > 0" class="flex items-center justify-between">
                <span>Last <strong>{{ wizardData.retention.keepYearly }}</strong> yearly backups</span>
                <span class="text-xs text-gray-500 dark:text-gray-400 dark:text-gray-500">{{ wizardData.retention.keepYearly }} years</span>
              </div>
              <div v-if="totalRetentionPeriods === 0" class="text-amber-600 font-medium">
                ⚠️ At least one retention value must be greater than 0
              </div>
            </div>
          </div>
        </div>

        <!-- Step 8: Schedule -->
        <div v-else-if="currentStep === 7" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Backup Schedule</label>
            <select v-model="wizardData.scheduleType" class="input w-full">
              <option value="manual">Manual Only</option>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>

          <div v-if="wizardData.scheduleType !== 'manual'">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Time</label>
            <input v-model="wizardData.scheduleTime" type="time" class="input w-full" />
          </div>

          <!-- Multi-day selection for weekly (reuse from BackupJobsView) -->
          <div v-if="wizardData.scheduleType === 'weekly'">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Days of Week</label>
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
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-2">Days of Month</label>
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

        <!-- Step 9: Review -->
        <div v-else-if="currentStep === 8" class="space-y-6">
          <div class="space-y-4">
            <div class="border-b pb-4">
              <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Server & Type</h3>
              <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Server:</dt>
                <dd class="font-medium">{{ selectedServer?.name }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Backup Type:</dt>
                <dd class="font-medium">{{ wizardData.backupType }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Snapshot:</dt>
                <dd class="font-medium">{{ wizardData.snapshotMethod || 'None' }}</dd>
              </dl>
            </div>

            <div class="border-b pb-4">
              <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Repository Configuration</h3>
              <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Name:</dt>
                <dd class="font-medium">{{ wizardData.repositoryName }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Storage Pool:</dt>
                <dd class="font-medium">{{ selectedPool?.name }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Encryption:</dt>
                <dd class="font-medium">{{ wizardData.encryption }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Compression:</dt>
                <dd class="font-medium">{{ wizardData.compression }}</dd>
              </dl>
            </div>

            <div class="border-b pb-4">
              <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Retention Policy</h3>
              <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Daily:</dt>
                <dd class="font-medium">{{ wizardData.retention.keepDaily }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Weekly:</dt>
                <dd class="font-medium">{{ wizardData.retention.keepWeekly }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Monthly:</dt>
                <dd class="font-medium">{{ wizardData.retention.keepMonthly }}</dd>
                <dt class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Yearly:</dt>
                <dd class="font-medium">{{ wizardData.retention.keepYearly }}</dd>
              </dl>
            </div>

            <div>
              <h3 class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Schedule</h3>
              <div class="space-y-1">
                <p class="text-sm">
                  <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Type:</span> 
                  <span class="font-medium capitalize">{{ wizardData.scheduleType }}</span>
                  <span v-if="wizardData.scheduleType !== 'manual'" class="ml-2">
                    at {{ wizardData.scheduleTime }}
                  </span>
                </p>
                
                <!-- Show selected days for weekly schedule -->
                <div v-if="wizardData.scheduleType === 'weekly' && wizardData.selectedWeekdays.length > 0" class="text-sm">
                  <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Days:</span>
                  <span class="font-medium ml-1">
                    {{ wizardData.selectedWeekdays.map(d => weekDays[d-1].short).join(', ') }}
                  </span>
                </div>
                
                <!-- Show selected days for monthly schedule -->
                <div v-if="wizardData.scheduleType === 'monthly' && wizardData.selectedMonthdays.length > 0" class="text-sm">
                  <span class="text-gray-600 dark:text-gray-400 dark:text-gray-500">Days of month:</span>
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
              Run a test backup after creation to verify configuration
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
        Previous
      </button>

      <div class="flex gap-3">
        <button 
          v-if="currentStep === steps.length - 1"
          @click="saveAsTemplate" 
          class="btn btn-outline"
        >
          Save as Template
        </button>

        <button 
          v-if="currentStep < steps.length - 1"
          @click="nextStep" 
          :disabled="!isCurrentStepValid"
          class="btn btn-primary"
        >
          Next
        </button>

        <button 
          v-if="currentStep === steps.length - 1"
          @click="createBackup" 
          :disabled="creating"
          class="btn btn-success"
        >
          {{ creating ? 'Creating...' : 'Create Backup Configuration' }}
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
import { serverService } from '../services/server'
import { storageService } from '../services/storage'
import { wizardService } from '../services/wizardService'

const router = useRouter()

// Wizard steps
const steps = [
  { label: 'Server', title: 'Select Server', description: 'Choose the server to backup' },
  { label: 'Type', title: 'Backup Type', description: 'What do you want to backup?' },
  { label: 'Source', title: 'Source Configuration', description: 'Configure what to backup' },
  { label: 'Snapshot', title: 'Snapshot Strategy', description: 'Choose snapshot method for consistency' },
  { label: 'Storage', title: 'Storage Pool', description: 'Where to store the backup' },
  { label: 'Repository', title: 'Repository Setup', description: 'Configure Borg repository' },
  { label: 'Retention', title: 'Retention Policy', description: 'How long to keep backups' },
  { label: 'Schedule', title: 'Schedule', description: 'When to run backups' },
  { label: 'Review', title: 'Review & Create', description: 'Review configuration' }
]

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
const snapshotCapabilities = ref([])
const serverCapabilities = ref(null)
const loadingCapabilities = ref(false)

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
    excludeBuildArtifacts: false
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

// Backup types
const backupTypes = [
  { id: 'files', name: 'Files & Folders', icon: '📁', description: 'Backup files and directories' },
  { id: 'mysql', name: 'MySQL', icon: '🗄️', description: 'MySQL/MariaDB databases' },
  { id: 'postgresql', name: 'PostgreSQL', icon: '🐘', description: 'PostgreSQL databases' },
  { id: 'mongodb', name: 'MongoDB', icon: '🍃', description: 'MongoDB databases' },
  { id: 'docker', name: 'Docker', icon: '🐳', description: 'Docker containers' },
  { id: 'system', name: 'Full System', icon: '💾', description: 'Complete system backup' }
]

// Week days
const weekDays = [
  { short: 'Mon', full: 'Monday' },
  { short: 'Tue', full: 'Tuesday' },
  { short: 'Wed', full: 'Wednesday' },
  { short: 'Thu', full: 'Thursday' },
  { short: 'Fri', full: 'Friday' },
  { short: 'Sat', full: 'Saturday' },
  { short: 'Sun', full: 'Sunday' }
]

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
  if (currentStep.value < steps.length - 1 && isCurrentStepValid.value) {
    currentStep.value++
    
    // Skip snapshot step for file and system backups
    if (currentStep.value === 3 && ['files', 'system'].includes(wizardData.value.backupType)) {
      currentStep.value++ // Skip to storage step
    }
    
    // Trigger actions for specific steps
    if (currentStep.value === 3 && needsSnapshot()) {
      detectSnapshotCapabilities()
    }
  }
}

function needsSnapshot() {
  // Snapshot is only useful for databases
  return ['mysql', 'mariadb', 'postgresql', 'mongodb'].includes(wizardData.value.backupType)
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
  wizardData.value.snapshotMethod = 'none'
  snapshotCapabilities.value = []
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
  try {
    const response = await wizardService.detectMySQL(wizardData.value.serverId)
    if (response.data.detected) {
      wizardData.value.sourceConfig.username = response.data.username
      wizardData.value.sourceConfig.password = response.data.password
      wizardData.value.sourceConfig.host = response.data.host
      wizardData.value.sourceConfig.port = response.data.port
    }
  } catch (error) {
    console.error('Failed to detect MySQL credentials:', error)
  }
}

const progressMessage = ref('')

async function detectSnapshotCapabilities() {
  if (!wizardData.value.serverId) return
  
  loadingCapabilities.value = true
  progressMessage.value = 'Initializing detection...'
  
  try {
    const response = await wizardService.getCapabilitiesWithPolling(
      wizardData.value.serverId,
      (message, progress) => {
        progressMessage.value = message || `Progress: ${progress}%`
      }
    )
    
    // The API returns capabilities data
    if (response.success && response.data) {
      snapshotCapabilities.value = response.data.snapshots || []
      
      // Store all capabilities for later use
      serverCapabilities.value = response.data
      
      // If we detected capabilities, auto-select the first one
      if (snapshotCapabilities.value.length > 0 && wizardData.value.snapshotMethod === 'none') {
        wizardData.value.snapshotMethod = snapshotCapabilities.value[0].type
      }
    }
  } catch (error) {
    console.error('Failed to detect snapshot capabilities:', error)
    snapshotCapabilities.value = []
    // If detection fails, default to no snapshot
    wizardData.value.snapshotMethod = 'none'
    alert(`Failed to detect capabilities: ${error.message}`)
  } finally {
    loadingCapabilities.value = false
    progressMessage.value = ''
  }
}

async function saveAsTemplate() {
  // TODO: Implement template saving
  console.log('Saving as template...')
}

async function createBackup() {
  creating.value = true
  try {
    // Prepare data for API
    const data = {
      server_id: wizardData.value.serverId,
      backup_type: wizardData.value.backupType,
      source_name: wizardData.value.repositoryName,
      source_config: wizardData.value.sourceConfig,
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