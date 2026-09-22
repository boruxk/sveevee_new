import apiClient from '@/services/api/client'

export const guestSupportTokenStorageKey = 'sveevee-guest-support-token'

const guestSupportHeaders = (token) => ({
	'X-Guest-Support-Token': token
})

export function fetchChats() {
	return apiClient.get('/chats')
}

export function startChat(userId, { markRead = true } = {}) {
	return apiClient.get(`/chats/users/${userId}`, { params: { mark_read: markRead ? 1 : 0 } })
}

export function fetchChat(conversationId, { markRead = true } = {}) {
	return apiClient.get(`/chats/${conversationId}`, { params: { mark_read: markRead ? 1 : 0 } })
}

export function fetchSupportChat({ markRead = true } = {}) {
	return apiClient.get('/chats/support', { params: { mark_read: markRead ? 1 : 0 } })
}

export function sendChatMessage(conversationId, body) {
	return apiClient.post(`/chats/${conversationId}/messages`, { body })
}

export function sendSupportChatMessage(body) {
	return apiClient.post('/chats/support/messages', { body })
}

export function startGuestSupportChat(payload) {
	return apiClient.post('/guest-support', payload, { skipAuth: true })
}

export function fetchGuestSupportChat(token, { markRead = true } = {}) {
	return apiClient.get('/guest-support', { headers: guestSupportHeaders(token), skipAuth: true, params: { mark_read: markRead ? 1 : 0 } })
}

export function sendGuestSupportMessage(token, body) {
	return apiClient.post('/guest-support/messages', { body }, { headers: guestSupportHeaders(token), skipAuth: true })
}

export function claimGuestSupportChat(token) {
	return apiClient.post('/guest-support/claim', {}, { headers: guestSupportHeaders(token) })
}

export function sendChatMessageToUser(userId, body) {
	return apiClient.post(`/chats/users/${userId}/messages`, { body })
}

export function markChatRead(conversationId) {
	return apiClient.patch(`/chats/${conversationId}/read`)
}

export function deleteChat(conversationId, mode) {
	return apiClient.delete(`/chats/${conversationId}`, { data: { mode } })
}
