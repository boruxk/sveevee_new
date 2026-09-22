<script setup>
	import { computed, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useCommunityStore } from '@/stores/community'
	import LikeButton from '@/components/community/LikeButton.vue'
	import ReportDialog from '@/components/community/ReportDialog.vue'

	const props = defineProps({
		targetType: { type: String, required: true },
		targetId: { type: [Number, String], required: true },
		social: { type: Object, default: null },
		to: { type: [String, Object], default: null }
	})
	const { t } = useI18n()
	const community = useCommunityStore()
	const reportOpen = ref(false)
	const state = computed(() => community.getState(props.targetType, props.targetId) || props.social)
</script>

<template>
	<div class="community-actions">
		<LikeButton :target-type="targetType" :target-id="targetId" :initial-state="social" compact />
		<q-btn v-if="to"
			flat
			dense
			no-caps
			:to="to"
			:aria-label="t('community.discussion')"
		>
			<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v12H9l-5 4V4Z" /><path d="M8 8h8M8 12h5" /></svg>
			<span>{{ t('community.discussion') }}<template v-if="state?.comments_count"> ({{ state.comments_count }})</template></span>
		</q-btn>
		<q-space />
		<q-btn flat round dense :aria-label="t('community.report')" @click="reportOpen = true">
			<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 21V3m0 1c5-4 9 4 14 0v10c-5 4-9-4-14 0" /></svg>
			<q-tooltip>{{ t('community.report') }}</q-tooltip>
		</q-btn>
		<ReportDialog v-model="reportOpen" :target-type="targetType" :target-id="targetId" />
	</div>
</template>

<style scoped lang="scss">
.community-actions { position: relative; z-index: 1; display: flex; flex: 0 0 auto; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 10px; padding-top: 8px; border-top: 1px solid rgba(17, 34, 45, .1); }
.community-actions svg { width: 19px; height: 19px; margin-inline-end: 5px; fill: none; stroke: currentColor; stroke-width: 1.7; stroke-linejoin: round; }
.community-actions :deep(.q-btn) { min-height: 38px; }
</style>
