import axios from 'axios'

// Create axios instance with default config
const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE || '/api',
  timeout: 30000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
})

// Request interceptor
apiClient.interceptors.request.use(
  (config) => {
    // Add auth token if available
    const token = localStorage.getItem('auth_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }

    // Add timestamp to prevent caching
    if (config.method === 'get') {
      config.params = {
        ...config.params,
        _t: Date.now(),
      }
    }

    return config
  },
  (error) => {
    return Promise.reject(error)
  }
)

// Response interceptor
apiClient.interceptors.response.use(
  (response) => {
    return response.data
  },
  (error) => {
    // Handle common errors
    if (error.response) {
      const { status, data } = error.response

      switch (status) {
        case 401:
          // Unauthorized - clear token and redirect to login
          localStorage.removeItem('auth_token')
          if (window.location.pathname !== '/login') {
            window.location.href = '/login'
          }
          break
        case 403:
          // Forbidden
          console.error('Access forbidden:', data?.message || 'You do not have permission')
          break
        case 404:
          // Not found
          console.error('Resource not found')
          break
        case 422:
          // Validation error
          console.error('Validation error:', data?.errors)
          break
        case 500:
          // Server error
          console.error('Server error:', data?.message || 'Internal server error')
          break
        default:
          console.error('Request failed:', data?.message || error.message)
      }

      return Promise.reject({
        status,
        message: data?.message || error.message,
        errors: data?.errors || {},
      })
    }

    // Network error
    if (error.request) {
      console.error('Network error - no response received')
      return Promise.reject({ message: 'Network error. Please check your connection.' })
    }

    // Request setup error
    return Promise.reject({ message: error.message || 'An unknown error occurred' })
  }
)

export default apiClient
