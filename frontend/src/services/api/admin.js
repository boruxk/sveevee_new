import apiClient from '@/services/api/client'

export function fetchAdminUsers(params = {}) {
	return apiClient.get('/admin/users', { params })
}

export function fetchAdminUser(id) {
	return apiClient.get(`/admin/users/${id}`)
}

export function banAdminUser(id, payload) {
	return apiClient.patch(`/admin/users/${id}/ban`, payload)
}

export function restoreAdminUser(id) {
	return apiClient.patch(`/admin/users/${id}/restore`)
}

export function messageAdminUser(id, body) {
	return apiClient.post(`/admin/users/${id}/message`, { body })
}
