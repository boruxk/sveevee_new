import apiClient from '@/services/api/client'
import { appendImageFile } from '@/utils/imageFiles'

export function fetchProfile() {
	return apiClient.get('/profile')
}

export function updateProfile(payload) {
	return apiClient.put('/profile', payload)
}

export function updateProfileLocale(locale) {
	return apiClient.put('/profile/locale', { locale })
}

export async function uploadProfilePhoto(file) {
	const formData = new FormData()
	await appendImageFile(formData, 'photo', file)

	return apiClient.post('/profile/photo', formData)
}

export function deleteProfilePhoto() {
	return apiClient.delete('/profile/photo')
}
