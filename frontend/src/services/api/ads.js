import apiClient from '@/services/api/client'

function toAdFormData(payload) {
	const formData = new FormData()
	formData.append('title', payload.title || '')
	formData.append('text', payload.text || '')

	if (payload.page_id) {
		formData.append('page_id', payload.page_id)
	}

	if (payload.status) {
		formData.append('status', payload.status)
	}

	if (payload.image) {
		formData.append('image', Array.isArray(payload.image) ? payload.image[0] : payload.image)
	}

	return formData
}

export function fetchAds(params = {}) {
	return apiClient.get('/ads', { params })
}

export function createAd(payload) {
	return apiClient.post('/ads', toAdFormData(payload))
}

export function updateAd(id, payload) {
	return apiClient.post(`/ads/${id}?_method=PUT`, toAdFormData(payload))
}

export function deleteAd(id) {
	return apiClient.delete(`/ads/${id}`)
}
