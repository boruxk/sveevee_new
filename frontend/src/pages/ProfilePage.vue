<script setup>
	import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useAppStore } from '@/stores/app'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { deleteProfilePhoto, fetchProfile, updateProfile, updateProfilePassword, uploadProfilePhoto } from '@/services/api/profile'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { IMAGE_ACCEPT, imageUploadDisplayName } from '@/utils/imageUploads'
	import PasswordInput from '@/components/PasswordInput.vue'
	import { buildUserTypeSelectOptions } from '@/constants/userTypes'

	const { t, locale } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const appStore = useAppStore()
	const loading = ref(false)
	const saving = ref(false)
	const passwordSaving = ref(false)
	const photoDeleting = ref(false)
	const formRef = ref(null)
	const passwordFormRef = ref(null)
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
		user_type: '',
		locale: 'he'
	})
	const passwordForm = reactive({
		current_password: '',
		password: '',
		password_confirmation: ''
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
	const flag = (...points) => String.fromCodePoint(...points)
	const localeOptions = computed(() => [
		{ label: `${flag(0x1f1ee, 0x1f1f1)} ${t('languages.he')}`, title: t('languages.he'), value: 'he', flag: flag(0x1f1ee, 0x1f1f1) },
		{ label: `${flag(0x1f1fa, 0x1f1f8)} ${t('languages.en')}`, title: t('languages.en'), value: 'en', flag: flag(0x1f1fa, 0x1f1f8) },
		{ label: `${flag(0x1f1f7, 0x1f1fa)} ${t('languages.ru')}`, title: t('languages.ru'), value: 'ru', flag: flag(0x1f1f7, 0x1f1fa) },
		{ label: `${flag(0x1f1eb, 0x1f1f7)} ${t('languages.fr')}`, title: t('languages.fr'), value: 'fr', flag: flag(0x1f1eb, 0x1f1f7) }
	])
	const userTypeOptions = computed(() => buildUserTypeSelectOptions(locale.value))
	const selectedUserTypeOption = computed(() => (
		userTypeOptions.value.find((option) => option.value === form.user_type) || null
	))
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)

	function hydrate(profile) {
		form.email = profile?.email || authStore.user?.email || ''
		form.given_name = authStore.user?.given_name || ''
		form.family_name = authStore.user?.family_name || ''
		form.phone = profile?.phone || ''
		form.city = profile?.city || ''
		form.neighborhood = profile?.neighborhood || ''
		form.user_type = profile?.user_type || ''
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

	async function savePassword() {
		if (!(await validateRequiredForm(passwordFormRef))) {
			return
		}

		passwordSaving.value = true

		try {
			await updateProfilePassword(passwordForm)
			passwordForm.current_password = ''
			passwordForm.password = ''
			passwordForm.password_confirmation = ''
			$q.notify({ type: 'positive', message: t('profile.passwordSaved') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('profile.passwordSaveFailed')) })
		} finally {
			passwordSaving.value = false
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
					<div class="row q-col-gutter-md q-pb-md profile-user-settings-row">
						<q-select
							v-model="form.locale"
							class="col-12 col-md-3 profile-locale-select"
							outlined
							emit-value
							map-options
							options-dense
							popup-content-class="profile-locale-select-menu"
							popup-content-style="max-height: min(72vh, 520px); overflow-y: auto;"
							:options="localeOptions"
							:label="t('profile.languages')"
						>
							<template #option="scope">
								<q-item v-bind="scope.itemProps">
									<q-item-section avatar>
										<span class="profile-locale-option__flag">{{ scope.opt.flag }}</span>
									</q-item-section>
									<q-item-section>
										<q-item-label>{{ scope.opt.title }}</q-item-label>
									</q-item-section>
								</q-item>
							</template>
						</q-select>
						<q-select
							v-model="form.user_type"
							class="col-12 col-md-4"
							outlined
							clearable
							emit-value
							map-options
							options-dense
							popup-content-class="user-type-select-menu"
							popup-content-style="max-height: min(72vh, 520px); overflow-y: auto;"
							:virtual-scroll-item-size="46"
							:virtual-scroll-slice-size="36"
							:options="userTypeOptions"
							option-disable="disable"
							:label="t('profile.userType')"
						>
							<template #selected>
								<div v-if="selectedUserTypeOption" class="profile-select-value">
									<span class="user-type-option__dot" :style="{ backgroundColor: selectedUserTypeOption.color }" />
									<span>{{ selectedUserTypeOption.label }}</span>
								</div>
							</template>
							<template #option="scope">
								<q-item v-bind="scope.itemProps" :class="{ 'user-type-option--group': scope.opt.group }">
									<q-item-section avatar>
										<span class="user-type-option__dot" :style="{ backgroundColor: scope.opt.color }" />
									</q-item-section>
									<q-item-section>
										<q-item-label>{{ scope.opt.label }}</q-item-label>
									</q-item-section>
								</q-item>
							</template>
						</q-select>
						<q-file class="col-12 col-md-5"
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
				<div v-if="!loading" class="profile-password-gap" />
				<q-form v-if="!loading"
					ref="passwordFormRef"
					greedy
					class="profile-password-form column q-gutter-md q-pl-md"
					@submit.prevent="savePassword()"
				>
					<div class="profile-password-intro">
						<h2>{{ t('profile.passwordTitle') }}</h2>
						<p>{{ t('profile.passwordBody') }}</p>
					</div>
					<div class="row q-col-gutter-md profile-password-fields">
						<PasswordInput class="col-12 col-md-4"
							v-model="passwordForm.current_password"
							autocomplete="current-password"
							:label="requiredLabel('auth.currentPassword')"
							:rules="[requiredRule]"
						/>
						<PasswordInput class="col-12 col-md-4"
							v-model="passwordForm.password"
							autocomplete="new-password"
							:label="requiredLabel('auth.newPassword')"
							:rules="[requiredRule]"
						/>
						<PasswordInput class="col-12 col-md-4"
							v-model="passwordForm.password_confirmation"
							autocomplete="new-password"
							:label="requiredLabel('auth.passwordConfirmation')"
							:rules="[requiredRule]"
						/>
					</div>
					<q-btn class="form-submit"
						color="primary"
						unelevated
						rounded
						type="submit"
						icon="lock_reset"
						:loading="passwordSaving"
						:label="t('profile.changePassword')"
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

.page-head h1 {
  margin: 0;
}

.form-submit {
  margin-inline-start: 0 !important;
}

.profile-password-gap {
  height: 52px;
}

.profile-password-form {
  gap: 0;

  h2 {
    margin: 0 0 14px;
    font-size: 1.35rem;
    line-height: 1.25;
  }

  p {
    margin: 0;
    color: var(--soz-muted);
    line-height: 1.55;
  }
}

.profile-password-fields {
  margin-top: 30px;
}

.profile-password-form .form-submit {
  margin-top: 26px;
}

.profile-user-settings-row {
  align-items: center;
}

.profile-select-value {
  display: inline-flex;
  align-items: center;
  min-width: 0;
  gap: 8px;
  overflow: hidden;
  white-space: nowrap;
  text-overflow: ellipsis;
}

.profile-select-value span:last-child {
  overflow: hidden;
  text-overflow: ellipsis;
}

.user-type-option--group {
  min-height: 44px;
  color: rgba(17, 34, 45, 0.9);
  font-size: 15px;
  font-weight: 900;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.profile-locale-option__flag {
  display: block;
  width: 24px;
  font-size: 20px;
  line-height: 1;
  text-align: center;
}

.user-type-option__dot {
  display: block;
  width: 12px;
  height: 12px;
  border-radius: 50%;
}

.user-type-option--group .user-type-option__dot {
  width: 15px;
  height: 15px;
  box-shadow: 0 6px 14px rgba(17, 34, 45, 0.18);
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

  .profile-password-form {
    padding-right: 0 !important;
    padding-left: 0 !important;
  }

  .form-submit {
    width: 100%;
  }

  .profile-password-gap {
    height: 40px;
  }

  .profile-password-fields {
    margin-top: 22px;
  }

}

@media (min-width: 701px) {
  .form-submit {
    align-self: center;
    width: 33.333%;
  }
}
</style>
