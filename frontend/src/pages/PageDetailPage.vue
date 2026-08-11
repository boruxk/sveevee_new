<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useAuthStore } from '@/stores/auth'
	import { fetchPage } from '@/services/api/pages'
	import { findPresencePalette } from '@/constants/presencePalettes'
	import { absoluteUrl, cleanText, truncateText, useSeo } from '@/composables/useSeo'
	import EventCard from '@/components/events/EventCard.vue'
	import ProductCard from '@/components/products/ProductCard.vue'
	import ServiceCard from '@/components/services/ServiceCard.vue'
	import PagePreview from '@/components/pages/PagePreview.vue'
	import PageRatingsDialog from '@/components/ratings/PageRatingsDialog.vue'
	import PageReviewDialog from '@/components/ratings/PageReviewDialog.vue'

	const route = useRoute()
	const { t } = useI18n()
	const authStore = useAuthStore()
	const page = ref(null)
	const loading = ref(false)
	const ratingsDialogOpen = ref(false)
	const reviewDialogOpen = ref(false)
	const selectedPalette = computed(() => findPresencePalette(page.value?.palette_key))
	const canRate = computed(() => authStore.isAuthenticated && page.value?.user_id !== authStore.user?.id)
	const featureFlags = computed(() => page.value?.features || page.value?.setup?.features || {})
	const featureFlag = (key, fallback) => {
		const value = featureFlags.value[key] ?? fallback

		return value === true || value === 'true' || value === 1 || value === '1'
	}
	const isStoreEnabled = computed(() => featureFlag('store', false))
	const isServicesEnabled = computed(() => featureFlag('services', false))
	const isEventsEnabled = computed(() => featureFlag('events', false))
	const visibleServices = computed(() => (Array.isArray(page.value?.services) ? page.value.services.filter((service) => service?.id) : []))
	const hasStoreProducts = computed(() => page.value?.type === 'business' && isStoreEnabled.value && page.value?.products?.length > 0)
	const hasBusinessServices = computed(() => (
		page.value?.type === 'business' &&
		isServicesEnabled.value &&
		visibleServices.value.length > 0
	))
	const hasCommunityEvents = computed(() => page.value?.type === 'community' && isEventsEnabled.value && page.value?.events?.length > 0)
	const hasPreviewContent = computed(() => hasStoreProducts.value || hasBusinessServices.value || hasCommunityEvents.value)
	const pageTypeLabel = computed(() => t(`pages.kinds.${page.value?.type || 'business'}`))
	const pageAddress = computed(() => page.value?.address_details || {})
	const seoDescription = computed(() => {
		if (!page.value) {
			return t('seo.pageFallbackDescription')
		}

		return truncateText(
			cleanText(page.value.public_description) ||
				t('seo.pageDescription', { name: page.value.name, type: pageTypeLabel.value })
		)
	})
	const seoImage = computed(() => page.value?.banner_url || page.value?.logo_url)
	const structuredAddress = computed(() => {
		const address = pageAddress.value

		if (!address.city && !address.street) {
			return undefined
		}

		return {
			'@type': 'PostalAddress',
			streetAddress: [address.street, address.number].filter(Boolean).join(' ') || undefined,
			addressLocality: address.city || undefined,
			addressRegion: address.neighborhood || undefined
		}
	})

	useSeo(computed(() => ({
		title: page.value?.name || t('seo.pageFallbackTitle'),
		description: seoDescription.value,
		image: seoImage.value,
		canonical: route.path,
		type: 'website',
		robots: page.value ? 'index,follow' : 'noindex,follow',
		jsonLd: page.value ? {
			'@context': 'https://schema.org',
			'@type': page.value.type === 'business' ? 'LocalBusiness' : 'Organization',
			name: page.value.name,
			description: seoDescription.value,
			url: absoluteUrl(route.path),
			image: seoImage.value || undefined,
			telephone: page.value.contact?.tel || page.value.phone || undefined,
			email: page.value.contact?.email || page.value.contact_email || undefined,
			address: structuredAddress.value,
			aggregateRating: page.value.rating_summary?.count > 0 ? {
				'@type': 'AggregateRating',
				ratingValue: page.value.rating_summary.average,
				ratingCount: page.value.rating_summary.count
			} : undefined
		} : null
	})))

	async function load() {
		loading.value = true
		try {
			const { data } = await fetchPage(route.params.id)
			page.value = data.data
		} finally {
			loading.value = false
		}
	}

	function syncRatingSummary(summary) {
		if (!page.value || !summary) {
			return
		}

		page.value = {
			...page.value,
			rating_summary: summary
		}
	}

	function handleRatingSaved(payload) {
		syncRatingSummary(payload.summary)
	}

	onMounted(load)
</script>

<template>
	<q-page padding class="detail-page">
		<div v-if="page" class="page-shell">
			<PagePreview
				:page="page"
				:palette="selectedPalette"
				:can-rate="canRate"
				:has-after-info="hasPreviewContent"
				@show-ratings="ratingsDialogOpen = true"
				@rate="reviewDialogOpen = true"
			>
				<template #afterInfo>
					<section v-if="hasStoreProducts" class="preview-section">
						<h2>{{ t('products.storeTitle') }}</h2>
						<div class="product-grid">
							<ProductCard v-for="product in page.products"
								:key="product.id"
								:product="product"
								:palette="selectedPalette"
							/>
						</div>
					</section>
					<section v-if="hasBusinessServices" class="preview-section">
						<h2>{{ t('businessServices.title') }}</h2>
						<div class="service-list">
							<ServiceCard v-for="service in visibleServices"
								:key="service.id"
								:service="service"
								:palette="selectedPalette"
							/>
						</div>
					</section>
					<section v-if="hasCommunityEvents" class="preview-section">
						<h2>{{ t('events.eventsTitle') }}</h2>
						<div class="event-grid">
							<EventCard v-for="event in page.events"
								:key="event.id"
								:event="event"
								:palette="selectedPalette"
							/>
						</div>
					</section>
				</template>
			</PagePreview>

			<PageRatingsDialog
				v-model="ratingsDialogOpen"
				:page-id="page.id"
				@loaded="syncRatingSummary"
			/>
			<PageReviewDialog
				v-model="reviewDialogOpen"
				:page-id="page.id"
				@saved="handleRatingSaved"
			/>
		</div>
		<div v-else-if="loading" class="row justify-center q-pa-xl">
			<q-spinner color="primary" />
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.detail-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.product-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
  margin-top: 18px;
}

.preview-section h2 {
  margin: 0;
  color: var(--presence-ink);
  font-size: 1.5rem;
  line-height: 1.2;
}

.preview-section + .preview-section {
  margin-top: 28px;
}

.preview-section :deep(.product-card),
.preview-section :deep(.service-card),
.preview-section :deep(.event-card) {
  border-color: color-mix(in srgb, var(--presence-accent) 30%, var(--presence-border));
  border-radius: 24px;
  background:
    radial-gradient(circle at top left, color-mix(in srgb, var(--presence-accent) 16%, transparent), transparent 42%),
    color-mix(in srgb, var(--presence-card) 88%, var(--presence-accent) 12%);
  box-shadow: 0 18px 38px color-mix(in srgb, var(--presence-accent) 16%, transparent);
  color: var(--presence-ink);
}

.preview-section :deep(.product-card__image),
.preview-section :deep(.service-card__image),
.preview-section :deep(.event-card__image) {
  overflow: hidden;
}

.preview-section :deep(.product-card__title),
.preview-section :deep(.service-card__title),
.preview-section :deep(.event-card__title),
.preview-section :deep(.product-card__price) {
  color: var(--presence-ink);
}

.preview-section :deep(.product-card__description),
.preview-section :deep(.service-card__description),
.preview-section :deep(.event-card__description),
.preview-section :deep(.event-card__meta) {
  color: var(--presence-muted);
}

.preview-section :deep(.event-card__meta-link),
.preview-section :deep(.event-detail-dialog__meta-link) {
  color: var(--presence-accent);
}

.event-grid,
.service-list {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
  margin-top: 18px;
}

@media (max-width: 760px) {
  .product-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 700px) {
  .detail-page {
    padding-inline: 10px;
  }
}
</style>
