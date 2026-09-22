<script setup>
	import { onBeforeUnmount, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute } from 'vue-router'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { catalogLabel, catalogTopicByKey } from '@/constants/catalogTopics'
	import { locationLabel } from '@/utils/locationLabels'
	import { createLatestRequest } from '@/utils/latestRequest'
	import { fetchSubscriptions, updateSubscription, deleteSubscription } from '@/services/api/community'

	const emit = defineEmits(['changed'])
	const { t, locale } = useI18n()
	const route = useRoute()
	const $q = useQuasar()
	const auth = useAuthStore()
	const rows = ref([])
	const cursor = ref(null)
	const loading = ref(false)
	const failed = ref(false)
	const busy = ref(new Set())
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const request = createLatestRequest()
	let generation = 0

	function label(row) {
		if (row.type === 'page') return row.page?.name || t('community.pageSubscription')
		return [catalogLabel(catalogTopicByKey(catalogGroups.value, row.category_key)?.labels, locale.value), locationLabel(row.city, 'city', locale.value), locationLabel(row.neighborhood, 'neighborhood', locale.value)].filter(Boolean).join(' · ') || t('community.topicSubscription')
	}

	function load(append = false) {
		if (append && (loading.value || !cursor.value)) return
		const next = append ? cursor.value : null
		return request.run(`${generation}:${next || ''}`, {
			request: signal => auth.isAuthenticated ? fetchSubscriptions({ cursor: next || undefined }, { signal }) : Promise.resolve({ data: { data: { items: [] } } }),
			onStart: () => { loading.value = true; failed.value = false; if (!append) { rows.value = []; cursor.value = null } },
			onResult: ({ data }) => {
				if (!Array.isArray(data.data?.items)) throw new Error('Invalid subscriptions')
				rows.value = [...new Map([...(append ? rows.value : []), ...data.data.items].map(row => [row.id, row])).values()]
				cursor.value = data.data.has_more ? data.data.next_cursor || null : null
			},
			onError: () => { failed.value = true },
			onSettled: () => { loading.value = false }
		})
	}

	async function change(row, notifications, remove = false) {
		if (!auth.isAuthenticated || busy.value.has(row.id)) return
		const current = generation
		busy.value = new Set([...busy.value, row.id])
		try {
			if (remove) {
				await deleteSubscription(row.id)
				if (current !== generation) return
				rows.value = rows.value.filter(item => item.id !== row.id)
			} else {
				await updateSubscription(row.id, { notifications_enabled: notifications })
				if (current !== generation) return
				rows.value = rows.value.map(item => item.id === row.id ? { ...item, notifications_enabled: notifications } : item)
			}
			emit('changed')
		} catch {
			if (current === generation) $q.notify({ type: 'negative', message: t('community.saveFailed') })
		} finally {
			if (current === generation) busy.value = new Set([...busy.value].filter(id => id !== row.id))
		}
	}

	watch(() => auth.user?.id, () => {
		generation++
		busy.value = new Set()
		load()
		loadCatalogTopics().catch(() => {})
	}, { immediate: true })
	onBeforeUnmount(() => { generation++; request.dispose() })
</script>

<template>
	<section class="subscriptions-panel" :aria-label="t('community.subscriptions')">
		<header class="subscriptions-panel__head">
			<h2>{{ t('community.subscriptions') }}</h2>
			<slot name="headerActions" />
		</header>
		<div v-if="!auth.isAuthenticated" class="subscriptions-panel__status">
			<q-btn color="primary" :label="t('community.signIn')" :to="{ name: 'login', query: { redirect: route.fullPath } }" />
		</div>
		<template v-else>
			<div class="subscriptions-panel__list" :aria-busy="loading">
				<article v-for="row in rows" :key="row.id" class="subscriptions-panel__row">
					<div class="subscriptions-panel__title">
						<q-icon :name="row.type === 'page' ? 'storefront' : 'place'" size="22px" color="primary" />
						<router-link v-if="row.page?.public_path" :to="row.page.public_path">{{ label(row) }}</router-link>
						<strong v-else>{{ label(row) }}</strong>
					</div>
					<div class="subscriptions-panel__actions">
						<q-toggle :model-value="Boolean(row.notifications_enabled)" :label="t('community.notifications')" :disable="busy.has(row.id)" @update:model-value="change(row, $event)" />
						<q-btn flat
							no-caps
							color="negative"
							:label="t('community.removeSubscription')"
							:loading="busy.has(row.id)"
							@click="change(row, false, true)"
						/>
					</div>
				</article>
			</div>
			<div v-if="loading" class="subscriptions-panel__status" role="status"><q-spinner size="28px" color="primary" /></div>
			<div v-else-if="failed" class="subscriptions-panel__status" role="alert">
				<p>{{ t('community.subscriptionsFailed') }}</p><q-btn flat :label="t('community.retry')" @click="load(rows.length > 0)" />
			</div>
			<p v-else-if="!rows.length" class="subscriptions-panel__status">{{ t('community.noSubscriptions') }}</p>
			<div v-if="cursor && !failed" class="subscriptions-panel__status"><q-btn outline :label="t('community.loadMore')" :loading="loading" @click="load(true)" /></div>
		</template>
	</section>
</template>

<style scoped>
.subscriptions-panel { min-width: 0; }
.subscriptions-panel__head { display: flex; justify-content: space-between; align-items: center; gap: 12px; }
.subscriptions-panel__head h2 { font-size: 1.35rem; margin: 0; }
.subscriptions-panel__list { display: grid; gap: 12px; margin-top: 18px; }
.subscriptions-panel__row { padding: 14px; border: 1px solid rgba(123, 63, 242, 0.14); border-radius: 16px; }
.subscriptions-panel__title, .subscriptions-panel__actions { display: flex; gap: 10px; align-items: center; }
.subscriptions-panel__title a, .subscriptions-panel__title strong { color: var(--soz-ink); font-weight: 700; overflow-wrap: anywhere; }
.subscriptions-panel__actions { margin-top: 8px; justify-content: space-between; flex-wrap: wrap; }
.subscriptions-panel__status { padding: 20px; text-align: center; color: var(--soz-muted); }

</style>
