import apiClient from '@/services/api/client'

export function register(payload) {
	return apiClient.post('/auth/register', payload)
}

export function login(payload) {
	return apiClient.post('/auth/login', payload)
}

export function logout() {
	return apiClient.post('/auth/logout')
}

export function fetchMe() {
	return apiClient.get('/me')
}
