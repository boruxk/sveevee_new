import apiClient from '@/services/api/client'

export function fetchHomeFeed(params = {}) {
	return apiClient.get('/home-feed', { params })
}
