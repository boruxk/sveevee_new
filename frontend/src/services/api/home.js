import apiClient from '@/services/api/client'

export function fetchHomeFeed() {
	return apiClient.get('/home-feed')
}
