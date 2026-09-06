const STORAGE_KEY = 'sveevee:leads-page-001:completion'
const REGISTRATION_STORAGE_KEY = 'sveevee:leads-page-001:registration'
const MAX_AGE_MS = 10 * 60 * 1000
const REGISTRATION_MAX_AGE_MS = 24 * 60 * 60 * 1000
const MAX_CLOCK_SKEW_MS = 60 * 1000

export function storeLeadsPage001Completion(pageId, created, registration = {}) {
	if (typeof window === 'undefined') {
		return
	}

	try {
		window.sessionStorage.removeItem(REGISTRATION_STORAGE_KEY)
		window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
			pageId: Number(pageId),
			created: created === true,
			createdAt: Date.now()
		}))

		const token = String(registration?.token || '').trim()
		const email = String(registration?.email || '').trim().toLowerCase()

		if (created === true && token) {
			window.sessionStorage.setItem(REGISTRATION_STORAGE_KEY, JSON.stringify({
				pageId: Number(pageId),
				token,
				email,
				createdAt: Date.now()
			}))
		}
	} catch {
		// The redirect must still work when browser storage is unavailable.
	}
}

export function consumeLeadsPage001Completion(expectedPageId, now = Date.now()) {
	if (typeof window === 'undefined') {
		return null
	}

	let raw
	try {
		raw = window.sessionStorage.getItem(STORAGE_KEY)
		window.sessionStorage.removeItem(STORAGE_KEY)
	} catch {
		return null
	}

	if (!raw) {
		return null
	}

	try {
		const value = JSON.parse(raw)
		const pageId = Number(value?.pageId)
		const createdAt = Number(value?.createdAt)

		if (
			!Number.isInteger(pageId) ||
			pageId <= 0 ||
			pageId !== Number(expectedPageId) ||
			typeof value?.created !== 'boolean' ||
			!Number.isFinite(createdAt) ||
			createdAt > now + MAX_CLOCK_SKEW_MS ||
			now - createdAt > MAX_AGE_MS
		) {
			return null
		}

		return {
			pageId,
			created: value.created
		}
	} catch {
		return null
	}
}

export function readLeadsPage001Registration(now = Date.now()) {
	if (typeof window === 'undefined') {
		return null
	}

	let raw
	try {
		raw = window.sessionStorage.getItem(REGISTRATION_STORAGE_KEY)
	} catch {
		return null
	}

	if (!raw) {
		return null
	}

	try {
		const value = JSON.parse(raw)
		const pageId = Number(value?.pageId)
		const token = String(value?.token || '').trim()
		const email = String(value?.email || '').trim().toLowerCase()
		const createdAt = Number(value?.createdAt)

		if (
			!Number.isInteger(pageId) ||
			pageId <= 0 ||
			!token ||
			!Number.isFinite(createdAt) ||
			createdAt > now + MAX_CLOCK_SKEW_MS ||
			now - createdAt > REGISTRATION_MAX_AGE_MS
		) {
			clearLeadsPage001Registration()

			return null
		}

		return { pageId, token, email }
	} catch {
		clearLeadsPage001Registration()

		return null
	}
}

export function clearLeadsPage001Registration() {
	if (typeof window === 'undefined') {
		return
	}

	try {
		window.sessionStorage.removeItem(REGISTRATION_STORAGE_KEY)
	} catch {
		// Browser storage may be unavailable without affecting normal authentication.
	}
}
