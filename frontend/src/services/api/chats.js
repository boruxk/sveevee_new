import apiClient from '@/services/api/client'

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

export function sendChatMessageToUser(userId, body) {
	return apiClient.post(`/chats/users/${userId}/messages`, { body })
}

export function markChatRead(conversationId) {
	return apiClient.patch(`/chats/${conversationId}/read`)
}
