import apiClient from '@/services/api/client'

export function fetchCatalog(params = {}) {
	const parts = ['catalog']

	if (params.citySlug) {
		parts.push(params.citySlug)
	}

	if (params.citySlug && params.neighborhoodSlug) {
		parts.push(params.neighborhoodSlug)
	}

	if (params.topicSlug) {
		parts.push(params.topicSlug)
	}

	const path = `/${parts.join('/')}`

	return apiClient.get(path, {
		params: params.scope ? { scope: params.scope } : undefined
	})
}
