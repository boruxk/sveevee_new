<script setup>
	import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute, useRouter } from 'vue-router'
	import { searchEverything } from '@/services/api/search'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { catalogPath, catalogTopicByKey, catalogTopicMatchesScope, CATALOG_SCOPES, pageRoute, userRoute } from '@/constants/catalogTopics'
	import AdCard from '@/components/AdCard.vue'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'

	function queryValue(value) {
		return Array.isArray(value) ? value[0] || '' : value || ''
	}

	const { t } = useI18n()
	const route = useRoute()
	const router = useRouter()
	const loading = ref(false)
	const hasSearched = ref(false)
	const q = ref(queryValue(route.query.q))
	const initialCity = queryValue(route.query.city)
	const filters = reactive({
		city: initialCity,
		neighborhood: initialCity ? queryValue(route.query.neighborhood) : '',
		scope: queryValue(route.query.scope),
		category: queryValue(route.query.category)
	})
	const advancedOpen = ref(Boolean(filters.scope || filters.category))
	const results = reactive({ users: [], pages: [], products: [], services: [], events: [], ads: [] })
	const combinedResults = computed(() => [
		...results.users.map((user) => ({ id: `user-${user.id}`, kind: 'user', value: user })),
		...results.pages.map((page) => ({ id: `page-${page.id}`, kind: 'page', value: page })),
		...results.products.map((product) => ({ id: `product-${product.id}`, kind: 'product', value: product })),
		...results.services.map((service) => ({ id: `service-${service.id}`, kind: 'service', value: service })),
		...results.events.map((event) => ({ id: `event-${event.id}`, kind: 'event', value: event })),
		...results.ads.map((ad) => ({ id: `ad-${ad.id}`, kind: 'ad', value: ad }))
	])
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

	async function submit() {
		const params = {
			q: q.value.trim(),
			city: filters.city,
			neighborhood: filters.city ? filters.neighborhood : '',
			scope: filters.scope,
			category: filters.scope ? filters.category : ''
		}

		loading.value = true
		try {
			await router.replace({
				name: 'search',
				query: Object.fromEntries(Object.entries(params).filter(([, value]) => value))
			})
			const { data } = await searchEverything(params)
			results.users = data.data?.users || []
			results.pages = data.data?.pages || []
			results.products = data.data?.products || []
			results.services = data.data?.services || []
			results.events = data.data?.events || []
			results.ads = data.data?.ads || []
			hasSearched.value = true
		} finally {
			loading.value = false
		}
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
		}
	})
</script>

<template>
	<q-page padding class="search-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<div>
					<h1 class="soz-page-title">{{ t('search.title') }}</h1>
				</div>
			</section>

			<section class="soz-section-card search-panel">
				<q-form class="search-form" @submit.prevent="submit">
					<q-input v-model="q" outlined clearable :label="t('search.placeholder')" />
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
						flat
						rounded
						type="button"
						icon="tune"
						:label="advancedOpen ? t('search.hideAdvanced') : t('search.advanced')"
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

			<section v-if="hasSearched || combinedResults.length > 0" class="result-section">
				<div v-if="hasSearched && !loading && combinedResults.length === 0" class="empty-state">{{ t('search.empty') }}</div>
				<div v-else class="result-list">
					<template v-for="item in combinedResults" :key="item.id">
						<router-link v-if="item.kind === 'user'" :to="userRoute(item.value)" class="result-card">
							<q-avatar size="54px" color="primary" text-color="white">
								<ResponsiveImage
									v-if="item.value.profile?.photo_url"
									class="result-avatar-image"
									:src="item.value.profile.photo_url"
									:alt="item.value.display_name"
									:avif-srcset="item.value.profile.photo_avif_srcset || ''"
									:webp-srcset="item.value.profile.photo_webp_srcset || ''"
									sizes="54px"
									:width="item.value.profile.photo_width || 96"
									:height="item.value.profile.photo_height || 96"
								/>
								<span v-else>{{ item.value.display_name.slice(0, 1) }}</span>
							</q-avatar>
							<div>
								<strong>{{ item.value.display_name }}</strong>
								<p>{{ item.value.profile?.neighborhood || item.value.profile?.city || '-' }}</p>
							</div>
						</router-link>

						<router-link v-else-if="item.kind === 'page'" :to="pageRoute(item.value)" class="result-card result-card--page">
							<q-avatar size="72px" rounded class="page-result-logo" color="primary" text-color="white">
								<ResponsiveImage
									v-if="item.value.logo_url"
									class="result-avatar-image"
									:src="item.value.logo_url"
									:alt="item.value.logo_alt || `${item.value.name} logo`"
									:avif-srcset="item.value.logo_avif_srcset || ''"
									:webp-srcset="item.value.logo_webp_srcset || ''"
									sizes="72px"
									:width="item.value.logo_width || 96"
									:height="item.value.logo_height || 96"
								/>
								<q-icon v-else :name="item.value.type === 'business' ? 'storefront' : 'diversity_3'" size="34px" />
							</q-avatar>
							<div>
								<strong>{{ item.value.name }}</strong>
								<p>{{ item.value.public_description || '-' }}</p>
							</div>
						</router-link>

						<router-link
							v-else-if="['product', 'service', 'event'].includes(item.kind)"
							:to="pageRoute(item.value.page)"
							class="result-card result-card--page"
						>
							<q-avatar size="72px" rounded class="page-result-logo" color="primary" text-color="white">
								<ResponsiveImage
									v-if="item.value.image_url"
									class="result-avatar-image"
									:src="item.value.image_url"
									:alt="item.value.image_alt || item.value.name"
									:avif-srcset="item.value.image_avif_srcset || ''"
									:webp-srcset="item.value.image_webp_srcset || ''"
									sizes="72px"
									:width="item.value.image_width || 768"
									:height="item.value.image_height || 576"
								/>
								<q-icon v-else :name="item.kind === 'event' ? 'event' : item.kind === 'service' ? 'design_services' : 'inventory_2'" size="34px" />
							</q-avatar>
							<div>
								<strong>{{ item.value.name }}</strong>
								<p>{{ item.value.description || item.value.page?.name || '-' }}</p>
							</div>
						</router-link>

						<div v-else class="result-ad-link">
							<AdCard :ad="item.value" />
						</div>
					</template>
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

.page-head {
  padding: 28px;
}

.page-head h1 {
  margin: 0;
}

.search-panel {
  margin-top: 18px;
  padding: 24px;
}

.search-form {
  display: grid;
  grid-template-columns: minmax(220px, 1.4fr) minmax(170px, 0.75fr) minmax(170px, 0.75fr) auto auto;
  gap: 12px;
  align-items: start;
  width: 100%;
}

.advanced-search-toggle {
  min-height: 56px;
  padding-inline: 18px;
  border: 1px solid rgba(123, 63, 242, 0.16);
  background: rgba(255, 255, 255, 0.54);
  color: var(--soz-primary-deep);
  font-weight: 780;
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

.result-card {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 14px;
  align-items: center;
  padding: 18px;
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.76);
  overflow: hidden;
}

.result-avatar-image {
  width: 100%;
  height: 100%;
  --responsive-image-fit: cover;
}

.result-ad-link {
  color: inherit;
  text-decoration: none;
}

.result-list :deep(.listing-card) {
  border-radius: 24px;
}

.result-card--page {
  min-height: 104px;
  padding: 22px;
}

.page-result-logo {
  border: 1px solid rgba(245, 66, 145, 0.2);
  border-radius: 18px;
  background: linear-gradient(135deg, rgba(255, 116, 38, 0.94), rgba(245, 66, 145, 0.94)) !important;
  box-shadow: 0 14px 28px rgba(245, 66, 145, 0.16);
}

.page-result-logo :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.result-card > div {
  min-width: 0;
}

.result-card strong {
  display: block;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.result-card p {
  overflow: hidden;
  margin: 4px 0 0;
  color: rgba(17, 34, 45, 0.62);
  text-overflow: ellipsis;
  white-space: nowrap;
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
  .page-head,
  .search-form,
  .result-list {
    grid-template-columns: 1fr;
  }

  .search-type-options {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 700px) {
  .search-page {
    padding-inline: 10px;
  }

  .page-head {
    overflow: hidden;
    padding: 20px;
  }

  .search-panel {
    margin-top: 14px;
    padding: 20px;
  }

  .search-form {
    max-width: 100%;
    min-width: 0;
    overflow: hidden;
  }

  .search-form > * {
    width: 100%;
    min-width: 0;
    max-width: 100%;
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

  .result-card {
    padding: 16px;
  }

  .result-card--page {
    min-height: 94px;
    padding: 18px;
  }

  .page-result-logo {
    width: 62px !important;
    height: 62px !important;
  }

  .result-card p {
    display: -webkit-box;
    white-space: normal;
    -webkit-box-orient: vertical;
    -webkit-line-clamp: 2;
  }
}
</style>
