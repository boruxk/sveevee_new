import apiClient from '@/services/api/client'
import { appendImageFile } from '@/utils/imageFiles'

async function toAdFormData(payload) {
	const formData = new FormData()
	formData.append('title', payload.title || '')
	formData.append('text', payload.text || '')
	formData.append('category', payload.category || '')

	if (payload.page_id) {
		formData.append('page_id', payload.page_id)
	}

	if (payload.status) {
		formData.append('status', payload.status)
	}

	if (payload.image_remove) {
		formData.append('image_remove', '1')
	}

	if (payload.image) {
		await appendImageFile(formData, 'image', payload.image)
	}

	return formData
}

export function fetchAds(params = {}) {
	return apiClient.get('/ads', { params })
}

export function fetchAd(id) {
	return apiClient.get(`/ads/${id}`)
}

export async function createAd(payload) {
	return apiClient.post('/ads', await toAdFormData(payload))
}

export async function updateAd(id, payload) {
	const formData = await toAdFormData(payload)
	formData.append('_method', 'PUT')

	return apiClient.post(`/ads/${id}`, formData)
}

export function deleteAd(id) {
	return apiClient.delete(`/ads/${id}`)
}
