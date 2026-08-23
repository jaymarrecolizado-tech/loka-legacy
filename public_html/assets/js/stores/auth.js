import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { authApi } from '@/api'

export const useAuthStore = defineStore('auth', () => {
  // State
  const user = ref(null)
  const token = ref(localStorage.getItem('auth_token'))
  const isLoading = ref(false)
  const error = ref(null)

  // Computed
  const isAuthenticated = computed(() => !!token.value && !!user.value)
  const userRole = computed(() => user.value?.role || null)
  const userName = computed(() => user.value?.name || '')
  const userEmail = computed(() => user.value?.email || '')

  // Actions
  const login = async (credentials) => {
    isLoading.value = true
    error.value = null
    try {
      const response = await authApi.login(credentials)
      token.value = response.token
      user.value = response.user
      localStorage.setItem('auth_token', response.token)
      return response
    } catch (err) {
      error.value = err.message || 'Login failed'
      throw err
    } finally {
      isLoading.value = false
    }
  }

  const logout = async () => {
    try {
      await authApi.logout()
    } catch (err) {
      console.error('Logout error:', err)
    } finally {
      token.value = null
      user.value = null
      localStorage.removeItem('auth_token')
    }
  }

  const fetchUser = async () => {
    if (!token.value) return

    isLoading.value = true
    try {
      user.value = await authApi.me()
    } catch (err) {
      error.value = err.message
      if (err.status === 401) {
        logout()
      }
    } finally {
      isLoading.value = false
    }
  }

  const hasPermission = (permission) => {
    if (!user.value?.permissions) return false
    return user.value.permissions.includes(permission)
  }

  const hasRole = (role) => {
    if (!user.value?.role) return false
    return user.value.role === role
  }

  const hasAnyRole = (roles) => {
    if (!user.value?.role) return false
    return roles.includes(user.value.role)
  }

  return {
    // State
    user,
    token,
    isLoading,
    error,
    // Computed
    isAuthenticated,
    userRole,
    userName,
    userEmail,
    // Actions
    login,
    logout,
    fetchUser,
    hasPermission,
    hasRole,
    hasAnyRole,
  }
})
