<script setup>
	import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { matFlag } from '@quasar/extras/material-icons'
	import { useAuthStore } from '@/stores/auth'
	import { useSeo, truncateText } from '@/composables/useSeo'
	import { createLatestRequest } from '@/utils/latestRequest'
	import { fetchQuestion, updateQuestion, deleteQuestion } from '@/services/api/community'
	import QuestionCard from '@/components/community/QuestionCard.vue'
	import QuestionComposer from '@/components/community/QuestionComposer.vue'
	import CommunityDiscussion from '@/components/community/CommunityDiscussion.vue'
	import ReportDialog from '@/components/community/ReportDialog.vue'

	const { t } = useI18n()
	const $q = useQuasar()
	const route = useRoute()
	const router = useRouter()
	const auth = useAuthStore()
	const question = ref(null)
	const loading = ref(false)
	const failed = ref(false)
	const unavailable = ref(false)
	const changing = ref(false)
	const editorOpen = ref(false)
	const reportOpen = ref(false)
	const request = createLatestRequest()
	const nearbyRoute = computed(() => typeof route.query.returnTo === 'string' && /^\/nearby(?:\?|$)/.test(route.query.returnTo) ? route.query.returnTo : { name: 'nearby' })
	let disposed = false
	useSeo(computed(() => ({
		title: question.value?.title || t('community.questions'),
		description: truncateText(question.value?.body || t('community.questionIntro')),
		canonical: `/questions/${route.params.id}`,
		robots: question.value ? 'index,follow' : 'noindex,follow'
	})))

	function load() {
		const id = String(route.params.id)
		return request.run(`${id}:${auth.user?.id || ''}`, {
			request: signal => fetchQuestion(id, { signal }),
			onStart: () => { loading.value = true; failed.value = false; unavailable.value = false; question.value = null; editorOpen.value = false; reportOpen.value = false },
			onResult: ({ data }) => { question.value = data.data },
			onError: error => { unavailable.value = [404, 410].includes(error.response?.status); failed.value = !unavailable.value },
			onSettled: () => { loading.value = false }
		})
	}

	function edited(value) {
		if (value?.id && String(value.id) === String(route.params.id)) question.value = value
	}

	async function toggleResolved() {
		if (!question.value?.can_edit || changing.value) return
		const current = question.value
		changing.value = true
		try {
			const { data } = await updateQuestion(current.id, { resolved: !current.resolved })
			if (!disposed && question.value?.id === current.id) question.value = data.data
		} catch {
			if (!disposed) $q.notify({ type: 'negative', message: t('community.saveFailed') })
		} finally {
			changing.value = false
		}
	}

	function confirmDelete() {
		if (!question.value?.can_edit || changing.value) return
		const id = question.value.id
		$q.dialog({
			title: t('community.deleteQuestionTitle'),
			message: t('community.deleteQuestionMessage'),
			ok: { label: t('community.delete'), color: 'negative' },
			cancel: { label: t('community.cancel'), flat: true }
		}).onOk(async() => {
			if (changing.value || disposed) return
			changing.value = true
			try {
				await deleteQuestion(id)
				if (disposed) return
				$q.notify({ type: 'positive', message: t('community.deleted') })
				if (String(route.params.id) === String(id)) await router.replace(nearbyRoute.value)
			} catch {
				if (!disposed) $q.notify({ type: 'negative', message: t('community.saveFailed') })
			} finally {
				changing.value = false
			}
		})
	}

	function updateCount(count) {
		if (question.value) question.value = { ...question.value, social: { ...question.value.social, comments_count: count } }
	}

	watch([() => route.params.id, () => auth.user?.id], load, { immediate: true })
	watch(() => [route.hash, loading.value, question.value?.id], async() => {
		if (route.hash !== '#discussion' || loading.value || !question.value) return
		await nextTick()
		if (!disposed && route.hash === '#discussion') document.getElementById('discussion')?.scrollIntoView({ block: 'start' })
	})
	onBeforeUnmount(() => { disposed = true; request.dispose() })
</script>

<template>
	<q-page padding class="question-detail-page">
		<div class="page-shell question-detail">
			<div><q-btn flat no-caps icon="arrow_back" :label="t('community.backNearby')" :to="nearbyRoute" /></div>
			<div v-if="loading" class="question-detail__status" role="status"><q-spinner color="primary" size="34px" /></div>
			<section v-else-if="failed || unavailable || !question" class="soz-section-card question-detail__status" role="alert">
				<p>{{ t(unavailable ? 'community.questionUnavailable' : 'community.loadFailed') }}</p>
				<q-btn v-if="failed" outline color="primary" :label="t('community.retry')" @click="load" />
			</section>
			<template v-else>
				<QuestionCard :question="question" detail>
					<template #actions>
						<template v-if="question.can_edit">
							<q-btn outline
								no-caps
								color="primary"
								:icon="question.resolved ? 'refresh' : 'check_circle'"
								:loading="changing"
								:label="t(question.resolved ? 'community.reopen' : 'community.markResolved')"
								@click="toggleResolved"
							/>
							<q-btn flat
								no-caps
								icon="edit"
								:label="t('community.edit')"
								:disable="changing"
								@click="editorOpen = true"
							/>
							<q-btn flat
								no-caps
								color="negative"
								icon="delete"
								:label="t('community.delete')"
								:disable="changing"
								@click="confirmDelete"
							/>
						</template>
						<q-btn v-else
							flat
							no-caps
							:icon="matFlag"
							:label="t('community.report')"
							@click="reportOpen = true"
						/>
					</template>
				</QuestionCard>
				<CommunityDiscussion :key="question.id"
					class="soz-section-card question-detail__discussion"
					target-type="question"
					:target-id="question.id"
					:question-author-id="question.author?.id"
					:initial-count="question.social?.comments_count || 0"
					@count-change="updateCount"
				/>
				<QuestionComposer v-model="editorOpen" :question="question" @saved="edited" />
				<ReportDialog v-model="reportOpen" target-type="question" :target-id="question.id" />
			</template>
		</div>
	</q-page>
</template>

<style scoped>
.question-detail-page { padding: 0 20px 36px; }
.question-detail { display: grid; gap: 20px; width: 100%; max-width: 880px; margin: 0 auto; }
.question-detail__discussion { padding: 28px; }

.question-detail__status { padding: 32px; text-align: center; color: var(--soz-muted); }
@media (max-width: 600px) { .question-detail-page { padding-inline: 14px; } .question-detail__discussion { padding: 16px; } }
</style>
