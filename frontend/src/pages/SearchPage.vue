<script setup>
	import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute, useRouter } from 'vue-router'
	import { searchEverything } from '@/services/api/search'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import AdCard from '@/components/AdCard.vue'

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
		neighborhood: initialCity ? queryValue(route.query.neighborhood) : ''
	})
	const results = reactive({ users: [], pages: [], ads: [] })
	const combinedResults = computed(() => [
		...results.users.map((user) => ({ id: `user-${user.id}`, kind: 'user', value: user })),
		...results.pages.map((page) => ({ id: `page-${page.id}`, kind: 'page', value: page })),
		...results.ads.map((ad) => ({ id: `ad-${ad.id}`, kind: 'ad', value: ad }))
	])
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
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
			neighborhood: filters.city ? filters.neighborhood : ''
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

	onMounted(async() => {
		await loadLocationOptions()
		citySelectOptions.value = cityOptions.value
		neighborhoodSelectOptions.value = neighborhoodOptions.value
		if (q.value || filters.city || filters.neighborhood) {
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
					<q-btn color="primary"
						unelevated
						rounded
						type="submit"
						icon="search"
						:loading="loading"
						:label="t('actions.search')"
					/>
				</q-form>
			</section>

			<section v-if="hasSearched || combinedResults.length > 0" class="result-section">
				<div v-if="hasSearched && !loading && combinedResults.length === 0" class="empty-state">{{ t('search.empty') }}</div>
				<div v-else class="result-list">
					<template v-for="item in combinedResults" :key="item.id">
						<router-link v-if="item.kind === 'user'" :to="{ name: 'user-page', params: { id: item.value.id } }" class="result-card">
							<q-avatar size="54px" color="primary" text-color="white">
								<img v-if="item.value.profile?.photo_url" :src="item.value.profile.photo_url" alt="" />
								<span v-else>{{ item.value.display_name.slice(0, 1) }}</span>
							</q-avatar>
							<div>
								<strong>{{ item.value.display_name }}</strong>
								<p>{{ item.value.profile?.neighborhood || item.value.profile?.city || '-' }}</p>
							</div>
						</router-link>

						<router-link v-else-if="item.kind === 'page'" :to="{ name: 'page-detail', params: { id: item.value.id } }" class="result-card result-card--page">
							<q-avatar size="72px" rounded class="page-result-logo" color="primary" text-color="white">
								<img v-if="item.value.logo_url" :src="item.value.logo_url" alt="" />
								<q-icon v-else :name="item.value.type === 'business' ? 'storefront' : 'diversity_3'" size="34px" />
							</q-avatar>
							<div>
								<strong>{{ item.value.name }}</strong>
								<p>{{ item.value.public_description || '-' }}</p>
							</div>
						</router-link>

						<router-link v-else :to="{ name: 'ad-detail', params: { id: item.value.id } }" class="result-ad-link">
							<AdCard :ad="item.value" />
						</router-link>
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
  grid-template-columns: minmax(260px, 1.5fr) minmax(190px, 0.8fr) minmax(190px, 0.8fr) auto;
  gap: 12px;
  align-items: start;
  width: 100%;
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
