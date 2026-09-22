<script setup>
	import { onBeforeUnmount, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { createLatestRequest } from '@/utils/latestRequest'
	import { fetchQuestions, updateQuestion } from '@/services/api/community'
	import QuestionCard from '@/components/community/QuestionCard.vue'
	import QuestionComposer from '@/components/community/QuestionComposer.vue'

	const { t } = useI18n()
	const auth = useAuthStore()
	const $q = useQuasar()
	const questions = ref([])
	const loading = ref(false)
	const failed = ref(false)
	const cursor = ref(null)
	const composerOpen = ref(false)
	const editing = ref(null)
	const changing = ref(new Set())
	const request = createLatestRequest()
	let generation = 0

	function load(append = false) {
		if (append && (loading.value || !cursor.value)) return
		const next = append ? cursor.value : null
		return request.run(`${generation}:${auth.user?.id || ''}:${next || ''}`, {
			request: signal => auth.isAuthenticated ? fetchQuestions({ mine: 1, cursor: next || undefined }, { signal }) : Promise.resolve({ data: { data: { items: [] } } }),
			onStart: () => { loading.value = true; failed.value = false; if (!append) { questions.value = []; cursor.value = null } },
			onResult: ({ data }) => {
				if (!Array.isArray(data.data?.items)) throw new Error('Invalid questions')
				const incoming = data.data.items.map(item => item.type === 'question' ? { ...item.value, social: item.social || item.value?.social } : item)
				questions.value = [...new Map([...(append ? questions.value : []), ...incoming].map(question => [question.id, question])).values()]
				cursor.value = data.data.has_more ? data.data.next_cursor || null : null
			},
			onError: () => { failed.value = true },
			onSettled: () => { loading.value = false }
		})
	}

	function compose(question = null) {
		editing.value = question
		composerOpen.value = true
	}

	function saved(value) {
		if (!value?.id) return
		const exists = questions.value.some(question => question.id === value.id)
		questions.value = exists ? questions.value.map(question => question.id === value.id ? value : question) : [value, ...questions.value]
	}

	async function toggleResolved(question) {
		if (!question.can_edit || changing.value.has(question.id)) return
		const current = generation
		changing.value = new Set([...changing.value, question.id])
		try {
			const { data } = await updateQuestion(question.id, { resolved: !question.resolved })
			if (current === generation) saved(data.data)
		} catch {
			if (current === generation) $q.notify({ type: 'negative', message: t('community.saveFailed') })
		} finally {
			if (current === generation) changing.value = new Set([...changing.value].filter(id => id !== question.id))
		}
	}

	watch(() => auth.user?.id, () => { generation++; composerOpen.value = false; editing.value = null; changing.value = new Set(); load() }, { immediate: true })
	onBeforeUnmount(() => { generation++; request.dispose() })
</script>

<template>
	<section class="my-questions" :aria-label="t('community.myQuestions')">
		<header class="my-questions__head">
			<h2>{{ t('community.myQuestions') }}</h2>
			<q-btn v-if="auth.isAuthenticated"
				unelevated
				rounded
				no-caps
				color="primary"
				icon="add"
				:label="t('community.ask')"
				@click="compose()"
			/>
		</header>
		<div class="my-questions__list" :aria-busy="loading">
			<QuestionCard v-for="question in questions" :key="question.id" :question="question">
				<template v-if="question.can_edit" #actions>
					<q-btn outline
						no-caps
						color="primary"
						:icon="question.resolved ? 'refresh' : 'check_circle'"
						:loading="changing.has(question.id)"
						:label="t(question.resolved ? 'community.reopen' : 'community.markResolved')"
						@click="toggleResolved(question)"
					/>
					<q-btn flat
						no-caps
						icon="edit"
						:label="t('community.edit')"
						:disable="changing.has(question.id)"
						@click="compose(question)"
					/>
				</template>
			</QuestionCard>
		</div>
		<div v-if="loading" class="my-questions__status" role="status"><q-spinner color="primary" size="28px" /></div>
		<div v-else-if="failed" class="my-questions__status" role="alert"><p>{{ t('community.loadFailed') }}</p><q-btn flat :label="t('community.retry')" @click="load(questions.length > 0)" /></div>
		<p v-else-if="!questions.length" class="my-questions__status">{{ t('community.noQuestions') }}</p>
		<div v-if="cursor && !failed" class="my-questions__status"><q-btn outline
			rounded
			color="primary"
			:loading="loading"
			:label="t('community.loadMore')"
			@click="load(true)"
		/></div>
		<QuestionComposer v-model="composerOpen" :question="editing" @saved="saved" />
	</section>
</template>

<style scoped>
.my-questions { display: grid; gap: 18px; min-width: 0; }
.my-questions__head { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.my-questions__head h2 { margin: 0; font-size: 1.4rem; }
.my-questions__list { display: grid; gap: 20px; }
.my-questions__status { padding: 18px; text-align: center; color: var(--soz-muted); margin: 0; }
</style>
