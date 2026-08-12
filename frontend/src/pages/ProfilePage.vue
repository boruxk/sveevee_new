<script setup>
	import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useAppStore } from '@/stores/app'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { deleteProfilePhoto, fetchProfile, updateProfile, uploadProfilePhoto } from '@/services/api/profile'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { IMAGE_ACCEPT, imageUploadDisplayName } from '@/utils/imageUploads'
	import LocaleSwitcher from '@/components/LocaleSwitcher.vue'

	const { t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const appStore = useAppStore()
	const loading = ref(false)
	const saving = ref(false)
	const photoDeleting = ref(false)
	const formRef = ref(null)
	const photo = ref(null)
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
	const form = reactive({
		email: '',
		given_name: '',
		family_name: '',
		phone: '',
		city: '',
		neighborhood: '',
		locale: 'he'
	})
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		addOption,
		filterOptions,
		hasOptionValue
	} = useLocationOptions(toRef(form, 'city'))
	const photoDisplayName = computed(() => imageUploadDisplayName(
		photo.value,
		authStore.user?.profile?.photo_url,
		authStore.user?.profile?.photo_name
	))
	const hasStoredPhoto = computed(() => Boolean(authStore.user?.profile?.photo_url) && !photo.value)
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)

	function hydrate(profile) {
		form.email = profile?.email || authStore.user?.email || ''
		form.given_name = authStore.user?.given_name || ''
		form.family_name = authStore.user?.family_name || ''
		form.phone = profile?.phone || ''
		form.city = profile?.city || ''
		form.neighborhood = profile?.neighborhood || ''
		form.locale = profile?.locale || authStore.user?.locale || appStore.locale
	}

	async function load() {
		loading.value = true
		try {
			await authStore.refreshUser()
			const { data } = await fetchProfile()
			hydrate(data.data)
		} finally {
			loading.value = false
		}
	}

	async function save() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		saving.value = true
		try {
			await updateProfile(form)
			rememberLocation(form.city, form.neighborhood)
			if (photo.value) {
				await uploadProfilePhoto(photo.value)
				photo.value = null
			}
			await authStore.refreshUser()
			hydrate(authStore.user.profile)
			$q.notify({ type: 'positive', message: t('profile.saved') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('profile.saveFailed')) })
		} finally {
			saving.value = false
		}
	}

	async function deletePhoto() {
		photoDeleting.value = true
		try {
			await deleteProfilePhoto()
			photo.value = null
			await authStore.refreshUser()
			hydrate(authStore.user.profile)
			$q.notify({ type: 'positive', message: t('profile.saved') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('profile.saveFailed')) })
		} finally {
			photoDeleting.value = false
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

	watch(cityOptions, (options) => {
		citySelectOptions.value = options
	}, { immediate: true })

	watch(neighborhoodOptions, (options) => {
		neighborhoodSelectOptions.value = options
	}, { immediate: true })

	watch(() => form.city, () => {
		if (!form.city) {
			form.neighborhood = ''
			return
		}

		if (form.neighborhood && !hasOptionValue(neighborhoodOptions.value, form.neighborhood)) {
			form.neighborhood = ''
		}
	})

	onMounted(async() => {
		await Promise.all([load(), loadLocationOptions()])
		citySelectOptions.value = cityOptions.value
		neighborhoodSelectOptions.value = neighborhoodOptions.value
	})
</script>

<template>
	<q-page padding class="profile-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<div>
					<h1 class="soz-page-title">{{ t('profile.title') }}</h1>
					<p>{{ t('profile.subtitle') }}</p>
				</div>
			</section>

			<section class="soz-section-card profile-panel q-mt-lg">
				<q-form v-if="!loading" ref="formRef" greedy class="column q-gutter-md q-pl-md q-pt-lg" @submit.prevent="save()">
					<div class="row q-col-gutter-md q-pb-md">
						<q-input class="col-12 col-md-4"
							v-model="form.email"
							outlined
							type="email"
							:label="requiredLabel('auth.email')"
							:rules="[requiredRule]"
						/>
						<q-input class="col-12 col-md-4"
							v-model="form.given_name"
							outlined
							:label="requiredLabel('auth.givenName')"
							:rules="[requiredRule]"
						/>
						<q-input class="col-12 col-md-4"
							v-model="form.family_name"
							outlined
							:label="requiredLabel('auth.familyName')"
							:rules="[requiredRule]"
						/>
					</div>
					<div class="row q-col-gutter-md q-pb-md">
						<q-input class="col-12 col-md-4" v-model="form.phone" outlined :label="t('auth.phone')" />
						<q-select class="col-12 col-md-4"
							v-model="form.city"
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
							:label="requiredLabel('auth.city')"
							:rules="[requiredRule]"
							@filter="filterCityOptions"
							@new-value="addOption"
						/>
						<q-select class="col-12 col-md-4"
							v-model="form.neighborhood"
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
							:disable="!form.city"
							@filter="filterNeighborhoodOptions"
							@new-value="addOption"
						/>
					</div>
					<div class="row q-col-gutter-md q-pb-md">
						<div class="col-12 col-md-6 profile-language-field">
							<div class="profile-language-field__label">{{ t('profile.languages') }}</div>
							<LocaleSwitcher persist class="profile-locale-switcher" @update:model-value="form.locale = $event" />
						</div>
						<q-file class="col-12 col-md-6"
							v-model="photo"
							outlined
							clearable
							:accept="IMAGE_ACCEPT"
							:display-value="photoDisplayName"
							:label="t('profile.photo')"
						>
							<template #append>
								<q-btn
									v-if="hasStoredPhoto"
									flat
									round
									dense
									color="negative"
									icon="delete"
									:loading="photoDeleting"
									:aria-label="t('actions.delete')"
									@click.stop.prevent="deletePhoto"
								>
									<q-tooltip>{{ t('actions.delete') }}</q-tooltip>
								</q-btn>
							</template>
						</q-file>
					</div>
					<q-btn class="form-submit"
						color="primary"
						unelevated
						rounded
						type="submit"
						icon="save"
						:loading="saving"
						:label="t('actions.save')"
					/>
				</q-form>
				<q-spinner v-else color="primary" />
			</section>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.profile-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.page-head,
.profile-panel {
  padding: 28px;
}

.form-submit {
  margin-inline-start: 0 !important;
}

.profile-language-field {
  display: grid;
  align-content: center;
  min-height: 50px;
  gap: 6px;
}

.profile-language-field__label {
  color: var(--soz-primary-deep);
  font-size: 13px;
  font-weight: 600;
  line-height: 1.2;
}

.profile-locale-switcher {
  width: min(160px, 100%);
}

@media (max-width: 700px) {
  .profile-page {
    padding-inline: 10px;
  }

  .page-head,
  .profile-panel {
    padding: 20px;
  }

  .profile-panel :deep(.q-form) {
    padding-top: 10px !important;
    padding-right: 0 !important;
    padding-left: 0 !important;
  }

  .form-submit {
    width: 100%;
  }

  .profile-locale-switcher {
    width: 100%;
  }
}
</style>
