<script setup>
	import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute, useRouter } from 'vue-router'
	import { searchEverything } from '@/services/api/search'
	import { useAuthStore } from '@/stores/auth'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { catalogPath, catalogTopicByKey, catalogTopicMatchesScope, CATALOG_SCOPES } from '@/constants/catalogTopics'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'
	import SearchQueryInput from '@/components/SearchQueryInput.vue'
	import SearchResultCard from '@/components/SearchResultCard.vue'

	function queryValue(value) {
		return Array.isArray(value) ? value[0] || '' : value || ''
	}

	const { t } = useI18n()
	const route = useRoute()
	const router = useRouter()
	const authStore = useAuthStore()
	const loading = ref(false)
	const loadingMore = ref(false)
	const hasSearched = ref(false)
	const discoveryMode = ref(false)
	const q = ref(queryValue(route.query.q))
	const initialCity = queryValue(route.query.city)
	const filters = reactive({
		city: initialCity,
		neighborhood: initialCity ? queryValue(route.query.neighborhood) : '',
		scope: queryValue(route.query.scope),
		category: queryValue(route.query.category)
	})
	const discoveryLocation = reactive({ city: '', neighborhood: '' })
	const discoveryPagination = reactive({ current_page: 1, last_page: 1, total: 0, has_more: false, next_page: null })
	const advancedOpen = ref(false)
	const results = reactive({ users: [], pages: [], products: [], services: [], events: [], ads: [] })
	const searchResults = computed(() => [
		...results.users.map((user) => ({ id: `user-${user.id}`, kind: 'user', value: user })),
		...results.pages.map((page) => ({ id: `page-${page.id}`, kind: 'page', value: page })),
		...results.products.map((product) => ({ id: `product-${product.id}`, kind: 'product', value: product })),
		...results.services.map((service) => ({ id: `service-${service.id}`, kind: 'service', value: service })),
		...results.events.map((event) => ({ id: `event-${event.id}`, kind: 'event', value: event })),
		...results.ads.map((ad) => ({ id: `ad-${ad.id}`, kind: 'ad', value: ad }))
	])
	const discoveryResults = computed(() => [
		...results.ads.map((ad) => ({ id: `ad-${ad.id}`, kind: 'ad', value: ad })),
		...results.pages.map((page) => ({ id: `page-${page.id}`, kind: 'page', value: page })),
		...results.products.map((product) => ({ id: `product-${product.id}`, kind: 'product', value: product })),
		...results.services.map((service) => ({ id: `service-${service.id}`, kind: 'service', value: service })),
		...results.events.map((event) => ({ id: `event-${event.id}`, kind: 'event', value: event }))
	].sort(compareDiscoveryItems))
	const combinedResults = computed(() => (discoveryMode.value ? discoveryResults.value : searchResults.value))
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const scopeCatalogMap = {
		pages: [CATALOG_SCOPES.BUSINESS_PAGES, CATALOG_SCOPES.COMMUNITY_PAGES],
		products: [CATALOG_SCOPES.PRODUCTS],
		services: [CATALOG_SCOPES.SERVICES],
		events: [CATALOG_SCOPES.EVENTS],
		ads: [CATALOG_SCOPES.ADS],
		users: [CATALOG_SCOPES.USERS]
	}
	const searchTypeOptions = computed(() => [
		{ label: t('catalog.sections.pages'), value: 'pages', icon: 'storefront' },
		{ label: t('catalog.sections.products'), value: 'products', icon: 'inventory_2' },
		{ label: t('catalog.sections.services'), value: 'services', icon: 'design_services' },
		{ label: t('catalog.sections.events'), value: 'events', icon: 'event' },
		{ label: t('catalog.sections.ads'), value: 'ads', icon: 'campaign' },
		{ label: t('catalog.sections.users'), value: 'users', icon: null }
	])
	const activeCategoryScopes = computed(() => scopeCatalogMap[filters.scope] || [])
	const selectedCategoryTopic = computed(() => catalogTopicByKey(catalogGroups.value, filters.category))
	const selectedCatalogPath = computed(() => {
		if (!selectedCategoryTopic.value) {
			return ''
		}

		return catalogPath(selectedCategoryTopic.value, filters.city, filters.neighborhood)
	})
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		addOption,
		filterOptions,
		hasOptionValue
	} = useLocationOptions(toRef(filters, 'city'))

	function mergeById(current, incoming) {
		const items = new Map(current.map((item) => [String(item.id), item]))
		incoming.forEach((item) => items.set(String(item.id), item))

		return [...items.values()]
	}

	function applyResults(payload = {}, { append = false } = {}) {
		for (const key of Object.keys(results)) {
			const incoming = payload[key] || []
			results[key] = append ? mergeById(results[key], incoming) : incoming
		}
	}

	function applyDiscoveryPagination(payload = {}) {
		Object.assign(discoveryPagination, {
			current_page: 1,
			last_page: 1,
			total: 0,
			has_more: false,
			next_page: null
		}, payload)
	}

	function hasSearchCriteria(params) {
		return Object.values(params).some(Boolean)
	}

	function discoveryItemLocation(item) {
		if (item.kind === 'ad') {
			return { city: item.value.city || '', neighborhood: item.value.neighborhood || '' }
		}

		if (item.kind === 'page') {
			return item.value.address_details || {}
		}

		return item.value.page?.address_details || {}
	}

	function discoveryPriority(item) {
		if (!discoveryLocation.city) {
			return 0
		}

		const location = discoveryItemLocation(item)
		if (location.city !== discoveryLocation.city) {
			return discoveryLocation.neighborhood ? 2 : 1
		}

		if (discoveryLocation.neighborhood && location.neighborhood !== discoveryLocation.neighborhood) {
			return 1
		}

		return 0
	}

	function compareDiscoveryItems(left, right) {
		const priorityDifference = discoveryPriority(left) - discoveryPriority(right)
		if (priorityDifference !== 0) {
			return priorityDifference
		}

		const leftTime = Date.parse(left.value.created_at || '') || 0
		const rightTime = Date.parse(right.value.created_at || '') || 0

		return rightTime - leftTime || String(right.id).localeCompare(String(left.id))
	}

	async function loadDiscovery({ page = 1, append = false } = {}) {
		const profile = authStore.user?.profile || {}
		const preferredCity = profile.city || ''

		if (append) {
			loadingMore.value = true
		} else {
			loading.value = true
		}
		try {
			const { data } = await searchEverything({
				discover: 1,
				page,
				preferred_city: preferredCity,
				preferred_neighborhood: preferredCity ? profile.neighborhood || '' : ''
			})
			applyResults(data.data, { append })
			applyDiscoveryPagination(data.data?.pagination)
			discoveryLocation.city = data.data?.preferred_location?.city || ''
			discoveryLocation.neighborhood = data.data?.preferred_location?.neighborhood || ''
			discoveryMode.value = true
			hasSearched.value = false
		} finally {
			if (append) {
				loadingMore.value = false
			} else {
				loading.value = false
			}
		}
	}

	function loadMoreDiscovery() {
		if (!discoveryMode.value || loadingMore.value || !discoveryPagination.has_more) {
			return
		}

		return loadDiscovery({ page: discoveryPagination.next_page, append: true })
	}

	async function submit() {
		const params = {
			q: q.value.trim(),
			city: filters.city,
			neighborhood: filters.city ? filters.neighborhood : '',
			scope: filters.scope,
			category: filters.scope ? filters.category : ''
		}

		if (!hasSearchCriteria(params)) {
			await router.replace({ name: 'search' })
			await loadDiscovery()
			return
		}

		loading.value = true
		try {
			await router.replace({
				name: 'search',
				query: Object.fromEntries(Object.entries(params).filter(([, value]) => value))
			})
			const { data } = await searchEverything(params)
			applyResults(data.data)
			applyDiscoveryPagination()
			discoveryLocation.city = ''
			discoveryLocation.neighborhood = ''
			discoveryMode.value = false
			hasSearched.value = true
		} finally {
			loading.value = false
		}
	}

	function removeExpiredAd(adId) {
		results.ads = results.ads.filter((ad) => ad.id !== adId)
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

	function toggleAdvancedSearch() {
		advancedOpen.value = !advancedOpen.value

		if (!advancedOpen.value) {
			filters.scope = ''
			filters.category = ''
		}
	}

	function selectedCategoryMatchesActiveScope() {
		return Boolean(
			selectedCategoryTopic.value &&
				catalogTopicMatchesScope(selectedCategoryTopic.value, activeCategoryScopes.value)
		)
	}

	watch(cityOptions, (options) => {
		citySelectOptions.value = options
	}, { immediate: true })

	watch(neighborhoodOptions, (options) => {
		neighborhoodSelectOptions.value = options
	}, { immediate: true })

	watch(() => filters.city, () => {
		if (!filters.city) {
			filters.neighborhood = ''
			return
		}

		if (filters.neighborhood && !hasOptionValue(neighborhoodOptions.value, filters.neighborhood)) {
			filters.neighborhood = ''
		}
	})

	watch(() => filters.scope, () => {
		if (!filters.scope || (filters.category && !selectedCategoryMatchesActiveScope())) {
			filters.category = ''
		}
	})

	onMounted(async() => {
		await Promise.all([loadLocationOptions(), loadCatalogTopics()])
		citySelectOptions.value = cityOptions.value
		neighborhoodSelectOptions.value = neighborhoodOptions.value
		if (!filters.scope || (filters.category && !selectedCategoryMatchesActiveScope())) {
			filters.category = ''
		}
		if (q.value || filters.city || filters.neighborhood || filters.scope || filters.category) {
			await submit()
		} else {
			await loadDiscovery()
		}
	})
</script>

<template>
	<q-page padding class="search-page">
		<div class="page-shell">
			<section class="soz-section-card search-panel">
				<q-form class="search-form" @submit.prevent="submit">
					<div class="search-form__primary">
						<SearchQueryInput
							v-model="q"
							:placeholder="t('search.placeholder')"
						/>
					</div>
					<div class="search-form__controls">
						<q-select v-model="filters.city"
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
						<q-select v-model="filters.neighborhood"
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
							:disable="!filters.city"
							@filter="filterNeighborhoodOptions"
							@new-value="addOption"
						/>
						<q-btn
							class="advanced-search-toggle"
							:class="{ 'advanced-search-toggle--active': advancedOpen }"
							flat
							rounded
							type="button"
							icon="tune"
							:label="t('search.advanced')"
							@click="toggleAdvancedSearch"
						/>
						<q-btn color="primary"
							unelevated
							rounded
							type="submit"
							icon="search"
							:loading="loading"
							:label="t('actions.search')"
						/>
					</div>
				</q-form>
				<div v-if="advancedOpen" class="advanced-search-panel">
					<div class="advanced-search-panel__head">
						<strong>{{ t('search.chooseType') }}</strong>
					</div>
					<div class="search-type-options" role="radiogroup" :aria-label="t('search.chooseType')">
						<button v-for="option in searchTypeOptions"
							:key="option.value"
							type="button"
							class="search-type-option"
							:class="{ 'search-type-option--active': filters.scope === option.value }"
							:aria-checked="filters.scope === option.value"
							role="radio"
							@click="filters.scope = option.value"
						>
							<svg v-if="option.value === 'users'" class="search-type-option__svg" viewBox="0 0 24 24" aria-hidden="true">
								<path d="M8.5 11.2a3.2 3.2 0 1 0 0-6.4 3.2 3.2 0 0 0 0 6.4Z" />
								<path d="M15.8 10.7a2.7 2.7 0 1 0 0-5.4 2.7 2.7 0 0 0 0 5.4Z" />
								<path d="M3.6 19.2c.5-3.4 2.4-5.1 5-5.1 2.7 0 4.6 1.7 5 5.1" />
								<path d="M13.2 14.4c.7-.3 1.5-.4 2.5-.4 2.4 0 4.1 1.5 4.6 4.4" />
							</svg>
							<q-icon v-else :name="option.icon" size="22px" />
							<span>{{ option.label }}</span>
						</button>
					</div>
					<CatalogCategorySelect
						v-if="filters.scope"
						v-model="filters.category"
						:groups="catalogGroups"
						:scope="activeCategoryScopes"
						:label="t('catalog.category')"
					/>
				</div>
				<div v-if="selectedCatalogPath" class="catalog-filter-link">
					<router-link :to="selectedCatalogPath">{{ t('catalog.openCatalogPage') }}</router-link>
				</div>
			</section>

			<section v-if="discoveryMode || hasSearched || combinedResults.length > 0" class="result-section">
				<div v-if="!loading && combinedResults.length === 0" class="empty-state">{{ t('search.empty') }}</div>
				<div v-else class="result-list">
					<SearchResultCard
						v-for="item in combinedResults"
						:key="item.id"
						:item="item"
						@expired="removeExpiredAd"
					/>
				</div>
				<div v-if="discoveryMode && combinedResults.length > 0 && discoveryPagination.has_more" class="discovery-load-more">
					<q-btn
						outline
						rounded
						no-caps
						color="primary"
						:loading="loadingMore"
						@click="loadMoreDiscovery"
					>
						<span class="discovery-load-more__content">
							<svg viewBox="0 0 24 24" aria-hidden="true">
								<path d="m6 9 6 6 6-6" />
							</svg>
							<span>{{ t('search.loadMore') }}</span>
						</span>
					</q-btn>
				</div>
			</section>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.search-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.search-panel {
  padding: 28px 24px;
}

.search-form {
  display: grid;
  gap: 24px;
  width: 100%;
}

.search-form__primary {
  width: calc(100% * 8 / 12);
  min-width: 0;
  margin-inline: auto;
}

.search-form__controls {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
  align-items: start;
  width: calc(100% * 10 / 12);
  min-width: 0;
  margin-inline: auto;
}

.search-form__controls .q-btn {
  min-height: 56px;
}

.advanced-search-toggle {
  min-height: 56px;
  padding-inline: 18px;
  border: 1px solid rgba(123, 63, 242, 0.16);
  background: rgba(255, 255, 255, 0.54);
  color: var(--soz-primary-deep);
  font-weight: 780;
}

.advanced-search-toggle--active,
.advanced-search-toggle--active:hover {
  border-color: var(--soz-primary);
  background: var(--soz-primary);
  color: #fff;
  box-shadow: 0 10px 22px rgba(123, 63, 242, 0.24);
}

.advanced-search-panel {
  display: grid;
  gap: 14px;
  margin-top: 16px;
  padding: 18px;
  border: 1px solid rgba(245, 66, 145, 0.14);
  border-radius: 24px;
  background:
    radial-gradient(circle at top left, rgba(255, 116, 38, 0.08), transparent 36%),
    rgba(255, 255, 255, 0.62);
}

.advanced-search-panel__head {
  color: rgba(17, 34, 45, 0.72);
  font-size: 0.96rem;
}

.search-type-options {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 10px;
}

.search-type-option {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  justify-content: center;
  min-height: 48px;
  min-width: 0;
  padding: 0 14px;
  border: 1px solid rgba(123, 63, 242, 0.14);
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.64);
  color: rgba(21, 31, 59, 0.78);
  font: inherit;
  font-size: 1.02rem;
  font-weight: 500;
  letter-spacing: 0;
  line-height: 1;
  cursor: pointer;
  transition:
    background 0.18s ease,
    border-color 0.18s ease,
    box-shadow 0.18s ease,
    color 0.18s ease,
    transform 0.18s ease;
}

.search-type-option:hover {
  border-color: rgba(123, 63, 242, 0.22);
  background: var(--soz-primary-tint);
  color: var(--soz-primary-deep);
}

.search-type-option--active,
.search-type-option--active:hover {
  border-color: transparent;
  background: var(--soz-menu-gradient);
  color: #fff;
  box-shadow: 0 12px 24px rgba(123, 63, 242, 0.24);
  transform: translateY(-1px);
}

.search-type-option .q-icon {
  flex: 0 0 auto;
}

.search-type-option__svg {
  flex: 0 0 auto;
  width: 22px;
  height: 22px;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.9;
}

.search-type-option span {
  overflow: hidden;
  min-width: 0;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.catalog-filter-link {
  display: flex;
  justify-content: flex-end;
  margin-top: 12px;
}

.catalog-filter-link a {
  color: var(--soz-primary-deep);
  font-weight: 780;
  text-decoration: none;
}

.result-section {
  margin-top: 22px;
}

.result-list {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

.discovery-load-more {
  display: flex;
  justify-content: center;
  margin-top: 22px;
}

.discovery-load-more .q-btn {
  min-height: 48px;
  padding-inline: 24px;
}

.discovery-load-more__content {
  display: inline-flex;
  gap: 9px;
  align-items: center;
  font-weight: 760;
}

.discovery-load-more__content svg {
  width: 20px;
  height: 20px;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 2;
}

.empty-state {
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 220px;
  padding: 42px 24px;
  border: 1px dashed rgba(123, 63, 242, 0.2);
  border-radius: 26px;
  background: var(--soz-soft-white);
  color: var(--soz-primary-deep);
  font-size: 1.28rem;
  font-weight: 650;
  text-align: center;
}

@media (max-width: 900px) {
  .search-form__primary {
    width: calc(100% * 10 / 12);
  }

  .search-form__controls {
    width: 100%;
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .search-type-options {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 700px) {
  .search-page {
    padding-inline: 10px;
  }

  .search-panel {
    padding: 20px;
  }

  .search-form {
    max-width: 100%;
    min-width: 0;
    overflow: hidden;
  }

  .search-form__primary,
  .search-form__controls {
    width: 100%;
    min-width: 0;
    max-width: 100%;
  }

  .search-form__controls {
    grid-template-columns: 1fr;
  }

  .search-form :deep(.q-field),
  .search-form :deep(.q-field__inner),
  .search-form :deep(.q-field__control) {
    min-width: 0;
    max-width: 100%;
  }

  .search-form .q-btn {
    width: 100%;
  }

  .search-type-options {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .search-type-option {
    min-height: 46px;
    padding-inline: 10px;
    font-size: 0.96rem;
  }

  .catalog-filter-link {
    justify-content: center;
  }

}
</style>
