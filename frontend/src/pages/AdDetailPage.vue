<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { fetchAd } from '@/services/api/ads'
	import { catalogLabel, catalogPath, catalogTopicForAdCategory } from '@/constants/catalogTopics'
	import { locationLabel as localizedLocationLabel } from '@/utils/locationLabels'
	import { absoluteUrl, cleanText, truncateText, useSeo } from '@/composables/useSeo'
	import AdCard from '@/components/AdCard.vue'

	const route = useRoute()
	const { t, locale } = useI18n()
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const ad = ref(null)
	const loading = ref(false)
	const ownerName = computed(() => ad.value?.page?.name || ad.value?.user?.display_name || '')
	const locationLabel = computed(() => [ad.value?.city, ad.value?.neighborhood].filter(Boolean).join(', '))
	const adTopic = computed(() => catalogTopicForAdCategory(catalogGroups.value, ad.value?.category))
	const adCatalogLinks = computed(() => {
		if (!adTopic.value) {
			return []
		}

		const city = ad.value?.city || ''
		const neighborhood = ad.value?.neighborhood || ''

		return [
			city ? {
				label: localizedLocationLabel(city, 'city', locale.value),
				to: catalogPath(adTopic.value, city)
			} : null,
			city && neighborhood ? {
				label: localizedLocationLabel(neighborhood, 'neighborhood', locale.value),
				to: catalogPath(adTopic.value, city, neighborhood)
			} : null,
			{
				label: catalogLabel(adTopic.value.labels, locale.value),
				to: catalogPath(adTopic.value)
			}
		].filter(Boolean)
	})
	const seoDescription = computed(() => {
		if (!ad.value) {
			return t('seo.adFallbackDescription')
		}

		return truncateText([cleanText(ad.value.text), locationLabel.value, ownerName.value].filter(Boolean).join(' '))
	})
	const canonicalPath = computed(() => ad.value?.public_path || route.path)

	useSeo(computed(() => ({
		title: ad.value?.title || t('seo.adFallbackTitle'),
		description: seoDescription.value,
		image: ad.value?.image_url,
		canonical: canonicalPath.value,
		type: 'article',
		robots: ad.value ? 'index,follow' : 'noindex,follow',
		jsonLd: ad.value ? {
			'@context': 'https://schema.org',
			'@type': 'Offer',
			name: ad.value.title,
			description: seoDescription.value,
			url: absoluteUrl(canonicalPath.value),
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

	onMounted(async() => {
		await Promise.all([load(), loadCatalogTopics()])
	})
</script>

<template>
	<q-page padding class="listing-detail-page">
		<div class="page-shell">
			<div v-if="ad" class="listing-detail-card">
				<nav v-if="adCatalogLinks.length" class="detail-catalog-links" aria-label="Catalog">
					<router-link v-for="link in adCatalogLinks" :key="link.to" :to="link.to">
						{{ link.label }}
					</router-link>
				</nav>
				<AdCard :ad="ad" :detail-links="false" />
			</div>
			<div v-else-if="loading" class="row justify-center q-pa-xl">
				<q-spinner color="primary" />
			</div>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.listing-detail-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.detail-catalog-links {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-bottom: 14px;
}

.detail-catalog-links a {
  color: var(--soz-primary-deep);
  font-weight: 780;
  text-decoration: none;
}

.detail-catalog-links a + a::before {
  padding-inline: 8px;
  color: rgba(17, 34, 45, 0.36);
  content: "/";
}

.listing-detail-card :deep(.listing-card) {
  min-height: 420px;
  height: auto;
}

@media (max-width: 700px) {
  .listing-detail-page {
    padding-inline: 10px;
  }
}
</style>
