const SITE_KEY = import.meta.env.VITE_RECAPTCHA_SITE_KEY || '6LdMPoItAAAAACyCtOSQcSYtSvjzBCHBbnz1ftDe'
const ENV_ENABLED = import.meta.env.VITE_RECAPTCHA_ENABLED
const LOAD_TIMEOUT_MS = 12000

let scriptPromise = null
let warmupInstalled = false

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

const waitForReady = async() => {
	const startedAt = Date.now()

	while (!window.grecaptcha?.ready) {
		if (Date.now() - startedAt >= LOAD_TIMEOUT_MS) {
			throw new Error('reCAPTCHA API did not become available in time.')
		}

		await new Promise((resolve) => window.setTimeout(resolve, 50))
	}

	await new Promise((resolve, reject) => {
		const timeout = window.setTimeout(
			() => reject(new Error('reCAPTCHA did not become ready in time.')),
			LOAD_TIMEOUT_MS
		)

		window.grecaptcha.ready(() => {
			window.clearTimeout(timeout)
			resolve()
		})
	})

	if (!window.grecaptcha?.execute) {
		throw new Error('reCAPTCHA execute API is unavailable.')
	}
}

const resetScriptLoader = () => {
	scriptPromise = null

	if (!window.grecaptcha?.execute) {
		document.querySelector('script[data-recaptcha-v3]')?.remove()
	}
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
		let timeout = null
		const finish = (callback, value) => {
			if (timeout) {
				window.clearTimeout(timeout)
			}

			callback(value)
		}

		if (existingScript) {
			timeout = window.setTimeout(
				() => reject(new Error('reCAPTCHA script load timed out.')),
				LOAD_TIMEOUT_MS
			)
			existingScript.addEventListener('load', () => finish(resolve, true), { once: true })
			existingScript.addEventListener('error', (error) => finish(reject, error), { once: true })
			return
		}

		const script = document.createElement('script')
		script.src = `https://www.google.com/recaptcha/api.js?render=${encodeURIComponent(SITE_KEY)}`
		script.async = true
		script.defer = true
		script.dataset.recaptchaV3 = 'true'
		timeout = window.setTimeout(
			() => reject(new Error('reCAPTCHA script load timed out.')),
			LOAD_TIMEOUT_MS
		)
		script.onload = () => finish(resolve, true)
		script.onerror = (error) => finish(reject, error)
		document.head.appendChild(script)
	}).catch((error) => {
		resetScriptLoader()
		throw error
	})

	return scriptPromise
}

export const recaptchaEnabled = () => Boolean(SITE_KEY && isBrowser && shouldRunRecaptcha())

export const prepareRecaptcha = async() => {
	if (!recaptchaEnabled()) {
		return false
	}

	try {
		await loadScript()
		await waitForReady()
		return true
	} catch {
		resetScriptLoader()
		return false
	}
}

export const installRecaptchaWarmup = () => {
	if (!recaptchaEnabled() || warmupInstalled) {
		return
	}

	warmupInstalled = true
	let warming = false

	const removeListeners = () => {
		document.removeEventListener('focusin', warmup, true)
		document.removeEventListener('pointerdown', warmup, true)
	}

	const warmup = (event) => {
		if (warming || !(event.target instanceof Element) || !event.target.closest('form')) {
			return
		}

		warming = true
		prepareRecaptcha().then((ready) => {
			if (ready) {
				removeListeners()
				return
			}

			warming = false
		})
	}

	document.addEventListener('focusin', warmup, true)
	document.addEventListener('pointerdown', warmup, true)
}

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

	const execute = async() => {
		await loadScript()
		await waitForReady()

		return window.grecaptcha.execute(SITE_KEY, { action })
	}

	try {
		return await execute()
	} catch {
		resetScriptLoader()
		return execute()
	}
}
