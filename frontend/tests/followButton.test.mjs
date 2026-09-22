import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue'

const source = (await readFile(new URL('../src/components/community/FollowButton.vue', import.meta.url), 'utf8'))
  .split('<script setup>')[1].split('</script>')[0]
  .replace(/^\s*import .+\r?\n/gm, '')

const response = data => ({ data: { data } })
const followed = id => ({ subscribed: true, subscription: { id } })
const flush = async () => { await nextTick(); await new Promise(resolve => setImmediate(resolve)) }

function setup(t, overrides = {}) {
  const auth = reactive({
    token: 'session-a', user: { id: 1, display_name: 'Member' },
    get isAuthenticated() { return Boolean(this.token && this.user) }
  })
  const props = reactive({ pageId: 3, city: '', categoryKey: '', neighborhood: '', initialState: null, ...overrides })
  const reads = [], writes = [], notifications = [], changes = [], cleanups = []
  const dependencies = {
    computed, ref, watch,
    onBeforeUnmount: callback => cleanups.push(callback),
    defineProps: () => props,
    defineEmits: () => (...args) => changes.push(args),
    useI18n: () => ({ t: key => key }),
    useAuthStore: () => auth,
    useRoute: () => ({ fullPath: '/business/3' }),
    useRouter: () => ({ push: () => {} }),
    useQuasar: () => ({ notify: value => notifications.push(value) }),
    fetchSubscriptionStatus: params => new Promise((resolve, reject) => reads.push({ params, resolve, reject })),
    createSubscription: params => new Promise((resolve, reject) => writes.push({ method: 'post', params, resolve, reject })),
    deleteSubscription: id => new Promise((resolve, reject) => writes.push({ method: 'delete', id, resolve, reject }))
  }
  const factory = new Function(...Object.keys(dependencies), source + '\nreturn { state, loading, saving, toggle }')
  const scope = effectScope()
  const button = scope.run(() => factory(...Object.values(dependencies)))
  t.after(() => { cleanups.forEach(callback => callback()); scope.stop() })
  return { auth, props, reads, writes, notifications, changes, button }
}

test('refreshing the same account keeps the loaded follow status without another request or spinner', async t => {
  const { auth, reads, button } = setup(t)
  assert.equal(reads.length, 1)
  reads[0].resolve(response(followed(8)))
  await flush()
  auth.user = { id: 1, display_name: 'Updated member', unread_messages_count: 2 }
  await flush()
  assert.equal(reads.length, 1)
  assert.equal(button.loading.value, false)
  assert.deepEqual(button.state.value, followed(8))
})

test('account refresh during the first status request does not start a competing read', async t => {
  const { auth, reads, button } = setup(t)
  auth.user = { ...auth.user, last_seen_at: '2026-09-22T12:00:00Z' }
  await flush()
  assert.equal(reads.length, 1)
  assert.equal(button.loading.value, true)
  reads[0].resolve(response(followed(8)))
  await flush()
  assert.equal(button.loading.value, false)
  assert.deepEqual(button.state.value, followed(8))
})

test('account refresh during unfollow preserves the pending write and its resulting status', async t => {
  const { auth, reads, writes, changes, button } = setup(t)
  reads[0].resolve(response(followed(8)))
  await flush()
  const action = button.toggle()
  auth.user = { ...auth.user, unread_messages_count: 4 }
  await flush()
  assert.equal(reads.length, 1)
  assert.equal(button.saving.value, true)
  await button.toggle()
  assert.equal(writes.length, 1)
  assert.equal(writes[0].method, 'delete')
  writes[0].resolve(response(null))
  await action
  assert.deepEqual(button.state.value, { subscribed: false, subscription: null })
  assert.equal(changes.length, 1)
})

test('changing page loads its status and ignores a late response from the previous page', async t => {
  const { props, reads, button } = setup(t)
  props.pageId = 4
  await flush()
  assert.equal(reads.length, 2)
  assert.equal(reads[1].params.page_id, 4)
  reads[1].resolve(response(followed(9)))
  await flush()
  reads[0].resolve(response(followed(8)))
  await flush()
  assert.deepEqual(button.state.value, followed(9))
})

test('logout discards old status; signing in again refreshes even when the user object stays the same', async t => {
  const { auth, reads, button } = setup(t)
  auth.token = null
  await flush()
  reads[0].resolve(response(followed(8)))
  await flush()
  assert.equal(button.state.value.subscribed, false)
  assert.equal(button.loading.value, false)
  auth.token = 'session-b'
  await flush()
  assert.equal(reads.length, 2)
  reads[1].resolve(response(followed(10)))
  await flush()
  assert.deepEqual(button.state.value, followed(10))
})

test('switching accounts discards late reads and refreshes supplied initial status', async t => {
  const { auth, reads, button } = setup(t, { initialState: followed(8) })
  assert.equal(reads.length, 0)
  auth.user = { id: 2 }
  await flush()
  assert.equal(reads.length, 1)
  auth.user = { id: 3 }
  await flush()
  assert.equal(reads.length, 2)
  reads[0].reject(new Error('old account request'))
  reads[1].resolve(response(followed(11)))
  await flush()
  assert.deepEqual(button.state.value, followed(11))
})
