<script setup>
	import { computed, onBeforeUnmount, ref, watch } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useAuthStore } from '@/stores/auth'
	import { verifyBusinessProPayment } from '@/services/api/businessPro'
	import { canPreviewBusinessPro, proStatusKey } from '@/utils/businessPro'
	const auth = useAuthStore()
	const route = useRoute()
	const { t } = useI18n()
	const visible = computed(() => canPreviewBusinessPro(auth))
	const result = ref(null)
	const loading = ref(false)
	const error = ref(false)
	let request = 0
	let disposed = false

	async function verify() {
		if (!visible.value || loading.value || !route.params.id) return
		const version = ++request
		loading.value = true
		error.value = false
		result.value = null
		try {
			const { data } = await verifyBusinessProPayment(route.params.id)
			if (!disposed && request === version && visible.value) result.value = data.data
		} catch { if (!disposed && request === version) error.value = true } finally { if (!disposed && request === version) loading.value = false }
	}

	watch([() => route.params.id, () => auth.user?.id, () => auth.token, visible], () => {
		request++
		result.value = null
		loading.value = false
		if (visible.value) verify()
	}, { immediate: true })
	onBeforeUnmount(() => { disposed = true; request++ })
</script>

<template>
	<q-page padding class="business-pro-payment"><section v-if="visible" class="soz-section-card">
		<h1>{{ t('businessPro.verifyTitle') }}</h1><p>{{ t('businessPro.verifyIntro') }}</p>
		<q-spinner v-if="loading" color="primary" size="32px" />
		<p v-if="error" role="alert" class="text-negative">{{ t('businessPro.verifyFailed') }}</p>
		<div v-if="result" class="business-pro-payment__result"><strong>{{ t('businessPro.paymentStatus') }}</strong><q-badge>{{ t(`businessPro.status.${proStatusKey(result.payment?.status)}`) }}</q-badge><p>{{ t(result.overview?.subscription?.has_access === true || result.overview?.has_access === true ? 'businessPro.accessActive' : 'businessPro.verificationPending') }}</p></div>
		<div class="business-pro-payment__actions"><q-btn outline color="primary" :loading="loading" :label="t('businessPro.verifyAgain')" @click="verify" /><q-btn color="primary" :to="{ name: 'profile', hash: '#business-pro' }" :label="t('businessPro.backToPlan')" /></div>
	</section></q-page>
</template>

<style scoped>
.business-pro-payment { width: 100%; max-width: 1280px; margin-inline: auto; }
.business-pro-payment > section { padding: clamp(20px,4vw,36px); }
.business-pro-payment h1 { font-size: clamp(25px,4vw,36px); margin: 0 0 18px; }
.business-pro-payment__result { margin: 24px 0; }
.business-pro-payment__result .q-badge { margin-inline-start: 12px; }
.business-pro-payment__actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 24px; }
</style>
