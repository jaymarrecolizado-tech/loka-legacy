/**
 * Vite Entry Point for Admin Panel
 * Import this file in your PHP templates for HMR support
 */

// Import the existing app functionality
import './app.js'

// Import Vue app (optional - for SPA sections)
// import { createApp } from 'vue'
// import AdminApp from './AdminApp.vue'

// Enable Vite HMR
if (import.meta.hot) {
  import.meta.hot.accept()
}
