import apiClient from '@/services/api/client'

export function searchEverything(params = {}) {
	return apiClient.get('/search', {
		params: typeof params === 'string' ? { q: params } : params,
		recaptcha: true
	})
}
