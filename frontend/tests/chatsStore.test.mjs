import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { createPinia, defineStore } from 'pinia'
import { toRaw } from 'vue'

const source = (await readFile(new URL('../src/stores/chats.js', import.meta.url), 'utf8'))
	.replace(/^import[\s\S]*?from '[^']+'\r?\n/gm, '')
	.replace('export const useChatsStore', 'const useChatsStore')

function deferred() {
	let resolve, reject
	const promise = new Promise((resolvePromise, rejectPromise) => { resolve = resolvePromise; reject = rejectPromise })
	return { promise, resolve, reject }
}

const tick = () => new Promise(resolve => setImmediate(resolve))
const response = data => ({ data: { data } })
const conversation = (id, overrides = {}) => ({ id, is_page_chat: false, last_message_at: '2026-09-18T12:00:00Z', latest_message: { body: `Chat ${id}` }, unread_count: 0, ...overrides })

function setup(overrides = {}) {
	const auth = { token: 'session-a', user: { id: 1 }, setUnreadMessagesCount(count) { this.user.unread_messages_count = count } }
	const privateRequests = []
	const pageRequests = []
	const mutations = []
	let now = 100_000
	const api = {
		fetchChats: () => { const request = deferred(); privateRequests.push(request); return request.promise },
		fetchVisitorPageChats: () => { const request = deferred(); pageRequests.push(request); return request.promise },
		fetchChat: async id => { mutations.push('fetchChat'); return response(conversation(id)) },
		fetchPageConversation: async id => { mutations.push('fetchPageConversation'); return response(conversation(id, { is_page_chat: true })) },
		startChat: async id => { mutations.push('startChat'); return response(conversation(id)) },
		sendChatMessage: async id => { mutations.push('sendChatMessage'); return response(conversation(id)) },
		sendChatMessageToUser: async id => { mutations.push('sendChatMessageToUser'); return response(conversation(id)) },
		sendPageChatMessage: async id => { mutations.push('sendPageChatMessage'); return response(conversation(id, { is_page_chat: true })) },
		markChatRead: async () => { mutations.push('markChatRead'); return response({ unread_count: 0 }) },
		markPageChatRead: async () => { mutations.push('markPageChatRead'); return response({ unread_count: 0 }) },
		deleteChat: async () => { mutations.push('deleteChat'); return response({ unread_count: 0 }) },
		...overrides
	}
	class FakeDate extends Date { static now() { return now } }
	const factory = new Function('defineStore', 'useAuthStore', 'toRaw', 'Date', ...Object.keys(api), source + '\nreturn useChatsStore')
	const store = factory(defineStore, () => auth, toRaw, FakeDate, ...Object.values(api))(createPinia())
	const finish = (index, conversations = [], pageConversations = [], unread = 0) => {
		privateRequests[index].resolve(response({ conversations, unread_count: unread }))
		pageRequests[index].resolve(response({ conversations: pageConversations }))
	}
	return { store, auth, privateRequests, pageRequests, mutations, finish, advance: milliseconds => { now += milliseconds } }
}

test('shell, overview and chat consumers share one request pair and a five-second freshness window', async () => {
	const { store, privateRequests, pageRequests, finish, advance } = setup()
	const first = store.loadConversations()
	const second = store.loadConversations()
	const third = store.loadConversations()
	assert.equal(store.loading, true)
	assert.equal(privateRequests.length, 1)
	assert.equal(pageRequests.length, 1)
	finish(0, [conversation(1)], [conversation(1, { is_page_chat: true, last_message_at: '2026-09-18T13:00:00Z' })], 4)
	await Promise.all([first, second, third])
	assert.equal(store.hasLoaded, true)
	assert.equal(store.loading, false)
	assert.equal(store.unreadCount, 4)
	assert.equal(store.conversations.length, 2, 'Different conversation types may legitimately share a numeric ID')
	assert.equal(store.conversations[0].is_page_chat, true)
	advance(4999)
	await store.loadConversations()
	assert.equal(privateRequests.length, 1)
	advance(1)
	const refresh = store.loadConversations()
	assert.equal(privateRequests.length, 2)
	assert.equal(store.loading, false)
	assert.equal(store.conversations.length, 2)
	finish(1, [conversation(2)])
	await refresh
	assert.equal(store.conversations[0].id, 2)
})

test('successful empty lists are cached and do not turn back into initial-loading spinners', async () => {
	const { store, privateRequests, finish, advance } = setup()
	const initial = store.loadConversations()
	finish(0)
	await initial
	await store.loadConversations()
	assert.equal(privateRequests.length, 1)
	assert.equal(store.hasLoaded, true)
	advance(5000)
	const refresh = store.loadConversations()
	assert.equal(store.loading, false)
	finish(1)
	await refresh
})

test('Pinia development action proxies share session state, cache and reset invalidation', async () => {
	const { store, privateRequests, finish } = setup()
	const trackedStore = () => new Proxy(store, {
		get: (target, key, receiver) => Reflect.get(target, key, receiver),
		set: (target, key, value, receiver) => Reflect.set(target, key, value, receiver)
	})
	const first = store.loadConversations.call(trackedStore())
	const second = store.loadConversations.call(trackedStore())
	assert.equal(privateRequests.length, 1)
	finish(0, [conversation(3)])
	await Promise.all([first, second])
	await store.loadConversations.call(trackedStore())
	assert.equal(privateRequests.length, 1)
	const old = store.loadConversations.call(trackedStore(), { force: true })
	store.reset.call(trackedStore())
	const current = store.loadConversations.call(trackedStore())
	finish(1, [conversation(4)])
	await old
	assert.deepEqual(store.conversations, [])
	assert.equal(store.loading, true)
	finish(2, [conversation(5)])
	await current
	assert.equal(store.conversations[0].id, 5)
})

test('failed refresh preserves the displayed list and unread count, then retries without caching the failure', async () => {
	const { store, privateRequests, pageRequests, finish } = setup()
	const initial = store.loadConversations()
	finish(0, [conversation(4)], [], 2)
	await initial
	const refresh = store.loadConversations({ force: true })
	assert.equal(store.loading, false)
	privateRequests[1].reject(new Error('network'))
	pageRequests[1].resolve(response({ conversations: [] }))
	await assert.rejects(refresh, /network/)
	assert.equal(store.conversations[0].id, 4)
	assert.equal(store.unreadCount, 2)
	assert.equal(store.hasLoaded, true)
	assert.equal(store.loading, false)
	const retry = store.loadConversations()
	assert.equal(privateRequests.length, 3)
	finish(2, [conversation(5)])
	await retry
	assert.equal(store.conversations[0].id, 5)
})

test('an initial failure clears loading and permits a fresh initial retry', async () => {
	const { store, privateRequests, pageRequests, finish } = setup()
	const failed = store.loadConversations()
	privateRequests[0].reject(new Error('offline'))
	pageRequests[0].resolve(response({ conversations: [] }))
	await assert.rejects(failed, /offline/)
	assert.equal(store.hasLoaded, false)
	assert.equal(store.loading, false)
	const retry = store.loadConversations()
	assert.equal(store.loading, true)
	finish(1, [conversation(6)])
	await retry
	assert.equal(store.loading, false)
})

test('mutations during an older read share one queued fresh pass and the older result never replaces post-mutation state', async () => {
	const { store, privateRequests, finish } = setup()
	const initial = store.loadConversations()
	const firstMutationRefresh = store.loadConversations({ force: true })
	const secondMutationRefresh = store.loadConversations({ force: true })
	assert.equal(privateRequests.length, 1)
	finish(0, [conversation(1)])
	await initial
	await tick()
	assert.equal(privateRequests.length, 2)
	assert.deepEqual(store.conversations, [])
	assert.equal(store.loading, true)
	finish(1, [conversation(2)])
	await Promise.all([firstMutationRefresh, secondMutationRefresh])
	assert.equal(store.conversations[0].id, 2)
	assert.equal(store.loading, false)
})

test('a mutation after the queued pass started gets another fresh pass, while simultaneous mutations still coalesce', async () => {
	const { store, privateRequests, finish } = setup()
	const initial = store.loadConversations()
	const queued = store.loadConversations({ force: true })
	finish(0)
	await initial
	await tick()
	assert.equal(privateRequests.length, 2)
	const laterMutation = store.loadConversations({ force: true })
	const samePass = store.loadConversations({ force: true })
	finish(1, [conversation(7)])
	await queued
	await tick()
	assert.equal(privateRequests.length, 3)
	assert.deepEqual(store.conversations, [])
	finish(2, [conversation(8)])
	await Promise.all([laterMutation, samePass])
	assert.equal(store.conversations[0].id, 8)
})

test('a forced refresh still runs after the pending older request failed', async () => {
	const { store, privateRequests, pageRequests, finish } = setup()
	const initial = store.loadConversations()
	const rejected = assert.rejects(initial, /old request/)
	const forced = store.loadConversations({ force: true })
	privateRequests[0].reject(new Error('old request'))
	pageRequests[0].resolve(response({ conversations: [] }))
	await rejected
	await tick()
	assert.equal(privateRequests.length, 2)
	finish(1, [conversation(9)])
	await forced
	assert.equal(store.conversations[0].id, 9)
})

test('account changes discard old responses, unread totals and queued work without clearing the new loading state', async () => {
	const { store, auth, privateRequests, finish } = setup()
	const old = store.loadConversations()
	const oldQueued = store.loadConversations({ force: true })
	auth.token = 'session-b'
	auth.user = { id: 2 }
	const current = store.loadConversations()
	assert.equal(privateRequests.length, 2)
	finish(0, [conversation(1)], [], 99)
	await Promise.all([old, oldQueued])
	assert.equal(privateRequests.length, 2, 'The previous account must not schedule another API request')
	assert.deepEqual(store.conversations, [])
	assert.equal(store.loading, true)
	assert.equal(auth.user.unread_messages_count, undefined)
	finish(1, [conversation(2)], [], 3)
	await current
	assert.equal(store.conversations[0].id, 2)
	assert.equal(auth.user.unread_messages_count, 3)
})

test('reset invalidates pending reads even for the same account; guests never fetch private lists', async () => {
	const { store, auth, privateRequests, finish } = setup()
	const old = store.loadConversations()
	store.reset()
	const current = store.loadConversations()
	finish(0, [conversation(1)])
	await old
	assert.deepEqual(store.conversations, [])
	assert.equal(store.loading, true)
	finish(1, [conversation(2)])
	await current
	auth.token = null
	auth.user = null
	await store.loadConversations()
	assert.deepEqual(store.conversations, [])
	assert.equal(store.hasLoaded, false)
	assert.equal(store.unreadCount, 0)
	assert.equal(privateRequests.length, 2)
})

test('open/start/send/read/delete actions refresh server state even within the freshness window', async () => {
	const { store, privateRequests, finish } = setup()
	const initial = store.loadConversations()
	finish(0, [conversation(1)])
	await initial
	const operations = [
		() => store.openConversation(1),
		() => store.openWithUser(1),
		() => store.send('Hello'),
		() => store.markRead(1),
		() => store.openConversation(1, 'page'),
		() => store.send('Page message'),
		() => store.markRead(1, 'page'),
		() => store.deleteConversation(1, 'self')
	]
	for (const [index, operation] of operations.entries()) {
		const pending = operation()
		await tick()
		assert.equal(privateRequests.length, index + 2)
		assert.equal(store.loading, false)
		finish(index + 1, [conversation(1)])
		await pending
	}
})

test('late detail and mutation responses cannot recreate conversations after logout', async () => {
	for (const operation of ['open', 'start', 'send', 'read', 'delete']) {
		const late = deferred()
		const apiName = { open: 'fetchChat', start: 'startChat', send: 'sendChatMessage', read: 'markChatRead', delete: 'deleteChat' }[operation]
		const { store, auth, privateRequests, finish } = setup({ [apiName]: () => late.promise })
		const initial = store.loadConversations()
		finish(0, [conversation(1)])
		await initial
		store.activeConversation = conversation(1)
		const actions = { open: () => store.openConversation(1), start: () => store.openWithUser(1), send: () => store.send('Hello'), read: () => store.markRead(1), delete: () => store.deleteConversation(1, 'self') }
		const pending = actions[operation]()
		auth.token = null
		auth.user = null
		store.reset()
		late.resolve(response(conversation(1, { unread_count: 99 })))
		await pending
		assert.deepEqual(store.conversations, [], operation)
		assert.equal(store.activeConversation, null, operation)
		assert.equal(store.unreadCount, 0, operation)
		assert.equal(store.sending, false, operation)
		assert.equal(privateRequests.length, 1, operation)
	}
})


test('visible detail refresh clears only that partner badge and preserves unread totals from other chat areas', async () => {
	const { store, finish } = setup({ fetchChat: async id => response(conversation(id, { latest_message: { id: 12 }, unread_count: 0 })) })
	const initial = store.loadConversations()
	finish(0, [conversation(1, { latest_message: { id: 12 }, unread_count: 2 })], [conversation(1, { is_page_chat: true, unread_count: 3 })], 9)
	await initial
	store.activeConversation = conversation(1)
	await store.refreshActiveConversation()
	assert.equal(store.conversations.find(row => !row.is_page_chat).unread_count, 0)
	assert.equal(store.conversations.find(row => row.is_page_chat).unread_count, 3)
	assert.equal(store.unreadCount, 7, 'Unread messages in owned page/support areas remain counted')
})

test('an older detail response cannot clear a newer incoming message shown by the list', async () => {
	const late = deferred()
	const { store, finish } = setup({ fetchChat: () => late.promise })
	const initial = store.loadConversations()
	finish(0, [conversation(1, { latest_message: { id: 10 }, unread_count: 1 })], [], 1)
	await initial
	store.activeConversation = conversation(1)
	const pending = store.refreshActiveConversation()
	store.conversations[0] = conversation(1, { latest_message: { id: 11 }, unread_count: 2 })
	store.syncUnread(2)
	late.resolve(response(conversation(1, { latest_message: { id: 10 }, unread_count: 0 })))
	await pending
	assert.equal(store.conversations[0].unread_count, 2)
	assert.equal(store.unreadCount, 2)
})

test('list metadata refresh does not discard a completed visible detail read', async () => {
	const late = deferred()
	const { store, finish } = setup({ fetchChat: () => late.promise })
	const initial = store.loadConversations()
	finish(0, [conversation(1, { latest_message: { id: 10 }, unread_count: 1 })], [], 1)
	await initial
	store.activeConversation = conversation(1)
	const pending = store.refreshActiveConversation()
	const refresh = store.loadConversations({ force: true })
	finish(1, [conversation(1, { latest_message: { id: 10 }, unread_count: 1 })], [], 1)
	await refresh
	late.resolve(response(conversation(1, { latest_message: { id: 10 }, unread_count: 0, messages: [{ id: 10, body: 'Visible incoming' }] })))
	await pending
	assert.equal(store.activeMessages[0].body, 'Visible incoming')
	assert.equal(store.conversations[0].unread_count, 0)
	assert.equal(store.unreadCount, 0)
})

test('an in-flight list from before a read is superseded rather than restoring an unread badge', async () => {
	const { store, finish, privateRequests } = setup({ fetchChat: async id => response(conversation(id, { latest_message: { id: 10 }, unread_count: 0 })) })
	const initial = store.loadConversations()
	finish(0, [conversation(1, { latest_message: { id: 10 }, unread_count: 1 })], [], 1)
	await initial
	store.activeConversation = conversation(1)
	const oldList = store.loadConversations({ force: true })
	await store.refreshActiveConversation()
	assert.equal(store.conversations[0].unread_count, 0)
	finish(1, [conversation(1, { latest_message: { id: 10 }, unread_count: 1 })], [], 1)
	await oldList
	await tick()
	assert.equal(store.conversations[0].unread_count, 0)
	assert.equal(privateRequests.length, 3)
	finish(2, [conversation(1, { latest_message: { id: 10 }, unread_count: 0 })])
	await tick()
})

test('hidden document and hidden thread refreshes do not mark messages read', async () => {
	const previousDocument = globalThis.document
	try {
		globalThis.document = { visibilityState: 'hidden' }
		const { store, finish, mutations } = setup()
		const initial = store.loadConversations()
		finish(0, [conversation(1, { unread_count: 1 })], [], 1)
		await initial
		store.activeConversation = conversation(1)
		await store.refreshActiveConversation()
		assert.deepEqual(mutations, [])
		globalThis.document.visibilityState = 'visible'
		await store.refreshActiveConversation(() => false)
		assert.deepEqual(mutations, [])
		assert.equal(store.unreadCount, 1)
	} finally {
		if (previousDocument === undefined) delete globalThis.document
		else globalThis.document = previousDocument
	}
})

test('string page conversation IDs use the page read endpoint', async () => {
	const { store, finish, mutations } = setup()
	const initial = store.loadConversations()
	finish(0, [], [conversation(1, { is_page_chat: true, unread_count: 1 })], 1)
	await initial
	store.activeConversation = conversation(1, { is_page_chat: true })
	const pending = store.markRead('1')
	await tick()
	finish(1)
	await pending
	assert.deepEqual(mutations, ['markPageChatRead'])
})


test('polls started before or during a send cannot replace the sent message with older history', async () => {
	for (const startsDuringSend of [false, true]) {
		const latePoll = deferred()
		const sendResult = deferred()
		const latest = conversation(1, { latest_message: { id: 11 }, messages: [{ id: 10 }, { id: 11, body: 'Newly sent' }] })
		const { store, finish } = setup({ fetchChat: () => latePoll.promise, sendChatMessage: () => sendResult.promise })
		const initial = store.loadConversations()
		finish(0, [conversation(1, { latest_message: { id: 10 } })])
		await initial
		store.activeConversation = conversation(1, { messages: [{ id: 10 }] })
		let poll
		if (!startsDuringSend) poll = store.refreshActiveConversation()
		const sending = store.send('Newly sent')
		if (startsDuringSend) poll = store.refreshActiveConversation()
		sendResult.resolve(response(latest))
		await tick()
		finish(1, [latest])
		await sending
		latePoll.resolve(response(conversation(1, { latest_message: { id: 10 }, messages: [{ id: 10 }] })))
		await poll
		assert.equal(store.activeMessages.at(-1).body, 'Newly sent', startsDuringSend ? 'poll during send' : 'poll before send')
		assert.equal(store.conversations[0].latest_message.id, 11)
	}
})

test('late send response does not reopen a conversation after the user navigates elsewhere', async () => {
	const lateSend = deferred()
	const { store, finish } = setup({ sendChatMessage: () => lateSend.promise })
	const initial = store.loadConversations()
	finish(0, [conversation(1), conversation(2)])
	await initial
	store.activeConversation = conversation(1)
	const sending = store.send('Message for first partner')
	store.clearActive()
	store.activeConversation = conversation(2, { messages: [{ id: 20, body: 'Other partner' }] })
	lateSend.resolve(response(conversation(1, { messages: [{ id: 11, body: 'Message for first partner' }] })))
	await tick()
	finish(1, [conversation(1), conversation(2)])
	await sending
	assert.equal(store.activeConversation.id, 2)
	assert.equal(store.activeMessages[0].body, 'Other partner')
})
