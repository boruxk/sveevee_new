import axios from 'axios'

const TOKEN_KEY = 'sveevee-token'

export const tokenStorageKey = TOKEN_KEY

const apiClient = axios.create({
	baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
	headers: {
		Accept: 'application/json'
	}
})

apiClient.interceptors.request.use((config) => {
	const token = localStorage.getItem(TOKEN_KEY)

	if (token) {
		config.headers.Authorization = `Bearer ${token}`
	}

	if (config.data instanceof FormData) {
		delete config.headers['Content-Type']
	} else {
		config.headers['Content-Type'] = 'application/json'
	}

	return config
})

export default apiClient
