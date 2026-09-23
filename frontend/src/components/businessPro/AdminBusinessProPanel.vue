<script setup>
	import { computed, onBeforeUnmount, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useAuthStore } from '@/stores/auth'
	import { fetchAdminProSubscriptions, fetchAdminProPayments, fetchAdminProFeatures, updateAdminProFeature } from '@/services/api/businessPro'
	import { localizedProText, proMoney, proStatusKey } from '@/utils/businessPro'
	import AdminProOfferCard from './AdminProOfferCard.vue'

	const auth = useAuthStore()
	const { t, locale } = useI18n()
	const visible = computed(() => auth.isAuthenticated && auth.isAdmin)
	const tab = ref('subscriptions')
	const rows = ref([])
	const page = ref(1)
	const lastPage = ref(1)
	const total = ref(0)
	const loading = ref(false)
	const error = ref('')
	const busyFeatures = ref({})
	let generation = 0
	let mutationGeneration = 0
	let disposed = false
	const lifecycleOptions = computed(() => ['draft', 'published'].map(value => ({ value, label: t(`businessPro.${value}`) })))

	function dateTime(value) {
		if (!value) return '—'
		const date = new Date(value)
		return Number.isNaN(date.getTime()) ? '—' : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
	}

	async function load(nextPage = 1) {
		const version = ++generation
		if (!visible.value) return
		loading.value = true
		error.value = ''
		rows.value = []
		try {
			const fetchRows = { subscriptions: fetchAdminProSubscriptions, payments: fetchAdminProPayments, features: fetchAdminProFeatures }[tab.value]
			const { data } = tab.value === 'features' ? await fetchRows() : await fetchRows({ page: nextPage, per_page: 20 })
			if (disposed || generation !== version || !visible.value) return
			rows.value = data.data?.items || []
			page.value = data.data?.pagination?.current_page || 1
			lastPage.value = data.data?.pagination?.last_page || 1
			total.value = data.data?.pagination?.total ?? rows.value.length
		} catch { if (!disposed && generation === version) error.value = t('businessPro.loadFailed') } finally { if (!disposed && generation === version) loading.value = false }
	}

	async function updateFeature(feature, patch) {
		if (!visible.value || busyFeatures.value[feature.id]) return
		const session = mutationGeneration
		busyFeatures.value = { ...busyFeatures.value, [feature.id]: true }
		error.value = ''
		try {
			await updateAdminProFeature(feature.id, patch)
			if (!disposed && session === mutationGeneration && visible.value && tab.value === 'features') await load()
		} catch { if (!disposed && session === mutationGeneration) error.value = t('businessPro.saveFailed') } finally { if (!disposed && session === mutationGeneration) busyFeatures.value = { ...busyFeatures.value, [feature.id]: false } }
	}

	watch(tab, () => load())
	watch([() => auth.user?.id, () => auth.token, visible], () => {
		generation++
		mutationGeneration++
		rows.value = []
		busyFeatures.value = {}
		if (visible.value) load()
	}, { immediate: true })
	onBeforeUnmount(() => { disposed = true; generation++; mutationGeneration++ })
</script>

<template>
	<section v-if="visible" class="admin-business-pro">
		<header class="admin-pro-header"><h2>{{ t('businessPro.plansTitle') }}</h2><q-btn flat color="primary" :to="{ name: 'business-pro' }" :label="t('businessPro.openPreview')" /></header>
		<div class="admin-pro-offers">
			<AdminProOfferCard plan-key="private_pro" />
			<AdminProOfferCard plan-key="business_pro" />
		</div>
		<q-tabs v-model="tab"
			dense
			align="left"
			active-color="primary"
			outside-arrows
			mobile-arrows
		><q-tab name="subscriptions" :label="t('businessPro.subscriptions')" /><q-tab name="payments" :label="t('businessPro.payments')" /><q-tab name="features" :label="t('businessPro.features')" /></q-tabs>
		<div class="admin-pro-header"><span>{{ t('businessPro.total', { count: total }) }}</span><q-btn flat color="primary" :loading="loading" :label="t('businessPro.refresh')" @click="load(page)" /></div>
		<p v-if="error" role="alert" class="text-negative">{{ error }}</p><div v-if="loading" class="text-center q-pa-lg"><q-spinner color="primary" size="28px" /></div>
		<template v-else-if="tab === 'features'"><p v-if="!rows.length">{{ t('businessPro.noFeatures') }}</p><article v-for="feature in rows" :key="feature.id" class="admin-pro-feature soz-section-card"><header><div><h3>{{ localizedProText(feature.labels, locale) || feature.key }}</h3><small>{{ feature.key }}</small></div><q-badge>{{ t(feature.implemented ? 'businessPro.implemented' : 'businessPro.notImplemented') }}</q-badge></header><p>{{ localizedProText(feature.descriptions, locale) }}</p><div class="admin-pro-feature__controls"><q-select :model-value="feature.lifecycle"
			outlined
			emit-value
			map-options
			:options="lifecycleOptions"
			:label="t('businessPro.publication')"
			:disable="busyFeatures[feature.id]"
			@update:model-value="value => updateFeature(feature, { lifecycle: value })"
		/><q-toggle :model-value="feature.enabled" :label="t('businessPro.featureEnabled')" :disable="busyFeatures[feature.id]" @update:model-value="value => updateFeature(feature, { enabled: value })" /></div></article></template>
		<template v-else-if="!loading"><div class="admin-pro-table-wrap"><table class="admin-pro-table"><thead><tr><th>{{ t('businessPro.account') }}</th><th>{{ t('businessPro.planColumn') }}</th><th>{{ t('businessPro.includedPage') }}</th><th>{{ t('businessPro.amount') }}</th><th>{{ t('businessPro.paymentStatus') }}</th><th>{{ t('businessPro.date') }}</th></tr></thead><tbody><tr v-for="row in rows" :key="row.id"><td><strong>{{ row.user?.name || row.user_id }}</strong><small>{{ row.user?.email }}</small></td><td>{{ t(row.plan_key === 'private_pro' ? 'businessPro.privateTitle' : 'businessPro.title') }}</td><td><router-link v-if="row.page" :to="{ name: 'business-detail', params: { id: row.page.id } }">{{ row.page.name }}</router-link><span v-else>{{ row.page_id || '—' }}</span></td><td>{{ proMoney(row.amount_minor, row.currency, locale) }}<small>{{ t(row.environment === 'sandbox' ? 'businessPro.testMode' : 'businessPro.productionMode') }}</small></td><td>{{ t(`businessPro.status.${proStatusKey(row.status)}`) }}<small v-if="row.cancel_at_period_end">{{ t('businessPro.renewalCancelled') }}</small><small v-if="row.failure_code">{{ row.failure_code }}</small></td><td>{{ dateTime(tab === 'subscriptions' ? row.current_period_end : row.paid_at || row.created_at) }}<small v-if="tab === 'payments'">{{ row.public_id }}</small></td></tr></tbody></table></div><p v-if="!rows.length">{{ t('businessPro.noRecords') }}</p><div v-if="lastPage > 1" class="admin-pro-pagination"><q-btn flat :label="t('businessPro.previous')" :disable="page <= 1" @click="load(page - 1)" /><span>{{ page }} / {{ lastPage }}</span><q-btn flat :label="t('businessPro.next')" :disable="page >= lastPage" @click="load(page + 1)" /></div></template>
	</section>
</template>

<style scoped>
.admin-pro-offers { display: grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap: 16px; }
@media (max-width: 800px) { .admin-pro-offers { grid-template-columns: 1fr; } }
.admin-business-pro { display: grid; gap: 20px; min-width: 0; }
.admin-pro-header, .admin-pro-feature header, .admin-pro-price-form, .admin-pro-feature__controls, .admin-pro-pagination { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 14px; }
.admin-pro-header h2 { margin: 0; font-size: 28px; }
.admin-pro-offer, .admin-pro-feature { padding: 22px; }
.admin-pro-offer h3, .admin-pro-feature h3 { font-size: 21px; margin: 0; }
.admin-pro-price-form { justify-content: flex-start; }
.admin-pro-price-form .q-field { min-width: min(260px,100%); }
.admin-pro-feature__controls .q-field { min-width: min(250px,100%); }
.admin-pro-table-wrap { overflow-x: auto; }
.admin-pro-table { width: 100%; border-collapse: collapse; text-align: start; }
.admin-pro-table th, .admin-pro-table td { padding: 14px 12px; border-bottom: 1px solid var(--soz-line); vertical-align: top; text-align: start; }
.admin-pro-table small { display: block; color: var(--soz-muted); margin-top: 4px; }
.admin-pro-pagination { justify-content: center; }
</style>
