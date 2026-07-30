import apiClient from '@/services/api/client'

function addressLine(address) {
	if (!address || typeof address !== 'object') {
		return ''
	}

	return [address.street, address.number, address.neighborhood, address.city].filter(Boolean).join(', ')
}

function toPageFormData(payload) {
	const formData = new FormData()
	formData.append('name', payload.name || '')
	formData.append('public_description', payload.public_description || '')
	formData.append('contact_email', payload.contact_email || '')
	formData.append('phone', payload.phone || '')
	formData.append('address', payload.address || addressLine(payload.setup?.address) || '')
	formData.append('palette_key', payload.palette_key || 'amber-dawn')
	formData.append('setup', JSON.stringify(payload.setup || {}))

	if (payload.logo) {
		formData.append('logo', Array.isArray(payload.logo) ? payload.logo[0] : payload.logo)
	}

	if (payload.banner) {
		formData.append('banner', Array.isArray(payload.banner) ? payload.banner[0] : payload.banner)
	}

	return formData
}

export function fetchMyPage(type) {
	return apiClient.get(`/pages/${type}/mine`)
}

export function saveMyPage(type, payload) {
	return apiClient.post(`/pages/${type}`, toPageFormData(payload))
}

export function deletePage(id) {
	return apiClient.delete(`/pages/${id}`)
}

export function fetchPage(id) {
	return apiClient.get(`/pages/${id}`)
}

export function fetchPageRatings(id) {
	return apiClient.get(`/pages/${id}/ratings`)
}

export function savePageRating(id, payload) {
	return apiClient.put(`/pages/${id}/ratings/me`, payload)
}
