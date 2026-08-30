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

export function fetchAiWorkPages(params = {}) {
	return apiClient.get('/ai-works/pages', { params })
}

export function fetchAiWorkPage(id) {
	return apiClient.get(`/ai-works/pages/${id}`)
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

export function checkAiWorkPageDuplicate(payload) {
	return apiClient.post('/ai-works/pages/duplicate-check', payload, { recaptcha: false })
}

export function fetchAiWorkPreferences() {
	return apiClient.get('/ai-works/preferences')
}

export function updateAiWorkPreferences(pageDefaults) {
	return apiClient.patch('/ai-works/preferences', { page_defaults: pageDefaults }, { recaptcha: false })
}

export function fetchAiPageImports() {
	return apiClient.get('/ai-works/page-imports')
}

export function createAiPageImport(payload) {
	return apiClient.post('/ai-works/page-imports', payload, { recaptcha: false })
}
