<script setup>
	import { computed, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute } from 'vue-router'
	import { matQuestionAnswer, matCampaign, matEvent, matChatBubbleOutline, matOutlinedFlag } from '@quasar/extras/material-icons'
	import { adRoute, catalogLabel, catalogTopicByKey, catalogTopicForAdCategory } from '@/constants/catalogTopics'
	import { localizedAdCategoryMeta } from '@/constants/adCategories'
	import { locationLabel } from '@/utils/locationLabels'
	import { useCommunityStore } from '@/stores/community'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import AdExpiryTimer from '@/components/AdExpiryTimer.vue'
	import CommunityBadge from '@/components/community/CommunityBadge.vue'
	import LikeButton from '@/components/community/LikeButton.vue'
	import ReportDialog from '@/components/community/ReportDialog.vue'

	const props = defineProps({
		item: { type: Object, required: true },
		catalogGroups: { type: Array, default: () => [] }
	})
	const emit = defineEmits(['expired'])
	const { t, locale } = useI18n()
	const route = useRoute()
	const community = useCommunityStore()
	const reportOpen = ref(false)
	const value = computed(() => props.item.value || {})
	const title = computed(() => value.value.title || value.value.name || '')
	const body = computed(() => value.value.body || value.value.text || value.value.description || '')
	const typeLabel = computed(() => t(`community.${{ question: 'questions', ad: 'ads', event: 'events' }[props.item.type]}`))
	const typeIcon = computed(() => ({ question: matQuestionAnswer, ad: matCampaign, event: matEvent }[props.item.type]))
	const detailRoute = computed(() => {
		if (props.item.type === 'ad') return adRoute(value.value)
		return {
			name: props.item.type === 'question' ? 'local-question' : 'event-detail',
			params: { id: value.value.id },
			query: { returnTo: route.fullPath }
		}
	})
	const repliesRoute = computed(() => ({ ...detailRoute.value, hash: '#discussion' }))
	const social = computed(() => community.getState(props.item.type, value.value.id))
	const category = computed(() => {
		const topic = props.item.type === 'ad' ? catalogTopicForAdCategory(props.catalogGroups, value.value.category) : catalogTopicByKey(props.catalogGroups, value.value.category_key || value.value.page?.category_key)
		return catalogLabel(topic?.labels, locale.value) ||
			(props.item.type === 'ad' ? localizedAdCategoryMeta(value.value.category, t)?.label : '') ||
			''
	})
	const owner = computed(() => value.value.author?.display_name || value.value.page?.name || value.value.user?.display_name || '')
	const location = computed(() => {
		const address = props.item.type === 'event' ? value.value.page?.address_details || value.value.user?.profile || {} : value.value
		return [locationLabel(address.city, 'city', locale.value), locationLabel(address.neighborhood, 'neighborhood', locale.value)].filter(Boolean).join(', ')
	})
	const dateLabel = computed(() => {
		if (props.item.type !== 'event' || !value.value.date) return ''
		const date = new Date(`${value.value.date}T00:00:00`)
		return Number.isNaN(date.getTime()) ? '' : [new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(date), value.value.time].filter(Boolean).join(' ')
	})
	const meta = computed(() => [dateLabel.value, location.value, owner.value].filter(Boolean).join(' \u00b7 '))
</script>

<template>
	<article class="nearby-feed-card">
		<div class="nearby-feed-card__media" aria-hidden="true">
			<ResponsiveImage v-if="value.image_url"
				:src="value.image_url"
				:avif-srcset="value.image_avif_srcset || ''"
				:webp-srcset="value.image_webp_srcset || ''"
				sizes="(max-width: 430px) 96px, (max-width: 700px) 112px, 190px"
				alt=""
			/>
			<q-icon v-else :name="typeIcon" class="nearby-feed-card__placeholder" />
		</div>
		<div class="nearby-feed-card__copy">
			<div class="nearby-feed-card__badges">
				<CommunityBadge>{{ typeLabel }}</CommunityBadge>
				<CommunityBadge v-if="value.resolved">{{ t('community.resolved') }}</CommunityBadge>
				<CommunityBadge v-else-if="category" :title="category">{{ category }}</CommunityBadge>
			</div>
			<h2 class="nearby-feed-card__title"><router-link :to="detailRoute" :title="title" dir="auto">{{ title }}</router-link></h2>
			<p class="nearby-feed-card__body" dir="auto">{{ body }}</p>
			<div class="nearby-feed-card__meta" :title="meta">{{ meta }}</div>
			<footer class="nearby-feed-card__actions">
				<LikeButton compact :target-type="item.type" :target-id="value.id" :initial-state="item.social || value.social" />
				<q-btn flat
					dense
					no-caps
					:icon="matChatBubbleOutline"
					:label="String(social.comments_count)"
					:aria-label="`${t('community.replies')}: ${social.comments_count}`"
					:to="repliesRoute"
				>
					<q-tooltip>{{ t('community.replies') }}</q-tooltip>
				</q-btn>
				<AdExpiryTimer v-if="item.type === 'ad'" :expires-at="value.expires_at || ''" @expired="emit('expired', item.id)" />
				<q-btn flat
					dense
					round
					:icon="matOutlinedFlag"
					class="nearby-feed-card__report"
					:aria-label="t('community.report')"
					@click="reportOpen = true"
				>
					<q-tooltip>{{ t('community.report') }}</q-tooltip>
				</q-btn>
			</footer>
		</div>
		<ReportDialog v-model="reportOpen" :target-type="item.type" :target-id="value.id" />
	</article>
</template>

<style scoped>
.nearby-feed-card { position: relative; display: grid; grid-template-columns: 190px minmax(0, 1fr); height: 190px; min-width: 0; overflow: hidden; border: 1px solid var(--soz-line); border-radius: 24px; background: rgba(255, 255, 255, .76); transition: border-color .18s ease, box-shadow .18s ease, transform .18s ease; }
.nearby-feed-card:hover, .nearby-feed-card:focus-within { border-color: var(--soz-primary); box-shadow: 0 14px 30px rgba(123, 63, 242, .1); }
.nearby-feed-card:hover { transform: translateY(-2px); }
.nearby-feed-card__media { display: grid; place-items: center; min-width: 0; min-height: 0; overflow: hidden; background: var(--soz-primary-tint); color: var(--soz-primary); }
.nearby-feed-card__media .responsive-image { width: 100%; height: 100%; }
.nearby-feed-card__placeholder { font-size: 52px; }
.nearby-feed-card__copy { display: flex; flex-direction: column; gap: 5px; min-width: 0; min-height: 0; padding: 12px 18px; }
.nearby-feed-card__badges { display: flex; gap: 6px; min-width: 0; overflow: hidden; flex-shrink: 0; }
.nearby-feed-card__badges .community-badge { display: block; min-width: 0; min-height: 24px; padding: 3px 9px; font-size: 11px; line-height: 16px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; box-shadow: none; }
.nearby-feed-card__badges .community-badge:first-child { flex-shrink: 0; }
.nearby-feed-card__title { margin: 0; min-width: 0; font-size: 1.1rem; line-height: 1.35; font-weight: 800; }
.nearby-feed-card__title a { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--soz-ink); text-decoration: none; }
.nearby-feed-card__title a::after { content: ''; position: absolute; inset: 0; z-index: 1; }
.nearby-feed-card__title a:focus-visible { outline: none; }
.nearby-feed-card:has(.nearby-feed-card__title a:focus-visible) { outline: 2px solid var(--soz-primary); outline-offset: 3px; }
.nearby-feed-card__body { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; flex: 1; min-height: 0; overflow: hidden; margin: 0; font-size: .88rem; line-height: 1.45; color: var(--soz-muted); overflow-wrap: anywhere; }
.nearby-feed-card__meta { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .77rem; line-height: 1.4; color: var(--soz-muted); }
.nearby-feed-card__actions { display: flex; align-items: center; gap: 6px; min-width: 0; margin-top: auto; }
.nearby-feed-card__actions :deep(.q-btn) { position: relative; z-index: 2; min-height: 28px; padding: 3px 7px; font-size: 12px; color: var(--soz-muted); }
.nearby-feed-card__actions :deep(.q-btn__content) { flex-wrap: nowrap; gap: 4px; }
.nearby-feed-card__actions :deep(.q-icon) { font-size: 18px; }
.nearby-feed-card__actions :deep(.community-like--active) { color: #d62070; }
.nearby-feed-card__actions .ad-expiry { min-width: 0; margin: 0; font-size: 11px; overflow: hidden; white-space: nowrap; }
.nearby-feed-card__actions :deep(.ad-expiry > span) { overflow: hidden; text-overflow: ellipsis; }
.nearby-feed-card__actions .nearby-feed-card__report { margin-inline-start: auto; min-width: 28px; width: 28px; height: 28px; padding: 0; }
@media (max-width: 700px) {
  .nearby-feed-card { grid-template-columns: 112px minmax(0, 1fr); height: 156px; border-radius: 20px; }
  .nearby-feed-card__copy { padding: 9px 10px; gap: 3px; }
  .nearby-feed-card__placeholder { font-size: 36px; }
  .nearby-feed-card__title { font-size: .95rem; }
  .nearby-feed-card__body { display: block; font-size: .78rem; white-space: nowrap; text-overflow: ellipsis; }
  .nearby-feed-card__meta { font-size: .7rem; }
  .nearby-feed-card__actions { gap: 3px; }
  .nearby-feed-card__actions .ad-expiry { display: none; }
}
@media (max-width: 430px) {
  .nearby-feed-card { grid-template-columns: 96px minmax(0, 1fr); height: 150px; }
}
</style>
