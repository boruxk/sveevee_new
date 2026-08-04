import apiClient from '@/services/api/client'
import { appendImageFile } from '@/utils/imageFiles'

async function toProductFormData(payload) {
	const formData = new FormData()
	formData.append('name', payload.name || '')
	formData.append('description', payload.description || '')
	formData.append('price', payload.price || '')
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

export function deleteProduct(id) {
	return apiClient.delete(`/products/${id}`)
}
