const SITE_KEY = import.meta.env.VITE_RECAPTCHA_SITE_KEY || '6LdMPoItAAAAACyCtOSQcSYtSvjzBCHBbnz1ftDe'
const ENV_ENABLED = import.meta.env.VITE_RECAPTCHA_ENABLED

let scriptPromise = null

const isBrowser = typeof window !== 'undefined' && typeof document !== 'undefined'

const isLocalHost = () => {
	if (!isBrowser) {
		return false
	}

	return ['localhost', '127.0.0.1', '::1'].includes(window.location.hostname)
}

const shouldRunRecaptcha = () => {
	if (ENV_ENABLED === 'true') {
		return true
	}

	if (ENV_ENABLED === 'false') {
		return false
	}

	return import.meta.env.PROD && !isLocalHost()
}

const waitForReady = () => {
	return new Promise((resolve) => {
		window.grecaptcha.ready(resolve)
	})
}

const loadScript = () => {
	if (!isBrowser || !SITE_KEY) {
		return Promise.resolve(false)
	}

	if (window.grecaptcha?.execute) {
		return Promise.resolve(true)
	}

	if (scriptPromise) {
		return scriptPromise
	}

	scriptPromise = new Promise((resolve, reject) => {
		const existingScript = document.querySelector('script[data-recaptcha-v3]')

		if (existingScript) {
			existingScript.addEventListener('load', () => resolve(true), { once: true })
			existingScript.addEventListener('error', reject, { once: true })
			return
		}

		const script = document.createElement('script')
		script.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(SITE_KEY)}`
		script.async = true
		script.defer = true
		script.dataset.recaptchaV3 = 'true'
		script.onload = () => resolve(true)
		script.onerror = reject
		document.head.appendChild(script)
	})

	return scriptPromise
}

export const recaptchaEnabled = () => Boolean(SITE_KEY && isBrowser && shouldRunRecaptcha())

export const recaptchaActionFromRequest = (config) => {
	const method = (config.method || 'get').toLowerCase()
	const rawUrl = config.url || ''
	const url = rawUrl.startsWith('http') ? new URL(rawUrl) : new URL(rawUrl, window.location.origin)
	const path = url.pathname
		.replace(/^\/api\/v1\//, '')
		.replace(/^\/+/, '')
		.replace(/[0-9a-f]{8,}/gi, 'id')
		.replace(/\d+/g, 'id')
		.replace(/[^A-Za-z0-9/_]/g, '_')
		.replace(/\/+/g, '/')
		.slice(0, 80)

	return `${method}_${path || 'root'}`.replace(/\/$/g, '')
}

export const executeRecaptcha = async(action) => {
	if (!recaptchaEnabled()) {
		return null
	}

	await loadScript()
	await waitForReady()

	return window.grecaptcha.execute(SITE_KEY, { action })
}
