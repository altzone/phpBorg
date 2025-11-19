import { defineStore } from 'pinia'
import dockerRestoreService from '../services/dockerRestore'

export const useDockerRestoreStore = defineStore('dockerRestore', {
  state: () => ({
    // Wizard state
    currentStep: 1,
    archive: null, // Selected archive from RestoreWizard
    server: null, // Server info from archive

    // Step 1: Mode selection
    mode: 'express', // 'express' or 'pro_safe'

    // Step 2: Restore type
    restoreType: 'full', // 'full', 'volumes_only', 'compose_only', 'custom'

    // Step 3: Destination
    destination: 'alternative', // 'in_place' or 'alternative'
    alternativePath: '', // Auto-generated or custom

    // Step 4: Advanced options
    composePathAdaptation: 'auto_modify', // 'none', 'auto_modify', 'generate_new'
    createLvmSnapshot: true,
    lvmPath: '/dev/vg_data/lv_docker',
    snapshotSize: '20G',
    createPreRestoreBackup: false,
    autoRestart: true,
    autoRollbackOnFailure: true,

    // Archive analysis (from API)
    analysis: null,
    analyzing: false,

    // Selected items (for custom restore)
    selectedVolumes: [],
    selectedProjects: [],
    selectedConfigs: [],

    // Step 5: Conflicts
    conflicts: null,
    detectingConflicts: false,

    // Script preview
    script: null,
    scriptAdvanced: false,
    generatingScript: false,

    // Restore execution
    operation: null,
    restoring: false,

    // Operations list
    operations: [],
    loadingOperations: false
  }),

  getters: {
    /**
     * Get selected items for API
     */
    getSelectedItems(state) {
      if (state.restoreType === 'full') {
        return {
          volumes: state.analysis?.volumes?.map(v => v.name) || [],
          projects: state.analysis?.compose_projects?.map(p => p.name) || [],
          configs: state.analysis?.configs || [],
          must_stop: []
        }
      }

      if (state.restoreType === 'volumes_only') {
        return {
          volumes: state.analysis?.volumes?.map(v => v.name) || [],
          projects: [],
          configs: [],
          must_stop: []
        }
      }

      if (state.restoreType === 'compose_only') {
        return {
          volumes: [],
          projects: state.analysis?.compose_projects?.map(p => p.name) || [],
          configs: state.analysis?.configs || [],
          must_stop: []
        }
      }

      // Custom selection
      return {
        volumes: state.selectedVolumes,
        projects: state.selectedProjects,
        configs: state.selectedConfigs,
        must_stop: state.conflicts?.must_stop || []
      }
    },

    /**
     * Check if can proceed to next step
     */
    canProceed(state) {
      switch (state.currentStep) {
        case 1:
          return state.mode && state.archive
        case 2:
          return state.restoreType
        case 3:
          return state.destination && (state.destination === 'in_place' || state.alternativePath)
        case 4:
          return true // Optional step
        case 5:
          return state.conflicts !== null
        case 6:
          return true
        default:
          return false
      }
    },

    /**
     * Get restore config for API
     */
    getRestoreConfig(state) {
      const config = {
        archive_id: state.archive?.id,
        server_id: state.server?.id,
        mode: state.mode,
        restore_type: state.restoreType,
        destination: state.destination,
        alternative_path: state.alternativePath || null,
        compose_path_adaptation: state.composePathAdaptation,
        selected_items: this.getSelectedItems,
        create_lvm_snapshot: state.createLvmSnapshot,
        lvm_path: state.lvmPath,
        snapshot_size: state.snapshotSize,
        create_pre_restore_backup: state.createPreRestoreBackup,
        auto_restart: state.autoRestart,
        auto_rollback_on_failure: state.autoRollbackOnFailure,
        containers_to_stop: state.conflicts?.must_stop || []
      }

      // Include operation_id if it exists (for startRestore after preview)
      if (state.operation?.id) {
        config.operation_id = state.operation.id
      }

      return config
    }
  },

  actions: {
    /**
     * Initialize wizard with archive
     */
    initWizard(archive, server) {
      this.archive = archive
      this.server = server
      this.currentStep = 1

      // Generate default alternative path
      const timestamp = new Date().toISOString().slice(0, 16).replace('T', '_').replace(':', '-')
      this.alternativePath = `/opt/restore_${timestamp}`

      // Auto-analyze archive
      this.analyzeArchive()
    },

    /**
     * Analyze archive content
     */
    async analyzeArchive() {
      if (!this.archive) return

      this.analyzing = true
      try {
        this.analysis = await dockerRestoreService.analyzeArchive(this.archive.id)
      } catch (error) {
        console.error('Failed to analyze archive:', error)
        this.analysis = null
        // Error will be handled by component
      } finally {
        this.analyzing = false
      }
    },

    /**
     * Detect conflicts with running containers
     */
    async detectConflicts() {
      if (!this.server) return

      this.detectingConflicts = true
      try {
        // detectConflicts now polls the job and returns the result directly
        const conflictsData = await dockerRestoreService.detectConflicts(
          this.server.id,
          this.getSelectedItems
        )

        // conflictsData is already the parsed result: {conflicts, must_stop, disk_space_ok, warnings}
        console.log('📊 Conflicts detection result:', conflictsData)
        console.log('💾 disk_space_ok type:', typeof conflictsData.disk_space_ok, 'value:', conflictsData.disk_space_ok)
        this.conflicts = conflictsData
      } catch (error) {
        console.error('Failed to detect conflicts:', error)
        // Error will be handled by component
      } finally {
        this.detectingConflicts = false
      }
    },

    /**
     * Generate script preview
     */
    async generateScript(advanced = false) {
      if (!this.operation) {
        console.warn('No operation created yet')
        return
      }

      this.generatingScript = true
      this.scriptAdvanced = advanced
      try {
        const response = await dockerRestoreService.previewScript(
          this.operation.id,
          advanced,
          this.getRestoreConfig
        )
        this.script = response.data.script
      } catch (error) {
        console.error('Failed to generate script:', error)
        // Error will be handled by component
      } finally {
        this.generatingScript = false
      }
    },

    /**
     * Create operation (for preview, without starting restore)
     */
    async createOperation() {
      try {
        const response = await dockerRestoreService.createOperation(this.getRestoreConfig)
        this.operation = response.data.operation
        return response.data.operation
      } catch (error) {
        console.error('Failed to create operation:', error)
        throw error
      }
    },

    /**
     * Start Docker restore
     */
    async startRestore() {
      this.restoring = true
      try {
        const response = await dockerRestoreService.startRestore(this.getRestoreConfig)
        this.operation = response.data
        return response.data
      } catch (error) {
        console.error('Failed to start restore:', error)
        throw error
      } finally {
        this.restoring = false
      }
    },

    /**
     * Get operation status
     */
    async getOperation(operationId) {
      try {
        const response = await dockerRestoreService.getOperation(operationId)
        this.operation = response.data.operation
        return response.data.operation
      } catch (error) {
        console.error('Failed to get operation:', error)
        throw error
      }
    },

    /**
     * List all operations
     */
    async listOperations(filters = {}) {
      this.loadingOperations = true
      try {
        const response = await dockerRestoreService.listOperations(filters)
        this.operations = response.data.operations
      } catch (error) {
        console.error('Failed to list operations:', error)
        // Error will be handled by component
      } finally {
        this.loadingOperations = false
      }
    },

    /**
     * Rollback operation
     */
    async rollback(operationId) {
      try {
        const response = await dockerRestoreService.rollback(operationId)
        return response.data
      } catch (error) {
        console.error('Failed to rollback:', error)
        throw error
      }
    },

    /**
     * Navigation
     */
    async nextStep() {
      if (this.canProceed && this.currentStep < 6) {
        this.currentStep++

        // Auto-detect conflicts when entering step 5
        if (this.currentStep === 5) {
          this.detectConflicts()
        }

        // Create operation when entering step 6 (preview)
        if (this.currentStep === 6 && !this.operation) {
          await this.createOperation()
        }
      }
    },

    prevStep() {
      if (this.currentStep > 1) {
        this.currentStep--
      }
    },

    goToStep(step) {
      if (step >= 1 && step <= 6) {
        this.currentStep = step
      }
    },

    /**
     * Reset wizard
     */
    reset() {
      this.$reset()
    }
  }
})
