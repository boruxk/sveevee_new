import apiClient from '@/services/api/client'
import { appendImageFile } from '@/utils/imageFiles'

async function toServiceFormData(payload) {
	const formData = new FormData()
	formData.append('name', payload.name || '')
	formData.append('description', payload.description || '')
	formData.append('link', payload.link || '')

	if (payload.image_remove) {
		formData.append('image_remove', '1')
	}

	if (payload.image) {
		await appendImageFile(formData, 'image', payload.image)
	}

	return formData
}

export async function createService(pageId, payload) {
	return apiClient.post(`/pages/${pageId}/services`, await toServiceFormData(payload))
}

export async function updateService(id, payload) {
	const formData = await toServiceFormData(payload)
	formData.append('_method', 'PUT')

	return apiClient.post(`/services/${id}`, formData)
}

export function deleteService(id) {
	return apiClient.delete(`/services/${id}`)
}
