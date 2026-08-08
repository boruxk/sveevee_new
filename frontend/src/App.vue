<script setup>
	import { computed } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import heroSeoImage from '@/assets/hero-main.png'
	import { absoluteUrl, SITE_NAME, useSeo } from '@/composables/useSeo'

	const route = useRoute()
	const { t } = useI18n()

	const routeSeo = computed(() => {
		const seo = route.meta.seo || {}
		const requiresAuth = Boolean(route.meta.requiresAuth)
		const isLanding = route.name === 'landing'
		const canonical = route.path

		return {
			title: seo.titleKey ? t(seo.titleKey) : t('seo.defaultTitle'),
			description: seo.descriptionKey ? t(seo.descriptionKey) : t('seo.defaultDescription'),
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
