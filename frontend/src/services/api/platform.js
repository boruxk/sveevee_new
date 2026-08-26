import apiClient from '@/services/api/client'

export function fetchPlatformStatus() {
	return apiClient.get('/platform-status')
}
