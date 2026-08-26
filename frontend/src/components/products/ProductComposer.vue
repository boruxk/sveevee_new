<script setup>
	import { computed, reactive, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { createProduct, updateProduct } from '@/services/api/products'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { IMAGE_ACCEPT, imageUploadDisplayName } from '@/utils/imageUploads'
	import { TITLE_MAX_LENGTH, TEXT_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'
	import { catalogTopicByKey, catalogTopicMatchesScope, CATALOG_SCOPES } from '@/constants/catalogTopics'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'

	const props = defineProps({
		pageId: {
			type: [Number, String],
			required: true
		},
		product: {
			type: Object,
			default: null
		},
		pageCategoryKey: {
			type: String,
			default: ''
		},
		catalogGroups: {
			type: Array,
			default: () => []
		}
	})

	const emit = defineEmits(['saved'])
	const { t } = useI18n()
	const $q = useQuasar()
	const loading = ref(false)
	const formRef = ref(null)
	const imageRemoved = ref(false)
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const form = reactive({
		name: '',
		brand: '',
		model: '',
		description: '',
		category_key: '',
		image: null,
		price: '',
		offer_enabled: false,
		offer_price: '',
		offer_starts_at: '',
		offer_ends_at: '',
		link: ''
	})
	const isEditing = computed(() => Boolean(props.product?.id))
	const actionLabel = computed(() => (isEditing.value ? t('actions.update') : t('actions.addProduct')))
	const hasStoredImage = computed(() => Boolean(props.product?.image_url) && !form.image && !imageRemoved.value)
	const imageDisplayName = computed(() => imageUploadDisplayName(
		form.image,
		imageRemoved.value ? '' : props.product?.image_url,
		imageRemoved.value ? '' : props.product?.image_name
	))
	const offerPriceRule = (value) => (
		!form.offer_enabled || Number(value) < Number(form.price) || t('products.offerMustBeLower')
	)
	const offerEndRule = (value) => (
		!form.offer_enabled || !form.offer_starts_at || value > form.offer_starts_at || t('products.offerEndAfterStart')
	)

	function localDate(value) {
		if (!value) {
			return ''
		}

		const date = new Date(value)
		if (Number.isNaN(date.getTime())) {
			return String(value).slice(0, 10)
		}

		const local = new Date(date.getTime() - date.getTimezoneOffset() * 60000)

		return local.toISOString().slice(0, 10)
	}

	function hydrate(product) {
		form.name = product?.name || ''
		form.brand = product?.brand || ''
		form.model = product?.model || ''
		form.description = product?.description || ''
		form.category_key = product?.category_key || defaultCategoryKey()
		form.image = null
		form.price = product?.normal_price ?? product?.price ?? ''
		form.offer_enabled = Boolean(product?.offer_enabled)
		form.offer_price = product?.offer_price ?? ''
		form.offer_starts_at = localDate(product?.offer_starts_at)
		form.offer_ends_at = localDate(product?.offer_ends_at)
		form.link = product?.link || ''
		imageRemoved.value = false
	}

	function defaultCategoryKey() {
		const pageCategory = catalogTopicByKey(props.catalogGroups, props.pageCategoryKey)
		const canUsePageCategory = pageCategory && catalogTopicMatchesScope(pageCategory, CATALOG_SCOPES.PRODUCTS)

		return canUsePageCategory ? pageCategory.key : ''
	}

	function removeStoredImage() {
		form.image = null
		imageRemoved.value = true
	}

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		loading.value = true

		try {
			let response

			if (isEditing.value) {
				response = await updateProduct(props.product.id, { ...form, image_remove: imageRemoved.value })
			} else {
				response = await createProduct(props.pageId, form)
			}

			hydrate(null)
			emit('saved', response.data.data)
			$q.notify({ type: 'positive', message: isEditing.value ? t('actions.update') : t('products.created') })
		} catch (error) {
			$q.notify({
				type: 'negative',
				message: apiErrorMessage(error, t('products.saveFailed'), {
					product_limit: t('products.limitReached', { limit: error.response?.data?.data?.limit })
				})
			})
		} finally {
			loading.value = false
		}
	}

	watch(() => props.product, hydrate, { immediate: true })
	watch(() => [props.pageCategoryKey, props.catalogGroups], () => {
		if (!isEditing.value && !form.category_key) {
			form.category_key = defaultCategoryKey()
		}
	})
	watch(() => form.image, (value) => {
		if (value) {
			imageRemoved.value = false
		}
	})
</script>

<template>
	<q-form ref="formRef" greedy class="product-composer" @submit.prevent="submit()">
		<q-input
			v-model="form.name"
			outlined
			:label="requiredLabel('products.name')"
			:maxlength="TITLE_MAX_LENGTH"
			:hint="characterLimitHint(form.name, TITLE_MAX_LENGTH, t)"
			counter
			persistent-hint
			:rules="[requiredRule]"
		/>
		<div class="product-composer__row product-composer__row--equal">
			<q-input v-model="form.brand" outlined :label="t('products.brand')" maxlength="120" />
			<q-input v-model="form.model" outlined :label="t('products.model')" maxlength="120" />
		</div>
		<CatalogCategorySelect
			v-model="form.category_key"
			:groups="catalogGroups"
			:scope="CATALOG_SCOPES.PRODUCTS"
			required
			:label="requiredLabel('catalog.category')"
		/>
		<q-input
			v-model="form.description"
			outlined
			type="textarea"
			autogrow
			:label="requiredLabel('products.description')"
			:maxlength="TEXT_MAX_LENGTH"
			:hint="characterLimitHint(form.description, TEXT_MAX_LENGTH, t)"
			counter
			persistent-hint
			:rules="[requiredRule]"
		/>
		<div class="product-composer__row">
			<q-file
				v-model="form.image"
				outlined
				clearable
				:accept="IMAGE_ACCEPT"
				:display-value="imageDisplayName"
				:label="isEditing ? t('products.image') : requiredLabel('products.image')"
				:rules="isEditing ? [] : [requiredRule]"
			>
				<template #append>
					<q-btn
						v-if="hasStoredImage"
						flat
						round
						dense
						color="negative"
						icon="delete"
						:aria-label="t('actions.delete')"
						@click.stop.prevent="removeStoredImage"
					>
						<q-tooltip>{{ t('actions.delete') }}</q-tooltip>
					</q-btn>
				</template>
			</q-file>
			<q-input
				v-model="form.price"
				outlined
				type="number"
				step="0.01"
				min="0"
				:label="requiredLabel(form.offer_enabled ? 'products.normalPrice' : 'products.price')"
				:rules="[requiredRule]"
			/>
		</div>
		<div class="product-composer__offer">
			<q-toggle v-model="form.offer_enabled" color="primary" :label="t('products.offer')" />
			<div v-if="form.offer_enabled" class="product-composer__offer-fields">
				<q-input
					v-model="form.offer_price"
					outlined
					type="number"
					step="0.01"
					min="0"
					:label="requiredLabel('products.offerPrice')"
					:rules="[requiredRule, offerPriceRule]"
				/>
				<q-input
					v-model="form.offer_starts_at"
					outlined
					type="date"
					:label="requiredLabel('products.offerStart')"
					:rules="[requiredRule]"
				/>
				<q-input
					v-model="form.offer_ends_at"
					outlined
					type="date"
					:label="requiredLabel('products.offerEnd')"
					:rules="[requiredRule, offerEndRule]"
				/>
			</div>
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
				:icon="isEditing ? 'save' : 'add'"
				:loading="loading"
				:label="actionLabel"
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

.product-composer__row--equal {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.product-composer__offer {
  display: grid;
  gap: 10px;
  padding: 14px;
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 18px;
  background: rgba(123, 63, 242, 0.045);
}

.product-composer__offer-fields {
  display: grid;
  grid-template-columns: minmax(130px, 0.6fr) repeat(2, minmax(180px, 1fr));
  gap: 12px;
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

  .product-composer__offer-fields {
    grid-template-columns: 1fr;
  }

  .product-composer__actions {
    justify-content: stretch;
  }

  .product-composer__actions .q-btn {
    width: 100%;
  }
}
</style>
