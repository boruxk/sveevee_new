import apiClient from '@/services/api/client'
import { appendImageFile } from '@/utils/imageFiles'

async function toEventFormData(payload) {
	const formData = new FormData()
	formData.append('name', payload.name || '')
	formData.append('description', payload.description || '')
	formData.append('date', payload.date || '')
	formData.append('time', payload.time || '')
	formData.append('end_time', payload.end_time || '')
	formData.append('address', payload.address || '')

	if (payload.image_remove) {
		formData.append('image_remove', '1')
	}

	if (payload.image) {
		await appendImageFile(formData, 'image', payload.image)
	}

	return formData
}

export async function createEvent(pageId, payload) {
	return apiClient.post(`/pages/${pageId}/events`, await toEventFormData(payload))
}

export async function updateEvent(id, payload) {
	const formData = await toEventFormData(payload)
	formData.append('_method', 'PUT')

	return apiClient.post(`/events/${id}`, formData)
}

export function deleteEvent(id) {
	return apiClient.delete(`/events/${id}`)
}
