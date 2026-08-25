import apiClient from '@/services/api/client'
import { appendImageFile } from '@/utils/imageFiles'

async function toProductFormData(payload) {
	const formData = new FormData()
	formData.append('name', payload.name || '')
	formData.append('brand', payload.brand || '')
	formData.append('model', payload.model || '')
	formData.append('description', payload.description || '')
	formData.append('category_key', payload.category_key || '')
	formData.append('price', payload.price ?? '')
	formData.append('offer_enabled', payload.offer_enabled ? '1' : '0')
	formData.append('offer_price', payload.offer_enabled ? payload.offer_price ?? '' : '')
	formData.append('offer_starts_at', payload.offer_enabled ? payload.offer_starts_at || '' : '')
	formData.append('offer_ends_at', payload.offer_enabled ? payload.offer_ends_at || '' : '')
	formData.append('link', payload.link || '')

	if (payload.image_remove) {
		formData.append('image_remove', '1')
	}

	if (payload.image) {
		await appendImageFile(formData, 'image', payload.image)
	}

	return formData
}

export async function createProduct(pageId, payload) {
	return apiClient.post(`/pages/${pageId}/products`, await toProductFormData(payload))
}

export async function updateProduct(id, payload) {
	const formData = await toProductFormData(payload)
	formData.append('_method', 'PUT')

	return apiClient.post(`/products/${id}`, formData)
}

export function fetchProduct(id) {
	return apiClient.get(`/products/${id}`)
}

export function recordProductContact(id) {
	return apiClient.post(`/products/${id}/contact`)
}

export function deleteProduct(id) {
	return apiClient.delete(`/products/${id}`)
}
