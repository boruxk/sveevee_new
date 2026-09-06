const STORAGE_KEY = 'sveevee:leads-page-001:completion'
const MAX_AGE_MS = 10 * 60 * 1000
const MAX_CLOCK_SKEW_MS = 60 * 1000

export function storeLeadsPage001Completion(pageId, created) {
	if (typeof window === 'undefined') {
		return
	}

	try {
		window.sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
			pageId: Number(pageId),
			created: created === true,
			createdAt: Date.now()
		}))
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
