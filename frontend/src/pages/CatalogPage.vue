<script setup>
	import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { fetchCatalog } from '@/services/api/catalog'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { absoluteUrl, cleanText, truncateText, useSeo } from '@/composables/useSeo'
	import { CATALOG_SCOPES, catalogHubPath, catalogLabel, catalogPath, catalogResultPath } from '@/constants/catalogTopics'
	import { locationLabel } from '@/utils/locationLabels'

	const route = useRoute()
	const router = useRouter()
	const { locale, t } = useI18n()
	const loading = ref(false)
	const directory = ref({ groups: [], popular_topics: [] })
	const catalog = ref(null)
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
	const locationForm = reactive({
		city: '',
		neighborhood: ''
	})
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		addOption,
		filterOptions,
		hasOptionValue
	} = useLocationOptions(toRef(locationForm, 'city'))

	const scopeHubSlug = computed(() => String(route.meta.catalogScopeSlug || ''))
	const isDirectory = computed(() => !route.params.topicSlug || Boolean(scopeHubSlug.value))
	const hub = computed(() => directory.value?.hub || null)
	const groups = computed(() => directory.value.groups || [])
	const popularTopics = computed(() => directory.value.popular_topics || [])
	const topic = computed(() => catalog.value?.topic || null)
	const activeCity = computed(() => directory.value?.city || catalog.value?.city || '')
	const activeNeighborhood = computed(() => directory.value?.neighborhood || catalog.value?.neighborhood || '')
	const hasActiveLocation = computed(() => Boolean(activeCity.value || activeNeighborhood.value))
	const activeCityLabel = computed(() => locationLabel(activeCity.value, 'city', locale.value))
	const activeNeighborhoodLabel = computed(() => locationLabel(activeNeighborhood.value, 'neighborhood', locale.value))
	const totalCount = computed(() => Number(catalog.value?.total_count || 0))
	const pageTitle = computed(() => {
		if (isDirectory.value) {
			return [
				catalogLabel(hub.value?.labels, locale.value) || t('catalog.title'),
				activeNeighborhoodLabel.value,
				activeCityLabel.value
			].filter(Boolean).join(' - ')
		}

		return catalog.value?.title_he || catalogLabel(topic.value?.labels, 'he') || t('catalog.title')
	})
	const pageDescription = computed(() => {
		if (isDirectory.value) {
			return catalogLabel(hub.value?.descriptions, locale.value) || t('catalog.intro')
		}

		return catalog.value?.description_he || t('catalog.intro')
	})
	const segmentDefinitions = computed(() => [
		{ key: 'pages', label: t('catalog.sections.pages'), icon: 'storefront', kind: 'page' },
		{ key: 'products', label: t('catalog.sections.products'), icon: 'inventory_2', kind: 'product' },
		{ key: 'services', label: t('catalog.sections.services'), icon: 'design_services', kind: 'service' },
		{ key: 'events', label: t('catalog.sections.events'), icon: 'event', kind: 'event' },
		{ key: 'ads', label: t('catalog.sections.ads'), icon: 'campaign', kind: 'ad' },
		{ key: 'users', label: t('catalog.sections.users'), icon: 'person', kind: 'user' }
	])
	const visibleSegments = computed(() => segmentDefinitions.value
		.map((definition) => ({
			...definition,
			count: catalog.value?.segments?.[definition.key]?.count || 0,
			items: catalog.value?.segments?.[definition.key]?.items || []
		}))
		.filter((segment) => segment.count > 0 || segment.items.length > 0))
	const relatedTopics = computed(() => catalog.value?.related_topics || [])
	const cityLabel = computed(() => locationLabel(catalog.value?.city, 'city', locale.value))
	const neighborhoodLabel = computed(() => locationLabel(catalog.value?.neighborhood, 'neighborhood', locale.value))
	const localLinks = computed(() => {
		if (!topic.value) {
			return []
		}

		return [
			catalog.value?.city ? {
				label: cityLabel.value,
				to: catalogPath(topic.value, catalog.value.city)
			} : null,
			catalog.value?.city && catalog.value?.neighborhood ? {
				label: neighborhoodLabel.value,
				to: catalogPath(topic.value, catalog.value.city, catalog.value.neighborhood)
			} : null
		].filter(Boolean)
	})
	const robots = computed(() => {
		if (isDirectory.value) {
			return 'index,follow'
		}

		return catalog.value?.indexable ? 'index,follow' : 'noindex,follow'
	})
	const itemList = computed(() => visibleSegments.value
		.flatMap((segment) => segment.items.map((item) => ({
			name: itemTitle(segment.kind, item),
			url: absoluteUrl(resultPath(segment.kind, item))
		})))
		.slice(0, 30))
	const jsonLd = computed(() => {
		if (isDirectory.value) {
			return {
				'@context': 'https://schema.org',
				'@type': 'CollectionPage',
				name: pageTitle.value,
				description: pageDescription.value,
				url: absoluteUrl(route.path)
			}
		}

		return [
			{
				'@context': 'https://schema.org',
				'@type': 'CollectionPage',
				name: pageTitle.value,
				description: pageDescription.value,
				url: absoluteUrl(route.path),
				about: topic.value ? catalogLabel(topic.value.labels, 'he') : undefined
			},
			{
				'@context': 'https://schema.org',
				'@type': 'BreadcrumbList',
				itemListElement: (catalog.value?.breadcrumbs || []).map((crumb, index) => ({
					'@type': 'ListItem',
					position: index + 1,
					name: crumb.label,
					item: absoluteUrl(crumb.path)
				}))
			},
			{
				'@context': 'https://schema.org',
				'@type': 'ItemList',
				itemListElement: itemList.value.map((item, index) => ({
					'@type': 'ListItem',
					position: index + 1,
					name: item.name,
					url: item.url
				}))
			}
		]
	})

	useSeo(computed(() => ({
		title: pageTitle.value,
		description: pageDescription.value,
		canonical: route.path,
		robots: robots.value,
		type: 'website',
		jsonLd: jsonLd.value
	})))

	async function load() {
		loading.value = true
		try {
			const { data } = await fetchCatalog({
				topicSlug: scopeHubSlug.value || route.params.topicSlug,
				citySlug: route.params.citySlug,
				neighborhoodSlug: route.params.neighborhoodSlug
			})

			if (isDirectory.value) {
				directory.value = data.data || { groups: [], popular_topics: [] }
				catalog.value = null
			} else {
				catalog.value = data.data
			}
			syncLocationForm(data.data?.city, data.data?.neighborhood)
		} finally {
			loading.value = false
		}
	}

	function syncLocationForm(city, neighborhood) {
		locationForm.city = city || ''
		locationForm.neighborhood = neighborhood || ''
	}

	function filterCityOptions(value, update) {
		update(() => {
			citySelectOptions.value = filterOptions(cityOptions.value, value)
		})
	}

	function filterNeighborhoodOptions(value, update) {
		update(() => {
			neighborhoodSelectOptions.value = filterOptions(neighborhoodOptions.value, value)
		})
	}

	function topicLink(value) {
		return catalogPath(value, activeCity.value, activeNeighborhood.value)
	}

	function currentCatalogPath(city = '', neighborhood = '') {
		if (scopeHubSlug.value) {
			return catalogHubPath(scopeHubSlug.value, city, neighborhood)
		}

		return catalogPath(topic.value || route.params.topicSlug, city, neighborhood)
	}

	function applyLocationFilter() {
		const city = locationForm.city || ''
		const neighborhood = city ? locationForm.neighborhood || '' : ''
		const target = currentCatalogPath(city, neighborhood)

		if (target !== route.path) {
			router.push(target)
		}
	}

	function clearLocationFilter() {
		locationForm.city = ''
		locationForm.neighborhood = ''
		const target = currentCatalogPath()

		if (target !== route.path) {
			router.push(target)
		}
	}

	function topicName(value) {
		return catalogLabel(value?.labels, locale.value)
	}

	function groupName(value) {
		return catalogLabel(value?.labels, locale.value)
	}

	function resultPath(kind, item) {
		const target = catalogResultPath(kind, item)

		if (typeof target === 'string') {
			return target
		}

		if (target.name === 'user-page') {
			return `/users/${target.params.id}`
		}

		if (target.name === 'ad-detail') {
			return `/ads/${target.params.id}`
		}

		return `/pages/${target.params.id}`
	}

	function resultTo(kind, item) {
		return catalogResultPath(kind, item)
	}

	function searchScopeForTopic(value) {
		const scopes = value?.scopes || []

		if (scopes.includes(CATALOG_SCOPES.BUSINESS_PAGES) || scopes.includes(CATALOG_SCOPES.COMMUNITY_PAGES)) {
			return 'pages'
		}

		if (scopes.includes(CATALOG_SCOPES.PRODUCTS)) {
			return 'products'
		}

		if (scopes.includes(CATALOG_SCOPES.SERVICES)) {
			return 'services'
		}

		if (scopes.includes(CATALOG_SCOPES.EVENTS)) {
			return 'events'
		}

		if (scopes.includes(CATALOG_SCOPES.ADS)) {
			return 'ads'
		}

		if (scopes.includes(CATALOG_SCOPES.USERS)) {
			return 'users'
		}

		return undefined
	}

	function itemTitle(kind, item) {
		if (kind === 'ad') {
			return item.title
		}

		if (kind === 'user') {
			return item.display_name
		}

		return item.name
	}

	function itemBody(kind, item) {
		let text = item.public_description || item.description

		if (kind === 'ad') {
			text = item.text
		}

		if (kind === 'user') {
			text = [item.profile?.neighborhood, item.profile?.city].filter(Boolean).join(', ')
		}

		return truncateText(cleanText(text), 150)
	}

	function itemImage(kind, item) {
		if (kind === 'user') {
			return item.profile?.photo_url
		}

		return item.image_url || item.logo_url || item.banner_url || item.page?.logo_url || null
	}

	function itemOwner(kind, item) {
		if (kind === 'page') {
			return [item.address_details?.neighborhood, item.address_details?.city].filter(Boolean).join(', ')
		}

		if (kind === 'ad') {
			return [item.neighborhood, item.city].filter(Boolean).join(', ')
		}

		return item.page?.name || ''
	}

	watch(cityOptions, (options) => {
		citySelectOptions.value = options
	}, { immediate: true })

	watch(neighborhoodOptions, (options) => {
		neighborhoodSelectOptions.value = options
	}, { immediate: true })

	watch(() => locationForm.city, () => {
		if (!locationForm.city) {
			locationForm.neighborhood = ''
			return
		}

		if (locationForm.neighborhood && !hasOptionValue(neighborhoodOptions.value, locationForm.neighborhood)) {
			locationForm.neighborhood = ''
		}
	})

	watch(() => route.fullPath, load)
	onMounted(async() => {
		await Promise.all([loadLocationOptions(), load()])
		citySelectOptions.value = cityOptions.value
		neighborhoodSelectOptions.value = neighborhoodOptions.value
	})
</script>

<template>
	<q-page padding class="catalog-page">
		<div class="catalog-shell">
			<section class="soz-section-card catalog-head">
				<div>
					<div class="catalog-kicker">{{ t('catalog.kicker') }}</div>
					<h1 class="soz-page-title">{{ pageTitle }}</h1>
					<p>{{ pageDescription }}</p>
				</div>
				<q-btn
					v-if="!isDirectory"
					rounded
					unelevated
					color="primary"
					icon="search"
					:to="{ name: 'search', query: { scope: searchScopeForTopic(topic), category: topic?.key, city: catalog?.city || undefined, neighborhood: catalog?.neighborhood || undefined } }"
					:label="t('actions.search')"
				/>
			</section>
			<section class="soz-section-card catalog-location-filter">
				<q-select
					v-model="locationForm.city"
					outlined
					clearable
					emit-value
					map-options
					use-input
					hide-selected
					fill-input
					input-debounce="0"
					new-value-mode="add-unique"
					:options="citySelectOptions"
					:label="t('auth.city')"
					@filter="filterCityOptions"
					@new-value="addOption"
				/>
				<q-select
					v-model="locationForm.neighborhood"
					outlined
					clearable
					emit-value
					map-options
					use-input
					hide-selected
					fill-input
					input-debounce="0"
					new-value-mode="add-unique"
					:options="neighborhoodSelectOptions"
					:label="t('auth.neighborhood')"
					:disable="!locationForm.city"
					@filter="filterNeighborhoodOptions"
					@new-value="addOption"
				/>
				<q-btn
					rounded
					unelevated
					color="primary"
					icon="place"
					:label="t('catalog.applyLocation')"
					@click="applyLocationFilter"
				/>
				<q-btn
					v-if="hasActiveLocation"
					rounded
					flat
					color="primary"
					icon="close"
					:label="t('catalog.clearLocation')"
					@click="clearLocationFilter"
				/>
			</section>

			<div v-if="loading" class="row justify-center q-pa-xl">
				<q-spinner color="primary" />
			</div>

			<template v-else-if="isDirectory">
				<section v-if="popularTopics.length" class="catalog-section">
					<div class="catalog-section__head">
						<h2>{{ t('catalog.popularTitle') }}</h2>
					</div>
					<div class="topic-grid topic-grid--popular">
						<router-link
							v-for="item in popularTopics"
							:key="item.key"
							class="topic-chip"
							:to="topicLink(item)"
							:style="{ '--topic-color': item.color }"
						>
							<span class="topic-chip__dot" />
							<span>{{ topicName(item) }}</span>
						</router-link>
					</div>
				</section>

				<section class="catalog-section">
					<div class="catalog-section__head">
						<h2>{{ t('catalog.allTitle') }}</h2>
					</div>
					<div class="catalog-group-list">
						<article v-for="group in groups" :key="group.key" class="catalog-group">
							<h3>
								<span :style="{ backgroundColor: group.color }" />
								{{ groupName(group) }}
							</h3>
							<div class="topic-grid">
								<router-link
									v-for="item in group.topics"
									:key="item.key"
									class="topic-chip"
									:to="topicLink(item)"
									:style="{ '--topic-color': item.color }"
								>
									<span class="topic-chip__dot" />
									<span>{{ topicName(item) }}</span>
								</router-link>
							</div>
						</article>
					</div>
				</section>
			</template>

			<template v-else-if="catalog">
				<nav v-if="catalog.breadcrumbs?.length" class="catalog-breadcrumbs" aria-label="Breadcrumb">
					<router-link v-for="crumb in catalog.breadcrumbs" :key="crumb.path" :to="crumb.path">
						{{ crumb.label }}
					</router-link>
				</nav>

				<section class="catalog-summary">
					<q-chip dense text-color="white" :style="{ backgroundColor: topic?.color }">
						{{ t('catalog.resultsCount', { count: totalCount }) }}
					</q-chip>
					<div v-if="localLinks.length" class="catalog-link-row">
						<router-link v-for="link in localLinks" :key="link.to" :to="link.to">
							{{ link.label }}
						</router-link>
					</div>
				</section>

				<section v-if="totalCount === 0" class="catalog-empty">
					{{ t('catalog.empty') }}
				</section>

				<section v-for="segment in visibleSegments" :key="segment.key" class="catalog-section">
					<div class="catalog-section__head">
						<h2>{{ segment.label }}</h2>
						<span>{{ t('catalog.resultsCount', { count: segment.count }) }}</span>
					</div>
					<div class="result-grid">
						<router-link
							v-for="item in segment.items"
							:key="`${segment.kind}-${item.id}`"
							class="catalog-result-card"
							:to="resultTo(segment.kind, item)"
						>
							<div class="catalog-result-card__media">
								<img v-if="itemImage(segment.kind, item)" :src="itemImage(segment.kind, item)" alt="" loading="lazy" decoding="async" />
								<q-icon v-else :name="segment.icon" size="34px" />
							</div>
							<div class="catalog-result-card__body">
								<h3>{{ itemTitle(segment.kind, item) }}</h3>
								<p>{{ itemBody(segment.kind, item) }}</p>
								<span v-if="itemOwner(segment.kind, item)">{{ itemOwner(segment.kind, item) }}</span>
							</div>
						</router-link>
					</div>
				</section>

				<section v-if="relatedTopics.length" class="catalog-section">
					<div class="catalog-section__head">
						<h2>{{ t('catalog.related') }}</h2>
					</div>
					<div class="topic-grid">
						<router-link
							v-for="item in relatedTopics"
							:key="item.key"
							class="topic-chip"
							:to="catalogPath(item, catalog.city || '', catalog.neighborhood || '')"
							:style="{ '--topic-color': item.color }"
						>
							<span class="topic-chip__dot" />
							<span>{{ topicName(item) }}</span>
						</router-link>
					</div>
				</section>
			</template>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.catalog-page {
  padding: 0 20px 36px;
}

.catalog-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.catalog-location-filter {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) auto auto;
  gap: 12px;
  align-items: start;
  margin-top: 18px;
  padding: 22px;
}

.catalog-head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 18px;
  align-items: center;
  padding: 30px;
}

.catalog-head h1 {
  margin: 0;
}

.catalog-head p {
  max-width: 920px;
  margin: 12px 0 0;
  color: rgba(17, 34, 45, 0.66);
  font-size: 1.05rem;
  line-height: 1.65;
}

.catalog-kicker {
  margin-bottom: 8px;
  color: var(--soz-primary-deep);
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.catalog-section {
  margin-top: 22px;
}

.catalog-section__head {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 14px;
}

.catalog-section__head h2 {
  margin: 0;
  font-size: clamp(1.35rem, 1.9vw, 1.9rem);
  line-height: 1.2;
}

.catalog-section__head span {
  color: rgba(17, 34, 45, 0.58);
  font-weight: 750;
}

.catalog-group-list {
  display: grid;
  gap: 18px;
}

.catalog-group {
  padding: 22px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 28px;
  background: rgba(255, 255, 255, 0.72);
}

.catalog-group h3 {
  display: flex;
  gap: 10px;
  align-items: center;
  margin: 0 0 16px;
  font-size: 1.12rem;
}

.catalog-group h3 span {
  width: 12px;
  height: 12px;
  border-radius: 999px;
}

.topic-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.topic-grid--popular {
  gap: 12px;
}

.topic-chip {
  display: inline-flex;
  max-width: 100%;
  min-height: 42px;
  gap: 8px;
  align-items: center;
  padding: 9px 14px;
  border: 1px solid color-mix(in srgb, var(--topic-color, #f54291) 28%, rgba(17, 34, 45, 0.08));
  border-radius: 999px;
  background: color-mix(in srgb, var(--topic-color, #f54291) 10%, rgba(255, 255, 255, 0.86));
  color: #152033;
  font-weight: 780;
  text-decoration: none;
  box-shadow: 0 12px 28px color-mix(in srgb, var(--topic-color, #f54291) 12%, transparent);
}

.topic-chip__dot {
  flex: 0 0 auto;
  width: 10px;
  height: 10px;
  border-radius: 999px;
  background: var(--topic-color, #f54291);
}

.topic-chip span:last-child {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.catalog-breadcrumbs,
.catalog-link-row {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-top: 18px;
}

.catalog-breadcrumbs a,
.catalog-link-row a {
  color: var(--soz-primary-deep);
  font-weight: 760;
  text-decoration: none;
}

.catalog-breadcrumbs a + a::before {
  padding-inline: 8px;
  color: rgba(17, 34, 45, 0.38);
  content: "/";
}

.catalog-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  margin-top: 18px;
}

.catalog-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 220px;
  margin-top: 22px;
  padding: 42px 24px;
  border: 1px dashed rgba(245, 66, 145, 0.24);
  border-radius: 28px;
  background: rgba(255, 255, 255, 0.72);
  color: var(--soz-primary-deep);
  font-size: 1.22rem;
  font-weight: 760;
  text-align: center;
}

.result-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}

.catalog-result-card {
  display: grid;
  min-width: 0;
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 28px;
  background: rgba(255, 255, 255, 0.78);
  color: #152033;
  text-decoration: none;
  box-shadow: 0 18px 38px rgba(245, 66, 145, 0.08);
}

.catalog-result-card__media {
  display: grid;
  place-items: center;
  aspect-ratio: 16 / 9;
  overflow: hidden;
  background: linear-gradient(135deg, rgba(255, 116, 38, 0.14), rgba(245, 66, 145, 0.12), rgba(123, 63, 242, 0.1));
  color: var(--soz-primary-deep);
}

.catalog-result-card__media img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.catalog-result-card__body {
  display: grid;
  gap: 8px;
  min-width: 0;
  padding: 18px;
}

.catalog-result-card__body h3 {
  overflow: hidden;
  margin: 0;
  font-size: 1.16rem;
  line-height: 1.25;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.catalog-result-card__body p {
  display: -webkit-box;
  min-height: 48px;
  overflow: hidden;
  margin: 0;
  color: rgba(17, 34, 45, 0.66);
  line-height: 1.5;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.catalog-result-card__body span {
  overflow: hidden;
  color: rgba(17, 34, 45, 0.52);
  font-size: 0.92rem;
  font-weight: 760;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@media (max-width: 980px) {
  .result-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 700px) {
  .catalog-page {
    padding-inline: 10px;
  }

  .catalog-head,
  .catalog-location-filter,
  .result-grid {
    grid-template-columns: 1fr;
  }

  .catalog-head {
    padding: 22px;
  }

  .catalog-location-filter {
    padding: 18px;
  }

  .catalog-location-filter .q-btn,
  .catalog-head .q-btn {
    width: 100%;
  }

  .catalog-group {
    padding: 18px;
    border-radius: 24px;
  }
}
</style>
