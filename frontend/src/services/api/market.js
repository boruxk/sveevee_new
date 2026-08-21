import apiClient from '@/services/api/client'

export function fetchMarket(params = {}) {
	const parts = ['market', params.citySlug]

	if (params.topicSlug) {
		parts.push(params.topicSlug)
	}

	return apiClient.get(`/${parts.filter(Boolean).join('/')}`, {
		params: params.limit ? { limit: params.limit } : undefined
	})
}
