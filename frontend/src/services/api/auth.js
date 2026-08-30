import apiClient from '@/services/api/client'

const apiBaseUrl = () => (import.meta.env.VITE_API_BASE_URL || '/api/v1').replace(/\/$/, '')

export function register(payload) {
	return apiClient.post('/auth/register', payload)
}

export function login(payload) {
	return apiClient.post('/auth/login', payload)
}

export function aiLogin(payload) {
	return apiClient.post('/auth/srvfrvrvv53Ljjug5h2h9zbdw', payload, { recaptcha: false })
}

export function forgotPassword(payload) {
	return apiClient.post('/auth/forgot-password', payload)
}

export function resetPassword(payload) {
	return apiClient.post('/auth/reset-password', payload)
}

export function logout() {
	return apiClient.post('/auth/logout')
}

export function fetchMe() {
	return apiClient.get('/me')
}

export function googleAuthRedirectUrl() {
	return `${apiBaseUrl()}/auth/google/redirect`
}
