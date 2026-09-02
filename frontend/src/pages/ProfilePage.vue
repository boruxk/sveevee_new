<script setup>
	import { computed, nextTick, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useAppStore } from '@/stores/app'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { useCredentialRules } from '@/composables/useCredentialRules'
	import {
		deleteProfilePhoto,
		fetchProfile,
		sendProfileEmailVerification,
		updateProfile,
		updateProfileEmailPreferences,
		updateProfilePassword,
		uploadProfilePhoto
	} from '@/services/api/profile'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { IMAGE_ACCEPT, imageUploadDisplayName } from '@/utils/imageUploads'
	import PasswordInput from '@/components/PasswordInput.vue'
	import LocaleFlag from '@/components/icons/LocaleFlag.vue'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { CATALOG_SCOPES } from '@/constants/catalogTopics'

	const { t } = useI18n()
	const $q = useQuasar()
	const route = useRoute()
	const router = useRouter()
	const authStore = useAuthStore()
	const appStore = useAppStore()
	const loading = ref(false)
	const saving = ref(false)
	const passwordSaving = ref(false)
	const verificationSending = ref(false)
	const emailPreferenceSaving = ref(false)
	const emailChatNotifications = ref(false)
	const savedEmailChatNotifications = ref(false)
	const savedEmail = ref('')
	const verificationRequested = ref(false)
	const emailVerification = ref({
		status: 'unverified',
		verified_at: null,
		can_resend: true,
		last_sent_at: null
	})
	const photoDeleting = ref(false)
	const formRef = ref(null)
	const cityFieldRef = ref(null)
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
	const allLocaleOptions = computed(() => [
		{ label: t('languages.he'), value: 'he' },
		{ label: t('languages.en'), value: 'en' },
		{ label: t('languages.ru'), value: 'ru' },
		{ label: t('languages.fr'), value: 'fr' }
	])
	const localeOptions = computed(() => allLocaleOptions.value.filter((option) => option.value !== 'ru'))
	const selectedLocale = computed(() => allLocaleOptions.value.find((option) => option.value === form.locale) || {
		label: form.locale,
		value: form.locale
	})
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const hasPassword = computed(() => authStore.user?.has_password !== false)
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const { emailRule, passwordRule, matchingPasswordRule } = useCredentialRules(t)
	const passwordConfirmationRule = matchingPasswordRule(() => passwordForm.password)
	const normalizedFormEmail = computed(() => form.email.trim().toLowerCase())
	const emailMatchesSaved = computed(() => normalizedFormEmail.value === savedEmail.value)
	const effectiveEmailStatus = computed(() => (
		emailMatchesSaved.value ? emailVerification.value.status : 'unverified'
	))
	const emailIsVerified = computed(() => effectiveEmailStatus.value === 'verified')
	const emailStatusIcon = computed(() => ({
		verified: 'verified',
		bounced: 'error',
		unverified: 'mark_email_unread'
	}[effectiveEmailStatus.value] || 'mark_email_unread'))
	const emailStatusColor = computed(() => ({
		verified: 'positive',
		bounced: 'negative',
		unverified: 'warning'
	}[effectiveEmailStatus.value] || 'warning'))
	const emailStatusLabel = computed(() => t(`profile.emailVerification.status.${effectiveEmailStatus.value}`))
	const verificationActionLabel = computed(() => (
		verificationRequested.value || emailVerification.value.last_sent_at ? t('profile.emailVerification.resend') : t('profile.emailVerification.send')
	))
	const emailFeatureTooltip = computed(() => (
		effectiveEmailStatus.value === 'bounced' ? t('profile.emailVerification.bouncedRequirement') : t('profile.emailVerification.required')
	))

	function hydrate(profile) {
		form.email = profile?.email || authStore.user?.email || ''
		savedEmail.value = form.email.trim().toLowerCase()
		form.given_name = authStore.user?.given_name || ''
		form.family_name = authStore.user?.family_name || ''
		form.phone = profile?.phone || ''
		form.city = profile?.city || ''
		form.neighborhood = profile?.neighborhood || ''
		form.user_type = profile?.user_type || ''
		form.locale = profile?.locale || authStore.user?.locale || appStore.locale
		emailVerification.value = profile?.email_verification || {
			status: 'unverified',
			verified_at: null,
			can_resend: true,
			last_sent_at: null
		}
		emailChatNotifications.value = Boolean(profile?.email_chat_notifications)
		savedEmailChatNotifications.value = emailChatNotifications.value
		verificationRequested.value = Boolean(emailVerification.value.last_sent_at)
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

			if (route.query.complete === '1' && authStore.user?.profile_complete) {
				router.push(route.query.redirect || { name: 'home' })
			}
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

	async function sendEmailVerification() {
		if (!emailMatchesSaved.value || !emailVerification.value.can_resend) {
			return
		}

		verificationSending.value = true
		try {
			const { data } = await sendProfileEmailVerification()
			emailVerification.value = data.data?.email_verification || emailVerification.value
			verificationRequested.value = true
			$q.notify({ type: 'positive', message: t('profile.emailVerification.sent') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('profile.emailVerification.sendFailed')) })
		} finally {
			verificationSending.value = false
		}
	}

	async function saveEmailChatNotifications(value) {
		if (!emailIsVerified.value || emailPreferenceSaving.value) {
			emailChatNotifications.value = savedEmailChatNotifications.value
			return
		}

		emailChatNotifications.value = value
		emailPreferenceSaving.value = true
		try {
			const { data } = await updateProfileEmailPreferences(value)
			emailChatNotifications.value = Boolean(data.data?.email_chat_notifications)
			savedEmailChatNotifications.value = emailChatNotifications.value
			await authStore.refreshUser()
			$q.notify({ type: 'positive', message: t('profile.notificationsSaved') })
		} catch (error) {
			emailChatNotifications.value = savedEmailChatNotifications.value
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('profile.notificationsSaveFailed')) })
		} finally {
			emailPreferenceSaving.value = false
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
		await Promise.all([load(), loadLocationOptions(), loadCatalogTopics()])
		citySelectOptions.value = cityOptions.value
		neighborhoodSelectOptions.value = neighborhoodOptions.value

		if (route.query.complete === '1' && !form.city) {
			await nextTick()
			await cityFieldRef.value?.validate()
		}

		if (route.query.emailVerification) {
			const status = route.query.emailVerification
			const positive = status === 'verified'
			$q.notify({
				type: positive ? 'positive' : 'negative',
				message: t(`profile.emailVerification.result.${status}`)
			})
			const query = { ...route.query }
			delete query.emailVerification
			await router.replace({ query })
		}
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
				<div v-if="route.query.complete === '1'" class="profile-completion-banner">
					<q-icon name="info" size="34px" />
					<div>
						<h2>{{ t('profile.completeTitle') }}</h2>
						<p>{{ t('profile.completeBody') }}</p>
					</div>
				</div>
				<q-form v-if="!loading" ref="formRef" greedy class="column q-gutter-md q-pl-md q-pt-lg" @submit.prevent="save()">
					<div class="row q-col-gutter-md q-pb-md">
						<div class="col-12 col-md-4 profile-email-field">
							<q-input
								v-model="form.email"
								outlined
								type="email"
								:label="requiredLabel('auth.email')"
								:rules="[requiredRule, emailRule]"
							/>
							<div class="email-verification-state">
								<span class="email-verification-status" :class="`text-${emailStatusColor}`">
									<q-icon :name="emailStatusIcon" size="18px" />
									{{ emailStatusLabel }}
								</span>
								<q-btn
									v-if="effectiveEmailStatus === 'unverified' && emailMatchesSaved && emailVerification.can_resend"
									flat
									dense
									no-caps
									color="primary"
									icon="mark_email_read"
									:loading="verificationSending"
									:label="verificationActionLabel"
									@click="sendEmailVerification"
								/>
							</div>
						</div>
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
							ref="cityFieldRef"
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
							<template #selected-item>
								<div class="profile-locale-selection">
									<LocaleFlag :locale="selectedLocale.value" :label="selectedLocale.label" />
								</div>
							</template>
							<template #option="scope">
								<q-item v-bind="scope.itemProps" class="profile-locale-option">
									<q-item-section avatar>
										<LocaleFlag :locale="scope.opt.value" :label="scope.opt.label" />
									</q-item-section>
								</q-item>
							</template>
						</q-select>
						<CatalogCategorySelect
							v-model="form.user_type"
							class="col-12 col-md-4"
							:groups="catalogGroups"
							:scope="CATALOG_SCOPES.USERS"
							:label="t('profile.userType')"
						/>
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
				<q-spinner v-if="loading" color="primary" />
			</section>

			<section v-if="!loading" class="soz-section-card profile-notifications-panel q-mt-lg">
				<div class="profile-section-intro">
					<h2>{{ t('profile.notificationsTitle') }}</h2>
				</div>
				<div
					class="notification-toggle-wrap"
					:class="{ 'notification-toggle-wrap--disabled': !emailIsVerified }"
					:tabindex="emailIsVerified ? undefined : 0"
					:aria-disabled="!emailIsVerified"
				>
					<q-toggle
						:model-value="emailChatNotifications"
						color="primary"
						:label="t('profile.chatEmailNotifications')"
						:disable="!emailIsVerified || emailPreferenceSaving"
						@update:model-value="saveEmailChatNotifications"
					/>
					<q-spinner v-if="emailPreferenceSaving" size="20px" color="primary" />
					<q-tooltip v-if="!emailIsVerified">{{ emailFeatureTooltip }}</q-tooltip>
				</div>
			</section>

			<section v-if="!loading" class="soz-section-card profile-password-panel q-mt-lg">
				<q-form v-if="hasPassword"
					ref="passwordFormRef"
					greedy
					class="profile-password-form column q-gutter-md q-pl-md"
					@submit.prevent="savePassword()"
				>
					<div class="profile-password-intro">
						<h2>{{ t('profile.passwordTitle') }}</h2>
						<p>{{ t('profile.passwordBody') }}</p>
						<p class="password-requirements">{{ t('auth.passwordRequirements') }}</p>
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
							:rules="[requiredRule, passwordRule]"
						/>
						<PasswordInput class="col-12 col-md-4"
							v-model="passwordForm.password_confirmation"
							autocomplete="new-password"
							:label="requiredLabel('auth.passwordConfirmation')"
							:rules="[requiredRule, passwordConfirmationRule]"
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
				<div v-else class="profile-password-form column q-gutter-md q-pl-md">
					<div class="profile-password-intro">
						<h2>{{ t('profile.passwordTitle') }}</h2>
						<p>{{ t('profile.googlePasswordBody') }}</p>
					</div>
					<q-btn class="form-submit"
						color="primary"
						unelevated
						rounded
						icon="lock_reset"
						:to="{ name: 'forgot-password' }"
						:label="t('auth.forgotPassword')"
					/>
				</div>
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
.profile-panel,
.profile-notifications-panel,
.profile-password-panel {
  padding: 28px;
}

.page-head h1 {
  margin: 0;
}

.form-submit {
  margin-inline-start: 0 !important;
}

.profile-email-field {
  min-width: 0;
}

.email-verification-state {
  display: flex;
  min-height: 32px;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin-top: -10px;
}

.email-verification-status {
  display: inline-flex;
  min-width: 0;
  align-items: center;
  gap: 6px;
  font-size: 0.84rem;
  font-weight: 700;
}

.profile-section-intro h2 {
  margin: 0 0 18px;
  font-size: 1.35rem;
  line-height: 1.25;
}

.notification-toggle-wrap {
  display: inline-flex;
  min-height: 40px;
  align-items: center;
  gap: 12px;
  outline: none;
}

.notification-toggle-wrap--disabled {
  cursor: help;
}

.notification-toggle-wrap--disabled:focus-visible {
  outline: 2px solid rgba(109, 55, 133, 0.4);
  outline-offset: 4px;
}

.profile-completion-banner {
  display: flex;
  gap: 18px;
  align-items: flex-start;
  margin-bottom: 28px;
  padding: 24px 26px;
  border: 1px solid rgba(43, 123, 180, 0.24);
  border-radius: 24px;
  color: #165178;
  background:
    radial-gradient(circle at top left, rgba(88, 190, 224, 0.16), transparent 40%),
    linear-gradient(135deg, rgba(235, 248, 255, 0.94), rgba(242, 244, 255, 0.92));
  box-shadow: 0 18px 38px rgba(42, 108, 153, 0.12);
}

.profile-completion-banner .q-icon {
  flex: 0 0 auto;
  margin-top: 1px;
}

.profile-completion-banner h2 {
  margin: 0 0 8px;
  font-size: 1.38rem;
  line-height: 1.25;
}

.profile-completion-banner p {
  margin: 0;
  color: rgba(22, 63, 88, 0.82);
  font-size: 1.06rem;
  font-weight: 650;
  line-height: 1.58;
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

.profile-locale-selection {
  display: inline-flex;
  align-items: center;
}

.profile-locale-option :deep(.q-item__section--avatar) {
  align-items: center;
  min-width: 28px;
}

.profile-password-intro .password-requirements {
  margin-top: 8px;
  color: rgba(17, 34, 45, 0.62);
  font-size: 0.86rem;
}

@media (max-width: 700px) {
  .profile-page {
    padding-inline: 10px;
  }

  .page-head,
  .profile-panel,
  .profile-notifications-panel,
  .profile-password-panel {
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
