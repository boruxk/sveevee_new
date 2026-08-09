<script setup>
	import { computed, reactive, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { createAd, updateAd } from '@/services/api/ads'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { IMAGE_ACCEPT, imageUploadDisplayName } from '@/utils/imageUploads'
	import { buildAdCategorySelectOptions } from '@/constants/adCategories'

	const props = defineProps({
		pageId: {
			type: [Number, String],
			default: null
		},
		ad: {
			type: Object,
			default: null
		},
		disabled: {
			type: Boolean,
			default: false
		}
	})

	const emit = defineEmits(['saved'])
	const { t, locale } = useI18n()
	const $q = useQuasar()
	const TITLE_MAX_LENGTH = 300
	const TEXT_MAX_LENGTH = 2000
	const loading = ref(false)
	const formRef = ref(null)
	const imageRemoved = ref(false)
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const form = reactive({
		title: '',
		text: '',
		category: '',
		image: null
	})
	const isEditing = computed(() => Boolean(props.ad?.id))
	const actionLabel = computed(() => (isEditing.value ? t('actions.update') : t('actions.createAd')))
	const categoryOptions = computed(() => {
		const currentLocale = locale.value

		return buildAdCategorySelectOptions((key) => t(key, { currentLocale }))
	})
	const hasStoredImage = computed(() => Boolean(props.ad?.image_url) && !form.image && !imageRemoved.value)
	const imageDisplayName = computed(() => imageUploadDisplayName(
		form.image,
		imageRemoved.value ? '' : props.ad?.image_url,
		imageRemoved.value ? '' : props.ad?.image_name
	))

	function hydrate(ad) {
		form.title = ad?.title || ''
		form.text = ad?.text || ''
		form.category = ad?.category || ''
		form.image = null
		imageRemoved.value = false
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
			const payload = {
				...form,
				page_id: props.pageId,
				image_remove: imageRemoved.value
			}
			let response

			if (isEditing.value) {
				response = await updateAd(props.ad.id, payload)
			} else {
				response = await createAd(payload)
			}

			hydrate(null)
			emit('saved', response.data.data)
			$q.notify({ type: 'positive', message: actionLabel.value })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('ads.saveFailed')) })
		} finally {
			loading.value = false
		}
	}

	watch(() => props.ad, hydrate, { immediate: true })
	watch(() => form.image, (value) => {
		if (value) {
			imageRemoved.value = false
		}
	})
</script>

<template>
	<q-form ref="formRef" greedy class="listing-composer" @submit.prevent="submit()">
		<q-input
			v-model="form.title"
			outlined
			:label="requiredLabel('ads.title')"
			:disable="disabled"
			:maxlength="TITLE_MAX_LENGTH"
			counter
			:rules="[requiredRule]"
		/>
		<q-input v-model="form.text"
			outlined
			type="textarea"
			autogrow
			:label="requiredLabel('ads.text')"
			:disable="disabled"
			:maxlength="TEXT_MAX_LENGTH"
			counter
			:rules="[requiredRule]"
		/>
		<q-select
			v-model="form.category"
			outlined
			clearable
			emit-value
			map-options
			options-dense
			popup-content-class="ad-category-select-menu"
			popup-content-style="max-height: min(72vh, 520px); overflow-y: auto;"
			:virtual-scroll-item-size="46"
			:virtual-scroll-slice-size="36"
			:options="categoryOptions"
			option-disable="disable"
			:label="t('ads.category')"
			:disable="disabled"
		>
			<template #option="scope">
				<q-item v-bind="scope.itemProps" :class="{ 'ad-category-option--group': scope.opt.group }">
					<q-item-section avatar>
						<span class="ad-category-option__dot" :style="{ backgroundColor: scope.opt.color }" />
					</q-item-section>
					<q-item-section>
						<q-item-label>{{ scope.opt.label }}</q-item-label>
					</q-item-section>
				</q-item>
			</template>
		</q-select>
		<div class="listing-composer__row">
			<q-file v-model="form.image"
				outlined
				clearable
				:accept="IMAGE_ACCEPT"
				:display-value="imageDisplayName"
				:label="t('ads.image')"
				:disable="disabled"
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
			<q-btn color="primary"
				unelevated
				rounded
				type="submit"
				:icon="isEditing ? 'save' : 'add'"
				:loading="loading"
				:disable="disabled"
				:label="actionLabel"
			/>
		</div>
	</q-form>
</template>

<style scoped lang="scss">
.listing-composer {
  display: grid;
  gap: 14px;
}

.listing-composer__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
}

.ad-category-option--group {
  min-height: 44px;
  color: rgba(17, 34, 45, 0.9);
  font-size: 15px;
  font-weight: 900;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.ad-category-option__dot {
  display: block;
  width: 12px;
  height: 12px;
  border-radius: 50%;
}

.ad-category-option--group .ad-category-option__dot {
  width: 15px;
  height: 15px;
  box-shadow: 0 6px 14px rgba(17, 34, 45, 0.18);
}

@media (max-width: 700px) {
  .listing-composer__row {
    grid-template-columns: 1fr;
  }

  .listing-composer__row .q-btn {
    width: 100%;
  }
}
</style>
