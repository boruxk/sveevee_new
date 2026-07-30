import apiClient from '@/services/api/client'

function toProductFormData(payload) {
	const formData = new FormData()
	formData.append('name', payload.name || '')
	formData.append('description', payload.description || '')
	formData.append('price', payload.price || '')
	formData.append('link', payload.link || '')

	if (payload.image) {
		formData.append('image', Array.isArray(payload.image) ? payload.image[0] : payload.image)
	}

	return formData
}

export function createProduct(pageId, payload) {
	return apiClient.post(`/pages/${pageId}/products`, toProductFormData(payload))
}
