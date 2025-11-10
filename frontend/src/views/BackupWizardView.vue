<template>
  <div class="backup-wizard">
    <!-- Header -->
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900">Backup Configuration Wizard</h1>
      <p class="mt-2 text-gray-600">Step-by-step configuration for professional backup setup</p>
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
              'bg-gray-200 text-gray-600': index > currentStep,
              'ring-4 ring-primary-200': index === currentStep
            }"
          >
            <svg v-if="index < currentStep" class="w-5 h-5" fill="currentColor" viewBox="0 0 20 20">
              <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" />
            </svg>
            <span v-else>{{ index + 1 }}</span>
          </div>
          <span class="mt-2 text-xs text-center max-w-[100px]" 
                :class="index <= currentStep ? 'text-gray-900 font-medium' : 'text-gray-500'">
            {{ step.label }}
          </span>
        </div>
      </div>
    </div>

    <!-- Step Content -->
    <div class="card">
      <div class="mb-6">
        <h2 class="text-xl font-semibold text-gray-900">{{ steps[currentStep].title }}</h2>
        <p class="text-gray-600 mt-1">{{ steps[currentStep].description }}</p>
      </div>

      <div class="wizard-content">
        <!-- Step 1: Server Selection -->
        <div v-if="currentStep === 0" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Select Server</label>
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
              class="relative flex flex-col items-center p-4 border-2 rounded-lg cursor-pointer hover:bg-gray-50 transition-colors"
              :class="{
                'border-primary-500 bg-primary-50': wizardData.backupType === type.id,
                'border-gray-200': wizardData.backupType !== type.id
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
                <div class="font-semibold text-gray-900">{{ type.name }}</div>
                <div class="text-xs text-gray-600 mt-1">{{ type.description }}</div>
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
              <label class="block text-sm font-medium text-gray-700 mb-2">Paths to Backup</label>
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
              <label class="block text-sm font-medium text-gray-700 mb-2">Exclude Patterns (optional)</label>
              <textarea 
                v-model="wizardData.sourceConfig.excludePatterns" 
                rows="3" 
                class="input w-full"
                placeholder="*.log&#10;/tmp/*&#10;cache/"
              ></textarea>
              <p class="text-xs text-gray-500 mt-1">One pattern per line</p>
            </div>
          </div>

          <!-- MySQL/MariaDB Configuration -->
          <div v-else-if="wizardData.backupType === 'mysql' || wizardData.backupType === 'mariadb'">
            <div class="space-y-4">
              <div>
                <label class="flex items-center justify-between mb-2">
                  <span class="text-sm font-medium text-gray-700">Database Credentials</span>
                  <button @click="detectMySQLCredentials" class="text-sm text-primary-600 hover:text-primary-700">
                    Auto-detect
                  </button>
                </label>
                
                <div class="grid grid-cols-2 gap-4">
                  <div>
                    <label class="block text-xs text-gray-600 mb-1">Host</label>
                    <input v-model="wizardData.sourceConfig.host" type="text" class="input w-full" placeholder="localhost" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 mb-1">Port</label>
                    <input v-model="wizardData.sourceConfig.port" type="number" class="input w-full" placeholder="3306" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 mb-1">Username</label>
                    <input v-model="wizardData.sourceConfig.username" type="text" class="input w-full" />
                  </div>
                  <div>
                    <label class="block text-xs text-gray-600 mb-1">Password</label>
                    <input v-model="wizardData.sourceConfig.password" type="password" class="input w-full" />
                  </div>
                </div>
              </div>
              
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Databases to Backup</label>
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
                  <p class="text-xs text-gray-500 mt-1">Comma-separated list of database names</p>
                </div>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Backup Options</label>
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
            <p class="text-gray-600">PostgreSQL configuration...</p>
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
                <label class="block text-sm font-medium text-gray-700 mb-2">Backup Scope</label>
                <div class="bg-gray-50 p-3 rounded-lg">
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
                <label class="block text-sm font-medium text-gray-700 mb-2">Standard Exclusions (Always Applied)</label>
                <div class="bg-gray-50 p-3 rounded-lg">
                  <div class="space-y-1 font-mono text-xs text-gray-600">
                    <div class="text-gray-700 font-semibold mb-2"># Critical system directories</div>
                    <div>/proc/*</div>
                    <div>/sys/*</div>
                    <div>/dev/*</div>
                    <div>/run/*</div>
                    <div>/tmp/*</div>
                    <div>/var/tmp/*</div>
                    <div>/mnt/*</div>
                    <div>/media/*</div>
                    <div class="text-gray-700 font-semibold mt-3 mb-2"># Swap files</div>
                    <div>/swapfile</div>
                    <div>*.swp</div>
                    <div>*.tmp</div>
                    <div>*~</div>
                  </div>
                </div>
                <p class="text-xs text-gray-500 mt-2">These exclusions are always applied to prevent backing up system runtime files.</p>
              </div>

              <!-- Optional Exclusions -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Optional Exclusions</label>
                <div class="space-y-3">
                  <!-- Docker -->
                  <div class="p-3 border rounded-lg">
                    <label class="flex items-start">
                      <input type="checkbox" v-model="wizardData.sourceConfig.excludeDocker" class="mt-1 mr-3" checked />
                      <div class="flex-1">
                        <div class="font-medium text-sm">Docker & Container Data</div>
                        <div class="text-xs text-gray-600 mt-1">
                          Excludes: /var/lib/docker/*, /var/lib/containerd/*, /var/lib/lxc/*
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
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
                        <div class="text-xs text-gray-600 mt-1">
                          Excludes: /var/lib/mysql/*, /var/lib/postgresql/*, /var/lib/mongodb/*
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
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
                        <div class="text-xs text-gray-600 mt-1">
                          Excludes: /var/lib/libvirt/*, *.qcow2, *.vmdk, *.vdi, /var/lib/vz/*
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
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
                        <div class="text-xs text-gray-600 mt-1">
                          Excludes: /var/log/*, *.log, *.log.*, /var/spool/mail/*
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
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
                        <div class="text-xs text-gray-600 mt-1">
                          Excludes: /var/cache/*, */.cache/*, /var/lib/apt/lists/*, */node_modules/*
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
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
                        <div class="text-xs text-gray-600 mt-1">
                          Excludes: */Downloads/*, */.Trash/*, */Trash/*, */.local/share/Trash/*
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
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
                        <div class="text-xs text-gray-600 mt-1">
                          Excludes: */target/*, */dist/*, */build/*, */.gradle/*, */.m2/*, */vendor/*
                        </div>
                        <div class="text-xs text-gray-500 mt-1">
                          Development build outputs and downloaded dependencies that can be regenerated.
                        </div>
                      </div>
                    </label>
                  </div>
                </div>
              </div>

              <!-- Custom Exclusions -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Additional Exclusions (Optional)</label>
                <textarea 
                  v-model="wizardData.sourceConfig.excludePatterns" 
                  rows="4" 
                  class="input w-full font-mono text-sm"
                  placeholder="# Add custom patterns to exclude&#10;/path/to/exclude/*&#10;*.bak&#10;/var/www/*/cache/*"
                ></textarea>
                <p class="text-xs text-gray-500 mt-1">Add your own exclusion patterns, one per line. Use * for wildcards.</p>
              </div>

              <!-- Backup Options -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Options</label>
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
          <div v-if="loadingCapabilities" class="text-center py-8">
            <div class="inline-block animate-spin rounded-full h-8 w-8 border-4 border-gray-200 border-t-primary-600"></div>
            <p class="mt-2 text-gray-600">Detecting snapshot capabilities...</p>
          </div>
          
          <div v-else>
            <div v-if="snapshotCapabilities.length > 0">
              <label class="block text-sm font-medium text-gray-700 mb-2">Available Snapshot Methods</label>
              <div class="space-y-2">
                <label 
                  v-for="cap in snapshotCapabilities" 
                  :key="cap.type"
                  class="flex items-start p-3 border rounded-lg cursor-pointer hover:bg-gray-50"
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
                    <div class="text-sm text-gray-600">{{ cap.description }}</div>
                    <div v-if="cap.details" class="text-xs text-gray-500 mt-1">
                      {{ cap.details }}
                    </div>
                  </div>
                </label>
                
                <label class="flex items-start p-3 border rounded-lg cursor-pointer hover:bg-gray-50"
                       :class="{ 'border-primary-500 bg-primary-50': wizardData.snapshotMethod === 'none' }">
                  <input type="radio" value="none" v-model="wizardData.snapshotMethod" class="mt-1" />
                  <div class="ml-3">
                    <div class="font-medium">No Snapshot</div>
                    <div class="text-sm text-gray-600">Proceed without filesystem snapshot</div>
                  </div>
                </label>
              </div>
            </div>
            
            <div v-else class="p-4 bg-yellow-50 rounded-lg">
              <div class="flex items-start">
                <svg class="w-5 h-5 text-yellow-400 mt-0.5" fill="currentColor" viewBox="0 0 20 20">
                  <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                </svg>
                <div class="ml-3">
                  <h3 class="text-sm font-medium text-yellow-800">No Snapshot Methods Available</h3>
                  <p class="mt-1 text-sm text-yellow-700">
                    No snapshot capabilities detected on this server. Backup will proceed without filesystem snapshots.
                  </p>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Step 5: Storage Pool -->
        <div v-else-if="currentStep === 4" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Select Storage Pool</label>
            <div class="space-y-2">
              <label 
                v-for="pool in storagePools" 
                :key="pool.id"
                class="flex items-start p-4 border rounded-lg cursor-pointer hover:bg-gray-50"
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
                  <div class="text-sm text-gray-600">{{ pool.path }}</div>
                  <div class="mt-2">
                    <div class="flex justify-between text-xs text-gray-500">
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
            <label class="block text-sm font-medium text-gray-700 mb-2">Repository Name</label>
            <input 
              v-model="wizardData.repositoryName" 
              type="text" 
              class="input w-full"
              :placeholder="`${selectedServer?.name}-${wizardData.backupType}`"
            />
            <p class="text-xs text-gray-500 mt-1">A unique name for this repository</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Encryption</label>
            <select v-model="wizardData.encryption" class="input w-full">
              <option value="repokey-blake2">Repokey Blake2 (Recommended)</option>
              <option value="repokey">Repokey SHA256</option>
              <option value="keyfile-blake2">Keyfile Blake2</option>
              <option value="keyfile">Keyfile SHA256</option>
              <option value="none">No Encryption</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Compression</label>
            <select v-model="wizardData.compression" class="input w-full">
              <option value="lz4">LZ4 (Fast)</option>
              <option value="zstd">Zstandard (Balanced)</option>
              <option value="zstd,3">Zstandard Level 3 (Better compression)</option>
              <option value="zlib">Zlib (Compatible)</option>
              <option value="none">No Compression</option>
            </select>
          </div>

          <div v-if="wizardData.encryption !== 'none'">
            <label class="block text-sm font-medium text-gray-700 mb-2">Passphrase</label>
            <input 
              v-model="wizardData.passphrase" 
              type="password" 
              class="input w-full"
              placeholder="Strong passphrase for encryption"
            />
            <p class="text-xs text-gray-500 mt-1">Store this passphrase securely - it cannot be recovered!</p>
          </div>
        </div>

        <!-- Step 7: Retention Policy -->
        <div v-else-if="currentStep === 6" class="space-y-4">
          <div class="grid grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Keep Daily</label>
              <input v-model.number="wizardData.retention.keepDaily" type="number" min="0" class="input w-full" />
              <p class="text-xs text-gray-500 mt-1">Number of daily backups to keep</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Keep Weekly</label>
              <input v-model.number="wizardData.retention.keepWeekly" type="number" min="0" class="input w-full" />
              <p class="text-xs text-gray-500 mt-1">Number of weekly backups to keep</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Keep Monthly</label>
              <input v-model.number="wizardData.retention.keepMonthly" type="number" min="0" class="input w-full" />
              <p class="text-xs text-gray-500 mt-1">Number of monthly backups to keep</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Keep Yearly</label>
              <input v-model.number="wizardData.retention.keepYearly" type="number" min="0" class="input w-full" />
              <p class="text-xs text-gray-500 mt-1">Number of yearly backups to keep</p>
            </div>
          </div>

          <div class="p-4 bg-blue-50 rounded-lg">
            <h3 class="text-sm font-medium text-blue-800 mb-2">Retention Preview</h3>
            <p class="text-sm text-blue-700">
              With this configuration, you'll keep approximately:
            </p>
            <ul class="mt-2 text-sm text-blue-700 list-disc list-inside">
              <li>{{ wizardData.retention.keepDaily }} daily backups (last {{ wizardData.retention.keepDaily }} days)</li>
              <li>{{ wizardData.retention.keepWeekly }} weekly backups (last {{ wizardData.retention.keepWeekly }} weeks)</li>
              <li>{{ wizardData.retention.keepMonthly }} monthly backups (last {{ wizardData.retention.keepMonthly }} months)</li>
              <li v-if="wizardData.retention.keepYearly > 0">{{ wizardData.retention.keepYearly }} yearly backups</li>
            </ul>
          </div>
        </div>

        <!-- Step 8: Schedule -->
        <div v-else-if="currentStep === 7" class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">Backup Schedule</label>
            <select v-model="wizardData.scheduleType" class="input w-full">
              <option value="manual">Manual Only</option>
              <option value="daily">Daily</option>
              <option value="weekly">Weekly</option>
              <option value="monthly">Monthly</option>
            </select>
          </div>

          <div v-if="wizardData.scheduleType !== 'manual'">
            <label class="block text-sm font-medium text-gray-700 mb-2">Time</label>
            <input v-model="wizardData.scheduleTime" type="time" class="input w-full" />
          </div>

          <!-- Multi-day selection for weekly (reuse from BackupJobsView) -->
          <div v-if="wizardData.scheduleType === 'weekly'">
            <label class="block text-sm font-medium text-gray-700 mb-2">Days of Week</label>
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
            <label class="block text-sm font-medium text-gray-700 mb-2">Days of Month</label>
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
              <h3 class="font-semibold text-gray-900 mb-2">Server & Type</h3>
              <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-600">Server:</dt>
                <dd class="font-medium">{{ selectedServer?.name }}</dd>
                <dt class="text-gray-600">Backup Type:</dt>
                <dd class="font-medium">{{ wizardData.backupType }}</dd>
                <dt class="text-gray-600">Snapshot:</dt>
                <dd class="font-medium">{{ wizardData.snapshotMethod || 'None' }}</dd>
              </dl>
            </div>

            <div class="border-b pb-4">
              <h3 class="font-semibold text-gray-900 mb-2">Repository Configuration</h3>
              <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-600">Name:</dt>
                <dd class="font-medium">{{ wizardData.repositoryName }}</dd>
                <dt class="text-gray-600">Storage Pool:</dt>
                <dd class="font-medium">{{ selectedPool?.name }}</dd>
                <dt class="text-gray-600">Encryption:</dt>
                <dd class="font-medium">{{ wizardData.encryption }}</dd>
                <dt class="text-gray-600">Compression:</dt>
                <dd class="font-medium">{{ wizardData.compression }}</dd>
              </dl>
            </div>

            <div class="border-b pb-4">
              <h3 class="font-semibold text-gray-900 mb-2">Retention Policy</h3>
              <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-gray-600">Daily:</dt>
                <dd class="font-medium">{{ wizardData.retention.keepDaily }}</dd>
                <dt class="text-gray-600">Weekly:</dt>
                <dd class="font-medium">{{ wizardData.retention.keepWeekly }}</dd>
                <dt class="text-gray-600">Monthly:</dt>
                <dd class="font-medium">{{ wizardData.retention.keepMonthly }}</dd>
                <dt class="text-gray-600">Yearly:</dt>
                <dd class="font-medium">{{ wizardData.retention.keepYearly }}</dd>
              </dl>
            </div>

            <div>
              <h3 class="font-semibold text-gray-900 mb-2">Schedule</h3>
              <p class="text-sm">
                <span class="text-gray-600">Type:</span> 
                <span class="font-medium">{{ wizardData.scheduleType }}</span>
                <span v-if="wizardData.scheduleType !== 'manual'" class="ml-2">
                  at {{ wizardData.scheduleTime }}
                </span>
              </p>
            </div>
          </div>

          <div class="flex items-center gap-4 p-4 bg-green-50 rounded-lg">
            <input type="checkbox" v-model="wizardData.runTestBackup" id="test-backup" />
            <label for="test-backup" class="text-sm text-gray-700">
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

// Data
const servers = ref([])
const storagePools = ref([])
const snapshotCapabilities = ref([])
const loadingCapabilities = ref(false)

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

// Computed
const selectedServer = computed(() => 
  servers.value.find(s => s.id === wizardData.value.serverId)
)

const selectedPool = computed(() => 
  storagePools.value.find(p => p.id === wizardData.value.storagePoolId)
)

const isCurrentStepValid = computed(() => {
  switch (currentStep.value) {
    case 0: return !!wizardData.value.serverId
    case 1: return !!wizardData.value.backupType
    case 2: return validateSourceConfig()
    case 3: return true // Snapshot is optional
    case 4: return !!wizardData.value.storagePoolId
    case 5: return !!wizardData.value.repositoryName && (!wizardData.value.encryption || wizardData.value.encryption === 'none' || !!wizardData.value.passphrase)
    case 6: return true // Retention has defaults
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
  }
}

function nextStep() {
  if (currentStep.value < steps.length - 1 && isCurrentStepValid.value) {
    currentStep.value++
    
    // Trigger actions for specific steps
    if (currentStep.value === 3) {
      detectSnapshotCapabilities()
    }
  }
}

function addPath() {
  wizardData.value.sourceConfig.paths.push('')
}

function removePath(index) {
  wizardData.value.sourceConfig.paths.splice(index, 1)
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

async function detectSnapshotCapabilities() {
  if (!wizardData.value.serverId) return
  
  loadingCapabilities.value = true
  try {
    const response = await wizardService.getCapabilities(wizardData.value.serverId)
    snapshotCapabilities.value = response.data.snapshots || []
  } catch (error) {
    console.error('Failed to detect capabilities:', error)
    snapshotCapabilities.value = []
  } finally {
    loadingCapabilities.value = false
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
      retention: wizardData.value.retention,
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
    
    // Navigate to backup jobs page on success
    router.push('/backup-jobs')
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
.wizard-progress {
  @apply relative;
}

.wizard-content {
  min-height: 400px;
}
</style>