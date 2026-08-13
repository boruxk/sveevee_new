import apiClient from '@/services/api/client'

export function fetchCatalog(params = {}) {
	const parts = [
		params.topicSlug,
		params.citySlug,
		params.neighborhoodSlug
	].filter(Boolean)

	const path = ['/catalog', ...parts].join('/')

	return apiClient.get(path)
}
