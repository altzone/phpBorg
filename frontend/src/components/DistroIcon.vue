<template>
  <div class="inline-flex items-center justify-center" :class="sizeClass">
    <Icon :icon="iconName" :class="iconClass" />
  </div>
</template>

<script setup>
import { computed } from 'vue'
import { Icon } from '@iconify/vue'

const props = defineProps({
  distribution: {
    type: String,
    default: ''
  },
  size: {
    type: String,
    default: 'md',
    validator: (value) => ['xs', 'sm', 'md', 'lg', 'xl', '2xl'].includes(value)
  },
  type: {
    type: String,
    default: 'os',
    validator: (value) => ['os', 'database', 'service'].includes(value)
  }
})

const distroLower = computed(() => (props.distribution || '').toLowerCase())

// OS/Distribution icons mapping
const osIcons = {
  ubuntu: 'simple-icons:ubuntu',
  debian: 'simple-icons:debian',
  centos: 'simple-icons:centos',
  rocky: 'simple-icons:rockylinux',
  alma: 'simple-icons:almalinux',
  rhel: 'simple-icons:redhat',
  'red hat': 'simple-icons:redhat',
  redhat: 'simple-icons:redhat',
  fedora: 'simple-icons:fedora',
  arch: 'simple-icons:archlinux',
  manjaro: 'simple-icons:manjaro',
  windows: 'simple-icons:windows',
  suse: 'simple-icons:suse',
  opensuse: 'simple-icons:opensuse',
  alpine: 'simple-icons:alpinelinux',
  gentoo: 'simple-icons:gentoo',
  mint: 'simple-icons:linuxmint',
  kali: 'simple-icons:kalilinux',
  nixos: 'simple-icons:nixos',
  void: 'simple-icons:voidlinux',
  freebsd: 'simple-icons:freebsd',
  openbsd: 'simple-icons:openbsd',
  macos: 'simple-icons:macos',
  darwin: 'simple-icons:apple'
}

// Database icons mapping
const dbIcons = {
  postgresql: 'simple-icons:postgresql',
  postgres: 'simple-icons:postgresql',
  mysql: 'simple-icons:mysql',
  mariadb: 'simple-icons:mariadb',
  mongodb: 'simple-icons:mongodb',
  redis: 'simple-icons:redis',
  sqlite: 'simple-icons:sqlite',
  oracle: 'simple-icons:oracle',
  sqlserver: 'simple-icons:microsoftsqlserver',
  'sql server': 'simple-icons:microsoftsqlserver',
  cassandra: 'simple-icons:apachecassandra',
  elasticsearch: 'simple-icons:elasticsearch',
  neo4j: 'simple-icons:neo4j',
  couchdb: 'simple-icons:apachecouchdb',
  influxdb: 'simple-icons:influxdb'
}

// Service icons mapping
const serviceIcons = {
  docker: 'simple-icons:docker',
  kubernetes: 'simple-icons:kubernetes',
  k8s: 'simple-icons:kubernetes',
  podman: 'simple-icons:podman',
  nginx: 'simple-icons:nginx',
  apache: 'simple-icons:apache',
  borg: 'mdi:package-variant-closed',
  borgbackup: 'mdi:package-variant-closed',
  restic: 'mdi:archive',
  lvm: 'mdi:harddisk-plus',
  zfs: 'simple-icons:openzfs',
  btrfs: 'mdi:harddisk',
  git: 'simple-icons:git',
  ssh: 'mdi:ssh',
  proxmox: 'simple-icons:proxmox',
  vmware: 'simple-icons:vmware',
  virtualbox: 'simple-icons:virtualbox',
  qemu: 'simple-icons:qemu'
}

const iconName = computed(() => {
  const name = distroLower.value

  // Check type-specific icons first
  if (props.type === 'database') {
    for (const [key, icon] of Object.entries(dbIcons)) {
      if (name.includes(key)) return icon
    }
    return 'mdi:database'
  }

  if (props.type === 'service') {
    for (const [key, icon] of Object.entries(serviceIcons)) {
      if (name.includes(key)) return icon
    }
    return 'mdi:cog'
  }

  // Default: OS/Distribution detection
  for (const [key, icon] of Object.entries(osIcons)) {
    if (name.includes(key)) return icon
  }

  // Check for generic linux
  if (name.includes('linux')) {
    return 'simple-icons:linux'
  }

  // Fallback to server icon
  return 'mdi:server'
})

const sizeClass = computed(() => {
  const sizes = {
    xs: 'w-4 h-4',
    sm: 'w-5 h-5',
    md: 'w-6 h-6',
    lg: 'w-8 h-8',
    xl: 'w-10 h-10',
    '2xl': 'w-12 h-12'
  }
  return sizes[props.size]
})

const iconClass = computed(() => {
  const sizes = {
    xs: 'text-base',
    sm: 'text-lg',
    md: 'text-xl',
    lg: 'text-2xl',
    xl: 'text-3xl',
    '2xl': 'text-4xl'
  }
  return sizes[props.size]
})
</script>
