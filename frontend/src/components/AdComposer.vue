<script setup>
	import { reactive, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { createAd } from '@/services/api/ads'
	import { useRequiredFields } from '@/composables/useRequiredFields'

	const props = defineProps({
		pageId: {
			type: [Number, String],
			default: null
		},
		disabled: {
			type: Boolean,
			default: false
		}
	})

	const emit = defineEmits(['saved'])
	const { t } = useI18n()
	const $q = useQuasar()
	const loading = ref(false)
	const formRef = ref(null)
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const form = reactive({
		title: '',
		text: '',
		image: null
	})

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		loading.value = true

		try {
			const { data } = await createAd({
				...form,
				page_id: props.pageId
			})
			form.title = ''
			form.text = ''
			form.image = null
			emit('saved', data.data)
			$q.notify({ type: 'positive', message: t('actions.createAd') })
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('ads.saveFailed') })
		} finally {
			loading.value = false
		}
	}

</script>

<template>
	<q-form ref="formRef" greedy class="ad-composer" @submit.prevent="submit()">
		<q-input
			v-model="form.title"
			outlined
			:label="requiredLabel('ads.title')"
			:disable="disabled"
			:rules="[requiredRule]"
		/>
		<q-input v-model="form.text"
			outlined
			type="textarea"
			autogrow
			:label="requiredLabel('ads.text')"
			:disable="disabled"
			:rules="[requiredRule]"
		/>
		<div class="ad-composer__row">
			<q-file v-model="form.image"
				outlined
				clearable
				accept="image/*"
				:label="t('ads.image')"
				:disable="disabled"
			/>
			<q-btn color="primary"
				unelevated
				rounded
				type="submit"
				icon="add"
				:loading="loading"
				:disable="disabled"
				:label="t('actions.createAd')"
			/>
		</div>
	</q-form>
</template>

<style scoped lang="scss">
.ad-composer {
  display: grid;
  gap: 14px;
}

.ad-composer__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
}

@media (max-width: 700px) {
  .ad-composer__row {
    grid-template-columns: 1fr;
  }
}
</style>
