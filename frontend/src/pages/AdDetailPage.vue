<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { fetchAd } from '@/services/api/ads'
	import { absoluteUrl, cleanText, truncateText, useSeo } from '@/composables/useSeo'
	import AdCard from '@/components/AdCard.vue'

	const route = useRoute()
	const { t } = useI18n()
	const ad = ref(null)
	const loading = ref(false)
	const ownerName = computed(() => ad.value?.page?.name || ad.value?.user?.display_name || '')
	const locationLabel = computed(() => [ad.value?.neighborhood, ad.value?.city].filter(Boolean).join(', '))
	const seoDescription = computed(() => {
		if (!ad.value) {
			return t('seo.adFallbackDescription')
		}

		return truncateText([cleanText(ad.value.text), locationLabel.value, ownerName.value].filter(Boolean).join(' '))
	})

	useSeo(computed(() => ({
		title: ad.value?.title || t('seo.adFallbackTitle'),
		description: seoDescription.value,
		image: ad.value?.image_url,
		canonical: route.path,
		type: 'article',
		robots: ad.value ? 'index,follow' : 'noindex,follow',
		jsonLd: ad.value ? {
			'@context': 'https://schema.org',
			'@type': 'Offer',
			name: ad.value.title,
			description: seoDescription.value,
			url: absoluteUrl(route.path),
			image: ad.value.image_url || undefined,
			availability: 'https://schema.org/InStock',
			areaServed: locationLabel.value || undefined,
			seller: ownerName.value ? {
				'@type': ad.value.page ? 'Organization' : 'Person',
				name: ownerName.value
			} : undefined
		} : null
	})))

	async function load() {
		loading.value = true
		try {
			const { data } = await fetchAd(route.params.id)
			ad.value = data.data
		} finally {
			loading.value = false
		}
	}

	onMounted(load)
</script>

<template>
	<q-page padding class="ad-detail-page">
		<div class="page-shell">
			<div v-if="ad" class="ad-detail-card">
				<AdCard :ad="ad" />
			</div>
			<div v-else-if="loading" class="row justify-center q-pa-xl">
				<q-spinner color="primary" />
			</div>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.ad-detail-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.ad-detail-card :deep(.listing-card) {
  min-height: 420px;
  height: auto;
}

@media (max-width: 700px) {
  .ad-detail-page {
    padding-inline: 10px;
  }
}
</style>
