# Backup Wizard Architecture

## 🎯 Objectif
Créer un wizard complet et fluide pour configurer un backup de A à Z, depuis la sélection du serveur jusqu'à la planification, en passant par la création du repository Borg.

## 🔄 Workflow Principal

```yaml
Step 1 - Server Selection:
  - Choose existing server
  - Or add new server inline

Step 2 - Backup Type:
  - Files & Folders
  - MySQL/MariaDB
  - PostgreSQL
  - MongoDB
  - Docker Containers
  - Full System

Step 3 - Source Configuration:
  - Dynamic based on type
  - Auto-detection when possible
  - Credential management
  - Test connectivity

Step 4 - Snapshot Strategy:
  - Auto-detect capabilities (LVM, ZFS, Btrfs)
  - Choose snapshot method
  - Or proceed without snapshot

Step 5 - Storage Pool:
  - Select from existing pools
  - Show available space
  - Performance tier selection

Step 6 - Repository Setup:
  - Create new or use existing
  - Encryption settings
  - Compression settings
  - Repository naming convention

Step 7 - Retention Policy:
  - Prune configuration
  - Keep daily/weekly/monthly/yearly
  - Visual retention preview

Step 8 - Schedule:
  - Multi-day selection
  - Backup windows
  - Priority settings

Step 9 - Validation:
  - Test backup (small test)
  - Verify all settings
  - Preview first runs

Step 10 - Review & Create:
  - Summary of all settings
  - Save as template option
  - Execute now option
```

## 📦 Database Schema Updates

### 1. Repository Enhanced
```sql
ALTER TABLE `repository` ADD COLUMN
  `snapshot_method` enum('none','lvm','zfs','btrfs','vmware','hyperv') DEFAULT 'none',
  `snapshot_config` JSON DEFAULT NULL COMMENT 'Snapshot-specific configuration',
  `pre_backup_commands` JSON DEFAULT NULL COMMENT 'Commands to run before backup',
  `post_backup_commands` JSON DEFAULT NULL COMMENT 'Commands to run after backup',
  `test_mode` tinyint(1) DEFAULT 0 COMMENT 'Is this a test repository?',
  `last_prune_at` datetime DEFAULT NULL,
  `prune_schedule` JSON DEFAULT NULL COMMENT 'Automated prune schedule';
```

### 2. Backup Templates
```sql
CREATE TABLE `backup_templates` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `description` text,
  `backup_type` enum('files','mysql','postgresql','mongodb','docker','system') NOT NULL,
  `source_config` JSON NOT NULL COMMENT 'Type-specific configuration template',
  `snapshot_method` varchar(50) DEFAULT NULL,
  `retention_policy` JSON NOT NULL,
  `compression` varchar(50) DEFAULT 'lz4',
  `encryption` varchar(50) DEFAULT 'repokey-blake2',
  `tags` JSON DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
);
```

### 3. Database Credentials Store
```sql
CREATE TABLE `database_credentials` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `server_id` int(11) NOT NULL,
  `db_type` enum('mysql','postgresql','mongodb','redis') NOT NULL,
  `host` varchar(255) DEFAULT 'localhost',
  `port` int(11) NOT NULL,
  `username` varchar(100) NOT NULL,
  `password` text NOT NULL COMMENT 'Encrypted',
  `auth_method` enum('password','socket','ssl','kerberos') DEFAULT 'password',
  `ssl_config` JSON DEFAULT NULL,
  `is_default` tinyint(1) DEFAULT 0,
  `test_status` enum('untested','success','failed') DEFAULT 'untested',
  `last_test_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `server_id` (`server_id`),
  UNIQUE KEY `server_db_type_default` (`server_id`, `db_type`, `is_default`)
);
```

## 🧩 Modular Components

### Database Module
```javascript
// Auto-detection for MySQL
async function detectMySQLCredentials(serverId) {
  // 1. Try /etc/mysql/debian.cnf
  const debianCnf = await ssh.readFile('/etc/mysql/debian.cnf');
  if (debianCnf) {
    return parseDebianCnf(debianCnf);
  }
  
  // 2. Try .my.cnf in home directory
  const myCnf = await ssh.readFile('~/.my.cnf');
  if (myCnf) {
    return parseMyCnf(myCnf);
  }
  
  // 3. Manual input required
  return null;
}

// Atomic backup check
async function canDoAtomicBackup(dbConfig) {
  // Check if database supports transactions
  const supportsTransactions = await checkTransactionSupport(dbConfig);
  
  // Check if on snapshotable filesystem
  const snapshotCapable = await checkSnapshotCapability(dbConfig.dataDir);
  
  return {
    atomic: supportsTransactions || snapshotCapable,
    method: supportsTransactions ? 'transaction' : 'snapshot',
    details: {...}
  };
}
```

### Snapshot Module
```javascript
// Detect snapshot capabilities
async function detectSnapshotCapabilities(server) {
  const capabilities = [];
  
  // Check LVM
  const lvmAvailable = await ssh.command('which lvcreate');
  if (lvmAvailable.success) {
    const volumes = await ssh.command('lvs --noheadings -o lv_name,vg_name,lv_path');
    capabilities.push({
      type: 'lvm',
      available: true,
      volumes: parseLVMVolumes(volumes)
    });
  }
  
  // Check ZFS
  const zfsAvailable = await ssh.command('which zfs');
  if (zfsAvailable.success) {
    const datasets = await ssh.command('zfs list -H -o name,mountpoint');
    capabilities.push({
      type: 'zfs',
      available: true,
      datasets: parseZFSDatasets(datasets)
    });
  }
  
  // Check Btrfs
  const btrfsAvailable = await ssh.command('which btrfs');
  if (btrfsAvailable.success) {
    const subvolumes = await ssh.command('btrfs subvolume list /');
    capabilities.push({
      type: 'btrfs',
      available: true,
      subvolumes: parseBtrfsSubvolumes(subvolumes)
    });
  }
  
  return capabilities;
}
```

## 🎯 API Endpoints

### Wizard API
```php
// GET /api/backup-wizard/capabilities/{serverId}
// Returns server capabilities (snapshot, databases, etc.)

// POST /api/backup-wizard/test-connection
// Test database or SSH connection

// POST /api/backup-wizard/validate-step
// Validate each wizard step

// POST /api/backup-wizard/create-backup-chain
// Creates repository + source + job in one transaction

// GET /api/backup-wizard/templates
// Get saved templates

// POST /api/backup-wizard/from-template
// Create backup from template
```

## 🖥️ Frontend Wizard Component

```vue
<template>
  <div class="backup-wizard">
    <!-- Progress Bar -->
    <div class="wizard-progress">
      <div v-for="(step, index) in steps" :key="index"
           :class="['step', { 
             'active': currentStep === index,
             'completed': currentStep > index,
             'error': step.hasError
           }]">
        <div class="step-number">{{ index + 1 }}</div>
        <div class="step-label">{{ step.label }}</div>
      </div>
      <div class="progress-line" :style="{width: progressWidth}"></div>
    </div>

    <!-- Step Content -->
    <div class="wizard-content">
      <transition name="slide">
        <component :is="currentStepComponent" 
                   v-model="wizardData"
                   @validate="validateStep"
                   @next="nextStep"
                   @previous="previousStep" />
      </transition>
    </div>

    <!-- Navigation -->
    <div class="wizard-navigation">
      <button @click="previousStep" 
              :disabled="currentStep === 0"
              class="btn btn-secondary">
        Previous
      </button>
      
      <button @click="saveAsTemplate" 
              v-if="currentStep === steps.length - 1"
              class="btn btn-outline">
        Save as Template
      </button>
      
      <button @click="nextStep" 
              v-if="currentStep < steps.length - 1"
              :disabled="!currentStepValid"
              class="btn btn-primary">
        Next
      </button>
      
      <button @click="createBackup" 
              v-if="currentStep === steps.length - 1"
              :disabled="!allStepsValid"
              class="btn btn-success">
        Create Backup Configuration
      </button>
    </div>
  </div>
</template>
```

## 🔧 Implementation Priority

### Phase 1 - Core Wizard (Week 1)
1. ✅ Basic wizard structure
2. ✅ Server selection step
3. ✅ Backup type selection
4. ✅ Storage pool selection
5. ✅ Repository creation

### Phase 2 - Auto-Detection (Week 2)
1. ⏳ MySQL credential detection
2. ⏳ Snapshot capability detection
3. ⏳ Filesystem analysis
4. ⏳ Connection testing

### Phase 3 - Advanced Features (Week 3)
1. ⏳ Template system
2. ⏳ Multiple backup sources
3. ⏳ Dependencies between backups
4. ⏳ Pre/post backup scripts

### Phase 4 - Optimization (Week 4)
1. ⏳ Backup chain validation
2. ⏳ Performance recommendations
3. ⏳ Storage optimization tips
4. ⏳ Monitoring integration

## 🎯 Success Criteria

1. **User Experience**
   - Setup time < 5 minutes for standard backup
   - Zero manual Borg commands needed
   - Clear error messages and recovery suggestions

2. **Technical**
   - Atomic backups for all database types
   - Automatic snapshot when available
   - Proper retention management
   - Efficient storage usage

3. **Business**
   - Support all major database systems
   - Enterprise-grade reliability
   - Compliance-ready (encryption, retention)
   - Multi-tenant capable

## 🚀 Next Steps

1. Create wizard frontend component structure
2. Implement auto-detection APIs
3. Add snapshot capability detection
4. Create backup chain transaction
5. Build template system
6. Add validation and testing

This architecture provides a complete, modular, and extensible backup configuration system!