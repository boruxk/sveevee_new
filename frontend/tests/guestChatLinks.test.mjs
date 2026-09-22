import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { test } from 'node:test'
import { containsGuestChatLink } from '../src/utils/guestChatLinks.js'

const cases = JSON.parse(readFileSync(new URL('../../backend/tests/Fixtures/guest-chat-links.json', import.meta.url), 'utf8'))
for (const { body, blocked } of cases) {
	test(`guest link policy: ${JSON.stringify(body)}`, () => {
		assert.equal(containsGuestChatLink(body), blocked)
	})
}
