import apiClient from './client'

export const submitLeadsPage001 = (payload) => apiClient.post('/business-page-leads', payload, {
	skipAuth: true
})

export const attachLeadsPage001 = (registrationToken) => apiClient.post('/business-page-leads/attach', {
	registration_token: registrationToken
})
