<script setup>
	import { computed, onMounted, ref, watch } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { setLocale } from '@/i18n'
	import { useAuthStore } from '@/stores/auth'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { fetchPage } from '@/services/api/pages'
	import { findPresencePalette } from '@/constants/presencePalettes'
	import { catalogLabel, catalogPath, catalogTopicByKey, publicPagePath } from '@/constants/catalogTopics'
	import { locationLabel } from '@/utils/locationLabels'
	import { absoluteUrl, cleanText, truncateText, useSeo } from '@/composables/useSeo'
	import EventCard from '@/components/events/EventCard.vue'
	import ProductCard from '@/components/products/ProductCard.vue'
	import ServiceCard from '@/components/services/ServiceCard.vue'
	import PagePreview from '@/components/pages/PagePreview.vue'
	import PageRatingsDialog from '@/components/ratings/PageRatingsDialog.vue'
	import PageReviewDialog from '@/components/ratings/PageReviewDialog.vue'

	const route = useRoute()
	const { t, locale } = useI18n()
	const authStore = useAuthStore()
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const SEO_LOCALES = ['he', 'en', 'ru', 'fr']
	const SCHEMA_WEEKDAYS = {
		sunday: 'Sunday',
		monday: 'Monday',
		tuesday: 'Tuesday',
		wednesday: 'Wednesday',
		thursday: 'Thursday',
		friday: 'Friday',
		saturday: 'Saturday'
	}
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
	const routeLocale = computed(() => String(route.params.locale || ''))
	const isBusinessPage = computed(() => page.value?.type === 'business')
	const pageTypeLabel = computed(() => t(`pages.kinds.${page.value?.type || 'business'}`))
	const pageAddress = computed(() => page.value?.address_details || {})
	const pageTopic = computed(() => catalogTopicByKey(catalogGroups.value, page.value?.category_key))
	const pageCity = computed(() => pageAddress.value.city || '')
	const pageNeighborhood = computed(() => pageAddress.value.neighborhood || '')
	const pageCityLabel = computed(() => (pageCity.value ? locationLabel(pageCity.value, 'city', locale.value) : ''))
	const pageNeighborhoodLabel = computed(() => (pageNeighborhood.value ? locationLabel(pageNeighborhood.value, 'neighborhood', locale.value) : ''))
	const pageTopicLabel = computed(() => catalogLabel(pageTopic.value?.labels, locale.value))
	const pageCatalogLinks = computed(() => {
		if (!pageTopic.value) {
			return []
		}

		return [
			pageCity.value ? {
				label: pageCityLabel.value,
				to: catalogPath(pageTopic.value, pageCity.value)
			} : null,
			pageCity.value && pageNeighborhood.value ? {
				label: pageNeighborhoodLabel.value,
				to: catalogPath(pageTopic.value, pageCity.value, pageNeighborhood.value)
			} : null,
			{
				label: pageTopicLabel.value,
				to: catalogPath(pageTopic.value)
			}
		].filter(Boolean)
	})
	const canonicalPath = computed(() => {
		if (!page.value) {
			return route.path
		}

		if (isBusinessPage.value) {
			return publicPagePath(page.value, routeLocale.value || locale.value)
		}

		return page.value.public_path || route.path
	})
	const localizedAlternates = computed(() => {
		if (!isBusinessPage.value || !page.value) {
			return null
		}

		return {
			...Object.fromEntries(SEO_LOCALES.map((item) => [item, publicPagePath(page.value, item)])),
			'x-default': publicPagePath(page.value, 'he')
		}
	})
	const businessSeoDescription = computed(() => {
		if (!isBusinessPage.value || !page.value) {
			return ''
		}

		return t('seo.businessPageDescription', {
			name: page.value.name,
			category: pageTopicLabel.value || t('pages.businessTitle'),
			city: pageCityLabel.value || pageCity.value || t('auth.city'),
			neighborhood: pageNeighborhoodLabel.value ? ` ${pageNeighborhoodLabel.value}` : ''
		})
	})
	const seoDescription = computed(() => {
		if (!page.value) {
			return t('seo.pageFallbackDescription')
		}

		return truncateText(
			cleanText(page.value.public_description) ||
				businessSeoDescription.value ||
				t('seo.pageDescription', { name: page.value.name, type: pageTypeLabel.value })
		)
	})
	const seoTitle = computed(() => {
		if (!page.value) {
			return t('seo.pageFallbackTitle')
		}

		if (isBusinessPage.value && (pageCityLabel.value || pageCity.value)) {
			return t('seo.businessPageTitle', {
				name: page.value.name,
				city: pageCityLabel.value || pageCity.value
			})
		}

		return page.value.name
	})
	const seoImage = computed(() => page.value?.banner_url || page.value?.logo_url)
	const shareUrl = computed(() => (page.value ? absoluteUrl(canonicalPath.value) : ''))
	const structuredAddress = computed(() => {
		const address = pageAddress.value

		if (!address.city && !address.street) {
			return undefined
		}

		return {
			'@type': 'PostalAddress',
			streetAddress: [address.street, address.number].filter(Boolean).join(' ') || undefined,
			addressLocality: address.city || undefined,
			addressRegion: address.neighborhood || undefined,
			addressCountry: 'IL'
		}
	})
	const structuredOpeningHours = computed(() => (page.value?.opening_hours || [])
		.filter((item) => item?.is_open && item.opens_at && item.closes_at && SCHEMA_WEEKDAYS[item.weekday])
		.map((item) => ({
			'@type': 'OpeningHoursSpecification',
			dayOfWeek: `https://schema.org/${SCHEMA_WEEKDAYS[item.weekday]}`,
			opens: item.opens_at,
			closes: item.closes_at
		})))
	const breadcrumbJsonLd = computed(() => {
		if (!page.value) {
			return null
		}

		const links = [
			...pageCatalogLinks.value,
			{
				label: page.value.name,
				to: canonicalPath.value
			}
		]

		if (links.length < 2) {
			return null
		}

		return {
			'@context': 'https://schema.org',
			'@type': 'BreadcrumbList',
			itemListElement: links.map((link, index) => ({
				'@type': 'ListItem',
				position: index + 1,
				name: link.label,
				item: absoluteUrl(link.to)
			}))
		}
	})
	const jsonLd = computed(() => {
		if (!page.value) {
			return null
		}

		const pageSchema = {
			'@context': 'https://schema.org',
			'@type': isBusinessPage.value ? 'LocalBusiness' : 'Organization',
			name: page.value.name,
			description: seoDescription.value,
			url: absoluteUrl(canonicalPath.value),
			image: seoImage.value || undefined,
			logo: page.value.logo_url || undefined,
			telephone: page.value.contact?.tel || page.value.phone || undefined,
			email: page.value.contact?.email || page.value.contact_email || undefined,
			address: structuredAddress.value,
			openingHoursSpecification: isBusinessPage.value && structuredOpeningHours.value.length ? structuredOpeningHours.value : undefined,
			aggregateRating: page.value.rating_summary?.count > 0 ? {
				'@type': 'AggregateRating',
				ratingValue: page.value.rating_summary.average,
				ratingCount: page.value.rating_summary.count
			} : undefined
		}

		return [pageSchema, breadcrumbJsonLd.value].filter(Boolean)
	})

	useSeo(computed(() => ({
		title: seoTitle.value,
		description: seoDescription.value,
		image: seoImage.value,
		canonical: canonicalPath.value,
		alternates: localizedAlternates.value,
		type: 'website',
		robots: page.value ? 'index,follow' : 'noindex,follow',
		jsonLd: jsonLd.value
	})))

	async function load() {
		loading.value = true
		try {
			if (routeLocale.value && routeLocale.value !== locale.value) {
				await setLocale(routeLocale.value)
			}

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

	watch(() => route.fullPath, load)

	onMounted(async() => {
		await Promise.all([load(), loadCatalogTopics()])
	})
</script>

<template>
	<q-page padding class="detail-page">
		<div v-if="page" class="page-shell">
			<nav v-if="pageCatalogLinks.length" class="detail-catalog-links" aria-label="Catalog">
				<router-link v-for="link in pageCatalogLinks" :key="link.to" :to="link.to">
					{{ link.label }}
				</router-link>
			</nav>

			<PagePreview
				:page="page"
				:palette="selectedPalette"
				:can-rate="canRate"
				:has-after-info="hasPreviewContent"
				:title-tag="isBusinessPage ? 'h1' : 'h2'"
				:description-fallback="businessSeoDescription"
				:share-url="shareUrl"
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
