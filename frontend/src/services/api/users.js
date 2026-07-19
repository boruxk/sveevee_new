import apiClient from '@/services/api/client'

export function fetchUser(id) {
	return apiClient.get(`/users/${id}`)
}
