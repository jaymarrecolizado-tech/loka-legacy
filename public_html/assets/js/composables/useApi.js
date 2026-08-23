import { ref } from 'vue'
import api from '@/api'

export function useApi() {
  const isLoading = ref(false)
  const error = ref(null)
  const data = ref(null)

  const execute = async (apiFunction, ...args) => {
    isLoading.value = true
    error.value = null
    try {
      data.value = await apiFunction(...args)
      return data.value
    } catch (err) {
      error.value = err
      throw err
    } finally {
      isLoading.value = false
    }
  }

  const reset = () => {
    isLoading.value = false
    error.value = null
    data.value = null
  }

  return {
    isLoading,
    error,
    data,
    execute,
    reset,
  }
}

export function usePaginatedApi(apiFunction) {
  const { isLoading, error, execute, reset } = useApi()
  const items = ref([])
  const pagination = ref({
    page: 1,
    perPage: 15,
    total: 0,
    lastPage: 1,
  })

  const fetch = async (params = {}) => {
    const result = await execute(apiFunction, {
      page: pagination.value.page,
      per_page: pagination.value.perPage,
      ...params,
    })

    if (result) {
      items.value = result.data || []
      pagination.value = {
        page: result.current_page || 1,
        perPage: result.per_page || 15,
        total: result.total || 0,
        lastPage: result.last_page || 1,
      }
    }

    return result
  }

  const nextPage = () => {
    if (pagination.value.page < pagination.value.lastPage) {
      pagination.value.page++
      return fetch()
    }
  }

  const prevPage = () => {
    if (pagination.value.page > 1) {
      pagination.value.page--
      return fetch()
    }
  }

  const goToPage = (page) => {
    pagination.value.page = page
    return fetch()
  }

  const setPerPage = (perPage) => {
    pagination.value.perPage = perPage
    pagination.value.page = 1
    return fetch()
  }

  return {
    isLoading,
    error,
    items,
    pagination,
    fetch,
    nextPage,
    prevPage,
    goToPage,
    setPerPage,
    reset: () => {
      reset()
      items.value = []
      pagination.value = {
        page: 1,
        perPage: 15,
        total: 0,
        lastPage: 1,
      }
    },
  }
}
