<script setup>
	import { computed, onMounted } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute } from 'vue-router'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { catalogLabel, catalogTopicByKey } from '@/constants/catalogTopics'
	import { locationLabel } from '@/utils/locationLabels'
	import LikeButton from '@/components/community/LikeButton.vue'
	import CommunityBadge from '@/components/community/CommunityBadge.vue'

	const props = defineProps({
		question: { type: Object, required: true },
		detail: { type: Boolean, default: false }
	})
	const { t, locale } = useI18n()
	const route = useRoute()
	const detailRoute = computed(() => ({ name: 'local-question', params: { id: props.question.id }, query: route.name === 'nearby' ? { returnTo: route.fullPath } : {} }))
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const location = computed(() => [locationLabel(props.question.city, 'city', locale.value), locationLabel(props.question.neighborhood, 'neighborhood', locale.value)].filter(Boolean).join(', '))
	const category = computed(() => catalogLabel(catalogTopicByKey(catalogGroups.value, props.question.category_key)?.labels, locale.value))
	const dateLabel = computed(() => {
		const date = new Date(props.question.created_at)
		return Number.isNaN(date.getTime()) ? '' : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(date)
	})
	onMounted(() => loadCatalogTopics().catch(() => {}))
</script>

<template>
	<article class="question-card soz-section-card">
		<header class="question-card__meta">
			<div class="question-card__author">
				<q-avatar size="34px" color="primary" text-color="white">
					<img v-if="question.author?.photo_url" :src="question.author.photo_url" alt="" loading="lazy" />
					<span v-else>{{ question.author?.display_name?.slice(0, 1) || '?' }}</span>
				</q-avatar>
				<span>{{ question.author?.display_name }}<time v-if="dateLabel" :datetime="question.created_at">{{ dateLabel }}</time></span>
			</div>
			<CommunityBadge v-if="question.resolved">{{ t('community.resolved') }}</CommunityBadge>
		</header>
		<h1 v-if="detail" class="question-card__title">{{ question.title }}</h1>
		<h2 v-else class="question-card__title"><router-link :to="detailRoute">{{ question.title }}</router-link></h2>
		<p class="question-card__body" :class="{ 'question-card__body--preview': !detail }" dir="auto">{{ question.body }}</p>
		<div v-if="location || category" class="question-card__tags">
			<span v-if="location"><q-icon name="place" /> {{ location }}</span>
			<span v-if="category">{{ category }}</span>
		</div>
		<footer class="question-card__actions">
			<LikeButton target-type="question" :target-id="question.id" :initial-state="question.social" />
			<q-btn v-if="!detail"
				flat
				no-caps
				icon="chat_bubble"
				:to="detailRoute"
				:label="`${t('community.replies')} · ${question.social?.comments_count || 0}`"
			/>
			<div v-if="$slots.actions" class="question-card__manage"><slot name="actions" /></div>
		</footer>
	</article>
</template>

<style scoped>
.question-card { display: grid; gap: 14px; min-width: 0; padding: 22px; }
.question-card__meta, .question-card__author, .question-card__actions, .question-card__tags { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.question-card__meta { justify-content: space-between; }
.question-card__author { min-width: 0; font-weight: 700; }
.question-card__author > span { overflow-wrap: anywhere; }
.question-card__author time { display: block; font-size: 0.76rem; font-weight: 400; color: var(--soz-muted); }
.question-card__title { margin: 0; font-size: 1.28rem; line-height: 1.4; overflow-wrap: anywhere; }
h1.question-card__title { font-size: clamp(1.4rem, 3vw, 2rem); }
.question-card__title a { color: var(--soz-ink); text-decoration: none; }
.question-card__title a:hover { color: var(--soz-primary); text-decoration: underline; }
.question-card__body { margin: 0; white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.65; }
.question-card__body--preview { display: -webkit-box; -webkit-line-clamp: 5; -webkit-box-orient: vertical; overflow: hidden; }
.question-card__tags { color: var(--soz-muted); font-size: 0.82rem; }
.question-card__tags > span { padding: 4px 9px; border-radius: 12px; background: rgba(123, 63, 242, 0.06); }
.question-card__actions { border-top: 1px solid rgba(17, 34, 45, 0.08); padding-top: 10px; }
.question-card__manage { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-inline-start: auto; }
@media (max-width: 600px) { .question-card { padding: 16px; gap: 12px; } }
</style>
