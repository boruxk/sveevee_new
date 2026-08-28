import apiClient from '@/services/api/client'

export function fetchAdminUsers(params = {}) {
	return apiClient.get('/admin/users', { params })
}

export function fetchAdminUserTable(params = {}) {
	return apiClient.get('/admin/users', { params: { paginated: 1, per_page: 50, ...params } })
}

export function fetchAdminSupportChats() {
	return apiClient.get('/admin/support-chats')
}

export function fetchAdminSupportChat(source, id) {
	return apiClient.get(`/admin/support-chats/${source}/${id}`)
}

export function sendAdminSupportMessage(source, id, body) {
	return apiClient.post(`/admin/support-chats/${source}/${id}/messages`, { body })
}

export function approvePageClaim(id) {
	return apiClient.post(`/admin/page-claims/${id}/approve`)
}

export function cancelPageClaim(id) {
	return apiClient.post(`/admin/page-claims/${id}/cancel`)
}

export function fetchAdminUser(id) {
	return apiClient.get(`/admin/users/${id}`)
}

export function deleteAdminUser(id) {
	return apiClient.delete(`/admin/users/${id}`)
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

export function fetchAdminSettings() {
	return apiClient.get('/admin/settings')
}

export function updateAdminSettings(section, payload) {
	return apiClient.patch(`/admin/settings/${section}`, payload)
}

export function fetchBlockedTerms() {
	return apiClient.get('/admin/blocked-terms')
}

export function createBlockedTerm(payload) {
	return apiClient.post('/admin/blocked-terms', payload)
}

export function updateBlockedTerm(id, payload) {
	return apiClient.put(`/admin/blocked-terms/${id}`, payload)
}

export function deleteBlockedTerm(id) {
	return apiClient.delete(`/admin/blocked-terms/${id}`)
}
