import assert from 'node:assert/strict'
import { afterEach, beforeEach, test } from 'node:test'
import { effectScope, nextTick, ref } from 'vue'
import { useSeo } from '../src/composables/useSeo.js'

let scopes

class Element {
	constructor(tagName) {
		this.tagName = tagName
		this.attributes = new Map()
		this.children = []
		this.textContent = ''
	}

	set id(value) { this.setAttribute('id', value) }
	get id() { return this.getAttribute('id') }
	set type(value) { this.setAttribute('type', value) }
	setAttribute(key, value) {
		this.attributes.set(key, String(value))
		if (key === 'content' && this.getAttribute('name') === 'robots') document.robotsWrites.push(String(value))
	}

	getAttribute(key) { return this.attributes.get(key) ?? null }
	appendChild(element) {
		this.children.push(element)
		element.parent = this
	}

	remove() {
		if (this.parent) this.parent.children.splice(this.parent.children.indexOf(this), 1)
	}

	querySelector(selector) { return this.querySelectorAll(selector)[0] || null }
	querySelectorAll(selector) {
		const name = selector.match(/^[a-z]+/i)?.[0]
		const attributes = [...selector.matchAll(/\[([^\]=]+)(?:="([^"]*)")?\]/g)]
		return this.children.filter((element) => (!name || element.tagName === name) && attributes.every(([, key, value]) => {
			return value === undefined ? element.attributes.has(key) : element.getAttribute(key) === value
		}))
	}
}

function add(tag, attributes, text = '') {
	const element = new Element(tag)
	for (const [key, value] of Object.entries(attributes)) element.setAttribute(key, value)
	element.textContent = text
	document.head.appendChild(element)
	return element
}

function mountSeo(config) {
	const source = ref(config)
	const scope = effectScope()
	scope.run(() => useSeo(source))
	scopes.push(scope)
	return { source, scope }
}

function serverHead(path = '/en/business/cafe-42') {
	document.title = 'Server business title'
	add('link', { rel: 'canonical', href: `https://sveevee.co.il${path}` })
	add('meta', { name: 'robots', content: 'index,follow' })
	add('meta', { name: 'description', content: 'Server business description' })
	add('link', { rel: 'alternate', hreflang: 'he', href: 'https://sveevee.co.il/he/business/cafe-42', 'data-sveevee-prerender': '' })
	add('script', { type: 'application/ld+json', 'data-sveevee-prerender': '' }, '{"@type":"LocalBusiness"}')
	add('style', { 'data-sveevee-prerender': '' }, '.sveevee-prerender{color:black}')
}

beforeEach(() => {
	scopes = []
	globalThis.window = { location: { origin: 'https://sveevee.co.il', pathname: '/en/business/cafe-42' } }
	globalThis.document = {
		title: '',
		head: new Element('head'),
		documentElement: { lang: 'en' },
		robotsWrites: [],
		prerenderBodyPresent: false,
		createElement: (name) => new Element(name),
		getElementById: (id) => document.head.children.find((element) => element.id === id) || null,
		querySelector: (selector) => selector === '#app .sveevee-prerender' && document.prerenderBodyPresent ? {} : null
	}
})

afterEach(() => {
	for (const scope of scopes.reverse()) scope.stop()
	delete globalThis.document
	delete globalThis.window
})

test('pending public details preserve matching server SEO and never write a temporary noindex', async() => {
	serverHead()
	mountSeo({ fallback: true, title: 'Generic fallback', robots: 'index,follow' })
	const detail = mountSeo({ pending: true, title: 'Business not loaded', robots: 'index,follow' })
	assert.equal(document.title, 'Server business title')
	assert.equal(document.head.querySelector('meta[name="description"]').getAttribute('content'), 'Server business description')
	assert.equal(document.head.querySelectorAll('link[hreflang]').length, 1)
	// A temporary API/network failure leaves the detail pending rather than declaring it missing.
	detail.source.value = { pending: true, title: 'Still waiting', robots: 'index,follow' }
	await nextTick()
	assert.equal(document.title, 'Server business title')
	assert.ok(document.robotsWrites.every((value) => !value.includes('noindex')))
})

test('client detail replaces server alternates/schema once without leaving duplicate SEO', async() => {
	serverHead()
	document.prerenderBodyPresent = true
	mountSeo({ fallback: true, title: 'Generic fallback' })
	const detail = mountSeo({ pending: true })
	assert.equal(document.head.querySelectorAll('style[data-sveevee-prerender]').length, 1)
	document.prerenderBodyPresent = false
	detail.source.value = {
		title: 'Cafe in Tel Aviv', description: 'Visible business description', robots: 'index,follow',
		canonical: '/en/business/cafe-42', alternates: { en: '/en/business/cafe-42', he: '/he/business/cafe-42' },
		jsonLd: { '@type': 'LocalBusiness', name: 'Cafe' }
	}
	await nextTick()
	assert.equal(document.title, 'Cafe in Tel Aviv | sveevee')
	assert.equal(document.head.querySelectorAll('link[rel="canonical"]').length, 1)
	assert.equal(document.head.querySelectorAll('link[rel="alternate"][hreflang]').length, 2)
	assert.equal(document.head.querySelectorAll('script[type="application/ld+json"]').length, 1)
	assert.equal(document.head.querySelectorAll('[data-sveevee-prerender]').length, 0)
	assert.equal(JSON.parse(document.getElementById('sveevee-jsonld').textContent).name, 'Cafe')
})

test('global locale/fallback updates cannot overwrite resolved page metadata', async() => {
	const fallback = mountSeo({ fallback: true, title: 'Generic fallback' })
	mountSeo({ title: 'Actual business', canonical: '/en/business/cafe-42', robots: 'index,follow' })
	fallback.source.value = { fallback: true, title: 'Autre titre générique' }
	await nextTick()
	assert.equal(document.title, 'Actual business | sveevee')
	assert.equal(document.head.querySelector('link[rel="canonical"]').getAttribute('href'), 'https://sveevee.co.il/en/business/cafe-42')
})

test('a confirmed unavailable page stays noindex despite later global fallback updates', async() => {
	serverHead()
	const fallback = mountSeo({ fallback: true, title: 'Generic fallback', robots: 'index,follow' })
	const detail = mountSeo({ pending: true })
	detail.source.value = { title: 'Unavailable', robots: 'noindex,follow', canonical: '/en/business/cafe-42' }
	await nextTick()
	fallback.source.value = { fallback: true, title: 'New generic title', robots: 'index,follow' }
	await nextTick()
	assert.equal(document.head.querySelector('meta[name="robots"]').getAttribute('content'), 'noindex,follow')
	assert.equal(document.head.querySelectorAll('script[type="application/ld+json"]').length, 0)
	assert.equal(document.head.querySelectorAll('link[hreflang]').length, 0)
})

test('route changes discard an old server canonical and schema while the next detail loads', async() => {
	serverHead()
	const fallback = mountSeo({ fallback: true, title: 'Generic fallback' })
	mountSeo({ pending: true })
	window.location.pathname = '/en/product/next-product-12'
	fallback.source.value = { fallback: true, title: 'Product', canonical: '/en/product/next-product-12' }
	await nextTick()
	assert.equal(document.title, 'Product | sveevee')
	assert.equal(document.head.querySelector('link[rel="canonical"]').getAttribute('href'), 'https://sveevee.co.il/en/product/next-product-12')
	assert.equal(document.head.querySelectorAll('script[type="application/ld+json"]').length, 0)
	assert.equal(document.head.querySelectorAll('link[hreflang]').length, 0)
})

test('private-route noindex overrides any matching server metadata', () => {
	window.location.pathname = '/profile'
	serverHead('/profile')
	mountSeo({ fallback: true, title: 'Profile', robots: 'noindex,nofollow', canonical: '/profile' })
	assert.equal(document.head.querySelector('meta[name="robots"]').getAttribute('content'), 'noindex,nofollow')
	assert.equal(document.head.querySelectorAll('script[type="application/ld+json"]').length, 0)
})

test('unmounted detail relinquishes metadata ownership to the next route', async() => {
	const fallback = mountSeo({ fallback: true, title: 'Generic fallback' })
	const detail = mountSeo({ title: 'Actual business' })
	window.location.pathname = '/login'
	detail.scope.stop()
	fallback.source.value = { fallback: true, title: 'Sign in', robots: 'noindex,nofollow' }
	await nextTick()
	assert.equal(document.title, 'Sign in | sveevee')
	assert.equal(document.head.querySelector('meta[name="robots"]').getAttribute('content'), 'noindex,nofollow')
})

test('private route policy wins even while an older detail effect is still present', () => {
	window.location.pathname = '/profile'
	mountSeo({ fallback: true, title: 'Profile', robots: 'noindex,nofollow' })
	mountSeo({ title: 'Old public detail', robots: 'index,follow' })
	assert.equal(document.head.querySelector('meta[name="robots"]').getAttribute('content'), 'noindex,nofollow')
	assert.equal(document.title, 'Profile | sveevee')
})
