import assert from 'node:assert/strict'
import { test } from 'node:test'
import { pendingGuestPageChatStart, runGuestPageChatStart } from '../src/utils/guestPageChatLifecycle.js'

test('reopened popup awaits the same first message without starting another conversation', async() => {
	let finish
	let requests = 0
	const first = runGuestPageChatStart(42, () => {
		requests += 1
		return new Promise((resolve) => { finish = resolve })
	})
	const reopenedPopup = pendingGuestPageChatStart('42')
	assert.ok(reopenedPopup)
	await Promise.resolve()
	const response = { data: { data: { token: 'opaque', conversation: { id: 1 } } } }
	finish(response)
	assert.deepEqual(await reopenedPopup, response)
	assert.deepEqual(await first, { response, messageSent: true })
	assert.equal(requests, 1)
	assert.equal(pendingGuestPageChatStart(42), null)
})

test('a racing caller is told its different draft was not sent', async() => {
	let finish
	const first = runGuestPageChatStart(42, () => new Promise((resolve) => { finish = resolve }))
	const differentDraft = runGuestPageChatStart(42, () => assert.fail('A second conversation must not be created'))
	await Promise.resolve()
	const response = { data: { data: { conversation: { messages: [{ body: 'First draft' }] } } } }
	finish(response)
	assert.equal((await first).messageSent, true)
	assert.deepEqual(await differentDraft, { response, messageSent: false })
})

test('one page never waits on another page first message', async() => {
	let finish
	const first = runGuestPageChatStart(42, () => new Promise((resolve) => { finish = resolve }))
	const other = await runGuestPageChatStart(43, async() => 'second page')
	assert.deepEqual(other, { response: 'second page', messageSent: true })
	finish('first page')
	assert.equal((await first).response, 'first page')
})

test('a failed start releases the lock so an explicit retry can send', async() => {
	const error = new Error('Request failed')
	const first = runGuestPageChatStart(42, async() => { throw error })
	const waiting = pendingGuestPageChatStart(42)
	const results = await Promise.allSettled([first, waiting])
	assert.ok(results.every((result) => result.status === 'rejected' && result.reason === error))
	assert.equal(pendingGuestPageChatStart(42), null)
	assert.deepEqual(await runGuestPageChatStart(42, async() => 'retry response'), { response: 'retry response', messageSent: true })
})
