import apiClient from './client'

export const submitLeadsPage001 = (payload) => apiClient.post('/business-page-leads', payload, {
	skipAuth: true
})
