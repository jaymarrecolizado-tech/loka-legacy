import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import { vehiclesApi } from '@/api'

export const useVehiclesStore = defineStore('vehicles', () => {
  // State
  const vehicles = ref([])
  const currentVehicle = ref(null)
  const isLoading = ref(false)
  const error = ref(null)
  const pagination = ref({
    page: 1,
    perPage: 15,
    total: 0,
    lastPage: 1,
  })
  const filters = ref({
    status: '',
    search: '',
    type: '',
  })

  // Computed
  const activeVehicles = computed(() => vehicles.value.filter((v) => v.status === 'active'))
  const inactiveVehicles = computed(() => vehicles.value.filter((v) => v.status !== 'active'))
  const vehicleCount = computed(() => pagination.value.total)

  // Actions
  const fetchVehicles = async (params = {}) => {
    isLoading.value = true
    error.value = null
    try {
      const response = await vehiclesApi.list({
        page: pagination.value.page,
        per_page: pagination.value.perPage,
        ...filters.value,
        ...params,
      })
      vehicles.value = response.data || []
      pagination.value = {
        page: response.current_page || 1,
        perPage: response.per_page || 15,
        total: response.total || 0,
        lastPage: response.last_page || 1,
      }
      return response
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      isLoading.value = false
    }
  }

  const fetchVehicle = async (id) => {
    isLoading.value = true
    error.value = null
    try {
      currentVehicle.value = await vehiclesApi.get(id)
      return currentVehicle.value
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      isLoading.value = false
    }
  }

  const createVehicle = async (data) => {
    isLoading.value = true
    error.value = null
    try {
      const vehicle = await vehiclesApi.create(data)
      vehicles.value.unshift(vehicle)
      return vehicle
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      isLoading.value = false
    }
  }

  const updateVehicle = async (id, data) => {
    isLoading.value = true
    error.value = null
    try {
      const vehicle = await vehiclesApi.update(id, data)
      const index = vehicles.value.findIndex((v) => v.id === id)
      if (index !== -1) {
        vehicles.value[index] = vehicle
      }
      if (currentVehicle.value?.id === id) {
        currentVehicle.value = vehicle
      }
      return vehicle
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      isLoading.value = false
    }
  }

  const deleteVehicle = async (id) => {
    isLoading.value = true
    error.value = null
    try {
      await vehiclesApi.delete(id)
      vehicles.value = vehicles.value.filter((v) => v.id !== id)
      if (currentVehicle.value?.id === id) {
        currentVehicle.value = null
      }
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      isLoading.value = false
    }
  }

  const updateVehicleStatus = async (id, status) => {
    isLoading.value = true
    error.value = null
    try {
      const vehicle = await vehiclesApi.updateStatus(id, status)
      const index = vehicles.value.findIndex((v) => v.id === id)
      if (index !== -1) {
        vehicles.value[index] = vehicle
      }
      return vehicle
    } catch (err) {
      error.value = err.message
      throw err
    } finally {
      isLoading.value = false
    }
  }

  const setFilters = (newFilters) => {
    filters.value = { ...filters.value, ...newFilters }
    pagination.value.page = 1
  }

  const setPage = (page) => {
    pagination.value.page = page
  }

  const setPerPage = (perPage) => {
    pagination.value.perPage = perPage
    pagination.value.page = 1
  }

  return {
    // State
    vehicles,
    currentVehicle,
    isLoading,
    error,
    pagination,
    filters,
    // Computed
    activeVehicles,
    inactiveVehicles,
    vehicleCount,
    // Actions
    fetchVehicles,
    fetchVehicle,
    createVehicle,
    updateVehicle,
    deleteVehicle,
    updateVehicleStatus,
    setFilters,
    setPage,
    setPerPage,
  }
})
