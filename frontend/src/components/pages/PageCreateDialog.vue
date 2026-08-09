<script setup>
	import { computed, reactive, ref, toRef, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { saveMyPage } from '@/services/api/pages'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { IMAGE_ACCEPT, imageUploadDisplayName } from '@/utils/imageUploads'

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
	const formRef = ref(null)
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
	const form = reactive({
		name: '',
		public_description: '',
		contact_email: '',
		phone: '',
		whatsapp: '',
		address: {
			street: '',
			number: '',
			city: '',
			neighborhood: ''
		},
		opening_hours: DEFAULT_OPENING_HOURS.map((item) => ({ ...item })),
		palette_key: 'amber-dawn',
		logo: null,
		banner: null
	})
	const dialogOpen = computed({
		get: () => props.modelValue,
		set: (value) => emit('update:modelValue', value)
	})
	const title = computed(() => (props.type === 'business' ? t('pages.businessTitle') : t('pages.communityTitle')))
	const logoDisplayName = computed(() => imageUploadDisplayName(form.logo))
	const bannerDisplayName = computed(() => imageUploadDisplayName(form.banner))
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		addOption,
		filterOptions
	} = useLocationOptions(toRef(form.address, 'city'))

	function addressLine(address) {
		return [address.street, address.number, address.neighborhood, address.city].filter(Boolean).join(', ')
	}

	function resetForm() {
		form.name = ''
		form.public_description = ''
		form.contact_email = authStore.user?.email || ''
		form.phone = authStore.user?.profile?.phone || ''
		form.whatsapp = ''
		form.address.street = ''
		form.address.number = ''
		form.address.city = authStore.user?.profile?.city || ''
		form.address.neighborhood = authStore.user?.profile?.neighborhood || ''
		form.opening_hours = DEFAULT_OPENING_HOURS.map((item) => ({ ...item }))
		form.palette_key = 'amber-dawn'
		form.logo = null
		form.banner = null
	}

	function pagePayload() {
		return {
			name: form.name.trim(),
			public_description: form.public_description.trim(),
			contact_email: form.contact_email.trim(),
			phone: form.phone.trim(),
			address: addressLine(form.address),
			palette_key: form.palette_key,
			setup: {
				contact: {
					tel: form.phone.trim() || null,
					email: form.contact_email.trim() || null,
					whatsapp: form.whatsapp.trim() || null
				},
				address: {
					street: form.address.street.trim() || null,
					number: form.address.number.trim() || null,
					city: form.address.city.trim() || null,
					neighborhood: form.address.neighborhood.trim() || null
				},
				opening_hours: form.opening_hours.map((item) => ({
					weekday: item.weekday,
					is_open: item.is_open,
					opens_at: item.is_open ? item.opens_at || null : null,
					closes_at: item.is_open ? item.closes_at || null : null
				}))
			},
			logo: form.logo,
			banner: form.banner
		}
	}

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		saving.value = true
		try {
			const { data } = await saveMyPage(props.type, pagePayload())
			rememberLocation(form.address.city, form.address.neighborhood)
			await authStore.refreshUser()
			$q.notify({ type: 'positive', message: t('pages.saved') })
			dialogOpen.value = false
			emit('created', data.data)
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('pages.saveFailed')) })
		} finally {
			saving.value = false
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

	watch(dialogOpen, async(value) => {
		if (!value) {
			return
		}

		resetForm()
		await loadLocationOptions()
		citySelectOptions.value = cityOptions.value
		neighborhoodSelectOptions.value = neighborhoodOptions.value
	})

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

		if (form.address.neighborhood && !neighborhoodOptions.value.includes(form.address.neighborhood)) {
			form.address.neighborhood = ''
		}
	})
</script>

<template>
	<q-dialog v-model="dialogOpen">
		<q-card class="page-create-dialog">
			<q-card-section class="dialog-head">
				<div>
					<div class="text-h6">{{ t('pages.setup') }}</div>
					<div class="text-body2 text-grey-7">{{ title }}</div>
				</div>
				<q-btn flat round icon="close" color="dark" v-close-popup />
			</q-card-section>

			<q-card-section class="page-create-dialog__body">
				<q-form ref="formRef" greedy class="page-create-form" @submit.prevent="submit()">
					<q-input v-model="form.name" outlined :label="requiredLabel('pages.name')" :rules="[requiredRule]" />
					<q-input
						v-model="form.public_description"
						outlined
						type="textarea"
						autogrow
						:label="t('pages.description')"
					/>

					<section class="presence-segment">
						<div class="presence-segment__title">{{ t('pages.sections.contact') }}</div>
						<div class="row q-col-gutter-md">
							<div class="col-12 col-md-4">
								<q-input v-model="form.phone" outlined :label="requiredLabel('pages.tel')" :rules="[requiredRule]" />
							</div>
							<div class="col-12 col-md-4">
								<q-input v-model="form.contact_email" outlined type="email" :label="requiredLabel('pages.email')" :rules="[requiredRule]" />
							</div>
							<div class="col-12 col-md-4">
								<q-input v-model="form.whatsapp" outlined :label="t('pages.whatsapp')" />
							</div>
						</div>
					</section>

					<section class="presence-segment">
						<div class="presence-segment__title">{{ t('pages.sections.address') }}</div>
						<div class="row q-col-gutter-md">
							<div class="col-12 col-md-4">
								<q-input v-model="form.address.street" outlined :label="requiredLabel('pages.street')" :rules="[requiredRule]" />
							</div>
							<div class="col-12 col-md-2">
								<q-input v-model="form.address.number" outlined :label="requiredLabel('pages.number')" :rules="[requiredRule]" />
							</div>
							<div class="col-12 col-md-3">
								<q-select v-model="form.address.city"
									outlined
									clearable
									use-input
									hide-selected
									fill-input
									input-debounce="0"
									new-value-mode="add-unique"
									:options="citySelectOptions"
									:label="requiredLabel('pages.city')"
									:rules="[requiredRule]"
									@filter="filterCityOptions"
									@new-value="addOption"
								/>
							</div>
							<div class="col-12 col-md-3">
								<q-select v-model="form.address.neighborhood"
									outlined
									clearable
									use-input
									hide-selected
									fill-input
									input-debounce="0"
									new-value-mode="add-unique"
									:options="neighborhoodSelectOptions"
									:label="t('auth.neighborhood')"
									:disable="!form.address.city"
									@filter="filterNeighborhoodOptions"
									@new-value="addOption"
								/>
							</div>
						</div>
					</section>

					<div class="upload-row">
						<q-file v-model="form.logo"
							outlined
							clearable
							:accept="IMAGE_ACCEPT"
							:display-value="logoDisplayName"
							:label="t('pages.logo')"
						/>
						<q-file v-model="form.banner"
							outlined
							clearable
							:accept="IMAGE_ACCEPT"
							:display-value="bannerDisplayName"
							:label="t('pages.banner')"
						/>
					</div>

					<div class="page-create-form__actions">
						<q-btn rounded
							unelevated
							color="primary"
							type="button"
							:loading="saving"
							:label="t('pages.saveSettings')"
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

  .upload-row {
    grid-template-columns: 1fr;
  }

  .page-create-form__actions .q-btn {
    width: min(260px, 100%);
  }
}
</style>
