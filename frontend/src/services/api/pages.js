import apiClient from '@/services/api/client'
import { appendImageFile } from '@/utils/imageFiles'

function addressLine(address) {
	if (!address || typeof address !== 'object') {
		return ''
	}

	return [address.street, address.number, address.neighborhood, address.city].filter(Boolean).join(', ')
}

async function toPageFormData(payload) {
	const formData = new FormData()
	formData.append('name', payload.name || '')
	formData.append('public_description', payload.public_description || '')
	formData.append('contact_email', payload.contact_email || '')
	formData.append('phone', payload.phone || '')
	formData.append('address', payload.address || addressLine(payload.setup?.address) || '')
	formData.append('website', payload.website || payload.setup?.website || '')
	formData.append('category_key', payload.category_key || '')
	formData.append('palette_key', payload.palette_key || 'amber-dawn')
	formData.append('setup', JSON.stringify(payload.setup || {}))

	if (payload.logo_remove) {
		formData.append('logo_remove', '1')
	}

	if (payload.logo) {
		await appendImageFile(formData, 'logo', payload.logo)
	}

	if (payload.banner_remove) {
		formData.append('banner_remove', '1')
	}

	if (payload.banner) {
		await appendImageFile(formData, 'banner', payload.banner)
	}

	return formData
}

export function fetchMyPage(type) {
	return apiClient.get(`/pages/${type}/mine`)
}

export async function saveMyPage(type, payload) {
	return apiClient.post(`/pages/${type}`, await toPageFormData(payload))
}

export function updatePageFeatures(type, payload) {
	return apiClient.patch(`/pages/${type}/features`, payload)
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

export function requestPageClaim(id, message) {
	return apiClient.post(`/pages/${id}/claim-requests`, { message })
}
