<script setup>
	import { computed, reactive, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { createPrice, updatePrice } from '@/services/api/prices'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { TITLE_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'

	const props = defineProps({
		pageId: {
			type: [Number, String],
			required: true
		},
		price: {
			type: Object,
			default: null
		}
	})

	const emit = defineEmits(['saved'])
	const { t } = useI18n()
	const $q = useQuasar()
	const formRef = ref(null)
	const loading = ref(false)
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const form = reactive({ name: '', price: '' })
	const isEditing = computed(() => Boolean(props.price?.id))
	const actionLabel = computed(() => (isEditing.value ? t('actions.update') : t('priceList.add')))

	function hydrate(price) {
		form.name = price?.name || ''
		form.price = price?.price ?? ''
	}

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		loading.value = true
		try {
			const payload = { name: form.name.trim(), price: form.price }
			const response = isEditing.value ? await updatePrice(props.price.id, payload) : await createPrice(props.pageId, payload)

			hydrate(null)
			emit('saved', response.data.data)
			$q.notify({ type: 'positive', message: t('priceList.saved') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('priceList.saveFailed')) })
		} finally {
			loading.value = false
		}
	}

	watch(() => props.price, hydrate, { immediate: true })
</script>

<template>
	<q-form ref="formRef" greedy class="price-composer" @submit.prevent="submit">
		<q-input
			v-model="form.name"
			outlined
			:label="requiredLabel('priceList.name')"
			:maxlength="TITLE_MAX_LENGTH"
			:hint="characterLimitHint(form.name, TITLE_MAX_LENGTH, t)"
			counter
			persistent-hint
			:rules="[requiredRule]"
		/>
		<q-input
			v-model="form.price"
			outlined
			type="number"
			step="0.01"
			min="0"
			:label="requiredLabel('priceList.price')"
			:rules="[requiredRule]"
		/>
		<q-btn
			rounded
			unelevated
			color="primary"
			type="submit"
			:icon="isEditing ? 'save' : 'add'"
			:loading="loading"
			:label="actionLabel"
		/>
	</q-form>
</template>

<style scoped lang="scss">
.price-composer {
  display: grid;
  gap: 14px;
}
</style>
