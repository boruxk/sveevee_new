<script setup>
	import { reactive, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { createProduct } from '@/services/api/products'
	import { useRequiredFields } from '@/composables/useRequiredFields'

	const props = defineProps({
		pageId: {
			type: [Number, String],
			required: true
		}
	})

	const emit = defineEmits(['saved'])
	const { t } = useI18n()
	const $q = useQuasar()
	const loading = ref(false)
	const formRef = ref(null)
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const form = reactive({
		name: '',
		description: '',
		image: null,
		price: '',
		link: ''
	})

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		loading.value = true

		try {
			const { data } = await createProduct(props.pageId, form)
			form.name = ''
			form.description = ''
			form.image = null
			form.price = ''
			form.link = ''
			emit('saved', data.data)
			$q.notify({ type: 'positive', message: t('products.created') })
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('products.saveFailed') })
		} finally {
			loading.value = false
		}
	}
</script>

<template>
	<q-form ref="formRef" greedy class="product-composer" @submit.prevent="submit()">
		<q-input
			v-model="form.name"
			outlined
			:label="requiredLabel('products.name')"
			:rules="[requiredRule]"
		/>
		<q-input
			v-model="form.description"
			outlined
			type="textarea"
			autogrow
			:label="requiredLabel('products.description')"
			:rules="[requiredRule]"
		/>
		<div class="product-composer__row">
			<q-file
				v-model="form.image"
				outlined
				clearable
				accept="image/*"
				:label="requiredLabel('products.image')"
				:rules="[requiredRule]"
			/>
			<q-input
				v-model="form.price"
				outlined
				type="number"
				step="0.01"
				min="0"
				:label="requiredLabel('products.price')"
				:rules="[requiredRule]"
			/>
		</div>
		<q-input
			v-model="form.link"
			outlined
			type="url"
			:label="requiredLabel('products.link')"
			:hint="t('products.linkHint')"
			persistent-hint
			:rules="[requiredRule]"
		/>
		<div class="product-composer__actions">
			<q-btn
				rounded
				unelevated
				color="primary"
				type="submit"
				icon="add"
				:loading="loading"
				:label="t('actions.addProduct')"
			/>
		</div>
	</q-form>
</template>

<style scoped lang="scss">
.product-composer {
  display: grid;
  gap: 14px;
}

.product-composer__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(160px, 0.5fr);
  gap: 12px;
  align-items: start;
}

.product-composer__actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 6px;
}

@media (max-width: 700px) {
  .product-composer__row {
    grid-template-columns: 1fr;
  }
}
</style>
