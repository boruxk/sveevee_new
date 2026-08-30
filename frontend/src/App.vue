<script setup>
	import { computed } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { absoluteUrl, SITE_NAME, useSeo } from '@/composables/useSeo'
	import { getLegalDocument } from '@/constants/legalDocuments'

	const route = useRoute()
	const { t, locale } = useI18n()
	const heroSeoImage = '/assets/landing/hero-main-1360.v1.webp'

	const routeSeo = computed(() => {
		const seo = route.meta.seo || {}
		const requiresAuth = Boolean(route.meta.requiresAuth)
		const isLanding = route.name === 'landing'
		const canonical = route.path
		const legalDocument = route.meta.legalDocument ? getLegalDocument(route.meta.legalDocument, locale.value) : null
		const title = legalDocument?.title || seo.title || (seo.titleKey ? t(seo.titleKey) : t('seo.defaultTitle'))
		const image = seo.image || (isLanding ? heroSeoImage : undefined)
		const imageAlt = seo.imageAltKey ? t(seo.imageAltKey) : (image ? title : undefined)
		const imageWidth = seo.imageWidth || (isLanding ? 1360 : undefined)
		const imageHeight = seo.imageHeight || (isLanding ? 765 : undefined)
		const imageObject = image ? {
			'@type': 'ImageObject',
			url: absoluteUrl(image),
			caption: imageAlt,
			width: imageWidth,
			height: imageHeight
		} : null

		return {
			title,
			exactTitle: isLanding ? `${title} | Sveevee` : undefined,
			description: legalDocument?.intro || seo.description || (seo.descriptionKey ? t(seo.descriptionKey) : t('seo.defaultDescription')),
			robots: seo.robots || (requiresAuth ? 'noindex,nofollow' : 'index,follow'),
			type: seo.type || 'website',
			canonical,
			image,
			imageAlt,
			imageWidth,
			imageHeight,
			jsonLd: isLanding ? [
				{
					'@context': 'https://schema.org',
					'@type': 'WebPage',
					name: title,
					description: legalDocument?.intro || (seo.descriptionKey ? t(seo.descriptionKey) : t('seo.defaultDescription')),
					url: absoluteUrl('/'),
					image: absoluteUrl(image),
					primaryImageOfPage: imageObject
				},
				{
					'@context': 'https://schema.org',
					'@type': 'WebSite',
					name: SITE_NAME,
					url: absoluteUrl('/'),
					potentialAction: {
						'@type': 'SearchAction',
						target: `${absoluteUrl('/search')}?q={search_term_string}`,
						'query-input': 'required name=search_term_string'
					}
				},
				{
					'@context': 'https://schema.org',
					'@type': 'WebApplication',
					name: SITE_NAME,
					url: absoluteUrl('/'),
					applicationCategory: 'LifestyleApplication',
					operatingSystem: 'Web'
				},
				{
					'@context': 'https://schema.org',
					'@type': 'Organization',
					name: SITE_NAME,
					url: absoluteUrl('/'),
					logo: absoluteUrl('/favicon.png')
				}
			] : null
		}
	})

	useSeo(routeSeo)
</script>

<template>
	<router-view />
</template>
