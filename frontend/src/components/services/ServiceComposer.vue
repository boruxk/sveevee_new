<script setup>
	import { computed, reactive, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { createService, updateService } from '@/services/api/services'
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
		service: {
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
		description: '',
		category_key: '',
		image: null,
		link: ''
	})
	const isEditing = computed(() => Boolean(props.service?.id))
	const actionLabel = computed(() => (isEditing.value ? t('actions.update') : t('businessServices.addService')))
	const hasStoredImage = computed(() => Boolean(props.service?.image_url) && !form.image && !imageRemoved.value)
	const imageDisplayName = computed(() => imageUploadDisplayName(
		form.image,
		imageRemoved.value ? '' : props.service?.image_url,
		imageRemoved.value ? '' : props.service?.image_name
	))

	function hydrate(service) {
		form.name = service?.name || ''
		form.description = service?.description || ''
		form.category_key = service?.category_key || defaultCategoryKey()
		form.image = null
		form.link = service?.link || ''
		imageRemoved.value = false
	}

	function defaultCategoryKey() {
		const pageCategory = catalogTopicByKey(props.catalogGroups, props.pageCategoryKey)
		const canUsePageCategory = pageCategory && catalogTopicMatchesScope(pageCategory, CATALOG_SCOPES.SERVICES)

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
				response = await updateService(props.service.id, { ...form, image_remove: imageRemoved.value })
			} else {
				response = await createService(props.pageId, form)
			}

			hydrate(null)
			emit('saved', response.data.data)
			$q.notify({ type: 'positive', message: isEditing.value ? t('actions.update') : t('businessServices.created') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('businessServices.saveFailed')) })
		} finally {
			loading.value = false
		}
	}

	watch(() => props.service, hydrate, { immediate: true })
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
	<q-form ref="formRef" greedy class="service-composer" @submit.prevent="submit()">
		<q-input
			v-model="form.name"
			outlined
			:label="requiredLabel('businessServices.name')"
			:maxlength="TITLE_MAX_LENGTH"
			:hint="characterLimitHint(form.name, TITLE_MAX_LENGTH, t)"
			counter
			persistent-hint
			:rules="[requiredRule]"
		/>
		<CatalogCategorySelect
			v-model="form.category_key"
			:groups="catalogGroups"
			:scope="CATALOG_SCOPES.SERVICES"
			required
			:label="requiredLabel('catalog.category')"
		/>
		<q-input
			v-model="form.description"
			outlined
			type="textarea"
			autogrow
			:label="requiredLabel('businessServices.description')"
			:maxlength="TEXT_MAX_LENGTH"
			:hint="characterLimitHint(form.description, TEXT_MAX_LENGTH, t)"
			counter
			persistent-hint
			:rules="[requiredRule]"
		/>
		<q-file
			v-model="form.image"
			outlined
			clearable
			:accept="IMAGE_ACCEPT"
			:display-value="imageDisplayName"
			:label="isEditing ? t('businessServices.image') : requiredLabel('businessServices.image')"
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
			v-model="form.link"
			outlined
			type="url"
			:label="t('businessServices.link')"
			:hint="t('businessServices.linkHint')"
			persistent-hint
		/>
		<div class="service-composer__actions">
			<q-btn
				rounded
				unelevated
				color="primary"
				type="submit"
				:icon="isEditing ? 'save' : 'design_services'"
				:loading="loading"
				:label="actionLabel"
			/>
		</div>
	</q-form>
</template>

<style scoped lang="scss">
.service-composer {
  display: grid;
  gap: 14px;
}

.service-composer__actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 6px;
}

@media (max-width: 700px) {
  .service-composer__actions {
    justify-content: stretch;
  }

  .service-composer__actions .q-btn {
    width: 100%;
  }
}
</style>
