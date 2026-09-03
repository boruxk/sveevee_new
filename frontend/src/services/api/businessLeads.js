import apiClient from './client'

export const submitBusinessPageLead = (payload) => apiClient.post('/business-page-leads', payload, {
	skipAuth: true
})
