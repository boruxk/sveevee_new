import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue'
import { apiErrorMessage } from '../src/utils/apiErrors.js'

const source = (await readFile(new URL('../src/components/AdComposer.vue', import.meta.url), 'utf8')).split('<script setup>')[1].split('</script>')[0].replace(/^\s*import .+\r?\n/gm, '')
const flush = async () => { await nextTick(); await new Promise(resolve => setImmediate(resolve)) }
const ok = data => ({ data: { data } })
function composer(t, ad = null) {
  const props = reactive({ ad, pageId: null, disabled: false })
  const auth = reactive({ user: { id: 1 }, token: 'token', isAuthenticated: true })
  const reads = [], writes = [], cleanup = [], notices = [], server = { failure: null }
  const dependencies = { computed, reactive, ref, watch, defineProps: () => props, defineEmits: () => () => {}, useI18n: () => ({ t: key => key }), useQuasar: () => ({ notify: notice => notices.push(notice) }), useAuthStore: () => auth,
    fetchAdProFeature: params => new Promise((resolve, reject) => reads.push({ params, resolve, reject })),
    createAd: async payload => { writes.push({ payload }); if (server.failure) throw server.failure; return ok({ id: 91 }) }, updateAd: async (id, payload) => { writes.push({ id, payload }); if (server.failure) throw server.failure; return ok({ id }) },
    useRequiredFields: () => ({ requiredLabel: value => value, requiredRule: () => true, validateRequiredForm: async () => true }),
    useCatalogTopics: () => ({ catalogGroups: ref([]), loadCatalogTopics: async () => [] }), catalogTopicForAdCategory: () => null,
    imageUploadDisplayName: () => '', apiErrorMessage, matLock: '', matStars: '', onMounted: () => {}, onBeforeUnmount: callback => cleanup.push(callback) }
  const scope = effectScope()
  const component = scope.run(() => new Function(...Object.keys(dependencies), source + '\nreturn { form, feature, canFeature, submit }')(...Object.values(dependencies)))
  t.after(() => { cleanup.forEach(fn => fn()); scope.stop() })
  return { component, props, auth, reads, writes, notices, server }
}

test('text edits preserve stored featured intent when eligibility fails or the subscription expires', async t => {
  const { component, reads, writes } = composer(t, { id: 7, title: 'Before', text: 'Text', featured_requested: true, is_featured: false })
  assert.equal(component.form.is_featured, true)
  assert.equal(component.canFeature.value, false)
  reads[0].reject(Error('Temporarily unavailable')); await flush()
  component.form.title = 'After'
  await component.submit()
  assert.equal(writes[0].id, 7)
  assert.equal(writes[0].payload.title, 'After')
  assert(!Object.hasOwn(writes[0].payload, 'is_featured'))
})

test('a save while eligibility loads omits the feature flag; server-authorized editing can turn it off', async t => {
  const { component, reads, writes } = composer(t, { id: 7, title: 'Before', text: 'Text', featured_requested: true, is_featured: true })
  await component.submit()
  assert(!Object.hasOwn(writes[0].payload, 'is_featured'))
  reads[0].resolve(ok({ available: true })); await flush()
  component.form.is_featured = false
  await component.submit()
  assert.equal(writes[1].payload.is_featured, false)
})

test('entitled personal ad creation includes the feature flag and stale account responses cannot unlock it', async t => {
  const { component, auth, reads, writes } = composer(t)
  auth.user = { id: 2 }; auth.token = 'new'; await flush()
  reads[0].resolve(ok({ available: true })); await flush()
  assert.equal(component.canFeature.value, false)
  reads[1].resolve(ok({ available: true })); await flush()
  component.form.is_featured = true
  await component.submit()
  assert.equal(writes[0].payload.is_featured, true)
})


test('an entitlement that expires during editing locks the control, explains the plan requirement and permits a normal retry', async t => {
  const { component, reads, writes, notices, server } = composer(t, { id: 7, title: 'Ad', text: 'Text', featured_requested: true, is_featured: true })
  reads[0].resolve(ok({ available: true })); await flush()
  server.failure = { response: { status: 402, data: { data: { reason: 'pro_feature_required' } } } }
  await component.submit()
  assert.equal(writes[0].payload.is_featured, true)
  assert.equal(component.canFeature.value, false)
  assert.equal(notices[0].message, 'businessPro.featuredAdRequiresPlan')
  server.failure = null
  await component.submit()
  assert(!Object.hasOwn(writes[1].payload, 'is_featured'))
  assert.equal(writes[1].payload.title, 'Ad')
})
