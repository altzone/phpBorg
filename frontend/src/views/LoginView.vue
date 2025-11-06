<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-primary-500 to-primary-700 px-4">
    <div class="max-w-md w-full">
      <!-- Logo and Title -->
      <div class="text-center mb-8">
        <h1 class="text-4xl font-bold text-white mb-2">phpBorg 2.0</h1>
        <p class="text-primary-100">Modern Backup Management</p>
      </div>

      <!-- Login Card -->
      <div class="card">
        <h2 class="text-2xl font-bold text-gray-900 mb-6">Sign In</h2>

        <!-- Error Message -->
        <div v-if="authStore.error" class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg">
          <p class="text-sm text-red-800">{{ authStore.error }}</p>
        </div>

        <!-- Login Form -->
        <form @submit.prevent="handleLogin">
          <div class="mb-4">
            <label for="username" class="block text-sm font-medium text-gray-700 mb-2">
              Username
            </label>
            <input
              id="username"
              v-model="username"
              type="text"
              required
              autofocus
              class="input"
              placeholder="Enter your username"
            />
          </div>

          <div class="mb-6">
            <label for="password" class="block text-sm font-medium text-gray-700 mb-2">
              Password
            </label>
            <input
              id="password"
              v-model="password"
              type="password"
              required
              class="input"
              placeholder="Enter your password"
            />
          </div>

          <button
            type="submit"
            :disabled="authStore.loading"
            class="w-full btn btn-primary"
          >
            <span v-if="authStore.loading">Signing in...</span>
            <span v-else>Sign In</span>
          </button>
        </form>

        <!-- Default Credentials Info -->
        <div class="mt-6 p-3 bg-gray-50 rounded-lg">
          <p class="text-xs text-gray-600 font-medium mb-1">Default credentials:</p>
          <p class="text-xs text-gray-500">Username: <code class="bg-gray-200 px-1 rounded">admin</code></p>
          <p class="text-xs text-gray-500">Password: <code class="bg-gray-200 px-1 rounded">admin123</code></p>
        </div>
      </div>

      <!-- Footer -->
      <div class="text-center mt-8">
        <p class="text-sm text-primary-100">
          Powered by BorgBackup &amp; PHP 8.3+
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const route = useRoute()
const authStore = useAuthStore()

const username = ref('')
const password = ref('')

async function handleLogin() {
  const success = await authStore.login(username.value, password.value)

  if (success) {
    // Redirect to original page or dashboard
    const redirect = route.query.redirect || '/'
    router.push(redirect)
  }
}
</script>
