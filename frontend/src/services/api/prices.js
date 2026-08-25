import apiClient from '@/services/api/client'

export function createPrice(pageId, payload) {
	return apiClient.post(`/pages/${pageId}/prices`, payload)
}

export function updatePrice(id, payload) {
	return apiClient.put(`/page-prices/${id}`, payload)
}

export function deletePrice(id) {
	return apiClient.delete(`/page-prices/${id}`)
}
