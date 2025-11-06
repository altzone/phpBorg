<template>
  <div>
    <div class="mb-8">
      <h1 class="text-3xl font-bold text-gray-900">Settings</h1>
      <p class="mt-2 text-gray-600">Configure system settings and preferences</p>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
      <!-- User Management -->
      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">User Management</h2>
        <div class="text-center py-8 text-gray-500">
          <p class="text-sm">Coming in Phase 6</p>
          <p class="text-xs mt-2 text-gray-400">Manage users, roles and permissions</p>
        </div>
      </div>

      <!-- System Configuration -->
      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">System Configuration</h2>
        <div class="text-center py-8 text-gray-500">
          <p class="text-sm">Coming in Phase 6</p>
          <p class="text-xs mt-2 text-gray-400">Configure paths, retention policies, etc.</p>
        </div>
      </div>

      <!-- Alert Configuration -->
      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">Alert Configuration</h2>
        <div class="text-center py-8 text-gray-500">
          <p class="text-sm">Coming in Phase 7 (Optional)</p>
          <p class="text-xs mt-2 text-gray-400">Email and notification settings</p>
        </div>
      </div>

      <!-- About -->
      <div class="card">
        <h2 class="text-lg font-semibold text-gray-900 mb-4">About</h2>
        <div class="space-y-2 text-sm">
          <div class="flex justify-between">
            <span class="text-gray-600">Version</span>
            <span class="font-medium">2.0.0</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">PHP Version</span>
            <span class="font-medium">8.3+</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">Database</span>
            <span class="font-medium">MySQL/MariaDB</span>
          </div>
          <div class="flex justify-between">
            <span class="text-gray-600">BorgBackup</span>
            <span class="font-medium">1.2+</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
