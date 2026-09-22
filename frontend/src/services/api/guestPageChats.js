import apiClient from '@/services/api/client'
import { runGuestPageChatStart } from '@/utils/guestPageChatLifecycle'
import { storeGuestPageChatToken } from '@/utils/guestPageChatSession'

const guestHeaders = (token) => ({ 'X-Guest-Page-Chat-Token': token })

export function createGuestPageChat(pageId, payload) {
	return runGuestPageChatStart(pageId, async() => {
		const response = await apiClient.post(`/pages/${pageId}/guest-chat`, payload, { skipAuth: true })
		// Outlive a closed popup, and make the token available before other instances resume.
		storeGuestPageChatToken(pageId, response.data.data.token)

		return response
	})
}

export function fetchGuestPageChat(pageId, token, { markRead = true } = {}) {
	return apiClient.get(`/pages/${pageId}/guest-chat`, { headers: guestHeaders(token), skipAuth: true, params: { mark_read: markRead ? 1 : 0 } })
}

export function sendGuestPageChatMessage(pageId, token, payload) {
	return apiClient.post(`/pages/${pageId}/guest-chat/messages`, payload, { headers: guestHeaders(token), skipAuth: true })
}

export function claimGuestPageChat(pageId, token) {
	return apiClient.post(`/pages/${pageId}/guest-chat/claim`, {}, { headers: guestHeaders(token), timeout: 15000 })
}
