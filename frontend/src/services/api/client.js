import axios from 'axios'
import { executeRecaptcha, recaptchaActionFromRequest } from '@/services/recaptcha'

const TOKEN_KEY = 'sveevee-token'

export const tokenStorageKey = TOKEN_KEY

const apiClient = axios.create({
	baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
	headers: {
		Accept: 'application/json'
	}
})

const MUTATING_METHODS = ['post', 'put', 'patch', 'delete']

apiClient.interceptors.request.use(async(config) => {
	const token = localStorage.getItem(TOKEN_KEY)
	const method = (config.method || 'get').toLowerCase()

	if (token) {
		config.headers.Authorization = `Bearer ${token}`
	}

	if (config.data instanceof FormData) {
		delete config.headers['Content-Type']
	} else {
		config.headers['Content-Type'] = 'application/json'
	}

	if (config.recaptcha || MUTATING_METHODS.includes(method)) {
		const action = recaptchaActionFromRequest(config)
		const recaptchaToken = await executeRecaptcha(action)

		if (recaptchaToken) {
			config.headers['X-Recaptcha-Action'] = action
			config.headers['X-Recaptcha-Token'] = recaptchaToken
		}
	}

	return config
})

apiClient.interceptors.response.use(
	(response) => response,
	(error) => {
		const payload = error.response?.data?.data

		if (error.response?.status === 503 && payload?.reason === 'maintenance') {
			window.dispatchEvent(new CustomEvent('sveevee:maintenance', {
				detail: payload.maintenance
			}))
		}

		return Promise.reject(error)
	}
)

export default apiClient
