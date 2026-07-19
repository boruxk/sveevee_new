import apiClient from '@/services/api/client'

export function fetchProfile() {
	return apiClient.get('/profile')
}

export function updateProfile(payload) {
	return apiClient.put('/profile', payload)
}

export function uploadProfilePhoto(file) {
	const formData = new FormData()
	formData.append('photo', Array.isArray(file) ? file[0] : file)

	return apiClient.post('/profile/photo', formData)
}
