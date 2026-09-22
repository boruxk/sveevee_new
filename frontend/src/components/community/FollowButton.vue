<script setup>
	import { computed, onBeforeUnmount, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute, useRouter } from 'vue-router'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { fetchSubscriptionStatus, createSubscription, deleteSubscription } from '@/services/api/community'

	const props = defineProps({
		pageId: { type: [Number, String], default: null },
		city: { type: String, default: '' },
		categoryKey: { type: String, default: '' },
		neighborhood: { type: String, default: '' },
		initialState: { type: Object, default: null },
		iconOnly: { type: Boolean, default: false }
	})
	const emit = defineEmits(['change'])
	const { t } = useI18n()
	const auth = useAuthStore()
	const router = useRouter()
	const route = useRoute()
	const $q = useQuasar()
	const state = ref({ subscribed: false, subscription: null })
	const loading = ref(false)
	const saving = ref(false)
	let version = 0
	const params = computed(() => props.pageId ? { page_id: props.pageId } : { city: props.city, category_key: props.categoryKey, neighborhood: props.neighborhood || undefined })
	const validTarget = computed(() => Boolean(props.pageId || (props.city && props.categoryKey)))
	watch(() => [JSON.stringify(params.value), auth.user?.id, props.initialState], async(values, previous) => {
		const request = ++version
		state.value = { subscribed: false, subscription: null }
		loading.value = false
		saving.value = false
		if (!auth.isAuthenticated || !validTarget.value) return
		if (props.initialState && (!previous || values[1] === previous[1])) { state.value = props.initialState; return }
		loading.value = true
		try {
			const { data } = await fetchSubscriptionStatus(params.value)
			if (request === version) state.value = data.data
		} catch {
			if (request === version) $q.notify({ type: 'negative', message: t('community.subscriptionsFailed') })
		} finally {
			if (request === version) loading.value = false
		}
	}, { immediate: true })

	async function toggle() {
		if (!auth.isAuthenticated) return router.push({ name: 'login', query: { redirect: route.fullPath } })
		if (!validTarget.value || saving.value) return
		const request = version
		saving.value = true
		try {
			let updated
			if (state.value.subscribed && state.value.subscription?.id) {
				await deleteSubscription(state.value.subscription.id)
				updated = { subscribed: false, subscription: null }
			} else {
				const { data } = await createSubscription({ ...params.value, notifications_enabled: true })
				updated = { subscribed: true, subscription: data.data?.subscription || data.data }
			}
			if (request === version) { state.value = updated; emit('change', updated) }
		} catch {
			$q.notify({ type: 'negative', message: t('community.actionFailed') })
		} finally {
			if (request === version) saving.value = false
		}
	}

	onBeforeUnmount(() => { version++ })
</script>

<template>
	<q-btn :outline="!iconOnly"
		:unelevated="iconOnly"
		:round="iconOnly"
		:rounded="!iconOnly"
		no-caps
		color="primary"
		:loading="loading || saving"
		:disable="!validTarget"
		:aria-pressed="state.subscribed"
		:aria-label="t(state.subscribed ? 'community.following' : 'community.follow')"
		@click.stop.prevent="toggle"
	>
		<svg viewBox="0 0 24 24" aria-hidden="true" class="follow-icon"><path v-if="state.subscribed" d="m5 12 4 4L19 6" /><path v-else d="M12 5v14M5 12h14" /></svg>
		<q-tooltip v-if="iconOnly">{{ t(state.subscribed ? 'community.following' : 'community.follow') }}</q-tooltip>
		<span v-else>{{ t(state.subscribed ? 'community.following' : 'community.follow') }}</span>
	</q-btn>
</template>

<style scoped>
.follow-icon { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
</style>
