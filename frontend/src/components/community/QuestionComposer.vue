<script setup>
	import { computed, onBeforeUnmount, reactive, ref, toRef, watch } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { createQuestion, updateQuestion } from '@/services/api/community'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'

	const props = defineProps({
		modelValue: { type: Boolean, default: false },
		question: { type: Object, default: null },
		initialCity: { type: String, default: '' },
		initialNeighborhood: { type: String, default: '' },
		initialCategory: { type: String, default: '' }
	})
	const emit = defineEmits(['update:modelValue', 'saved'])
	const { t } = useI18n()
	const $q = useQuasar()
	const route = useRoute()
	const auth = useAuthStore()
	const saving = ref(false)
	const error = ref(false)
	const form = reactive({ title: '', body: '', city: '', neighborhood: '', category_key: '' })
	const cities = ref([])
	const neighborhoods = ref([])
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const { cityOptions, neighborhoodOptions, loadLocationOptions, rememberLocation, filterOptions, addOption } = useLocationOptions(toRef(form, 'city'))
	let generation = 0
	const open = computed({ get: () => props.modelValue, set: value => { if (!saving.value) emit('update:modelValue', value) } })
	const required = value => Boolean(String(value || '').trim()) || t('community.required')

	function filterCities(value, update) {
		update(() => { cities.value = filterOptions(cityOptions.value, value) })
	}

	function filterNeighborhoods(value, update) {
		update(() => { neighborhoods.value = filterOptions(neighborhoodOptions.value, value) })
	}

	function setCity(value) {
		form.city = value || ''
		form.neighborhood = ''
	}

	async function save() {
		if (saving.value || !auth.isAuthenticated) return
		const request = ++generation
		saving.value = true
		error.value = false
		const payload = Object.fromEntries(Object.entries(form).map(([key, value]) => [key, String(value || '').trim()]))
		try {
			const { data } = props.question?.id ? await updateQuestion(props.question.id, payload) : await createQuestion(payload)
			if (request !== generation) return
			rememberLocation(payload.city, payload.neighborhood)
			emit('saved', data.data)
			emit('update:modelValue', false)
			$q.notify({ type: 'positive', message: t('community.questionSaved') })
		} catch {
			if (request === generation) error.value = true
		} finally {
			if (request === generation) saving.value = false
		}
	}

	watch(cityOptions, value => { cities.value = value }, { immediate: true })
	watch(neighborhoodOptions, value => { neighborhoods.value = value }, { immediate: true })
	watch(() => [props.modelValue, props.question?.id], ([isOpen]) => {
		if (!isOpen) return
		generation++
		error.value = false
		saving.value = false
		const question = props.question
		Object.assign(form, {
			title: question?.title || '',
			body: question?.body || '',
			city: question?.city || props.initialCity || auth.user?.profile?.city || '',
			neighborhood: question?.neighborhood || props.initialNeighborhood || '',
			category_key: question?.category_key || props.initialCategory || ''
		})
		rememberLocation(form.city, form.neighborhood)
		loadCatalogTopics().catch(() => {})
		loadLocationOptions()
	}, { immediate: true })
	onBeforeUnmount(() => { generation++ })
</script>

<template>
	<q-dialog v-model="open" :persistent="saving">
		<q-card class="question-composer">
			<header class="question-composer__head">
				<div><h2>{{ t(question ? 'community.edit' : 'community.ask') }}</h2><p>{{ t('community.questionIntro') }}</p></div>
				<q-btn flat
					round
					icon="close"
					:aria-label="t('community.close')"
					:disable="saving"
					@click="open = false"
				/>
			</header>
			<q-form v-if="auth.isAuthenticated" class="question-composer__form" @submit.prevent="save">
				<q-banner v-if="error" rounded class="bg-red-1 text-negative" role="alert">{{ t('community.saveFailed') }}</q-banner>
				<q-input v-model="form.title"
					outlined
					:label="t('community.questionTitle')"
					maxlength="180"
					:rules="[required]"
					:disable="saving"
				/>
				<q-input v-model="form.body"
					outlined
					type="textarea"
					autogrow
					:label="t('community.questionBody')"
					maxlength="5000"
					counter
					:rules="[required]"
					:disable="saving"
				/>
				<div class="question-composer__location">
					<q-select :model-value="form.city"
						outlined
						use-input
						fill-input
						hide-selected
						emit-value
						map-options
						input-debounce="0"
						:label="t('community.city')"
						:options="cities"
						:rules="[required]"
						:disable="saving"
						@update:model-value="setCity"
						@filter="filterCities"
						@new-value="addOption"
					/>
					<q-select v-model="form.neighborhood"
						outlined
						clearable
						use-input
						fill-input
						hide-selected
						emit-value
						map-options
						input-debounce="0"
						:label="t('community.neighborhood')"
						:options="neighborhoods"
						:disable="saving || !form.city"
						@filter="filterNeighborhoods"
						@new-value="addOption"
					/>
				</div>
				<CatalogCategorySelect v-model="form.category_key" :groups="catalogGroups" scope="" :disabled="saving" :label="t('community.category')" />
				<footer class="question-composer__actions">
					<q-btn flat :label="t('community.cancel')" :disable="saving" @click="open = false" />
					<q-btn unelevated
						rounded
						color="primary"
						type="submit"
						:loading="saving"
						:label="t(question ? 'community.save' : 'community.publish')"
					/>
				</footer>
			</q-form>
			<div v-else class="question-composer__form">
				<p>{{ t('community.signInHint') }}</p>
				<q-btn color="primary" :label="t('community.signIn')" :to="{ name: 'login', query: { redirect: route.fullPath } }" />
			</div>
		</q-card>
	</q-dialog>
</template>

<style scoped>
.question-composer { width: min(680px, 95vw); max-width: 95vw; border-radius: 24px; }
.question-composer__head { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; padding: 24px 24px 0; }
.question-composer__head h2 { font-size: 1.4rem; margin: 0 0 8px; }
.question-composer__head p { color: var(--soz-muted); margin: 0; }
.question-composer__form { display: grid; gap: 16px; padding: 24px; }
.question-composer__location { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
.question-composer__actions { display: flex; justify-content: flex-end; flex-wrap: wrap; gap: 8px; }
@media (max-width: 600px) { .question-composer__form { padding: 18px; } .question-composer__head { padding: 18px 18px 0; } .question-composer__location { grid-template-columns: 1fr; } }
</style>
