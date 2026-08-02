import apiClient from '@/services/api/client'
import { appendImageFile } from '@/utils/imageFiles'

async function toEventFormData(payload) {
	const formData = new FormData()
	formData.append('name', payload.name || '')
	formData.append('description', payload.description || '')
	formData.append('date', payload.date || '')
	formData.append('time', payload.time || '')
	formData.append('address', payload.address || '')

	if (payload.image) {
		await appendImageFile(formData, 'image', payload.image)
	}

	return formData
}

export async function createEvent(pageId, payload) {
	return apiClient.post(`/pages/${pageId}/events`, await toEventFormData(payload))
}
