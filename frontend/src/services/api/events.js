import apiClient from '@/services/api/client'

function toEventFormData(payload) {
	const formData = new FormData()
	formData.append('name', payload.name || '')
	formData.append('description', payload.description || '')
	formData.append('date', payload.date || '')
	formData.append('time', payload.time || '')
	formData.append('address', payload.address || '')

	if (payload.image) {
		formData.append('image', Array.isArray(payload.image) ? payload.image[0] : payload.image)
	}

	return formData
}

export function createEvent(pageId, payload) {
	return apiClient.post(`/pages/${pageId}/events`, toEventFormData(payload))
}
