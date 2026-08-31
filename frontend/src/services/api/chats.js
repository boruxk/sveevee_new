import apiClient from '@/services/api/client'

export const guestSupportTokenStorageKey = 'sveevee-guest-support-token'

const guestSupportHeaders = (token) => ({
	'X-Guest-Support-Token': token
})

export function fetchChats() {
	return apiClient.get('/chats')
}

export function startChat(userId) {
	return apiClient.get(`/chats/users/${userId}`)
}

export function fetchChat(conversationId) {
	return apiClient.get(`/chats/${conversationId}`)
}

export function fetchSupportChat() {
	return apiClient.get('/chats/support')
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

export function fetchGuestSupportChat(token) {
	return apiClient.get('/guest-support', { headers: guestSupportHeaders(token), skipAuth: true })
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
