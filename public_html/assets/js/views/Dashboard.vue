<template>
  <div class="dashboard p-6">
    <div class="mb-6">
      <h1 class="text-2xl font-bold text-gray-900">Dashboard</h1>
      <p class="text-gray-600">Welcome back, {{ authStore.userName }}</p>
    </div>

    <!-- Stats Cards -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
      <div
        v-for="stat in stats"
        :key="stat.label"
        class="bg-white rounded-lg shadow p-6 border-l-4"
        :class="stat.borderColor"
      >
        <div class="flex items-center justify-between">
          <div>
            <p class="text-sm text-gray-600">{{ stat.label }}</p>
            <p class="text-2xl font-bold mt-1" :class="stat.textColor">{{ stat.value }}</p>
          </div>
          <component :is="stat.icon" class="w-8 h-8" :class="stat.iconColor" />
        </div>
      </div>
    </div>

    <!-- Recent Activity -->
    <div class="bg-white rounded-lg shadow p-6">
      <h2 class="text-lg font-semibold text-gray-900 mb-4">Recent Activity</h2>
      <p class="text-gray-500">Loading recent activity...</p>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores'

const authStore = useAuthStore()

const stats = ref([
  {
    label: 'Active Vehicles',
    value: '24',
    icon: 'CarIcon',
    textColor: 'text-blue-600',
    iconColor: 'text-blue-500',
    borderColor: 'border-blue-500',
  },
  {
    label: 'Today\'s Trips',
    value: '12',
    icon: 'MapIcon',
    textColor: 'text-green-600',
    iconColor: 'text-green-500',
    borderColor: 'border-green-500',
  },
  {
    label: 'Pending Requests',
    value: '5',
    icon: 'ClockIcon',
    textColor: 'text-yellow-600',
    iconColor: 'text-yellow-500',
    borderColor: 'border-yellow-500',
  },
  {
    label: 'Active Drivers',
    value: '18',
    icon: 'UserIcon',
    textColor: 'text-purple-600',
    iconColor: 'text-purple-500',
    borderColor: 'border-purple-500',
  },
])

onMounted(() => {
  // Fetch dashboard data
})
</script>
