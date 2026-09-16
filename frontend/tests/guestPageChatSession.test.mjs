import assert from 'node:assert/strict'
import { beforeEach, test } from 'node:test'
import {
	clearPendingGuestPageChatClaim,
	completePendingGuestPageChatClaim,
	guestPageChatRedirect,
	readGuestPageChatToken,
	readPendingGuestPageChatClaim,
	rememberGuestPageChatClaim,
	removeGuestPageChatToken,
	storeGuestPageChatToken
} from '../src/utils/guestPageChatSession.js'

let values

beforeEach(() => {
	values = new Map()
	globalThis.window = {
		sessionStorage: {
			getItem: (key) => values.get(key) ?? null,
			setItem: (key, value) => values.set(key, value),
			removeItem: (key) => values.delete(key)
		}
	}
})

function prepare(pageId = 42, token = 'opaque-guest-token') {
	storeGuestPageChatToken(pageId, token)
	rememberGuestPageChatClaim({ pageId, redirect: `/en/business/${pageId}?pageChat=1` })
}

test('tokens are isolated per page and never put into claim intents or URLs', () => {
	prepare()
	assert.equal(storeGuestPageChatToken(43, 'another-token'), true)
	assert.equal(readGuestPageChatToken(42), 'opaque-guest-token')
	assert.equal(readGuestPageChatToken(43), 'another-token')
	assert.deepEqual(readPendingGuestPageChatClaim(), { pageId: 42, redirect: '/en/business/42?pageChat=1' })
	assert.equal(values.get('sveevee:guest-page-chat:claim').includes('opaque-guest-token'), false)
	removeGuestPageChatToken(42)
	assert.equal(readGuestPageChatToken(42), null)
	assert.equal(readGuestPageChatToken(43), 'another-token')
})

test('redirects only return to the intended business page and strip credential/query payloads', () => {
	assert.equal(guestPageChatRedirect('42', '/he/business/42?token=SECRET&redirect=https://evil.example&lang=ru#SECRET'), '/he/business/42?lang=ru&pageChat=1')
	assert.equal(guestPageChatRedirect(42, '/pages/42'), '/pages/42?pageChat=1')
	for (const redirect of [
		'https://evil.example/business/42', '//evil.example/business/42', '/\\evil.example/business/42',
		'/business/43', '/users/42', '/community/42', '/business/42/anything', '/%2fbusiness/42',
		'/business/42\n', '/business/42\u0000', '/business/42?x=1\r\nHost:evil.example'
	]) {
		assert.equal(guestPageChatRedirect(42, redirect), null, redirect)
	}
})

test('invalid identifiers, tokens and corrupted intents cannot grant access or a redirect', () => {
	for (const pageId of [0, -1, 1.5, true, null, {}, '1e2', Number.MAX_SAFE_INTEGER + 1]) {
		assert.equal(storeGuestPageChatToken(pageId, 'token'), false)
		assert.equal(rememberGuestPageChatClaim({ pageId, redirect: '/business/42' }), null)
	}
	for (const token of ['', ' token', 'token\nheader', 'a'.repeat(513), null, {}]) {
		assert.equal(storeGuestPageChatToken(42, token), false)
	}
	values.set('sveevee:guest-page-chat:claim', 'broken JSON')
	assert.equal(readPendingGuestPageChatClaim(), null)
	assert.equal(values.has('sveevee:guest-page-chat:claim'), false)
})

test('canonical public slugs preserve Unicode/encoded business URLs and validate the final page ID', () => {
	for (const slug of ['cafe-example-42', 'בית-קפה-42', 'café-déjà-vu-42', 'кафе-42', 'מסעדת-2026-42', 'page-42']) {
		const encoded = encodeURIComponent(slug)
		const expected = `/he/business/${encoded}?pageChat=1`
		assert.equal(guestPageChatRedirect(42, `/he/business/${slug}`), expected)
		assert.equal(guestPageChatRedirect(42, `/he/business/${encoded}`), expected)
		assert.equal(rememberGuestPageChatClaim({ pageId: 42, redirect: `/he/business/${encoded}` }).redirect, expected)
		assert.equal(readPendingGuestPageChatClaim().redirect, expected)
	}
	for (const slug of ['cafe-43', '42-cafe', 'cafe--42', '-42', 'cafe%2F42', 'cafe%5C-42', 'cafe%252F-42', 'cafe%00-42', '%C3%28-42', 'cafe%3Ftoken%3Dsecret-42']) {
		assert.equal(guestPageChatRedirect(42, `/business/${slug}`), null, slug)
	}
})

test('expired or future-dated registration intents are discarded without deleting other chats', () => {
	prepare()
	assert.equal(readPendingGuestPageChatClaim(Date.now() + 24 * 60 * 60 * 1000 + 1), null)
	assert.equal(readGuestPageChatToken(42), 'opaque-guest-token')
	prepare()
	assert.equal(readPendingGuestPageChatClaim(Date.now() - 2 * 60 * 1000), null)
})

test('unrelated login or a different page never claims a stored chat', async() => {
	storeGuestPageChatToken(42, 'token')
	const unexpectedClaim = async() => assert.fail('Unexpected claim')
	assert.deepEqual(await completePendingGuestPageChatClaim({ claim: unexpectedClaim }), { status: 'none' })
	prepare()
	assert.deepEqual(await completePendingGuestPageChatClaim({ pageId: 43, claim: unexpectedClaim }), { status: 'none' })
	assert.equal(readPendingGuestPageChatClaim().pageId, 42)
})

test('CTA before the first message still restores the page without a request', async() => {
	rememberGuestPageChatClaim({ pageId: 42, redirect: '/business/42' })
	const result = await completePendingGuestPageChatClaim({ claim: async() => assert.fail('No chat exists to claim') })
	assert.deepEqual(result, { status: 'none', pageId: 42, redirect: '/business/42?pageChat=1' })
	assert.equal(readPendingGuestPageChatClaim(), null)
})

test('successful claim preserves history response and removes only the claimed guest credential', async() => {
	prepare()
	storeGuestPageChatToken(43, 'unrelated-token')
	const conversation = { id: 91, messages: [{ id: 501, body: 'Prior message' }] }
	const result = await completePendingGuestPageChatClaim({ claim: async(pageId, token) => {
		assert.equal(pageId, 42)
		assert.equal(token, 'opaque-guest-token')
		return { data: { data: conversation } }
	} })
	assert.equal(result.status, 'claimed')
	assert.deepEqual(result.conversation, conversation)
	assert.equal(readGuestPageChatToken(42), null)
	assert.equal(readPendingGuestPageChatClaim(), null)
	assert.equal(readGuestPageChatToken(43), 'unrelated-token')
})

test('network and server errors preserve the authenticated registration flow and allow retry', async() => {
	for (const status of [undefined, 401, 403, 409, 422, 429, 500, 503]) {
		prepare()
		const result = await completePendingGuestPageChatClaim({ claim: async() => {
			throw status ? { response: { status } } : new Error('Network interrupted')
		} })
		assert.equal(result.status, 'pending')
		assert.equal(result.redirect, '/en/business/42?pageChat=1')
		assert.equal(readGuestPageChatToken(42), 'opaque-guest-token')
		assert.equal(readPendingGuestPageChatClaim().pageId, 42)
		const retry = await completePendingGuestPageChatClaim({ claim: async() => ({ data: { data: { id: 91 } } }) })
		assert.equal(retry.status, 'claimed')
	}
})

test('missing/expired guest access ends the claim without breaking registration', async() => {
	for (const status of [404, 410]) {
		prepare()
		const result = await completePendingGuestPageChatClaim({ claim: async() => { throw { response: { status } } } })
		assert.equal(result.status, 'unavailable')
		assert.equal(result.redirect, '/en/business/42?pageChat=1')
		assert.equal(readGuestPageChatToken(42), null)
		assert.equal(readPendingGuestPageChatClaim(), null)
	}
})

test('unexpected successful response cannot discard access to an unconfirmed chat', async() => {
	prepare()
	const result = await completePendingGuestPageChatClaim({ claim: async() => ({ data: {} }) })
	assert.equal(result.status, 'pending')
	assert.equal(result.reason, 'invalid_response')
	assert.equal(readGuestPageChatToken(42), 'opaque-guest-token')
	assert.equal(readPendingGuestPageChatClaim().pageId, 42)
})

test('concurrent retries reuse one request and never clear a newer page intent', async() => {
	prepare()
	let resolveClaim
	let requests = 0
	const claim = () => {
		requests += 1
		return new Promise((resolve) => { resolveClaim = resolve })
	}
	const first = completePendingGuestPageChatClaim({ claim })
	const second = completePendingGuestPageChatClaim({ claim })
	prepare(43, 'newer-token')
	resolveClaim({ data: { data: { id: 91 } } })
	assert.equal((await first).status, 'claimed')
	assert.equal((await second).status, 'claimed')
	assert.equal(requests, 1)
	assert.equal(readGuestPageChatToken(42), null)
	assert.equal(readPendingGuestPageChatClaim().pageId, 43)
	assert.equal(readGuestPageChatToken(43), 'newer-token')
})

test('blocked browser storage never makes the registration bridge throw', async() => {
	Object.defineProperty(window, 'sessionStorage', { get: () => { throw new Error('Storage disabled') } })
	assert.equal(readGuestPageChatToken(42), null)
	assert.equal(storeGuestPageChatToken(42, 'token'), false)
	assert.equal(rememberGuestPageChatClaim({ pageId: 42, redirect: '/business/42' }), null)
	assert.equal(readPendingGuestPageChatClaim(), null)
	assert.doesNotThrow(() => removeGuestPageChatToken(42))
	assert.doesNotThrow(() => clearPendingGuestPageChatClaim())
	assert.deepEqual(await completePendingGuestPageChatClaim(), { status: 'none' })
})
