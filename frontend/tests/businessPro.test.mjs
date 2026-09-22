import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { test } from 'node:test'
import { computed, effectScope, nextTick, reactive, ref, watch } from 'vue'
import { businessProFeatureAvailable, canPreviewBusinessPro, proMoney, validCardcomCheckoutUrl, proStatusKey } from '../src/utils/businessPro.js'

const script = async name => (await readFile(new URL('../src/' + name, import.meta.url), 'utf8')).split('<script setup>')[1].split('</script>')[0].replace(/^\s*import .+\r?\n/gm, '')
const panelSource = await script('components/businessPro/BusinessProPanel.vue')
const paymentSource = await script('pages/BusinessProPaymentPage.vue')
const ok = data => ({ data: { data } })
const flush = async () => { await nextTick(); await new Promise(resolve => setImmediate(resolve)) }
const snapshot = (overrides = {}) => ({ pages: [{ id: 7, name: 'First business' }, { id: 9, name: 'Second business' }], offer: { amount_minor: 4900, currency: 'ILS', environment: 'sandbox', billing_enabled: true, interval: 'monthly', included_pages: 1 }, subscription: null, has_access: false, can_checkout: true, can_cancel: false, features: [], ...overrides })

function panel(t, { preview = true, compact = false } = {}) {
  const auth = reactive({ token: 'token-1', user: { id: 1, business_pro_preview: preview }, isAuthenticated: true, isAdmin: false })
  const route = reactive({ fullPath: '/profile' })
  const reads = [], checkouts = [], cancels = [], redirects = [], cleanup = []
  const dependencies = { computed, ref, watch, defineProps: () => ({ pageId: 9, compact }), onBeforeUnmount: callback => cleanup.push(callback), useAuthStore: () => auth, useRoute: () => route,
    useI18n: () => ({ t: key => key, locale: ref('en') }), canPreviewBusinessPro, proMoney, proStatusKey, validCardcomCheckoutUrl,
    fetchBusinessPro: () => new Promise((resolve, reject) => reads.push({ resolve, reject })),
    createBusinessProCheckout: payload => new Promise((resolve, reject) => checkouts.push({ payload, resolve, reject })),
    cancelBusinessPro: () => new Promise((resolve, reject) => cancels.push({ resolve, reject })), window: { location: { assign: url => redirects.push(url) } }
  }
  const factory = new Function(...Object.keys(dependencies), panelSource + '\nreturn { overview, consent, selectedPageId, busy, error, load, checkout, cancel, canCheckout, visible, detailsOpen, openDetails, closeDetails, canOpenDetails, privatePreview, hasSubscription, detailsButtonKey }')
  const scope = effectScope(), component = scope.run(() => factory(...Object.values(dependencies)))
  t.after(() => { cleanup.forEach(fn => fn()); scope.stop() })
  return { component, auth, route, reads, checkouts, cancels, redirects }
}

test('ordinary accounts cannot request private metadata; opt-in preview loads on eligibility change', async t => {
  const { component, auth, reads } = panel(t, { preview: false })
  assert.equal(reads.length, 0); assert.equal(component.visible.value, false)
  auth.user.business_pro_preview = true; await flush(); assert.equal(reads.length, 1)
  reads[0].resolve(ok(snapshot())); await flush(); assert.equal(component.selectedPageId.value, 9)
})

test('checkout requires consent and selected owned page, binds displayed49ILS price, rejects unsafe destination', async t => {
  const { component, reads, checkouts, redirects } = panel(t)
  reads[0].resolve(ok(snapshot())); await flush()
  await component.checkout(); assert.equal(checkouts.length, 0)
  component.consent.value = true
  const request = component.checkout()
  assert.deepEqual(checkouts[0].payload, { page_id: 9, consent: true, locale: 'en', amount_minor: 4900, currency: 'ILS' })
  checkouts[0].resolve(ok({ checkout_url: 'https://secure.cardcom.solutions.evil.test/checkout' })); await request
  assert.equal(redirects.length, 0); assert.equal(component.error.value, 'businessPro.checkoutFailed')
  component.selectedPageId.value = 999; await flush(); component.consent.value = true
  await component.checkout(); assert.equal(checkouts.length, 1)
})

test('changed offer resets consent and requires reviewing authoritative new price', async t => {
  const { component, reads, checkouts } = panel(t)
  reads[0].resolve(ok(snapshot())); await flush(); component.consent.value = true
  const request = component.checkout(); checkouts[0].reject({ response: { status: 409, data: { errors: { reason: 'offer_changed' } } } }); await flush()
  assert.equal(reads.length, 2)
  reads[1].resolve(ok(snapshot({ offer: { ...snapshot().offer, amount_minor: 5900 } }))); await request
  assert.equal(component.consent.value, false); assert.equal(component.overview.value.offer.amount_minor, 5900)
  assert.equal(component.error.value, 'businessPro.offerChanged'); assert.equal(component.busy.value, '')
})

test('a checkout response from a previous session cannot redirect a different account', async t => {
  const { component, auth, reads, checkouts, redirects } = panel(t)
  reads[0].resolve(ok(snapshot())); await flush(); component.consent.value = true
  const request = component.checkout(); auth.user = { id: 2, business_pro_preview: true }; auth.token = 'token-2'; await flush()
  checkouts[0].resolve(ok({ checkout_url: 'https://secure.cardcom.solutions/EA/LPC6/1000/test' })); await request
  assert.deepEqual(redirects, []); assert.equal(component.overview.value, null)
  reads[1].resolve(ok(snapshot())); await flush(); assert.equal(component.consent.value, false)
})

test('cancellation refresh preserves paid access through the returned period end', async t => {
  const { component, reads, cancels } = panel(t)
  const active = snapshot({ subscription: { status: 'active', has_access: true, page_id: 9, current_period_end: '2026-10-22T12:00:00Z', cancel_at_period_end: false }, has_access: true, can_checkout: false, can_cancel: true })
  reads[0].resolve(ok(active)); await flush()
  const request = component.cancel(); cancels[0].resolve(ok({})); await flush()
  assert.equal(component.overview.value.has_access, true)
  reads[1].resolve(ok({ ...active, subscription: { ...active.subscription, cancel_at_period_end: true }, can_cancel: false })); await request
  assert.equal(component.overview.value.has_access, true); assert.equal(component.overview.value.subscription.cancel_at_period_end, true)
})

test('payment return ignores success query and accepts only server verification', async t => {
  const auth = reactive({ token: 'token', user: { id: 1, business_pro_preview: true }, isAuthenticated: true, isAdmin: false })
  const route = reactive({ params: { id: 'payment-uuid' }, query: { result: 'success', active: '1' } })
  const requests = [], cleanup = []
  const dependencies = { computed, ref, watch, useAuthStore: () => auth, useRoute: () => route, useI18n: () => ({ t: key => key }), onBeforeUnmount: fn => cleanup.push(fn), canPreviewBusinessPro, proStatusKey,
    verifyBusinessProPayment: id => new Promise(resolve => requests.push({ id, resolve })) }
  const scope = effectScope(), component = scope.run(() => new Function(...Object.keys(dependencies), paymentSource + '\nreturn { result, verify, loading }')(...Object.values(dependencies)))
  t.after(() => { cleanup.forEach(fn => fn()); scope.stop() })
  assert.equal(requests[0].id, 'payment-uuid'); assert.equal(component.result.value, null)
  requests[0].resolve(ok({ payment: { status: 'pending' }, overview: { has_access: false, subscription: null } })); await flush()
  assert.equal(component.result.value.overview.has_access, false)
  route.query.result = 'success-again'; await flush(); assert.equal(requests.length, 1)
})

test('future feature interactions require authoritative implemented/enabled entitlement, including private draft testing', () => {
  assert.equal(businessProFeatureAvailable({ available: true, implemented: true, enabled: true, lifecycle: 'draft' }), true)
  for (const key of ['available', 'implemented', 'enabled']) assert.equal(businessProFeatureAvailable({ available: true, implemented: true, enabled: true, [key]: false }), false)
  assert.equal(businessProFeatureAvailable({ available: 'true', implemented: true, enabled: true }), false)
  assert.equal(canPreviewBusinessPro({ isAuthenticated: true, user: { business_pro_preview: 'true' } }), false)
})

test('Cardcom redirects permit exact HTTPS secure/test hosts only, without credentials or nonstandard ports', () => {
  assert(validCardcomCheckoutUrl('https://secure.cardcom.solutions/EA/LPC6/1000/id'))
  assert(validCardcomCheckoutUrl('https://test.cardcom.solutions/path'))
  for (const value of ['javascript:alert(1)', 'http://secure.cardcom.solutions/x', 'https://secure.cardcom.solutions.evil.test/x', 'https://user@secure.cardcom.solutions/x', 'https://secure.cardcom.solutions:8443/x']) assert.equal(validCardcomCheckoutUrl(value), null)
})


test('compact Buy only opens shared details, without requesting checkout or loading another panel', async t => {
  const { component, reads, checkouts, cancels } = panel(t, { compact: true })
  reads[0].resolve(ok(snapshot({ private_preview: true }))); await flush()
  component.openDetails(); await flush()
  assert.equal(component.detailsOpen.value, true)
  assert.equal(component.consent.value, false)
  assert.equal(reads.length, 1)
  assert.equal(checkouts.length, 0)
  assert.equal(cancels.length, 0)
  component.consent.value = true
  component.closeDetails(); await flush()
  assert.equal(component.consent.value, false)
})

test('compact Buy is disabled without billing but existing paid subscription remains manageable', async t => {
  const { component, reads } = panel(t, { compact: true })
  reads[0].resolve(ok(snapshot({ offer: { ...snapshot().offer, billing_enabled: false }, can_checkout: false }))); await flush()
  assert.equal(component.canOpenDetails.value, false)
  component.openDetails(); assert.equal(component.detailsOpen.value, false)
  const request = component.load()
  reads[1].resolve(ok(snapshot({ offer: { ...snapshot().offer, billing_enabled: false }, subscription: { status: 'active', has_access: true, page_id: 9 }, has_access: true, can_checkout: false, can_cancel: true }))); await request
  assert.equal(component.canOpenDetails.value, true)
  component.openDetails(); assert.equal(component.detailsOpen.value, true)
})

test('profile route change closes details and ignores an in-flight checkout redirect', async t => {
  const { component, route, reads, checkouts, redirects } = panel(t, { compact: true })
  reads[0].resolve(ok(snapshot())); await flush(); component.openDetails(); component.consent.value = true
  const request = component.checkout()
  route.fullPath = '/business'; await flush()
  assert.equal(component.detailsOpen.value, false)
  assert.equal(component.consent.value, false)
  checkouts[0].resolve(ok({ checkout_url: 'https://secure.cardcom.solutions/EA/LPC6/1000/test' })); await request
  assert.deepEqual(redirects, [])
})

test('private-preview copy follows server rollout metadata and logout closes the dialog', async t => {
  const { component, auth, reads } = panel(t, { compact: true })
  reads[0].resolve(ok(snapshot({ private_preview: false, offer: { ...snapshot().offer, environment: 'production' } }))); await flush()
  assert.equal(component.privatePreview.value, false)
  component.openDetails(); component.consent.value = true
  auth.token = null; auth.user = null; auth.isAuthenticated = false; await flush()
  assert.equal(component.detailsOpen.value, false)
  assert.equal(component.consent.value, false)
  assert.equal(component.overview.value, null)
})


test('inactive failed subscriptions remain manageable when billing is disabled without enabling payments', async t => {
  const { component, reads, checkouts, cancels } = panel(t, { compact: true })
  reads[0].resolve(ok(snapshot({ subscription: { status: 'inactive', has_access: false }, offer: { ...snapshot().offer, billing_enabled: false }, can_checkout: false }))); await flush()
  assert.equal(component.hasSubscription.value, true)
  assert.equal(component.canOpenDetails.value, true)
  assert.equal(component.detailsButtonKey.value, 'businessPro.manage')
  component.openDetails()
  assert.equal(component.detailsOpen.value, true)
  assert.equal(component.canCheckout.value, false)
  component.consent.value = true
  await component.checkout(); await component.cancel()
  assert.equal(checkouts.length, 0)
  assert.equal(cancels.length, 0)
  assert.equal(component.overview.value.has_access, false)
})

test('private testers without a subscription can inspect details while checkout stays unavailable', async t => {
  const { component, reads, checkouts } = panel(t, { compact: true })
  reads[0].resolve(ok(snapshot({ private_preview: true, offer: { ...snapshot().offer, billing_enabled: false }, can_checkout: false }))); await flush()
  assert.equal(component.hasSubscription.value, false)
  assert.equal(component.detailsButtonKey.value, 'businessPro.viewDetails')
  assert.equal(component.canOpenDetails.value, true)
  component.openDetails()
  assert.equal(component.detailsOpen.value, true)
  component.consent.value = true
  await component.checkout()
  assert.equal(checkouts.length, 0)
  assert.equal(reads.length, 1)
})
