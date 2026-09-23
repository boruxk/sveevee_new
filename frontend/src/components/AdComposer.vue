<script setup>
	import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { createAd, updateAd } from '@/services/api/ads'
	import { fetchAdProFeature } from '@/services/api/businessPro'
	import { useAuthStore } from '@/stores/auth'
	import { matLock, matStars } from '@quasar/extras/material-icons'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { IMAGE_ACCEPT, imageUploadDisplayName } from '@/utils/imageUploads'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { catalogTopicForAdCategory, CATALOG_SCOPES } from '@/constants/catalogTopics'
	import { TITLE_MAX_LENGTH, TEXT_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'

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
	const { t } = useI18n()
	const $q = useQuasar()
	const auth = useAuthStore()
	const feature = ref(null)
	let featureRequest = 0
	const canFeature = computed(() => feature.value?.available === true)
	const featureRequiredHint = computed(() => t(props.pageId || props.ad?.page_id || props.ad?.page?.id ? 'businessPro.featuredAdPageRequired' : 'businessPro.featuredAdRequiresPlan'))
	const featureHint = computed(() => {
		if (canFeature.value) return t('businessPro.featuredAdDescription')
		if (feature.value?.locked_reason === 'not_available') return t('businessPro.featuredAdUnavailable')
		return featureRequiredHint.value
	})
	const loading = ref(false)
	const formRef = ref(null)
	const imageRemoved = ref(false)
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const form = reactive({
		title: '',
		text: '',
		category: '',
		is_featured: false,
		image: null
	})
	const isEditing = computed(() => Boolean(props.ad?.id))
	const actionLabel = computed(() => (isEditing.value ? t('actions.update') : t('actions.createAd')))
	const hasStoredImage = computed(() => Boolean(props.ad?.image_url) && !form.image && !imageRemoved.value)
	const imageDisplayName = computed(() => imageUploadDisplayName(
		form.image,
		imageRemoved.value ? '' : props.ad?.image_url,
		imageRemoved.value ? '' : props.ad?.image_name
	))

	function normalizedCategoryValue(value) {
		if (!value) {
			return ''
		}

		return catalogTopicForAdCategory(catalogGroups.value, value)?.key || value
	}

	function hydrate(ad) {
		form.title = ad?.title || ''
		form.text = ad?.text || ''
		form.category = normalizedCategoryValue(ad?.category)
		form.is_featured = (ad?.featured_requested ?? ad?.is_featured) === true
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
			const { is_featured: requestedFeatured, ...adFields } = form
			const payload = {
				...adFields,
				...(canFeature.value ? { is_featured: requestedFeatured } : {}),
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
			if (error.response?.status === 402 && error.response?.data?.data?.reason === 'pro_feature_required') {
				featureRequest++
				feature.value = { ...feature.value, available: false, locked_reason: 'subscription_required' }
			}
			$q.notify({
				type: 'negative',
				message: apiErrorMessage(error, t('ads.saveFailed'), {
					pro_feature_required: featureRequiredHint.value,
					active_ad_limit: t('ads.activeLimitReached', { limit: error.response?.data?.data?.limit })
				})
			})
		} finally {
			loading.value = false
		}
	}

	watch(() => props.ad, hydrate, { immediate: true })
	watch([() => props.pageId, () => props.ad?.id, () => auth.user?.id, () => auth.token], async() => {
		const version = ++featureRequest
		feature.value = null
		if (!auth.isAuthenticated) return
		try {
			const params = props.ad?.id ? { ad_id: props.ad.id } : props.pageId ? { page_id: props.pageId } : {}
			const { data } = await fetchAdProFeature(params)
			if (version === featureRequest) feature.value = data.data
		} catch {
			if (version === featureRequest) feature.value = { available: false, locked_reason: 'not_available' }
		}
	}, { immediate: true })
	watch(catalogGroups, () => {
		form.category = normalizedCategoryValue(form.category)
	})
	watch(() => form.image, (value) => {
		if (value) {
			imageRemoved.value = false
		}
	})

	onMounted(loadCatalogTopics)
	onBeforeUnmount(() => { featureRequest++ })
</script>

<template>
	<q-form ref="formRef" greedy class="listing-composer" @submit.prevent="submit()">
		<q-input
			v-model="form.title"
			outlined
			:label="requiredLabel('ads.title')"
			:disable="disabled"
			:maxlength="TITLE_MAX_LENGTH"
			:hint="characterLimitHint(form.title, TITLE_MAX_LENGTH, t)"
			counter
			persistent-hint
			:rules="[requiredRule]"
		/>
		<q-input v-model="form.text"
			outlined
			type="textarea"
			autogrow
			:label="requiredLabel('ads.text')"
			:disable="disabled"
			:maxlength="TEXT_MAX_LENGTH"
			:hint="characterLimitHint(form.text, TEXT_MAX_LENGTH, t)"
			counter
			persistent-hint
			:rules="[requiredRule]"
		/>
		<CatalogCategorySelect
			v-model="form.category"
			:groups="catalogGroups"
			:scope="CATALOG_SCOPES.ADS"
			:label="t('ads.category')"
			:disable="disabled"
		/>
		<div class="listing-composer__featured" :class="{ 'listing-composer__featured--locked': !canFeature }" :tabindex="!canFeature ? 0 : undefined" :title="featureHint">
			<div class="listing-composer__featured-control">
				<q-icon :name="canFeature ? matStars : matLock" size="20px" />
				<q-toggle :model-value="canFeature && form.is_featured"
					color="primary"
					:label="t('businessPro.featuredAd')"
					:disable="disabled || !canFeature"
					@update:model-value="form.is_featured = $event"
				/>
			</div>
			<p>{{ featureHint }}</p>
			<router-link v-if="!canFeature" :to="{ name: 'profile', hash: '#business-pro' }">{{ t('businessPro.plansTitle') }}</router-link>
			<q-tooltip>{{ featureHint }}</q-tooltip>
		</div>
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

.listing-composer__featured { border: 1px solid #e9b367; border-radius: 16px; padding: 12px 16px; background: #fff7e9; }
.listing-composer__featured--locked { border-color: var(--soz-line); background: rgba(245, 245, 250, .85); }
.listing-composer__featured-control { display: flex; align-items: center; gap: 6px; }
.listing-composer__featured p { margin: 4px 0; color: var(--soz-muted); font-size: 13px; line-height: 1.5; }
.listing-composer__featured a { color: var(--soz-primary); font-size: 13px; }
.listing-composer__featured:focus-visible { outline: 2px solid var(--soz-primary); outline-offset: 3px; }

@media (max-width: 700px) {
  .listing-composer__row {
    grid-template-columns: 1fr;
  }

  .listing-composer__row .q-btn {
    width: 100%;
  }
}
</style>
