import assert from 'node:assert/strict'
import { test } from 'node:test'
import { pageSaveResult } from '../src/utils/pageSaveResult.js'
import { pageClaimComparison } from '../src/utils/pageClaimComparison.js'

const t = (key) => key

test('a pending save is never returned as a page, including multiple matched pages', () => {
 const claims = [{ id: 7, page: { id: 90 } }, { id: 8, page: { id: 91 } }]
 const response = { status: 202, data: { data: { save_outcome: 'claim_conflict', pending_claim: true, claim_requests: claims } } }
 assert.deepEqual(pageSaveResult(response), { outcome: 'claim_conflict', page: null, claimRequests: claims })
 assert.equal(pageSaveResult({ status: 200, data: { data: { pending_claim: true, id: 99 } } }).page, null)
})

test('adoption preserves the actual existing page ID and returned filled details', () => {
 const page = { id: 90, save_outcome: 'adopted', name: 'Existing page', setup: { address: { city: 'Ashdod' } } }
 const result = pageSaveResult({ status: 200, data: { data: page } })
 assert.equal(result.outcome, 'adopted')
 assert.equal(result.page, page)
 assert.equal(result.page.id, 90)
 assert.deepEqual(result.claimRequests, [])
 assert.equal(pageSaveResult({ status: 200, data: { data: { id: 91 } } }).outcome, 'updated')
})

test('unexpected 202 and missing page bodies fail instead of clearing a form', () => {
 for (const response of [{ status: 202, data: { data: { id: 99 } } }, { status: 200, data: { data: {} } }, {}, { status: 200, data: { data: { id: 0 } } }]) {
  assert.throws(() => pageSaveResult(response))
 }
})

test('comparison preserves explicit clearing, ignores unsubmitted fields and never exposes upload paths', () => {
 const claim = {
  page: { name: 'Old', phone: '03-0000000', contact_email: 'current@example.test', logo_url: '/logo.png', setup: { address: { city: 'Ashdod', street: 'Old street' } } },
  proposed_data: { name: 'New', phone: null, category_key: 'food_catering.fish_stores', logo_path: 'private/unpublished.png', setup: { address: { city: 'Eilat', street: 'New street' } } }
 }
 const before = structuredClone(claim)
 const rows = pageClaimComparison(claim, { t, categoryLabel: () => 'Fish shops', cityLabel: (city) => `City ${city}` })
 assert.equal(rows.find(row => row.key === 'phone').after, '')
 assert.equal(rows.find(row => row.key === 'phone').changed, true)
 assert.equal(rows.find(row => row.key === 'category').after, 'Fish shops')
 assert.equal(rows.find(row => row.key === 'address').after, 'New street, City Eilat')
 assert.equal(rows.some(row => ['email', 'logo'].includes(row.key)), false)
 assert.equal(JSON.stringify(rows).includes('private/unpublished'), false)
 assert.deepEqual(claim, before)
})

test('hours, feature switches, uploaded previews and supplied unchanged values remain readable', () => {
 const shared = { name: 'Same name', setup: { opening_hours: [{ weekday: 'monday', is_open: false, opens_at: null, closes_at: null }], features: { store: false } } }
 const rows = pageClaimComparison({ page: shared, proposed_data: { ...structuredClone(shared), logo_url: '/new.png' } }, { t })
 assert.equal(rows.find(row => row.key === 'name').changed, false)
 assert.equal(rows.find(row => row.key === 'hours').after, 'pages.weekdays.monday: pages.closed')
 assert.equal(rows.find(row => row.key === 'store').after, 'admin.claimFeatureDisabled')
 assert.deepEqual(rows.find(row => row.key === 'logo'), { key: 'logo', labelKey: 'pages.logo', before: '', after: '/new.png', image: true, changed: true })
})

test('all four locales translate conflict states, admin actions and every matching field', async () => {
 const fields = ['name', 'phone', 'email', 'website', 'city', 'neighborhood', 'address', 'category', 'whatsapp', 'facebook', 'instagram', 'tiktok', 'x', 'telegram']
 for (const locale of ['he', 'en', 'ru', 'fr']) {
  const { default: messages } = await import(`../src/i18n/messages/${locale}.js`)
  for (const key of ['adopted', 'claimConflictTitle', 'claimConflictPending']) assert.ok(messages.pages[key])
  for (const key of ['claimConflict', 'claimKeepOwner', 'claimAssignRequester', 'claimRejectMatch', 'claimCompareData', 'claimCurrentOwner', 'claimRequester']) assert.ok(messages.admin[key])
  for (const field of fields) assert.ok(messages.admin.claimMatchedFields[field])
 }
})
