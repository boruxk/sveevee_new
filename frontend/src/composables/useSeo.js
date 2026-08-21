import { unref, watchEffect } from 'vue'

const SITE_NAME = 'sveevee'
const DEFAULT_DESCRIPTION = 'Local ads, business pages, community pages, store products, events, ratings, and direct chat for your neighborhood.'

function cleanText(value) {
	return String(value || '')
		.replace(/\s+/g, ' ')
		.trim()
}

function truncateText(value, length = 155) {
	const text = cleanText(value)

	if (text.length <= length) {
		return text
	}

	return `${text.slice(0, length - 3).trim()}...`
}

function absoluteUrl(value) {
	if (!value || typeof window === 'undefined') {
		return value || ''
	}

	try {
		return new URL(value, window.location.origin).toString()
	} catch {
		return window.location.origin
	}
}

function resolveConfig(source) {
	if (typeof source === 'function') {
		return source()
	}

	return unref(source)
}

function ensureMeta(attribute, key) {
	const selector = `meta[${attribute}="${key}"]`
	let tag = document.head.querySelector(selector)

	if (!tag) {
		tag = document.createElement('meta')
		tag.setAttribute(attribute, key)
		tag.setAttribute('data-sveevee-seo', '')
		document.head.appendChild(tag)
	}

	return tag
}

function setMeta(attribute, key, value) {
	const tag = ensureMeta(attribute, key)
	tag.setAttribute('content', value)
}

function removeMeta(attribute, key) {
	const tag = document.head.querySelector(`meta[${attribute}="${key}"]`)
	tag?.remove()
}

function setCanonical(url) {
	let tag = document.head.querySelector('link[rel="canonical"]')

	if (!tag) {
		tag = document.createElement('link')
		tag.setAttribute('rel', 'canonical')
		tag.setAttribute('data-sveevee-seo', '')
		document.head.appendChild(tag)
	}

	tag.setAttribute('href', url)
}

function setAlternateLinks(alternates) {
	document.head.querySelectorAll('link[data-sveevee-hreflang]').forEach((tag) => tag.remove())

	if (!alternates) {
		return
	}

	Object.entries(alternates)
		.filter(([, url]) => url)
		.forEach(([hreflang, url]) => {
			const tag = document.createElement('link')
			tag.setAttribute('rel', 'alternate')
			tag.setAttribute('hreflang', hreflang)
			tag.setAttribute('href', absoluteUrl(url))
			tag.setAttribute('data-sveevee-hreflang', '')
			document.head.appendChild(tag)
		})
}

function setJsonLd(value) {
	const id = 'sveevee-jsonld'
	let tag = document.getElementById(id)

	if (!value) {
		tag?.remove()
		return
	}

	if (!tag) {
		tag = document.createElement('script')
		tag.id = id
		tag.type = 'application/ld+json'
		tag.setAttribute('data-sveevee-seo', '')
		document.head.appendChild(tag)
	}

	tag.textContent = JSON.stringify(value)
}

function applySeo(config = {}) {
	if (typeof document === 'undefined') {
		return
	}

	const title = cleanText(config.title || SITE_NAME)
	const description = truncateText(config.description || DEFAULT_DESCRIPTION)
	const fullTitle = config.exactTitle || (title === SITE_NAME ? title : `${title} | ${SITE_NAME}`)
	const canonical = absoluteUrl(config.canonical || window.location.pathname)
	const image = absoluteUrl(config.image)
	const robots = config.robots || 'index,follow'
	const locale = document.documentElement.lang || 'he'
	const type = config.type || 'website'

	document.title = fullTitle
	setCanonical(canonical)
	setAlternateLinks(config.alternates)
	setMeta('name', 'description', description)
	setMeta('name', 'robots', robots)
	setMeta('name', 'application-name', SITE_NAME)
	setMeta('property', 'og:site_name', SITE_NAME)
	setMeta('property', 'og:title', fullTitle)
	setMeta('property', 'og:description', description)
	setMeta('property', 'og:type', type)
	setMeta('property', 'og:url', canonical)
	setMeta('property', 'og:locale', locale)
	setMeta('name', 'twitter:card', image ? 'summary_large_image' : 'summary')
	setMeta('name', 'twitter:title', fullTitle)
	setMeta('name', 'twitter:description', description)

	if (image) {
		setMeta('property', 'og:image', image)
		setMeta('name', 'twitter:image', image)
	} else {
		removeMeta('property', 'og:image')
		removeMeta('name', 'twitter:image')
	}

	setJsonLd(config.jsonLd)
}

export function useSeo(source) {
	watchEffect(() => {
		applySeo(resolveConfig(source))
	})
}

export { SITE_NAME, cleanText, truncateText, absoluteUrl }
