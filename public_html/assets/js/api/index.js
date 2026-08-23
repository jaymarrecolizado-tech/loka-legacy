import apiClient from './client'

// Auth API
export const authApi = {
  login: (credentials) => apiClient.post('/auth/login', credentials),
  logout: () => apiClient.post('/auth/logout'),
  me: () => apiClient.get('/auth/me'),
  refresh: () => apiClient.post('/auth/refresh'),
  resetPassword: (email) => apiClient.post('/auth/reset-password', { email }),
  changePassword: (data) => apiClient.post('/auth/change-password', data),
}

// Users API
export const usersApi = {
  list: (params) => apiClient.get('/users', { params }),
  get: (id) => apiClient.get(`/users/${id}`),
  create: (data) => apiClient.post('/users', data),
  update: (id, data) => apiClient.put(`/users/${id}`, data),
  delete: (id) => apiClient.delete(`/users/${id}`),
  toggleStatus: (id) => apiClient.post(`/users/${id}/toggle-status`),
}

// Vehicles API
export const vehiclesApi = {
  list: (params) => apiClient.get('/vehicles', { params }),
  get: (id) => apiClient.get(`/vehicles/${id}`),
  create: (data) => apiClient.post('/vehicles', data),
  update: (id, data) => apiClient.put(`/vehicles/${id}`, data),
  delete: (id) => apiClient.delete(`/vehicles/${id}`),
  updateStatus: (id, status) => apiClient.patch(`/vehicles/${id}/status`, { status }),
  getMaintenance: (id) => apiClient.get(`/vehicles/${id}/maintenance`),
}

// Trips API
export const tripsApi = {
  list: (params) => apiClient.get('/trips', { params }),
  get: (id) => apiClient.get(`/trips/${id}`),
  create: (data) => apiClient.post('/trips', data),
  update: (id, data) => apiClient.put(`/trips/${id}`, data),
  delete: (id) => apiClient.delete(`/trips/${id}`),
  start: (id) => apiClient.post(`/trips/${id}/start`),
  complete: (id, data) => apiClient.post(`/trips/${id}/complete`, data),
  cancel: (id, reason) => apiClient.post(`/trips/${id}/cancel`, { reason }),
  getRoutes: (params) => apiClient.get('/trips/routes', { params }),
}

// Bookings API
export const bookingsApi = {
  list: (params) => apiClient.get('/bookings', { params }),
  get: (id) => apiClient.get(`/bookings/${id}`),
  create: (data) => apiClient.post('/bookings', data),
  update: (id, data) => apiClient.put(`/bookings/${id}`, data),
  delete: (id) => apiClient.delete(`/bookings/${id}`),
  approve: (id) => apiClient.post(`/bookings/${id}/approve`),
  reject: (id, reason) => apiClient.post(`/bookings/${id}/reject`, { reason }),
  checkAvailability: (params) => apiClient.get('/bookings/availability', { params }),
}

// Drivers API
export const driversApi = {
  list: (params) => apiClient.get('/drivers', { params }),
  get: (id) => apiClient.get(`/drivers/${id}`),
  create: (data) => apiClient.post('/drivers', data),
  update: (id, data) => apiClient.put(`/drivers/${id}`, data),
  delete: (id) => apiClient.delete(`/drivers/${id}`),
  updateStatus: (id, status) => apiClient.patch(`/drivers/${id}/status`, { status }),
  getAssignedTrips: (id) => apiClient.get(`/drivers/${id}/trips`),
}

// Maintenance API
export const maintenanceApi = {
  list: (params) => apiClient.get('/maintenance', { params }),
  get: (id) => apiClient.get(`/maintenance/${id}`),
  create: (data) => apiClient.post('/maintenance', data),
  update: (id, data) => apiClient.put(`/maintenance/${id}`, data),
  delete: (id) => apiClient.delete(`/maintenance/${id}`),
  schedule: (data) => apiClient.post('/maintenance/schedule', data),
  complete: (id, data) => apiClient.post(`/maintenance/${id}/complete`, data),
}

// Reports API
export const reportsApi = {
  tripSummary: (params) => apiClient.get('/reports/trips/summary', { params }),
  vehicleUtilization: (params) => apiClient.get('/reports/vehicles/utilization', { params }),
  driverPerformance: (params) => apiClient.get('/reports/drivers/performance', { params }),
  costAnalysis: (params) => apiClient.get('/reports/costs/analysis', { params }),
  export: (type, params) => apiClient.get(`/reports/export/${type}`, { params, responseType: 'blob' }),
}

// Settings API
export const settingsApi = {
  get: () => apiClient.get('/settings'),
  update: (data) => apiClient.put('/settings', data),
  updateBookingRules: (data) => apiClient.put('/settings/booking-rules', data),
  updateMaintenanceSchedule: (data) => apiClient.put('/settings/maintenance-schedule', data),
}

// Notifications API
export const notificationsApi = {
  list: (params) => apiClient.get('/notifications', { params }),
  markAsRead: (id) => apiClient.post(`/notifications/${id}/read`),
  markAllAsRead: () => apiClient.post('/notifications/read-all'),
  count: () => apiClient.get('/notifications/count'),
}

export default {
  auth: authApi,
  users: usersApi,
  vehicles: vehiclesApi,
  trips: tripsApi,
  bookings: bookingsApi,
  drivers: driversApi,
  maintenance: maintenanceApi,
  reports: reportsApi,
  settings: settingsApi,
  notifications: notificationsApi,
}
