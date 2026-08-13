<script setup>
	import { computed } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { absoluteUrl, SITE_NAME, useSeo } from '@/composables/useSeo'
	import { getLegalDocument } from '@/constants/legalDocuments'

	const route = useRoute()
	const { t, locale } = useI18n()
	const heroSeoImage = '/assets/landing/hero-main.v1.webp'

	const routeSeo = computed(() => {
		const seo = route.meta.seo || {}
		const requiresAuth = Boolean(route.meta.requiresAuth)
		const isLanding = route.name === 'landing'
		const canonical = route.path
		const legalDocument = route.meta.legalDocument ? getLegalDocument(route.meta.legalDocument, locale.value) : null

		return {
			title: legalDocument?.title || (seo.titleKey ? t(seo.titleKey) : t('seo.defaultTitle')),
			description: legalDocument?.intro || (seo.descriptionKey ? t(seo.descriptionKey) : t('seo.defaultDescription')),
			robots: seo.robots || (requiresAuth ? 'noindex,nofollow' : 'index,follow'),
			type: seo.type || 'website',
			canonical,
			image: isLanding ? heroSeoImage : undefined,
			jsonLd: isLanding ? [
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
				}
			] : null
		}
	})

	useSeo(routeSeo)
</script>

<template>
	<router-view />
</template>
