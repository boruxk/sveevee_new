import apiClient from '@/services/api/client'

export function searchEverything(q) {
	return apiClient.get('/search', { params: { q } })
}
