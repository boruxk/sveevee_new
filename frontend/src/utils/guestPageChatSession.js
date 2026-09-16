const TOKEN_STORAGE_PREFIX = 'sveevee:guest-page-chat:token:'
const CLAIM_STORAGE_KEY = 'sveevee:guest-page-chat:claim'
const CLAIM_MAX_AGE_MS = 24 * 60 * 60 * 1000
const MAX_CLOCK_SKEW_MS = 60 * 1000
const pendingRequests = new Map()

function validPageId(value) {
	if (!['number', 'string'].includes(typeof value) || (typeof value === 'string' && !/^[1-9]\d*$/.test(value))) {
		return null
	}

	const pageId = Number(value)

	return Number.isSafeInteger(pageId) && pageId > 0 ? pageId : null
}

function readStorage(key) {
	try {
		return typeof window === 'undefined' ? null : window.sessionStorage.getItem(key)
	} catch {
		return null
	}
}

function writeStorage(key, value) {
	try {
		if (typeof window === 'undefined') {
			return false
		}

		window.sessionStorage.setItem(key, value)

		return true
	} catch {
		return false
	}
}

function removeStorage(key) {
	try {
		if (typeof window !== 'undefined') {
			window.sessionStorage.removeItem(key)
		}
	} catch {
		// Storage restrictions must not prevent registration or opening the chat.
	}
}

function validToken(value) {
	return typeof value === 'string' && value.length > 0 && value.length <= 512 && /^[\x21-\x7e]+$/.test(value)
}

export function readGuestPageChatToken(pageId) {
	const id = validPageId(pageId)
	const token = id ? readStorage(`${TOKEN_STORAGE_PREFIX}${id}`) : null

	return validToken(token) ? token : null
}

export function storeGuestPageChatToken(pageId, token) {
	const id = validPageId(pageId)

	return Boolean(id && validToken(token) && writeStorage(`${TOKEN_STORAGE_PREFIX}${id}`, token))
}

export function removeGuestPageChatToken(pageId) {
	const id = validPageId(pageId)

	if (id) {
		removeStorage(`${TOKEN_STORAGE_PREFIX}${id}`)
	}
}

export function guestPageChatRedirect(pageId, redirect) {
	const id = validPageId(pageId)

	if (!id || typeof redirect !== 'string' || redirect.length > 2048 || /[\s\\]/.test(redirect) || Array.from(redirect).some((character) => character.charCodeAt(0) < 32 || character.charCodeAt(0) === 127)) {
		return null
	}

	try {
		const url = new URL(redirect, 'https://guest-chat.invalid')
		const match = url.pathname.match(/^\/(?:(?:he|en|ru|fr)\/)?(?:business|pages)\/([^/]+)\/?$/)

		if (!redirect.startsWith('/') || redirect.startsWith('//') || url.origin !== 'https://guest-chat.invalid' || !match) {
			return null
		}

		const slug = decodeURIComponent(match[1])
		// PublicSlug::make uses Unicode letters/numbers separated by hyphens, then the numeric ID.
		if (!/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u.test(slug)) {
			return null
		}
		const routeId = /^[1-9]\d*$/.test(slug) ? slug : slug.match(/-([1-9]\d*)$/)?.[1]
		if (validPageId(routeId) !== id) {
			return null
		}

		// Preserve the page language, but never carry credentials or nested redirects.
		const locale = url.searchParams.get('lang')
		const query = new URLSearchParams()
		if (['he', 'en', 'ru', 'fr'].includes(locale)) {
			query.set('lang', locale)
		}
		query.set('pageChat', '1')

		return `${url.pathname}?${query.toString()}`
	} catch {
		return null
	}
}

export function rememberGuestPageChatClaim({ pageId, redirect }) {
	const id = validPageId(pageId)
	const safeRedirect = guestPageChatRedirect(id, redirect)

	if (!id || !safeRedirect) {
		return null
	}

	const intent = { pageId: id, redirect: safeRedirect }

	return writeStorage(CLAIM_STORAGE_KEY, JSON.stringify({ ...intent, createdAt: Date.now() })) ? intent : null
}

export function clearPendingGuestPageChatClaim() {
	removeStorage(CLAIM_STORAGE_KEY)
}

export function readPendingGuestPageChatClaim(now = Date.now()) {
	const raw = readStorage(CLAIM_STORAGE_KEY)

	if (!raw) {
		return null
	}

	try {
		const value = JSON.parse(raw)
		const pageId = validPageId(value?.pageId)
		const redirect = guestPageChatRedirect(pageId, value?.redirect)
		const createdAt = value?.createdAt

		if (pageId && redirect && typeof createdAt === 'number' && Number.isFinite(createdAt) && createdAt <= now + MAX_CLOCK_SKEW_MS && now - createdAt <= CLAIM_MAX_AGE_MS) {
			return { pageId, redirect }
		}
	} catch {
		// Corrupt or stale intents should behave like an ordinary registration.
	}

	clearPendingGuestPageChatClaim()

	return null
}

async function requestClaim(pageId, token) {
	const { claimGuestPageChat } = await import('../services/api/guestPageChats.js')

	return claimGuestPageChat(pageId, token)
}

function clearCompletedClaim(intent, token) {
	const current = readPendingGuestPageChatClaim()
	if (readGuestPageChatToken(intent.pageId) === token) {
		if (current?.pageId === intent.pageId && current.redirect === intent.redirect) {
			clearPendingGuestPageChatClaim()
		}
		removeGuestPageChatToken(intent.pageId)
	}
}

export async function completePendingGuestPageChatClaim({ pageId, claim = requestClaim } = {}) {
	const intent = readPendingGuestPageChatClaim()

	if (!intent || (pageId !== undefined && validPageId(pageId) !== intent.pageId)) {
		return { status: 'none' }
	}

	const token = readGuestPageChatToken(intent.pageId)

	if (!token) {
		clearPendingGuestPageChatClaim()

		return { ...intent, status: 'none' }
	}

	const key = `${intent.pageId}:${token}`
	if (pendingRequests.has(key)) {
		return pendingRequests.get(key)
	}

	const pending = (async() => {
		try {
			const response = await claim(intent.pageId, token)
			const payload = response?.data?.data || response?.data
			const conversation = payload?.conversation || payload
			if (!validPageId(conversation?.id)) {
				return { ...intent, status: 'pending', reason: 'invalid_response' }
			}
			clearCompletedClaim(intent, token)

			return { ...intent, status: 'claimed', conversation }
		} catch (error) {
			const status = error?.response?.status
			const unavailable = status === 404 || status === 410

			if (unavailable) {
				clearCompletedClaim(intent, token)
			}

			return { ...intent, status: unavailable ? 'unavailable' : 'pending', reason: status ? `http_${status}` : 'network' }
		}
	})()
	pendingRequests.set(key, pending)

	try {
		return await pending
	} finally {
		pendingRequests.delete(key)
	}
}
