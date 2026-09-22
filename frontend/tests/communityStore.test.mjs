import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { createPinia, defineStore } from 'pinia'
import { notificationActionPath, notificationParameters, notificationTranslationKeys } from '../src/utils/accountNotifications.js'

const source = (await readFile(new URL('../src/stores/community.js', import.meta.url), 'utf8'))
  .replace(/^import .+\n/gm, '')
  .replace('export const useCommunityStore', 'const useCommunityStore')

function setup(overrides = {}) {
  const auth = { user: { id: 1 } }
  const calls = []
  const api = {
    fetchSocialStates: async ({ targets }) => {
      calls.push(['batch', targets])
      return { data: { data: { items: targets.map(target => { const [type, id] = target.split(':'); return { type, id: Number(id), liked: false, likes_count: 2, comments_count: 3 } }) } } }
    },
    putSocialLike: async (type, id) => { calls.push(['put', type, id]); return { data: { data: { liked: true, likes_count: 3, comments_count: 3 } } } },
    deleteSocialLike: async (type, id) => { calls.push(['delete', type, id]); return { data: { data: { liked: false, likes_count: 2, comments_count: 3 } } } },
    ...overrides
  }
  const factory = new Function('defineStore', 'useAuthStore', 'fetchSocialStates', 'putSocialLike', 'deleteSocialLike', source + '\nreturn useCommunityStore')
  const useStore = factory(defineStore, () => auth, api.fetchSocialStates, api.putSocialLike, api.deleteSocialLike)
  return { store: useStore(createPinia()), auth, calls }
}

const tick = () => new Promise(resolve => setImmediate(resolve))

test('card state is seeded without requests; repeated targets share one batch and batches stay bounded', async () => {
  const { store, calls } = setup()
  store.seed('ad', 1, { liked: true, likes_count: 6, comments_count: 9 })
  await store.ensure('ad', 1)
  assert.deepEqual(calls, [])
  const requests = [store.ensure('event', 2), store.ensure('event', 2), ...Array.from({ length: 201 }, (_, index) => store.ensure('question', index + 1))]
  await Promise.all(requests)
  assert.equal(calls.length, 3)
  assert(calls.every(call => call[1].length <= 100))
  assert.equal(calls.flatMap(call => call[1]).filter(target => target === 'event:2').length, 1)
  assert.equal(store.getState('event', 2).likes_count, 2)
  assert.equal(store.getState('ad', 1).liked, true)
})

test('rapid repeated activation performs one mutation and all consumers see authoritative state', async () => {
  let finish
  let puts = 0
  const { store } = setup({ putSocialLike: () => { puts++; return new Promise(resolve => { finish = resolve }) } })
  store.seed('question', 7, { liked: false, likes_count: 2, comments_count: 3 })
  const first = store.toggleLike('question', 7)
  const duplicate = store.toggleLike('question', 7)
  await tick()
  assert.equal(puts, 1)
  finish({ data: { data: { liked: true, likes_count: 3, comments_count: 3 } } })
  await Promise.all([first, duplicate])
  assert.equal(store.getState('question', 7).liked, true)
  store.seed('question', 7, { liked: false, likes_count: 2, comments_count: 0 })
  assert.equal(store.getState('question', 7).liked, true, 'Stale card props cannot undo a successful action')
  await store.toggleLike('question', 7)
  assert.equal(store.getState('question', 7).liked, false)
})

test('failed mutation keeps counts and becomes retryable', async () => {
  const { store } = setup({ putSocialLike: async () => { throw new Error('network') } })
  store.seed('ad', 8, { liked: false, likes_count: 4, comments_count: 2 })
  await assert.rejects(store.toggleLike('ad', 8), /network/)
  assert.deepEqual(store.getState('ad', 8), { liked: false, likes_count: 4, comments_count: 2 })
  assert.equal(store.busy['ad:8'], false)
})

test('viewer changes discard stale reads and prevent a queued like from using the next account', async () => {
  let finish
  let puts = 0
  const { store, auth } = setup({
    fetchSocialStates: () => new Promise(resolve => { finish = resolve }),
    putSocialLike: async () => { puts++; throw new Error('should not run') }
  })
  const pending = store.toggleLike('ad', 9)
  await tick()
  auth.user = { id: 2 }
  store.syncViewer()
  finish({ data: { data: { items: [{ type: 'ad', id: 9, liked: true, likes_count: 7 }] } } })
  await pending
  assert.equal(puts, 0)
  assert.deepEqual(store.states, {})
  assert.equal(store.getState('ad', 9).liked, false)
})

test('a stale write response cannot overwrite the next viewer state', async () => {
  let finish
  const { store, auth } = setup({ putSocialLike: () => new Promise(resolve => { finish = resolve }) })
  store.seed('ad', 2, { liked: false, likes_count: 2 })
  const pending = store.toggleLike('ad', 2)
  await tick()
  auth.user = null
  store.seed('ad', 2, { liked: false, likes_count: 3 })
  finish({ data: { data: { liked: true, likes_count: 3 } } })
  await pending
  assert.equal(store.getState('ad', 2).liked, false)
})

test('community notifications have localized messages and only local action links', async () => {
  for (const locale of ['en', 'he', 'ru', 'fr']) {
    const { default: messages } = await import(`../src/i18n/messages/${locale}.js`)
    for (const type of ['community_reply', 'community_helpful', 'community_like', 'community_activity']) {
      const notification = { type, data: { actor_name: 'Someone', title: 'Local advice', action_path: '/questions/7#discussion' } }
      const keys = notificationTranslationKeys(notification)
      assert(messages.community[keys.title.split('.')[1]])
      assert(messages.community[keys.body.split('.')[1]])
      assert.equal(notificationParameters(notification).actor, 'Someone')
      assert.equal(notificationParameters(notification).title, 'Local advice')
      assert.equal(notificationActionPath(notification), '/questions/7#discussion')
    }
  }
  for (const action_path of ['//external.test', '/\\external.test', '/\nexternal.test', 'https://external.test']) {
    assert.equal(notificationActionPath({ data: { action_path } }), '/me')
  }
})
