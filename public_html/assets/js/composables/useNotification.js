import { ref, computed } from 'vue'
import { notificationsApi } from '@/api'

export function useNotification() {
  const notifications = ref([])
  const unreadCount = ref(0)
  const isLoading = ref(false)

  const hasUnread = computed(() => unreadCount.value > 0)

  const fetchNotifications = async () => {
    isLoading.value = true
    try {
      notifications.value = await notificationsApi.list({ limit: 50 })
      unreadCount.value = await notificationsApi.count()
    } catch (err) {
      console.error('Failed to fetch notifications:', err)
    } finally {
      isLoading.value = false
    }
  }

  const markAsRead = async (id) => {
    try {
      await notificationsApi.markAsRead(id)
      const notification = notifications.value.find((n) => n.id === id)
      if (notification) {
        notification.read_at = new Date().toISOString()
      }
      unreadCount.value = Math.max(0, unreadCount.value - 1)
    } catch (err) {
      console.error('Failed to mark notification as read:', err)
    }
  }

  const markAllAsRead = async () => {
    try {
      await notificationsApi.markAllAsRead()
      notifications.value.forEach((n) => {
        if (!n.read_at) {
          n.read_at = new Date().toISOString()
        }
      })
      unreadCount.value = 0
    } catch (err) {
      console.error('Failed to mark all notifications as read:', err)
    }
  }

  return {
    notifications,
    unreadCount,
    hasUnread,
    isLoading,
    fetchNotifications,
    markAsRead,
    markAllAsRead,
  }
}
