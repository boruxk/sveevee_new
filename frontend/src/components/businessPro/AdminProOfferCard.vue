<script setup>
	import { computed, onBeforeUnmount, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useAuthStore } from '@/stores/auth'
	import { fetchAdminProOffer, updateAdminProOffer } from '@/services/api/businessPro'

	const props = defineProps({ planKey: { type: String, required: true } })
	const auth = useAuthStore()
	const { t } = useI18n()
	const offer = ref(null)
	const priceInput = ref('')
	const saving = ref(false)
	const error = ref('')
	const saved = ref(false)
	let generation = 0
	let disposed = false
	const visible = computed(() => auth.isAuthenticated && auth.isAdmin)
	const title = computed(() => t(props.planKey === 'private_pro' ? 'businessPro.privateTitle' : 'businessPro.title'))
	const amountMinor = computed(() => {
		const value = String(priceInput.value).trim()
		if (!/^\d+(?:[.,]\d{1,2})?$/.test(value)) return null
		const amount = Math.round(Number(value.replace(',', '.')) * 100)
		return Number.isSafeInteger(amount) && amount >= 100 && amount <= 1000000 ? amount : null
	})
	const current = version => !disposed && generation === version && visible.value

	async function load() {
		const version = ++generation
		error.value = ''
		try {
			const { data } = await fetchAdminProOffer({ params: { plan_key: props.planKey } })
			if (current(version)) { offer.value = data.data; priceInput.value = String(data.data.amount_minor / 100) }
		} catch { if (current(version)) error.value = t('businessPro.loadFailed') }
	}

	async function save() {
		if (!visible.value || amountMinor.value == null || saving.value || !offer.value) return
		const version = ++generation
		saving.value = true
		error.value = ''
		saved.value = false
		try {
			const { data } = await updateAdminProOffer({ plan_key: props.planKey, amount_minor: amountMinor.value })
			if (current(version)) { offer.value = data.data; priceInput.value = String(data.data.amount_minor / 100); saved.value = true }
		} catch { if (current(version)) error.value = t('businessPro.saveFailed') } finally { if (current(version)) saving.value = false }
	}

	watch([() => auth.user?.id, () => auth.token, visible, () => props.planKey], () => {
		generation++
		offer.value = null
		priceInput.value = ''
		error.value = ''
		saved.value = false
		saving.value = false
		if (visible.value) load()
	}, { immediate: true })
	onBeforeUnmount(() => { disposed = true; generation++ })
</script>

<template>
	<section class="admin-pro-offer soz-section-card">
		<h3>{{ title }}</h3><p>{{ t('businessPro.futureContractsOnly') }}</p>
		<q-form class="admin-pro-price-form" @submit="save"><q-input v-model="priceInput"
			outlined
			inputmode="decimal"
			:label="t('businessPro.monthlyPriceIls')"
			:disable="!offer || saving"
			:error="Boolean(priceInput) && amountMinor == null"
			:error-message="t('businessPro.priceRange')"
		/><q-btn type="submit" color="primary" :label="t('businessPro.save')" :loading="saving" :disable="!offer || amountMinor == null" /></q-form>
		<p v-if="error" role="alert" class="text-negative">{{ error }} <q-btn v-if="!offer" flat :label="t('businessPro.retry')" @click="load" /></p>
		<p v-if="saved" role="status" class="text-positive">{{ t('businessPro.saved') }}</p>
	</section>
</template>

<style scoped>
.admin-pro-offer { padding: 22px; min-width: 0; }
.admin-pro-offer h3 { font-size: 21px; margin: 0; }
.admin-pro-price-form { display: flex; align-items: center; flex-wrap: wrap; gap: 14px; }
.admin-pro-price-form .q-field { flex: 1 1 200px; min-width: 0; }
</style>
