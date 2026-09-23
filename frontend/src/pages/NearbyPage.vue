<script setup>
	import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { matDashboard, matQuestionAnswer, matCampaign, matEvent, matNotificationsNone } from '@quasar/extras/material-icons'
	import { useAuthStore } from '@/stores/auth'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { locationLabel } from '@/utils/locationLabels'
	import { createLatestRequest } from '@/utils/latestRequest'
	import { fetchNearby } from '@/services/api/community'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'
	import NearbyFeedCard from '@/components/community/NearbyFeedCard.vue'
	import QuestionComposer from '@/components/community/QuestionComposer.vue'
	import SubscriptionsDialog from '@/components/community/SubscriptionsDialog.vue'
	import FollowButton from '@/components/community/FollowButton.vue'

	const { t, locale } = useI18n()
	const route = useRoute()
	const router = useRouter()
	const auth = useAuthStore()
	const queryText = key => typeof route.query[key] === 'string' ? route.query[key] : ''
	const kinds = ['all', 'question', 'ad', 'event', 'following']
	const routeFilters = () => ({
		kind: kinds.includes(queryText('kind')) ? queryText('kind') : 'all',
		category_key: queryText('category_key')
	})
	const filters = reactive(routeFilters())
	const city = computed(() => auth.isAuthenticated ? String(auth.user?.profile?.city || '').trim() : '')
	const neighborhood = computed(() => city.value ? String(auth.user?.profile?.neighborhood || '').trim() : '')
	const areaLabel = computed(() => [locationLabel(city.value, 'city', locale.value), locationLabel(neighborhood.value, 'neighborhood', locale.value)].filter(Boolean).join(' \u00b7 '))
	const items = ref([])
	const nextCursor = ref(null)
	const hasMore = ref(false)
	const loading = ref(false)
	const failed = ref(false)
	const feedRefreshed = ref(false)
	const composerOpen = ref(false)
	const subscriptionsOpen = ref(false)
	const subscriptionsVersion = ref(0)
	const request = createLatestRequest()
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const feedKey = computed(() => JSON.stringify([filters.kind, city.value, neighborhood.value, filters.category_key, auth.user?.id || null]))
	const loginRoute = computed(() => ({ name: 'login', query: { redirect: route.fullPath } }))
	const followingGuest = computed(() => filters.kind === 'following' && !auth.isAuthenticated)
	const tabs = computed(() => [
		{ value: 'all', label: t('community.all'), icon: matDashboard },
		{ value: 'question', label: t('community.questions'), icon: matQuestionAnswer },
		{ value: 'ad', label: t('community.ads'), icon: matCampaign },
		{ value: 'event', label: t('community.events'), icon: matEvent },
		{ value: 'following', label: t('community.following'), icon: matNotificationsNone }
	])

	function load(append = false, refreshed = false) {
		if (append && (loading.value || !nextCursor.value)) return
		const cursor = append ? nextCursor.value : null
		const params = { ...filters, city: city.value, neighborhood: neighborhood.value, cursor: cursor || undefined }
		return request.run(`${feedKey.value}:${cursor || ''}`, {
			request: signal => followingGuest.value ? Promise.resolve({ data: { data: { items: [], has_more: false } } }) : fetchNearby(params, { signal }),
			onStart: () => {
				loading.value = true
				failed.value = false
				if (!append) { items.value = []; nextCursor.value = null; hasMore.value = false; feedRefreshed.value = refreshed }
			},
			onResult: ({ data }) => {
				if (!Array.isArray(data.data?.items)) throw new Error('Invalid nearby feed')
				const incoming = data.data.items.filter(item => ['question', 'ad', 'event'].includes(item.type) && item.value?.id)
				items.value = [...new Map([...(append ? items.value : []), ...incoming].map(item => [`${item.type}:${item.id}`, item])).values()]
				nextCursor.value = data.data.next_cursor || null
				hasMore.value = Boolean(data.data.has_more && nextCursor.value)
			},
			onError: error => {
				if (append && error.response?.status === 422 && error.response?.data?.errors?.cursor) {
					load(false, true)
				} else failed.value = true
			},
			onSettled: () => { loading.value = false }
		})
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

	watch(feedKey, () => load(), { immediate: true })
	watch(() => route.query, () => {
		if (route.name === 'nearby') Object.assign(filters, routeFilters())
	})
	watch(() => [filters.kind, filters.category_key], () => {
		if (route.name !== 'nearby') return
		const query = {
			...route.query,
			kind: filters.kind === 'all' ? undefined : filters.kind,
			city: undefined,
			neighborhood: undefined,
			category_key: filters.category_key || undefined
		}
		if (['kind', 'city', 'neighborhood', 'category_key'].some(key => route.query[key] !== query[key])) router.replace({ query }).catch(() => {})
	}, { immediate: true })
	onMounted(() => {
		loadCatalogTopics().catch(() => {})
	})
	onBeforeUnmount(() => request.dispose())
</script>

<template>
	<q-page padding class="nearby-page">
		<div class="page-shell nearby-page__shell">
			<header class="soz-section-card nearby-page__head">
				<div><h1 class="soz-page-title">{{ t('community.nearby') }}</h1><p>{{ t('community.nearbyIntro') }}</p><p class="nearby-page__area">{{ areaLabel ? t('community.profileArea', { location: areaLabel }) : t('community.allAreas') }}</p></div>
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
			<section class="nearby-page__controls">
				<div class="nearby-page__toolbar">
					<q-tabs v-model="filters.kind"
						no-caps
						inline-label
						align="left"
						:breakpoint="0"
						active-color="primary"
						indicator-color="primary"
						class="nearby-page__tabs"
					>
						<q-tab v-for="tab in tabs" :key="tab.value" :name="tab.value" :label="tab.label" :icon="tab.icon" />
					</q-tabs>
					<CatalogCategorySelect v-model="filters.category_key" class="nearby-page__category" :groups="catalogGroups" scope="" :label="t('community.category')" />
				</div>
				<div v-if="city && filters.category_key" class="nearby-page__follow">
					<span>{{ t('community.followSelection') }}</span>
					<FollowButton :key="`${city}:${neighborhood}:${filters.category_key}:${subscriptionsVersion}`" :city="city" :neighborhood="neighborhood" :category-key="filters.category_key" @change="followChanged" />
				</div>
			</section>

			<section class="soz-section-card nearby-page__panel" :aria-label="tabs.find(tab => tab.value === filters.kind)?.label">
				<q-banner v-if="!auth.isAuthenticated" rounded class="nearby-page__guest bg-purple-1">
					{{ t('community.signInHint') }}
					<template #action><q-btn flat no-caps color="primary" :label="t('community.signIn')" :to="loginRoute" /></template>
				</q-banner>
				<q-banner v-if="feedRefreshed" rounded class="bg-purple-1" role="status">{{ t('community.feedChanged') }}</q-banner>
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
		<QuestionComposer v-model="composerOpen" :initial-city="city" :initial-neighborhood="neighborhood" :initial-category="filters.category_key || ''" @saved="showQuestion" />
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
.nearby-page__head .nearby-page__area { font-size: .88rem; }
.nearby-page__toolbar { display: flex; align-items: center; gap: 16px; min-width: 0; }
.nearby-page__category { flex: 0 1 280px; min-width: 200px; }
.nearby-page__follow { display: flex; gap: 12px; align-items: center; justify-content: space-between; flex-wrap: wrap; color: var(--soz-muted); }
.nearby-page__controls {
  display: grid;
  gap: 12px;
  min-width: 0;
  max-width: 100%;
  padding: 8px 14px;
  border: 1px solid var(--soz-line);
  border-radius: 30px;
  background: var(--soz-soft-white);
  backdrop-filter: blur(18px);
  box-shadow: 0 18px 40px rgba(33, 18, 8, .04), inset 0 1px 0 rgba(255, 255, 255, .8);
}
.nearby-page__tabs { flex: 1 1 0; min-width: 0; }
.nearby-page__tabs :deep(.q-tabs__content) { flex-wrap: wrap; justify-content: flex-start; gap: 6px; overflow: visible; }
.nearby-page__tabs :deep(.q-tabs__arrow) { display: none; }
.nearby-page__tabs :deep(.q-tabs__indicator), .nearby-page__tabs :deep(.q-tab__indicator) { display: none; }
.nearby-page__tabs :deep(.q-tab) {
  flex: 0 1 auto;
  min-height: 48px;
  max-width: 100%;
  padding: 0 12px;
  border-radius: 999px;
  color: var(--soz-muted);
  transition: background-color .18s ease, box-shadow .18s ease, color .18s ease;
}
.nearby-page__tabs :deep(.q-tab:hover) { background: var(--soz-primary-tint); }
.nearby-page__tabs :deep(.q-tab--active), .nearby-page__tabs :deep(.q-tab--active:hover) { background: var(--soz-menu-gradient); color: #fff !important; }
.nearby-page__tabs :deep(.q-tab--active .q-focus-helper) { opacity: 0 !important; }
.nearby-page__tabs :deep(.q-tab--active .q-icon), .nearby-page__tabs :deep(.q-tab--active .q-tab__label) { color: #fff !important; }
.nearby-page__tabs :deep(.q-tab__content) { gap: 8px; }
.nearby-page__tabs :deep(.q-tab__label) { font-size: 1rem; white-space: normal; }
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
  .nearby-page__head, .nearby-page__panel { padding: 20px; }
  .nearby-page__actions, .nearby-page__actions .q-btn { width: 100%; }
  .nearby-page__actions { align-items: stretch; }
  .nearby-page__controls { border-radius: 22px; padding: 10px; }
  .nearby-page__toolbar { flex-wrap: wrap; gap: 12px; }
  .nearby-page__tabs { flex-basis: 100%; }
  .nearby-page__category { flex: 1 1 100%; min-width: 0; }
  .nearby-page__tabs :deep(.q-tabs__content) { width: auto; gap: 4px; }
  .nearby-page__tabs :deep(.q-tab) { flex: 0 1 auto; min-width: 0; min-height: 40px; padding: 0 10px; }
  .nearby-page__tabs :deep(.q-tab__content) { display: inline-flex; flex-direction: row; align-items: center; justify-content: center; min-height: 40px; gap: 5px; line-height: 1; }
  .nearby-page__tabs :deep(.q-icon) { font-size: 18px; line-height: 1; }
  .nearby-page__tabs :deep(.q-tab__label) { overflow: visible; font-size: .8rem; font-weight: 700; text-overflow: clip; white-space: normal; }
}
</style>
