import apiClient from '@/services/api/client'

export function fetchLocations() {
	return apiClient.get('/locations')
}
