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
const RECAPTCHA_RETRY_DELAY_MS = 300

const isRecaptchaRejection = (error) => {
	return error.response?.status === 422 && Array.isArray(error.response?.data?.errors?.recaptcha)
}

const wait = (milliseconds) => new Promise((resolve) => window.setTimeout(resolve, milliseconds))

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

	if (config.recaptcha !== false && (config.recaptcha || MUTATING_METHODS.includes(method))) {
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
	async(error) => {
		const payload = error.response?.data?.data

		if (error.response?.status === 503 && payload?.reason === 'maintenance') {
			window.dispatchEvent(new CustomEvent('sveevee:maintenance', {
				detail: payload.maintenance
			}))
		}

		if (isRecaptchaRejection(error) && error.config && !error.config.recaptchaRetried) {
			error.config.recaptchaRetried = true
			await wait(RECAPTCHA_RETRY_DELAY_MS)
			return apiClient.request(error.config)
		}

		return Promise.reject(error)
	}
)

export default apiClient
