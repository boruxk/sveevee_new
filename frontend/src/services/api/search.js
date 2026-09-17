import apiClient from '@/services/api/client'

export function searchEverything(params = {}, { signal } = {}) {
	return apiClient.get('/search', {
		params: typeof params === 'string' ? { q: params } : params,
		recaptcha: true,
		signal
	})
}
