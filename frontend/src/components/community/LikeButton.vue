<script setup>
	import { computed, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute, useRouter } from 'vue-router'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useCommunityStore } from '@/stores/community'

	const props = defineProps({
		targetType: { type: String, required: true },
		targetId: { type: [Number, String], required: true },
		initialState: { type: Object, default: null },
		compact: { type: Boolean, default: false }
	})
	const { t } = useI18n()
	const auth = useAuthStore()
	const community = useCommunityStore()
	const route = useRoute()
	const router = useRouter()
	const $q = useQuasar()
	const state = computed(() => community.getState(props.targetType, props.targetId))
	const busy = computed(() => Boolean(community.busy[`${props.targetType}:${props.targetId}`]))
	watch(() => [props.targetType, props.targetId, props.initialState, auth.user?.id], (values, previous) => {
		community.seed(props.targetType, props.targetId, !previous || values[3] === previous[3] ? props.initialState : null)
		community.ensure(props.targetType, props.targetId).catch(() => {})
	}, { immediate: true })

	async function toggle() {
		if (!auth.isAuthenticated) return router.push({ name: 'login', query: { redirect: route.fullPath } })
		try {
			await community.toggleLike(props.targetType, props.targetId)
		} catch {
			$q.notify({ type: 'negative', message: t('community.actionFailed') })
		}
	}
</script>

<template>
	<q-btn flat
		dense
		no-caps
		class="community-like"
		:class="{ 'community-like--active': state.liked }"
		:loading="busy"
		:aria-pressed="state.liked"
		:aria-label="t(state.liked ? 'community.unlike' : 'community.like')"
		@click.stop.prevent="toggle"
	>
		<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z" /></svg>
		<span v-if="!compact">{{ t(state.liked ? 'community.liked' : 'community.like') }}</span>
		<span>{{ state.likes_count }}</span>
	</q-btn>
</template>

<style scoped>
.community-like { color: var(--soz-muted); }
.community-like--active { color: #d62070; }
.community-like svg { width: 19px; height: 19px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linejoin: round; }
.community-like--active svg { fill: currentColor; }
</style>
