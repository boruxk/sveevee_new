import apiClient from '@/services/api/client'

export function fetchNotifications(params = {}) {
	return apiClient.get('/notifications', { params })
}

export function markNotificationRead(id) {
	return apiClient.patch(`/notifications/${id}/read`, {}, { recaptcha: false })
}

export function markAllNotificationsRead() {
	return apiClient.patch('/notifications/read-all', {}, { recaptcha: false })
}
