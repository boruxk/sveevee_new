<script setup>
	import { computed, onBeforeUnmount, reactive, ref, toRef, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { useCredentialRules } from '@/composables/useCredentialRules'
	import { saveMyPage } from '@/services/api/pages'
	import { createAdminPage, fetchAdminPageOwnerOptions } from '@/services/api/admin'
	import { presencePalettes } from '@/constants/presencePalettes'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { pageSaveResult } from '@/utils/pageSaveResult'
	import { IMAGE_ACCEPT, imageUploadDisplayName } from '@/utils/imageUploads'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'
	import BusinessDetailsFields from '@/components/pages/BusinessDetailsFields.vue'
	import { CATALOG_SCOPES } from '@/constants/catalogTopics'

	const DEFAULT_OPENING_HOURS = [
		{ weekday: 'sunday', is_open: false, opens_at: null, closes_at: null },
		{ weekday: 'monday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'tuesday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'wednesday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'thursday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'friday', is_open: true, opens_at: '09:00', closes_at: '13:00' },
		{ weekday: 'saturday', is_open: false, opens_at: null, closes_at: null }
	]

	const props = defineProps({
		modelValue: {
			type: Boolean,
			default: false
		},
		adminMode: {
			type: Boolean,
			default: false
		},
		type: {
			type: String,
			default: 'business'
		}
	})

	const emit = defineEmits(['update:modelValue', 'created'])
	const { t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const saving = ref(false)
	const submitting = ref(false)
	const pendingClaimReview = ref(false)
	const selectedOwner = ref(null)
	const selectedOwnerId = computed(() => selectedOwner.value?.value || null)
	const ownerOptions = ref([])
	const ownerOptionsLoading = ref(false)
	const ownerOptionsError = ref(false)
	const openingHoursEdited = ref(false)
	let ownerRequestId = 0
	const formRef = ref(null)
	const businessDetailsRef = ref(null)
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const form = reactive({
		name: '',
		public_description: '',
		contact_email: '',
		phone: '',
		whatsapp: '',
		website: '',
		socials: { facebook: '', instagram: '', tiktok: '', x: '', telegram: '' },
		features: { store: false, services: false, events: false, price_list: false },
		address: {
			street: '',
			number: '',
			city: '',
			neighborhood: ''
		},
		opening_hours: DEFAULT_OPENING_HOURS.map((item) => ({ ...item })),
		service_areas: [],
		specialties: [],
		category_key: '',
		palette_key: 'amber-dawn',
		logo: null,
		banner: null
	})
	const dialogOpen = computed({
		get: () => props.modelValue,
		set: (value) => {
			if (!submitting.value) emit('update:modelValue', value)
		}
	})
	const title = computed(() => (props.adminMode ? t('admin.pages.createTitle') : props.type === 'business' ? t('pages.businessTitle') : t('pages.communityTitle')))
	const pageCatalogScope = computed(() => (
		props.type === 'community' ? CATALOG_SCOPES.COMMUNITY_PAGES : CATALOG_SCOPES.BUSINESS_PAGES
	))
	const logoDisplayName = computed(() => imageUploadDisplayName(form.logo))
	const bannerDisplayName = computed(() => imageUploadDisplayName(form.banner))
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const { emailRule } = useCredentialRules(t)
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		addOption,
		filterOptions,
		hasOptionValue
	} = useLocationOptions(toRef(form.address, 'city'))

	function addressLine(address) {
		return [address.street, address.number, address.neighborhood, address.city].filter(Boolean).join(', ')
	}

	function normalizedWebsite(value) {
		const raw = String(value || '').trim()
		if (!raw || (/^[a-z][a-z\d+.-]*:/i.test(raw) && !/^https?:\/\//i.test(raw))) return ''
		try {
			const url = new URL(/^https?:\/\//i.test(raw) ? raw : `https://${raw}`)

			return ['http:', 'https:'].includes(url.protocol) && url.hostname ? url.toString() : ''
		} catch {
			return ''
		}
	}

	function websiteRule(value) {
		return !String(value || '').trim() || Boolean(normalizedWebsite(value)) || t('pages.websiteInvalid')
	}

	function optionalEmailRule(value) {
		return !String(value || '').trim() || emailRule(value)
	}

	function contactFieldLabel(key) {
		return props.adminMode ? t(key) : requiredLabel(key)
	}

	function optionalAdminRules() {
		return props.adminMode ? [] : [requiredRule]
	}

	function resetForm() {
		pendingClaimReview.value = false
		selectedOwner.value = null
		ownerOptions.value = []
		ownerOptionsError.value = false
		openingHoursEdited.value = false
		form.name = ''
		form.public_description = ''
		form.contact_email = props.adminMode ? '' : authStore.user?.email || ''
		form.phone = props.adminMode ? '' : authStore.user?.profile?.phone || ''
		form.whatsapp = ''
		form.website = ''
		Object.keys(form.socials).forEach((key) => { form.socials[key] = '' })
		Object.keys(form.features).forEach((key) => { form.features[key] = false })
		form.address.street = ''
		form.address.number = ''
		form.address.city = props.adminMode ? '' : authStore.user?.profile?.city || ''
		form.address.neighborhood = props.adminMode ? '' : authStore.user?.profile?.neighborhood || ''
		form.opening_hours = DEFAULT_OPENING_HOURS.map((item) => props.adminMode ? { weekday: item.weekday, is_open: false, opens_at: null, closes_at: null } : { ...item })
		form.service_areas = []
		form.specialties = []
		form.category_key = ''
		form.palette_key = 'amber-dawn'
		form.logo = null
		form.banner = null
	}

	function pagePayload() {
		return {
			name: String(form.name || '').trim(),
			public_description: String(form.public_description || '').trim(),
			contact_email: String(form.contact_email || '').trim(),
			phone: String(form.phone || '').trim(),
			address: addressLine(form.address),
			category_key: form.category_key || null,
			palette_key: form.palette_key,
			...(props.adminMode ? { website: normalizedWebsite(form.website) } : {}),
			setup: {
				...(props.adminMode ? {
					website: normalizedWebsite(form.website),
					socials: Object.fromEntries(Object.entries(form.socials).map(([key, value]) => [key, String(value || '').trim() || null])),
					features: { ...form.features }
				} : {}),
				contact: {
					tel: String(form.phone || '').trim() || null,
					email: String(form.contact_email || '').trim() || null,
					whatsapp: String(form.whatsapp || '').trim() || null
				},
				address: {
					street: String(form.address.street || '').trim() || null,
					number: String(form.address.number || '').trim() || null,
					city: String(form.address.city || '').trim() || null,
					neighborhood: String(form.address.neighborhood || '').trim() || null
				},
				// An untouched admin form must not invent business hours.
				opening_hours: (props.adminMode && !openingHoursEdited.value ? [] : form.opening_hours).map((item) => ({
					weekday: item.weekday,
					is_open: item.is_open,
					opens_at: item.is_open ? item.opens_at || null : null,
					closes_at: item.is_open ? item.closes_at || null : null
				})),
				service_areas: props.type === 'business' ? [...form.service_areas] : [],
				specialties: props.type === 'business' ? [...form.specialties] : []
			},
			logo: form.logo,
			banner: form.banner
		}
	}

	async function submit() {
		if (submitting.value) return
		submitting.value = true
		try {
			businessDetailsRef.value?.commitPending()
			if (!(await validateRequiredForm(formRef))) return
			saving.value = true

			const response = props.adminMode ? await createAdminPage({ ...pagePayload(), user_id: selectedOwnerId.value }) : await saveMyPage(props.type, pagePayload())
			const result = pageSaveResult(response)
			if (!props.adminMode && result.outcome === 'claim_conflict') {
				pendingClaimReview.value = true
				$q.notify({ type: 'info', message: t('pages.claimConflictPending') })
				return
			}
			if (!result.page) throw new Error(t('admin.pages.createFailed'))
			pendingClaimReview.value = false
			rememberLocation(form.address.city, form.address.neighborhood)
			if (!props.adminMode) await authStore.refreshUser()
			const messageKey = props.adminMode ? 'admin.pages.createSuccess' : result.outcome === 'adopted' ? 'pages.adopted' : 'pages.saved'
			$q.notify({ type: 'positive', message: t(messageKey) })
			saving.value = false
			submitting.value = false
			dialogOpen.value = false
			emit('created', result.page)
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t(props.adminMode ? 'admin.pages.createFailed' : 'pages.saveFailed')) })
		} finally {
			saving.value = false
			submitting.value = false
		}
	}

	async function filterOwnerOptions(value, update = (callback) => callback(), abort = () => {}) {
		const requestId = ++ownerRequestId
		ownerOptionsLoading.value = true
		ownerOptionsError.value = false
		try {
			const { data } = await fetchAdminPageOwnerOptions({ without_business_page: 1, q: String(value || '').trim() || undefined })
			if (requestId !== ownerRequestId || !dialogOpen.value) return
			const options = (data.data?.items || []).map((user) => ({
				label: [user.display_name, user.email].filter(Boolean).join(' \u00b7 '),
				value: user.id,
				user
			}))
			update(() => {
				if (requestId === ownerRequestId) ownerOptions.value = options
			})
		} catch {
			if (requestId !== ownerRequestId || !dialogOpen.value) return
			ownerOptionsError.value = true
			ownerOptions.value = []
			abort()
		} finally {
			if (requestId === ownerRequestId) ownerOptionsLoading.value = false
		}
	}

	function filterCityOptions(value, update) {
		update(() => {
			citySelectOptions.value = filterOptions(cityOptions.value, value)
		})
	}

	function filterNeighborhoodOptions(value, update) {
		update(() => {
			neighborhoodSelectOptions.value = filterOptions(neighborhoodOptions.value, value)
		})
	}

	watch(dialogOpen, (value) => {
		ownerRequestId++
		ownerOptionsLoading.value = false
		if (!value) return
		resetForm()
		Promise.allSettled([loadLocationOptions(), loadCatalogTopics()])
		if (props.adminMode) filterOwnerOptions('')
	}, { immediate: true })

	onBeforeUnmount(() => { ownerRequestId++ })

	watch(cityOptions, (options) => {
		citySelectOptions.value = options
	}, { immediate: true })

	watch(neighborhoodOptions, (options) => {
		neighborhoodSelectOptions.value = options
	}, { immediate: true })

	watch(() => form.address.city, () => {
		if (!form.address.city) {
			form.address.neighborhood = ''
			return
		}

		if (form.address.neighborhood && !hasOptionValue(neighborhoodOptions.value, form.address.neighborhood)) {
			form.address.neighborhood = ''
		}
	})
</script>

<template>
	<q-dialog v-model="dialogOpen" :persistent="submitting">
		<q-card class="page-create-dialog">
			<q-card-section class="dialog-head">
				<div>
					<div class="text-h6">{{ props.adminMode ? t('admin.pages.createTitle') : t('pages.setup') }}</div>
					<div v-if="!props.adminMode" class="text-body2 text-grey-7">{{ title }}</div>
				</div>
				<q-btn flat
					round
					icon="close"
					color="dark"
					:disable="submitting"
					:aria-label="t('actions.close')"
					@click="dialogOpen = false"
				/>
			</q-card-section>

			<q-card-section class="page-create-dialog__body">
				<q-banner v-if="pendingClaimReview" rounded class="bg-blue-1 text-dark q-mb-md" role="status" aria-live="polite">
					<strong>{{ t('pages.claimConflictTitle') }}</strong>
					<div>{{ t('pages.claimConflictPending') }}</div>
				</q-banner>
				<q-form ref="formRef" greedy class="page-create-form" @submit.prevent="submit()">
					<q-select
						v-if="props.adminMode"
						v-model="selectedOwner"
						outlined
						clearable
						use-input
						input-debounce="300"
						:options="ownerOptions"
						:loading="ownerOptionsLoading"
						:disable="saving"
						:label="t('admin.pages.chooseOwner')"
						:hint="t('admin.pages.createOwnerHint')"
						:error="ownerOptionsError"
						:error-message="t('admin.pages.ownerOptionsFailed')"
						@filter="filterOwnerOptions"
					>
						<template #no-option>
							<q-item><q-item-section>{{ t('admin.pages.noOwnerOptions') }}</q-item-section></q-item>
						</template>
					</q-select>
					<q-input v-model="form.name" :disable="saving" outlined :label="requiredLabel('pages.name')" :rules="[requiredRule]" />
					<q-input
						v-model="form.public_description"
						:disable="saving"
						outlined
						type="textarea"
						autogrow
						:label="t('pages.description')"
					/>
					<CatalogCategorySelect
						v-model="form.category_key"
						:groups="catalogGroups"
						:scope="pageCatalogScope"
						:disabled="saving"
						required
						:label="requiredLabel('catalog.category')"
					/>

					<q-input
						v-if="props.adminMode"
						v-model="form.website"
						outlined
						clearable
						inputmode="url"
						:disable="saving"
						:label="t('pages.website')"
						:rules="[websiteRule]"
					/>

					<section class="presence-segment">
						<div class="presence-segment__title">{{ t('pages.sections.contact') }}</div>
						<div class="row q-col-gutter-md">
							<div class="col-12 col-md-4">
								<q-input v-model="form.phone" :disable="saving" outlined :label="contactFieldLabel('pages.tel')" :rules="optionalAdminRules()" />
							</div>
							<div class="col-12 col-md-4">
								<q-input v-model="form.contact_email"
									:disable="saving"
									outlined
									type="email"
									:label="contactFieldLabel('pages.email')"
									:rules="props.adminMode ? [optionalEmailRule] : [requiredRule]"
								/>
							</div>
							<div class="col-12 col-md-4">
								<q-input v-model="form.whatsapp" :disable="saving" outlined :label="t('pages.whatsapp')" />
							</div>
						</div>
					</section>

					<section class="presence-segment">
						<div class="presence-segment__title">{{ t('pages.sections.address') }}</div>
						<div class="row q-col-gutter-md">
							<div class="col-12 col-md-4">
								<q-input v-model="form.address.street" :disable="saving" outlined :label="contactFieldLabel('pages.street')" :rules="optionalAdminRules()" />
							</div>
							<div class="col-12 col-md-2">
								<q-input v-model="form.address.number" :disable="saving" outlined :label="contactFieldLabel('pages.number')" :rules="optionalAdminRules()" />
							</div>
							<div class="col-12 col-md-3">
								<q-select v-model="form.address.city"
									outlined
									clearable
									emit-value
									map-options
									use-input
									hide-selected
									fill-input
									input-debounce="0"
									new-value-mode="add-unique"
									:options="citySelectOptions"
									:disable="saving"
									:label="contactFieldLabel('pages.city')"
									:rules="optionalAdminRules()"
									@filter="filterCityOptions"
									@new-value="addOption"
								/>
							</div>
							<div class="col-12 col-md-3">
								<q-select v-model="form.address.neighborhood"
									outlined
									clearable
									emit-value
									map-options
									use-input
									hide-selected
									fill-input
									input-debounce="0"
									new-value-mode="add-unique"
									:options="neighborhoodSelectOptions"
									:label="t('auth.neighborhood')"
									:disable="saving || !form.address.city"
									@filter="filterNeighborhoodOptions"
									@new-value="addOption"
								/>
							</div>
						</div>
					</section>

					<section v-if="props.adminMode" class="presence-segment">
						<div class="presence-segment__title">{{ t('pages.sections.socials') }}</div>
						<div class="social-fields">
							<q-input v-model="form.socials.facebook" outlined :label="t('pages.socials.facebook')" :disable="saving" />
							<q-input v-model="form.socials.instagram" outlined :label="t('pages.socials.instagram')" :disable="saving" />
							<q-input v-model="form.socials.tiktok" outlined :label="t('pages.socials.tiktok')" :disable="saving" />
							<q-input v-model="form.socials.x" outlined :label="t('pages.socials.x')" :disable="saving" />
							<q-input v-model="form.socials.telegram" outlined :label="t('pages.socials.telegram')" :disable="saving" />
						</div>
					</section>

					<section v-if="props.adminMode" class="presence-segment">
						<div class="presence-segment__title">{{ t('pages.sections.openingHours') }}</div>
						<div class="hours-grid">
							<div v-for="item in form.opening_hours" :key="item.weekday" class="hours-row">
								<div class="hours-row__day">{{ t(`pages.weekdays.${item.weekday}`) }}</div>
								<q-toggle v-model="item.is_open" color="primary" :label="item.is_open ? t('pages.open') : t('pages.closed')" :disable="saving" @update:model-value="openingHoursEdited = true" />
								<q-input v-model="item.opens_at"
									outlined
									type="time"
									:disable="saving || !item.is_open"
									:label="t('pages.opensAt')"
									:rules="item.is_open ? [requiredRule] : []"
									@update:model-value="openingHoursEdited = true"
								/>
								<q-input v-model="item.closes_at"
									outlined
									type="time"
									:disable="saving || !item.is_open"
									:label="t('pages.closesAt')"
									:rules="item.is_open ? [requiredRule] : []"
									@update:model-value="openingHoursEdited = true"
								/>
							</div>
						</div>
					</section>

					<BusinessDetailsFields
						v-if="props.type === 'business'"
						ref="businessDetailsRef"
						v-model:service-areas="form.service_areas"
						v-model:specialties="form.specialties"
						:city-options="cityOptions"
						:disabled="saving"
					/>

					<div class="upload-row">
						<q-file v-model="form.logo"
							outlined
							clearable
							:accept="IMAGE_ACCEPT"
							:disable="saving"
							:display-value="logoDisplayName"
							:label="t('pages.logo')"
						/>
						<q-file v-model="form.banner"
							outlined
							clearable
							:accept="IMAGE_ACCEPT"
							:disable="saving"
							:display-value="bannerDisplayName"
							:label="t('pages.banner')"
						/>
					</div>

					<section v-if="props.adminMode" class="presence-segment">
						<div class="presence-segment__title">{{ t('pages.palette') }}</div>
						<div class="palette-grid">
							<button v-for="palette in presencePalettes"
								:key="palette.key"
								type="button"
								class="palette-card"
								:class="{ 'palette-card--active': form.palette_key === palette.key }"
								:aria-pressed="form.palette_key === palette.key"
								:disabled="saving"
								@click="form.palette_key = palette.key"
							>
								<span class="palette-card__swatch" :style="{ background: palette.hero }" />
								<span>{{ t(palette.nameKey) }}</span>
							</button>
						</div>
					</section>

					<section v-if="props.adminMode" class="presence-segment">
						<div class="presence-segment__title">{{ t('pages.modules') }}</div>
						<div class="feature-options">
							<q-toggle v-model="form.features.store" :label="t('businessFeatures.store')" :disable="saving" />
							<q-toggle v-model="form.features.services" :label="t('businessFeatures.services')" :disable="saving" />
							<q-toggle v-model="form.features.price_list" :label="t('businessFeatures.priceList')" :disable="saving" />
						</div>
					</section>

					<div class="page-create-form__actions">
						<q-btn rounded
							unelevated
							color="primary"
							type="button"
							:loading="submitting"
							:label="t(props.adminMode ? 'admin.pages.createSubmit' : 'pages.saveSettings')"
							@click="submit"
						/>
					</div>
				</q-form>
			</q-card-section>
		</q-card>
	</q-dialog>
</template>

<style scoped lang="scss">
.page-create-dialog {
  display: flex;
  flex-direction: column;
  width: min(980px, calc(100vw - 24px));
  max-width: 980px;
  max-height: calc(100vh - 32px);
  border-radius: 30px;
  background: #f9f2eb;
}

.dialog-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

.page-create-dialog__body {
  overflow-y: auto;
  padding-top: 0;
}

.page-create-form {
  display: grid;
  gap: 14px;
}

.presence-segment {
  display: grid;
  gap: 14px;
  padding: 18px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.78);
}

.presence-segment__title {
  color: #151f2d;
  font-size: 15px;
  font-weight: 700;
}

.social-fields,
.hours-grid,
.palette-grid {
  display: grid;
  gap: 12px;
}

.social-fields { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.palette-grid { grid-template-columns: repeat(4, minmax(0, 1fr)); }

.hours-row {
  display: grid;
  grid-template-columns: minmax(88px, 1fr) minmax(100px, 1fr) repeat(2, minmax(0, 1.4fr));
  gap: 12px;
  align-items: center;
}

.hours-row__day { font-weight: 600; }
.feature-options { display: flex; flex-wrap: wrap; gap: 12px; }

.palette-card {
  display: grid;
  gap: 8px;
  padding: 10px;
  border: 2px solid rgba(17, 34, 45, 0.12);
  border-radius: 14px;
  background: #fff;
  text-align: start;
  color: #151f2d;
  cursor: pointer;
}

.palette-card--active { border-color: var(--q-primary); }
.palette-card:focus-visible { outline: 3px solid var(--q-primary); outline-offset: 2px; }
.palette-card:disabled { opacity: 0.6; cursor: default; }
.palette-card__swatch { height: 40px; border-radius: 8px; }

.upload-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  align-items: start;
}

.page-create-form__actions {
  display: flex;
  justify-content: center;
  padding-top: 8px;
}

@media (max-width: 700px) {
  .page-create-dialog {
    width: calc(100vw - 20px);
    max-height: calc(100dvh - 20px);
    border-radius: 22px;
  }

  .page-create-dialog__body {
    padding-inline: 14px;
  }

  .presence-segment {
    padding: 14px;
    border-radius: 16px;
  }

  .social-fields { grid-template-columns: 1fr; }
  .palette-grid,
  .hours-row { grid-template-columns: repeat(2, minmax(0, 1fr)); }

  .upload-row {
    grid-template-columns: 1fr;
  }

  .page-create-form__actions .q-btn {
    width: min(260px, 100%);
  }
}
</style>
