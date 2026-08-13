<script setup>
	import { computed, reactive, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { createEvent, updateEvent } from '@/services/api/events'
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
		event: {
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
		date: '',
		time: '',
		end_time: '',
		address: ''
	})
	const isEditing = computed(() => Boolean(props.event?.id))
	const actionLabel = computed(() => (isEditing.value ? t('actions.update') : t('actions.addEvent')))
	const hasStoredImage = computed(() => Boolean(props.event?.image_url) && !form.image && !imageRemoved.value)
	const imageDisplayName = computed(() => imageUploadDisplayName(
		form.image,
		imageRemoved.value ? '' : props.event?.image_url,
		imageRemoved.value ? '' : props.event?.image_name
	))

	function hydrate(event) {
		form.name = event?.name || ''
		form.description = event?.description || ''
		form.category_key = event?.category_key || defaultCategoryKey()
		form.image = null
		form.date = event?.date || ''
		form.time = event?.time || ''
		form.end_time = event?.end_time || ''
		form.address = event?.address || ''
		imageRemoved.value = false
	}

	function defaultCategoryKey() {
		const pageCategory = catalogTopicByKey(props.catalogGroups, props.pageCategoryKey)
		const canUsePageCategory = pageCategory && catalogTopicMatchesScope(pageCategory, CATALOG_SCOPES.EVENTS)

		return canUsePageCategory ? pageCategory.key : ''
	}

	function timeRule(value) {
		return /^([01]\d|2[0-3]):[0-5]\d$/.test(String(value || '').trim()) || t('validation.time24')
	}

	function optionalTimeRule(value) {
		const time = String(value || '').trim()

		return !time || timeRule(time)
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
				response = await updateEvent(props.event.id, { ...form, image_remove: imageRemoved.value })
			} else {
				response = await createEvent(props.pageId, form)
			}

			hydrate(null)
			emit('saved', response.data.data)
			$q.notify({ type: 'positive', message: isEditing.value ? t('actions.update') : t('events.created') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('events.saveFailed')) })
		} finally {
			loading.value = false
		}
	}

	watch(() => props.event, hydrate, { immediate: true })
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
	<q-form ref="formRef" greedy class="event-composer" @submit.prevent="submit()">
		<q-input
			v-model="form.name"
			outlined
			:label="requiredLabel('events.name')"
			:maxlength="TITLE_MAX_LENGTH"
			:hint="characterLimitHint(form.name, TITLE_MAX_LENGTH, t)"
			counter
			persistent-hint
			:rules="[requiredRule]"
		/>
		<CatalogCategorySelect
			v-model="form.category_key"
			:groups="catalogGroups"
			:scope="CATALOG_SCOPES.EVENTS"
			required
			:label="requiredLabel('catalog.category')"
		/>
		<q-input
			v-model="form.description"
			outlined
			type="textarea"
			autogrow
			:label="requiredLabel('events.description')"
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
			:label="isEditing ? t('events.image') : requiredLabel('events.image')"
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
		<div class="event-composer__row">
			<q-input
				v-model="form.date"
				outlined
				type="date"
				:label="requiredLabel('events.date')"
				:rules="[requiredRule]"
			/>
			<q-input
				v-model="form.time"
				outlined
				mask="##:##"
				placeholder="HH:MM"
				inputmode="numeric"
				:label="requiredLabel('events.time')"
				:rules="[requiredRule, timeRule]"
			/>
			<q-input
				v-model="form.end_time"
				outlined
				clearable
				mask="##:##"
				placeholder="HH:MM"
				inputmode="numeric"
				:label="t('events.endTime')"
				:rules="[optionalTimeRule]"
			/>
		</div>
		<q-input
			v-model="form.address"
			outlined
			:label="requiredLabel('events.address')"
			:hint="t('events.addressHint')"
			persistent-hint
			:rules="[requiredRule]"
		/>
		<div class="event-composer__actions">
			<q-btn
				rounded
				unelevated
				color="primary"
				type="submit"
				:icon="isEditing ? 'save' : 'event'"
				:loading="loading"
				:label="actionLabel"
			/>
		</div>
	</q-form>
</template>

<style scoped lang="scss">
.event-composer {
  display: grid;
  gap: 14px;
}

.event-composer__row {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  align-items: start;
}

.event-composer__actions {
  display: flex;
  justify-content: flex-end;
  padding-top: 6px;
}

@media (max-width: 700px) {
  .event-composer__row {
    grid-template-columns: 1fr;
  }

  .event-composer__actions {
    justify-content: stretch;
  }

  .event-composer__actions .q-btn {
    width: 100%;
  }
}
</style>
