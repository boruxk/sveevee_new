import apiClient from '@/services/api/client'

export function fetchPageChat(pageId) {
	return apiClient.get(`/pages/${pageId}/chat`)
}

export function fetchPageChats(pageId) {
	return apiClient.get(`/pages/${pageId}/chats`)
}

export function fetchVisitorPageChats() {
	return apiClient.get('/page-chats')
}

export function fetchPageConversation(conversationId) {
	return apiClient.get(`/page-chats/${conversationId}`)
}

export function sendPageChatMessage(conversationId, body) {
	return apiClient.post(`/page-chats/${conversationId}/messages`, { body })
}

export function sendPageChatMessageToPage(pageId, body) {
	return apiClient.post(`/pages/${pageId}/chat/messages`, { body })
}

export function markPageChatRead(conversationId) {
	return apiClient.patch(`/page-chats/${conversationId}/read`)
}
