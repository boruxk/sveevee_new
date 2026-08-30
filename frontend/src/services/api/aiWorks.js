import apiClient from '@/services/api/client'

export function fetchAiWorkTasks() {
	return apiClient.get('/ai-works/tasks')
}

export function createAiWorkTask(payload) {
	return apiClient.post('/ai-works/tasks', payload)
}

export function updateAiWorkTask(id, payload) {
	return apiClient.put(`/ai-works/tasks/${id}`, payload)
}

export function deleteAiWorkTask(id) {
	return apiClient.delete(`/ai-works/tasks/${id}`)
}

export function fetchAiWorkPages() {
	return apiClient.get('/ai-works/pages')
}

export function createAiWorkPage(payload) {
	return apiClient.post('/ai-works/pages', payload, { recaptcha: false })
}

export function updateAiWorkPage(id, payload) {
	return apiClient.put(`/ai-works/pages/${id}`, payload, { recaptcha: false })
}

export function deleteAiWorkPage(id) {
	return apiClient.delete(`/ai-works/pages/${id}`)
}
