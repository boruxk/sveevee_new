<script setup>
	import { computed, onBeforeUnmount, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { matDashboard, matQuestionAnswer, matCampaign, matEvent, matNotificationsNone } from '@quasar/extras/material-icons'
	import { useAuthStore } from '@/stores/auth'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { createLatestRequest } from '@/utils/latestRequest'
	import { fetchNearby } from '@/services/api/community'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'
	import NearbyFeedCard from '@/components/community/NearbyFeedCard.vue'
	import QuestionComposer from '@/components/community/QuestionComposer.vue'
	import SubscriptionsDialog from '@/components/community/SubscriptionsDialog.vue'
	import FollowButton from '@/components/community/FollowButton.vue'

	const { t } = useI18n()
	const route = useRoute()
	const router = useRouter()
	const auth = useAuthStore()
	const queryText = key => typeof route.query[key] === 'string' ? route.query[key] : ''
	const kinds = ['all', 'question', 'ad', 'event', 'following']
	const routeFilters = () => ({
		kind: kinds.includes(queryText('kind')) ? queryText('kind') : 'all',
		city: Object.hasOwn(route.query, 'city') ? queryText('city') : auth.user?.profile?.city || '',
		neighborhood: Object.hasOwn(route.query, 'neighborhood') ? queryText('neighborhood') : (!Object.hasOwn(route.query, 'city') ? auth.user?.profile?.neighborhood || '' : ''),
		category_key: queryText('category_key')
	})
	const filters = reactive(routeFilters())
	const items = ref([])
	const nextCursor = ref(null)
	const hasMore = ref(false)
	const loading = ref(false)
	const failed = ref(false)
	const composerOpen = ref(false)
	const subscriptionsOpen = ref(false)
	const subscriptionsVersion = ref(0)
	const cities = ref([])
	const neighborhoods = ref([])
	const request = createLatestRequest()
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const { cityOptions, neighborhoodOptions, loadLocationOptions, rememberLocation, filterOptions } = useLocationOptions(toRef(filters, 'city'))
	const feedKey = computed(() => JSON.stringify([filters.kind, filters.city, filters.neighborhood, filters.category_key, auth.user?.id || null]))
	const loginRoute = computed(() => ({ name: 'login', query: { redirect: route.fullPath } }))
	const followingGuest = computed(() => filters.kind === 'following' && !auth.isAuthenticated)
	const tabs = computed(() => [
		{ value: 'all', label: t('community.all'), icon: matDashboard },
		{ value: 'question', label: t('community.questions'), icon: matQuestionAnswer },
		{ value: 'ad', label: t('community.ads'), icon: matCampaign },
		{ value: 'event', label: t('community.events'), icon: matEvent },
		{ value: 'following', label: t('community.following'), icon: matNotificationsNone }
	])

	function load(append = false) {
		if (append && (loading.value || !nextCursor.value)) return
		const cursor = append ? nextCursor.value : null
		const params = { ...filters, cursor: cursor || undefined }
		return request.run(`${feedKey.value}:${cursor || ''}`, {
			request: signal => followingGuest.value ? Promise.resolve({ data: { data: { items: [], has_more: false } } }) : fetchNearby(params, { signal }),
			onStart: () => {
				loading.value = true
				failed.value = false
				if (!append) { items.value = []; nextCursor.value = null; hasMore.value = false }
			},
			onResult: ({ data }) => {
				if (!Array.isArray(data.data?.items)) throw new Error('Invalid nearby feed')
				const incoming = data.data.items.filter(item => ['question', 'ad', 'event'].includes(item.type) && item.value?.id)
				items.value = [...new Map([...(append ? items.value : []), ...incoming].map(item => [`${item.type}:${item.id}`, item])).values()]
				nextCursor.value = data.data.next_cursor || null
				hasMore.value = Boolean(data.data.has_more && nextCursor.value)
			},
			onError: () => { failed.value = true },
			onSettled: () => { loading.value = false }
		})
	}

	function filterCities(value, update) {
		update(() => { cities.value = filterOptions(cityOptions.value, value) })
	}

	function filterNeighborhoods(value, update) {
		update(() => { neighborhoods.value = filterOptions(neighborhoodOptions.value, value) })
	}

	function setCity(value) {
		filters.city = value || ''
		filters.neighborhood = ''
	}

	function ask() {
		if (!auth.isAuthenticated) return router.push(loginRoute.value)
		composerOpen.value = true
	}

	function manageSubscriptions() {
		if (!auth.isAuthenticated) return router.push(loginRoute.value)
		subscriptionsOpen.value = true
	}

	function showQuestion(question) {
		if (question?.id) router.push({ name: 'local-question', params: { id: question.id }, query: { returnTo: route.fullPath } })
	}

	function removeExpired(id) {
		items.value = items.value.filter(item => !(item.type === 'ad' && item.id === id))
	}

	function subscriptionsChanged() {
		subscriptionsVersion.value++
		if (filters.kind === 'following') load()
	}

	function followChanged() {
		if (filters.kind === 'following') load()
	}

	watch(cityOptions, value => { cities.value = value }, { immediate: true })
	watch(neighborhoodOptions, value => { neighborhoods.value = value }, { immediate: true })
	watch(feedKey, () => load(), { immediate: true })
	watch(() => route.query, () => {
		if (route.name === 'nearby') Object.assign(filters, routeFilters())
	})
	watch(() => [filters.kind, filters.city, filters.neighborhood, filters.category_key], () => {
		if (route.name !== 'nearby') return
		const query = {
			...route.query,
			kind: filters.kind === 'all' ? undefined : filters.kind,
			city: filters.city || '',
			neighborhood: filters.neighborhood || undefined,
			category_key: filters.category_key || undefined
		}
		if (['kind', 'city', 'neighborhood', 'category_key'].some(key => route.query[key] !== query[key])) router.replace({ query }).catch(() => {})
	})
	onMounted(() => {
		rememberLocation(filters.city, filters.neighborhood)
		loadLocationOptions()
		loadCatalogTopics().catch(() => {})
	})
	onBeforeUnmount(() => request.dispose())
</script>

<template>
	<q-page padding class="nearby-page">
		<div class="page-shell nearby-page__shell">
			<header class="soz-section-card nearby-page__head">
				<div><h1 class="soz-page-title">{{ t('community.nearby') }}</h1><p>{{ t('community.nearbyIntro') }}</p></div>
				<div class="nearby-page__actions">
					<q-btn outline
						rounded
						no-caps
						color="primary"
						:icon="matNotificationsNone"
						:label="t('community.subscriptions')"
						@click="manageSubscriptions"
					/>
					<q-btn unelevated
						rounded
						no-caps
						color="primary"
						icon="add"
						:label="t('community.ask')"
						@click="ask"
					/>
				</div>
			</header>
			<q-tabs v-model="filters.kind"
				no-caps
				inline-label
				align="left"
				active-color="primary"
				indicator-color="primary"
				class="nearby-page__tabs"
			>
				<q-tab v-for="tab in tabs" :key="tab.value" :name="tab.value" :label="tab.label" :icon="tab.icon" />
			</q-tabs>
			<section class="soz-section-card nearby-page__filters" :aria-label="t('community.filters')">
				<div class="nearby-page__filter-grid">
					<q-select :model-value="filters.city"
						outlined
						clearable
						use-input
						fill-input
						hide-selected
						emit-value
						map-options
						input-debounce="0"
						:label="t('community.city')"
						:options="cities"
						@filter="filterCities"
						@update:model-value="setCity"
					/>
					<q-select v-model="filters.neighborhood"
						outlined
						clearable
						use-input
						fill-input
						hide-selected
						emit-value
						map-options
						input-debounce="0"
						:label="t('community.neighborhood')"
						:options="neighborhoods"
						:disable="!filters.city"
						@filter="filterNeighborhoods"
					/>
					<CatalogCategorySelect v-model="filters.category_key" :groups="catalogGroups" scope="" :label="t('community.category')" />
				</div>
				<div v-if="filters.city && filters.category_key" class="nearby-page__follow">
					<span>{{ t('community.followSelection') }}</span>
					<FollowButton :key="`${filters.city}:${filters.neighborhood}:${filters.category_key}:${subscriptionsVersion}`" :city="filters.city" :neighborhood="filters.neighborhood || ''" :category-key="filters.category_key" @change="followChanged" />
				</div>
			</section>

			<section class="soz-section-card nearby-page__panel" :aria-label="tabs.find(tab => tab.value === filters.kind)?.label">
				<q-banner v-if="!auth.isAuthenticated" rounded class="nearby-page__guest bg-purple-1">
					{{ t('community.signInHint') }}
					<template #action><q-btn flat no-caps color="primary" :label="t('community.signIn')" :to="loginRoute" /></template>
				</q-banner>
				<div class="nearby-page__feed" :aria-busy="loading">
					<NearbyFeedCard v-for="item in items"
						:key="`${item.type}:${item.id}`"
						:item="item"
						:catalog-groups="catalogGroups"
						@expired="removeExpired"
					/>
				</div>
				<div v-if="loading" class="nearby-page__status" role="status"><q-spinner color="primary" size="32px" /></div>
				<q-banner v-else-if="failed" rounded class="bg-red-1 text-negative" role="alert">
					{{ t('community.loadFailed') }}
					<template #action><q-btn flat :label="t('community.retry')" @click="load(items.length > 0)" /></template>
				</q-banner>
				<p v-else-if="!items.length && !followingGuest" class="nearby-page__status">{{ t('community.emptyFeed') }}</p>
				<div v-if="hasMore && !failed" class="nearby-page__status"><q-btn outline
					rounded
					color="primary"
					:loading="loading"
					:label="t('community.loadMore')"
					@click="load(true)"
				/></div>
			</section>
		</div>
		<QuestionComposer v-model="composerOpen" :initial-city="filters.city" :initial-neighborhood="filters.neighborhood || ''" :initial-category="filters.category_key || ''" @saved="showQuestion" />
		<SubscriptionsDialog v-model="subscriptionsOpen" @changed="subscriptionsChanged" />
	</q-page>
</template>

<style scoped>
.nearby-page { padding: 0 20px 36px; }
.nearby-page__shell { display: grid; gap: 20px; width: 100%; max-width: 1280px; min-width: 0; margin: 0 auto; }
.nearby-page__head, .nearby-page__actions { display: flex; gap: 18px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
.nearby-page__head { padding: 28px; }
.nearby-page__head > div { min-width: 0; }
.nearby-page__head p { margin: 8px 0 0; color: var(--soz-muted); }
.nearby-page__actions { justify-content: flex-end; gap: 10px; }
.nearby-page__filters { display: grid; gap: 14px; padding: 28px; min-width: 0; }
.nearby-page__filter-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
.nearby-page__follow { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; color: var(--soz-muted); }
.nearby-page__tabs {
  min-width: 0;
  max-width: 100%;
  padding: 8px 14px;
  border: 1px solid var(--soz-line);
  border-radius: 30px;
  background: var(--soz-soft-white);
  backdrop-filter: blur(18px);
  box-shadow: 0 18px 40px rgba(33, 18, 8, .04), inset 0 1px 0 rgba(255, 255, 255, .8);
}
.nearby-page__tabs :deep(.q-tabs__content) { gap: 18px; }
.nearby-page__tabs :deep(.q-tabs__indicator), .nearby-page__tabs :deep(.q-tab__indicator) { display: none; }
.nearby-page__tabs :deep(.q-tab) {
  min-height: 54px;
  padding: 0 20px;
  border-radius: 999px;
  color: var(--soz-muted);
  transition: background-color .18s ease, box-shadow .18s ease, color .18s ease;
}
.nearby-page__tabs :deep(.q-tab:hover) { background: var(--soz-primary-tint); }
.nearby-page__tabs :deep(.q-tab--active), .nearby-page__tabs :deep(.q-tab--active:hover) { background: var(--soz-menu-gradient); color: #fff !important; }
.nearby-page__tabs :deep(.q-tab--active .q-focus-helper) { opacity: 0 !important; }
.nearby-page__tabs :deep(.q-tab--active .q-icon), .nearby-page__tabs :deep(.q-tab--active .q-tab__label) { color: #fff !important; }
.nearby-page__tabs :deep(.q-tab__content) { gap: 8px; }
.nearby-page__tabs :deep(.q-tab__label) { font-size: 1.2rem; }
.nearby-page__panel { display: grid; gap: 18px; min-width: 0; padding: 28px; }
.nearby-page__feed { display: grid; gap: 16px; min-width: 0; }
.nearby-page__feed:empty { display: none; }
.nearby-page__status { text-align: center; padding: 24px; color: var(--soz-muted); margin: 0; }
@media (max-width: 900px) {
  .nearby-page__head { display: grid; }
  .nearby-page__actions { justify-content: flex-start; }
}
@media (max-width: 700px) {
  .nearby-page { padding-inline: 10px; }
  .nearby-page__head, .nearby-page__filters, .nearby-page__panel { padding: 20px; }
  .nearby-page__filter-grid { grid-template-columns: 1fr; }
  .nearby-page__actions, .nearby-page__actions .q-btn { width: 100%; }
  .nearby-page__actions { align-items: stretch; }
  .nearby-page__tabs { border-radius: 22px; padding: 6px 8px; }
  .nearby-page__tabs :deep(.q-tabs__content) { width: auto; gap: 4px; }
  .nearby-page__tabs :deep(.q-tab) { flex: 0 0 auto; min-width: max-content; min-height: 40px; padding: 0 10px; }
  .nearby-page__tabs :deep(.q-tab__content) { display: inline-flex; flex-direction: row; align-items: center; justify-content: center; min-height: 40px; gap: 5px; line-height: 1; }
  .nearby-page__tabs :deep(.q-icon) { font-size: 18px; line-height: 1; }
  .nearby-page__tabs :deep(.q-tab__label) { overflow: visible; font-size: .8rem; font-weight: 700; text-overflow: clip; white-space: nowrap; }
}
</style>
