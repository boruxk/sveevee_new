<script setup>
	import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute } from 'vue-router'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useCommunityStore } from '@/stores/community'
	import { fetchDiscussionComments, createDiscussionComment, deleteDiscussionComment, markQuestionHelpful, unmarkQuestionHelpful, fetchNearbyPageOptions, fetchSocialState } from '@/services/api/community'
	import LikeButton from './LikeButton.vue'
	import CommunityBadge from './CommunityBadge.vue'
	import ReportDialog from './ReportDialog.vue'
	import DeleteIcon from '@/components/icons/DeleteIcon.vue'

	const props = defineProps({
		targetType: { type: String, required: true },
		targetId: { type: [Number, String], required: true },
		questionAuthorId: { type: [Number, String], default: null },
		initialCount: { type: Number, default: 0 },
		questions: { type: Boolean, default: false }
	})
	const emit = defineEmits(['count-change'])
	const { t, locale } = useI18n()
	const route = useRoute()
	const auth = useAuthStore()
	const community = useCommunityStore()
	const $q = useQuasar()
	const items = ref([])
	const loading = ref(false)
	const error = ref('')
	const failedAction = ref('load')
	const nextCursor = ref(null)
	const hasMore = ref(false)
	const draft = ref('')
	const replyTo = ref(null)
	const recommendedPage = ref(null)
	const pageOptions = ref([])
	const pageSearchError = ref(false)
	const saving = ref(false)
	const busyComments = ref({})
	const composer = ref(null)
	const reportId = ref(null)
	const reportOpen = ref(false)
	let generation = 0
	let pageSearch = 0
	const questionOwner = computed(() => props.targetType === 'question' && auth.isAuthenticated && String(auth.user.id) === String(props.questionAuthorId))
	const sortedItems = computed(() => {
		const chronological = [...items.value].sort((left, right) => new Date(left.created_at) - new Date(right.created_at) || left.id - right.id)
		const loadedIds = new Set(chronological.map((item) => item.id))
		const roots = chronological.filter((item) => !item.parent_id || !loadedIds.has(item.parent_id))
		return roots.flatMap((item) => [item, ...chronological.filter((reply) => reply.parent_id === item.id)])
	})

	function parentName(comment) {
		return items.value.find((item) => item.id === comment.parent_id)?.author?.display_name || t('community.member')
	}

	function dateTime(value) {
		const date = new Date(value)
		if (Number.isNaN(date.getTime())) return ''
		return new Intl.DateTimeFormat({ he: 'he-IL', en: 'en-US', ru: 'ru-RU', fr: 'fr-FR' }[locale.value] || locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(date)
	}

	function pagePath(page, rating = false) {
		const path = String(page?.public_path || `/pages/${page?.id}`)
		if (!path.startsWith('/') || path.startsWith('//')) return '/'
		return rating ? `${path}${path.includes('?') ? '&' : '?'}rate=1` : path
	}

	async function loadMore() {
		if (loading.value) return
		const request = generation
		loading.value = true
		error.value = ''
		try {
			const { data } = await fetchDiscussionComments(props.targetType, props.targetId, { cursor: nextCursor.value || undefined })
			if (request !== generation) return
			const known = new Map(items.value.map((item) => [item.id, item]))
			for (const item of data.data?.items || []) known.set(item.id, item)
			items.value = [...known.values()]
			nextCursor.value = data.data?.next_cursor || null
			hasMore.value = Boolean(data.data?.has_more)
		} catch {
			if (request === generation) {
				error.value = t(props.questions ? 'community.questionsFailed' : 'community.commentsFailed')
				failedAction.value = 'load'
			}
		} finally {
			if (request === generation) loading.value = false
		}
	}

	watch(() => [props.targetType, props.targetId, auth.user?.id], () => {
		generation++
		pageSearch++
		items.value = []
		nextCursor.value = null
		hasMore.value = false
		loading.value = false
		saving.value = false
		busyComments.value = {}
		draft.value = ''
		replyTo.value = null
		recommendedPage.value = null
		pageOptions.value = []
		pageSearchError.value = false
		loadMore()
	}, { immediate: true })

	async function updateCount() {
		const request = generation
		try {
			const { data } = await fetchSocialState(props.targetType, props.targetId)
			if (request !== generation) return
			const count = Number(data.data?.comments_count) || 0
			community.setCommentsCount(props.targetType, props.targetId, count)
			emit('count-change', count)
		} catch { /* A later refresh can update the count without discarding a saved comment. */ }
	}

	async function reply(comment) {
		replyTo.value = { id: comment.parent_id || comment.id, name: comment.author?.display_name || t('community.member') }
		await nextTick()
		composer.value?.$el?.scrollIntoView({ block: 'center', behavior: 'smooth' })
		composer.value?.focus()
	}

	async function searchPages(value, update, abort) {
		const request = ++pageSearch
		pageSearchError.value = false
		if (value.trim().length < 2) { update(() => { pageOptions.value = [] }); return }
		try {
			const { data } = await fetchNearbyPageOptions({ q: value.trim() })
			if (request !== pageSearch) { abort(); return }
			update(() => { pageOptions.value = (data.data?.items || []).map((page) => ({ label: [page.name, page.city].filter(Boolean).join(' · '), value: page.id })) })
		} catch {
			if (request === pageSearch) pageSearchError.value = true
			abort()
		}
	}

	async function submit() {
		if (!auth.isAuthenticated || !draft.value.trim() || saving.value) return
		const request = generation
		saving.value = true
		error.value = ''
		try {
			const { data } = await createDiscussionComment(props.targetType, props.targetId, { body: draft.value.trim(), parent_id: replyTo.value?.id || null, recommended_page_id: recommendedPage.value?.value || null })
			if (request !== generation) return
			items.value = [...items.value.filter((item) => item.id !== data.data.id), data.data]
			draft.value = ''
			replyTo.value = null
			recommendedPage.value = null
			await updateCount()
		} catch {
			if (request === generation) {
				error.value = t(props.questions && !replyTo.value ? 'community.questionSaveFailed' : 'community.commentSaveFailed')
				failedAction.value = 'send'
			}
		} finally {
			if (request === generation) saving.value = false
		}
	}

	async function changeComment(comment, action) {
		if (busyComments.value[comment.id]) return
		const request = generation
		busyComments.value[comment.id] = true
		try {
			if (action === 'delete') {
				await deleteDiscussionComment(comment.id)
				if (request !== generation) return
				items.value = items.value.filter((item) => item.id !== comment.id && item.parent_id !== comment.id)
				await updateCount()
			} else {
				const { data } = await (comment.helpful ? unmarkQuestionHelpful(props.targetId, comment.id) : markQuestionHelpful(props.targetId, comment.id))
				if (request === generation) items.value = items.value.map((item) => item.id === comment.id ? data.data : item)
			}
		} catch {
			$q.notify({ type: 'negative', message: t('community.actionFailed') })
		} finally {
			if (request === generation) busyComments.value[comment.id] = false
		}
	}

	onBeforeUnmount(() => { generation++; pageSearch++ })
</script>

<template>
	<section id="discussion" class="community-discussion">
		<h2>{{ t(questions ? 'community.questions' : 'community.replies') }}</h2>
		<p v-if="!loading && !items.length && !error" class="discussion-empty">{{ t(questions ? 'community.noQuestionsYet' : 'community.noReplies') }}</p>
		<div class="discussion-list">
			<article v-for="comment in sortedItems" :id="`comment-${comment.id}`" :key="comment.id" class="discussion-comment" :class="{ 'discussion-comment--reply': comment.parent_id }">
				<header>
					<strong>{{ comment.author?.display_name || t('community.member') }}</strong>
					<CommunityBadge v-if="comment.is_owner">{{ t('community.ownerReply') }}</CommunityBadge>
					<CommunityBadge v-if="comment.helpful">{{ t('community.helpful') }}</CommunityBadge>
					<time :datetime="comment.created_at">{{ dateTime(comment.created_at) }}</time>
				</header>
				<p v-if="comment.parent_id" class="discussion-context">{{ t('community.replyingTo', { name: parentName(comment) }) }}</p>
				<p class="discussion-body" dir="auto">{{ comment.body }}</p>
				<div v-if="comment.recommended_page" class="discussion-recommendation">
					<span>{{ t('community.recommendedBusiness') }}</span>
					<router-link :to="pagePath(comment.recommended_page)">{{ comment.recommended_page.name }}</router-link>
					<router-link v-if="auth.isAuthenticated && comment.recommended_page.can_rate" :to="pagePath(comment.recommended_page, true)">{{ t('ratings.ratePage') }}</router-link>
				</div>
				<footer>
					<LikeButton target-type="comment" :target-id="comment.id" :initial-state="comment.social" compact />
					<q-btn v-if="auth.isAuthenticated"
						flat
						dense
						no-caps
						:label="t('community.reply')"
						@click="reply(comment)"
					/>
					<q-btn v-if="questionOwner && String(comment.author?.id) !== String(auth.user?.id)"
						flat
						dense
						no-caps
						:loading="busyComments[comment.id]"
						:label="t(comment.helpful ? 'community.unmarkHelpful' : 'community.markHelpful')"
						@click="changeComment(comment, 'helpful')"
					/>
					<q-btn v-if="comment.can_delete"
						flat
						dense
						:loading="busyComments[comment.id]"
						:aria-label="t('community.delete')"
						@click="changeComment(comment, 'delete')"
					><DeleteIcon :size="18" /></q-btn>
					<q-btn v-if="auth.isAuthenticated"
						flat
						dense
						no-caps
						:label="t('community.report')"
						@click="reportId = comment.id; reportOpen = true"
					/>
				</footer>
			</article>
		</div>
		<q-spinner v-if="loading" color="primary" size="24px" />
		<q-btn v-if="hasMore && !loading" flat color="primary" :label="t('community.loadMore')" @click="loadMore" />
		<p v-if="error" role="alert" class="text-negative">{{ error }} <q-btn flat dense :label="t('community.retry')" @click="failedAction === 'send' ? submit() : loadMore()" /></p>
		<q-form v-if="auth.isAuthenticated" class="discussion-composer" @submit="submit">
			<div v-if="replyTo" class="discussion-replying"><span>{{ t('community.replyingTo', { name: replyTo.name }) }}</span><q-btn flat dense no-caps :label="t('community.cancel')" @click="replyTo = null" /></div>
			<q-input ref="composer"
				v-model="draft"
				outlined
				type="textarea"
				:label="t(questions && !replyTo ? 'community.question' : 'community.writeReply')"
				maxlength="3000"
				:disable="saving"
			/>
			<q-select v-model="recommendedPage"
				outlined
				use-input
				clearable
				:input-debounce="250"
				:label="t('community.recommendBusiness')"
				:hint="t('community.recommendBusinessHint')"
				:options="pageOptions"
				:disable="saving"
				@filter="searchPages"
			>
				<template #no-option><q-item><q-item-section>{{ t(pageSearchError ? 'community.loadFailed' : 'community.noMatchingBusiness') }}</q-item-section></q-item></template>
			</q-select>
			<q-btn type="submit" color="primary" :label="t(questions && !replyTo ? 'community.ask' : 'community.sendReply')" :loading="saving" :disable="!draft.trim()" />
		</q-form>
		<div v-else class="discussion-signin"><p>{{ t('community.signInHint') }}</p><q-btn outline color="primary" :label="t('community.signIn')" :to="{ name: 'login', query: { redirect: route.fullPath } }" /></div>
		<ReportDialog v-if="reportId" v-model="reportOpen" target-type="comment" :target-id="reportId" />
	</section>
</template>

<style scoped>
.community-discussion { min-width: 0; scroll-margin-top: 90px; }
.community-discussion h2 { font-size: 24px; margin: 0 0 18px; }
.discussion-list { display: grid; gap: 14px; }
.discussion-comment { padding: 16px; border: 1px solid var(--soz-line); border-radius: 20px; background: rgba(255,255,255,.8); scroll-margin-top: 90px; min-width: 0; }
.discussion-comment--reply { margin-inline-start: min(24px, 4vw); border-inline-start: 3px solid var(--soz-primary-soft); }
.discussion-comment header, .discussion-comment footer { display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
.discussion-comment header time { font-size: 12px; color: var(--soz-muted); margin-inline-start: auto; unicode-bidi: isolate; }
.discussion-body { white-space: pre-wrap; overflow-wrap: anywhere; margin: 12px 0; }
.discussion-context, .discussion-empty { color: var(--soz-muted); font-size: 13px; margin: 8px 0; }
.discussion-recommendation { display: flex; flex-wrap: wrap; gap: 8px; padding: 10px 12px; margin-bottom: 8px; background: var(--soz-primary-tint); border-radius: 12px; }
.discussion-recommendation a { color: var(--soz-primary-deep); text-decoration: underline; overflow-wrap: anywhere; }
.discussion-composer { display: grid; gap: 16px; margin-top: 22px; }
.discussion-composer > .q-btn { justify-self: end; }
.discussion-replying { display: flex; gap: 8px; align-items: center; justify-content: space-between; }
.discussion-signin { margin-top: 20px; }
@media (max-width: 600px) { .discussion-comment { padding: 12px; } .discussion-comment header time { width: 100%; margin: 0; } }
</style>
