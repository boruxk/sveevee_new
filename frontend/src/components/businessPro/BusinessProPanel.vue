<script setup>
	import { computed, onBeforeUnmount, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute } from 'vue-router'
	import { QCard, QDialog } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { cancelBusinessPro, createBusinessProCheckout, fetchBusinessPro } from '@/services/api/businessPro'
	import { canPreviewBusinessPro, proMoney, proStatusKey, validCardcomCheckoutUrl } from '@/utils/businessPro'
	import BusinessProFeature from './BusinessProFeature.vue'

	const props = defineProps({
		pageId: { type: [Number, String], default: null },
		compact: { type: Boolean, default: false }
	})
	const auth = useAuthStore()
	const route = useRoute()
	const { t, locale } = useI18n()
	const visible = computed(() => canPreviewBusinessPro(auth))
	const overview = ref(null)
	const loading = ref(false)
	const busy = ref('')
	const error = ref('')
	const consent = ref(false)
	const selectedPageId = ref(null)
	const cancelOpen = ref(false)
	const detailsOpen = ref(false)
	let request = 0
	let action = 0
	let disposed = false
	const offer = computed(() => overview.value?.offer || {})
	const subscription = computed(() => overview.value?.subscription || null)
	const hasSubscription = computed(() => Boolean(subscription.value))
	const privatePreview = computed(() => overview.value?.private_preview === true)
	const canOpenDetails = computed(() => visible.value && Boolean(overview.value) && !loading.value && !busy.value && (hasSubscription.value || privatePreview.value || offer.value.billing_enabled === true))
	const detailsButtonKey = computed(() => {
		if (hasSubscription.value) return 'businessPro.manage'
		return privatePreview.value && !offer.value.billing_enabled ? 'businessPro.viewDetails' : 'businessPro.buy'
	})
	const testMode = computed(() => offer.value.environment === 'sandbox')
	const monthlyPrice = computed(() => proMoney(offer.value.amount_minor, offer.value.currency, locale.value))
	const contractPrice = computed(() => proMoney(subscription.value?.amount_minor ?? offer.value.amount_minor, subscription.value?.currency || offer.value.currency, locale.value))
	const pageOptions = computed(() => (overview.value?.pages || []).map(page => ({ label: page.name, value: page.id })))
	const selectedPageExists = computed(() => pageOptions.value.some(page => String(page.value) === String(selectedPageId.value)))
	const canCheckout = computed(() => overview.value?.can_checkout === true && offer.value.billing_enabled === true && selectedPageExists.value)
	const current = version => !disposed && request === version && visible.value
	const periodEnd = computed(() => {
		const value = subscription.value?.current_period_end
		if (!value) return ''
		const date = new Date(value)
		return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(locale.value, { dateStyle: 'long' }).format(date)
	})

	function openDetails() {
		if (!canOpenDetails.value) return
		consent.value = false
		detailsOpen.value = true
	}

	function closeDetails() {
		detailsOpen.value = false
		cancelOpen.value = false
		consent.value = false
	}

	async function load() {
		const version = ++request
		if (!visible.value) { overview.value = null; return }
		loading.value = true
		error.value = ''
		try {
			const { data } = await fetchBusinessPro()
			if (current(version)) {
				overview.value = data.data
				const pageIds = (overview.value?.pages || []).map(page => String(page.id))
				const preferred = [subscription.value?.page_id, selectedPageId.value, props.pageId].find(id => id != null && pageIds.includes(String(id)))
				selectedPageId.value = preferred ?? overview.value?.pages?.[0]?.id ?? null
			}
		} catch {
			if (current(version)) { overview.value = null; error.value = t('businessPro.loadFailed') }
		} finally { if (current(version)) loading.value = false }
	}

	async function checkout() {
		if (!visible.value || !consent.value || !canCheckout.value || busy.value || loading.value) return
		const version = ++request
		const actionId = ++action
		busy.value = 'checkout'
		error.value = ''
		try {
			const { data } = await createBusinessProCheckout({ page_id: selectedPageId.value, consent: true, locale: locale.value, amount_minor: offer.value.amount_minor, currency: offer.value.currency })
			if (!current(version)) return
			const url = validCardcomCheckoutUrl(data.data?.checkout_url)
			if (!url) throw new Error('Invalid checkout destination')
			window.location.assign(url)
		} catch (failure) {
			if (!current(version)) return
			const reason = failure.response?.data?.data?.reason || failure.response?.data?.errors?.reason
			if (reason === 'offer_changed') {
				consent.value = false
				await load()
				if (!disposed && action === actionId && visible.value) error.value = t('businessPro.offerChanged')
			} else error.value = t(failure.response?.status === 503 ? 'businessPro.checkoutUnavailable' : 'businessPro.checkoutFailed')
		} finally { if (!disposed && action === actionId) busy.value = '' }
	}

	async function cancel() {
		if (!visible.value || !overview.value?.can_cancel || busy.value) return
		const version = ++request
		const actionId = ++action
		busy.value = 'cancel'
		error.value = ''
		try {
			await cancelBusinessPro()
			if (!current(version)) return
			cancelOpen.value = false
			await load()
		} catch { if (current(version)) error.value = t('businessPro.cancelFailed') } finally { if (!disposed && action === actionId) busy.value = '' }
	}

	watch(selectedPageId, () => { consent.value = false })
	watch(detailsOpen, (open) => { if (!open) { consent.value = false; cancelOpen.value = false } })
	watch(() => route.fullPath, () => {
		closeDetails()
		if (busy.value) { request++; action++; busy.value = ''; loading.value = false }
	})
	watch([() => auth.user?.id, () => auth.token, visible], () => {
		request++
		action++
		selectedPageId.value = null
		overview.value = null
		consent.value = false
		detailsOpen.value = false
		cancelOpen.value = false
		busy.value = ''
		loading.value = false
		if (visible.value) load()
	}, { immediate: true })
	onBeforeUnmount(() => { disposed = true; request++; action++ })
</script>

<template>
	<section v-if="visible" class="business-pro-panel" :class="{ 'business-pro-panel--compact': compact }">
		<template v-if="compact">
			<header class="business-pro-summary-heading"><h2>{{ t('businessPro.title') }}</h2><q-badge v-if="testMode" color="orange-9">{{ t('businessPro.testMode') }}</q-badge></header>
			<div v-if="loading && !detailsOpen" class="q-py-sm"><q-spinner color="primary" size="24px" /></div>
			<template v-if="overview">
				<div class="business-pro-summary-price"><strong>{{ t('businessPro.pricePerMonth', { price: subscription?.has_access ? contractPrice : monthlyPrice }) }}</strong><q-badge v-if="subscription">{{ t(`businessPro.status.${proStatusKey(subscription.status)}`) }}</q-badge></div>
				<p class="business-pro-summary-intro">{{ t('businessPro.profileIntro') }}</p>
				<div class="business-pro-summary-actions"><q-btn rounded
					unelevated
					color="primary"
					:label="t(detailsButtonKey)"
					:disable="!canOpenDetails"
					@click="openDetails"
				/><p v-if="!hasSubscription && !offer.billing_enabled" class="business-pro-muted">{{ t('businessPro.availableLater') }}</p></div>
			</template>
			<div v-if="error && !detailsOpen" role="alert" class="business-pro-error"><span>{{ error }}</span><q-btn v-if="!overview" flat color="primary" :label="t('businessPro.retry')" @click="load" /></div>
		</template>
		<component :is="compact ? QDialog : 'div'" :model-value="compact ? detailsOpen : undefined" :persistent="Boolean(busy)" @update:model-value="detailsOpen = $event">
			<component :is="compact ? QCard : 'div'" class="business-pro-details" :class="{ 'business-pro-details--dialog': compact }" :aria-label="compact ? t('businessPro.title') : undefined">
				<header class="business-pro-heading"><div><h2>{{ t('businessPro.title') }}</h2><p v-if="privatePreview">{{ t('businessPro.privatePreview') }}</p></div><q-badge v-if="testMode || privatePreview" :color="testMode ? 'orange-9' : 'grey-7'">{{ t(testMode ? 'businessPro.testMode' : 'businessPro.privatePreview') }}</q-badge><q-btn v-if="compact"
					flat
					round
					icon="close"
					:aria-label="t('businessPro.close')"
					:disable="Boolean(busy)"
					@click="closeDetails"
				/></header>
				<div v-if="loading" class="text-center q-pa-lg"><q-spinner color="primary" size="32px" /></div>
				<div v-if="error" role="alert" class="business-pro-error"><span>{{ error }}</span><q-btn v-if="!overview" flat color="primary" :label="t('businessPro.retry')" @click="load" /></div>
				<template v-if="overview && !loading">
					<section class="business-pro-plan">
						<div class="business-pro-plan__headline"><strong class="business-pro-price">{{ t('businessPro.pricePerMonth', { price: subscription?.has_access ? contractPrice : monthlyPrice }) }}</strong><q-badge>{{ t(`businessPro.status.${proStatusKey(subscription?.status || 'inactive')}`) }}</q-badge></div>
						<p>{{ t('businessPro.accountPlan') }}</p>
						<q-select v-if="pageOptions.length"
							v-model="selectedPageId"
							outlined
							emit-value
							map-options
							:options="pageOptions"
							:label="t('businessPro.includedPage')"
							:disable="!overview.can_checkout || Boolean(busy)"
						/>
						<div v-else class="business-pro-muted"><p>{{ t('businessPro.noBusinessPage') }}</p><q-btn flat color="primary" :to="{ name: 'business' }" :label="t('businessPro.createBusiness')" /></div>
						<p v-if="testMode" class="business-pro-test-note">{{ t('businessPro.testNotice') }}</p>
						<p v-if="subscription?.cancel_at_period_end && periodEnd">{{ t('businessPro.endsOn', { date: periodEnd }) }}</p>
						<p v-else-if="periodEnd && overview.has_access">{{ t('businessPro.renewsOn', { date: periodEnd }) }}</p>
						<template v-if="overview.can_checkout">
							<q-checkbox v-model="consent" class="business-pro-consent" :disable="Boolean(busy) || !canCheckout" :label="t('businessPro.billingConsent', { price: monthlyPrice })" />
							<p class="business-pro-muted">{{ t('businessPro.cancelTerms') }}</p>
							<q-btn rounded
								unelevated
								color="primary"
								:label="t('businessPro.checkout')"
								:loading="busy === 'checkout'"
								:disable="!canCheckout || !consent || Boolean(busy)"
								@click="checkout"
							/>
						</template>
						<p v-if="!offer.billing_enabled" class="business-pro-muted">{{ t('businessPro.checkoutUnavailable') }}</p>
						<div class="business-pro-actions"><q-btn v-if="overview.can_cancel"
							outline
							color="primary"
							:label="t('businessPro.cancelRenewal')"
							:disable="Boolean(busy)"
							@click="cancelOpen = true"
						/><q-btn flat color="primary" :label="t('businessPro.refresh')" :disable="Boolean(busy)" @click="load" /></div>
					</section>
					<section class="business-pro-features"><h3>{{ t('businessPro.features') }}</h3><p v-if="!overview.features?.length" class="business-pro-empty">{{ t('businessPro.noFeatures') }}</p><BusinessProFeature v-for="feature in overview.features || []" :key="feature.key" :feature="feature" /></section>
				</template>
			</component>
		</component>
		<q-dialog v-model="cancelOpen" :persistent="busy === 'cancel'"><q-card class="business-pro-cancel"><q-card-section><h3>{{ t('businessPro.cancelRenewal') }}</h3><p>{{ t('businessPro.cancelTerms') }}</p></q-card-section><q-card-actions align="right"><q-btn flat :label="t('businessPro.keepSubscription')" :disable="Boolean(busy)" v-close-popup /><q-btn color="negative" :label="t('businessPro.confirmCancel')" :loading="busy === 'cancel'" @click="cancel" /></q-card-actions></q-card></q-dialog>
	</section>
</template>

<style scoped>
.business-pro-panel, .business-pro-details { display: grid; gap: 24px; min-width: 0; }
.business-pro-panel--compact { gap: 16px; }
.business-pro-summary-heading, .business-pro-summary-price, .business-pro-summary-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.business-pro-summary-heading h2 { margin: 0; font-size: 24px; font-weight: 800; }
.business-pro-summary-price { justify-content: flex-start; }
.business-pro-summary-price strong { font-size: 22px; }
.business-pro-summary-intro { margin: 0; line-height: 1.6; color: var(--soz-muted); }
.business-pro-summary-actions { justify-content: flex-start; }
.business-pro-summary-actions p { margin: 0; }
.business-pro-details--dialog { width: min(820px,calc(100vw - 32px)); max-width: 820px; max-height: calc(100dvh - 32px); padding: clamp(18px,3vw,28px); border-radius: 24px; background: var(--soz-page-bg, #faf7ff); overflow-y: auto; }
.business-pro-details--dialog .business-pro-heading > div { flex: 1; min-width: 0; }
@media (max-width: 600px) { .business-pro-details--dialog { width: calc(100vw - 20px); max-height: calc(100dvh - 20px); } .business-pro-details--dialog .business-pro-heading h2 { font-size: 26px; } }
.business-pro-heading, .business-pro-plan__headline, .business-pro-actions { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 14px; }
.business-pro-heading h2 { margin: 0; font-size: clamp(28px,4vw,38px); font-weight: 850; }
.business-pro-heading p { margin: 7px 0 0; color: var(--soz-muted); }
.business-pro-plan { padding: clamp(20px,4vw,32px); border: 1px solid var(--soz-line); border-radius: 24px; background: rgba(255,255,255,.86); }
.business-pro-price { font-size: clamp(22px,3vw,30px); }
.business-pro-plan p { line-height: 1.65; }
.business-pro-test-note { padding: 12px 16px; background: #fff2db; border-radius: 12px; color: #754413; }
.business-pro-muted { color: var(--soz-muted); font-size: 13px; }
.business-pro-consent { margin-inline-start: -8px; }
.business-pro-actions { justify-content: flex-start; margin-top: 20px; }
.business-pro-features { display: grid; gap: 16px; }
.business-pro-features > h3 { margin: 0; font-size: 23px; }
.business-pro-empty { padding: 20px; border: 1px dashed var(--soz-line); border-radius: 20px; color: var(--soz-muted); margin: 0; }
.business-pro-error { color: var(--q-negative); }
.business-pro-cancel { width: min(480px,calc(100vw - 32px)); border-radius: 22px; }
.business-pro-cancel h3 { margin: 0 0 14px; font-size: 24px; }
</style>
