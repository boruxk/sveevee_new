<script setup>
	import { computed, reactive, ref } from 'vue'
	import { useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useAppStore } from '@/stores/app'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import GoogleAuthButton from '@/components/GoogleAuthButton.vue'
	import PasswordInput from '@/components/PasswordInput.vue'
	import { getLegalDocument } from '@/constants/legalDocuments'

	const { t, locale } = useI18n()
	const $q = useQuasar()
	const router = useRouter()
	const authStore = useAuthStore()
	const appStore = useAppStore()
	const formRef = ref(null)
	const form = reactive({
		email: '',
		password: '',
		password_confirmation: '',
		given_name: '',
		family_name: '',
		consented: false
	})
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const termsTitle = computed(() => getLegalDocument('terms', locale.value).title)
	const privacyTitle = computed(() => getLegalDocument('privacy', locale.value).title)
	const consentRule = (value) => value === true || t('auth.consentRequired')

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		try {
			await authStore.register({ ...form, locale: appStore.locale })
			router.replace({ name: 'profile', query: { complete: '1' } })
		} catch {
			$q.notify({ type: 'negative', message: t('auth.registerFailed') })
		}
	}
</script>

<template>
	<q-page padding class="auth-page">
		<div class="auth-shell">
			<section class="soz-section-card auth-panel">
				<div class="auth-panel__inner">
					<h1 class="soz-page-title">{{ t('auth.registerTitle') }}</h1>
					<p class="auth-welcome">{{ t('auth.registerWelcome') }}</p>
					<GoogleAuthButton class="auth-google" />
					<div class="auth-divider">{{ t('auth.or') }}</div>
					<q-form ref="formRef" greedy class="register-form" @submit.prevent="submit()">
						<div class="register-form__row">
							<q-input class="col-12 col-md-4"
								v-model="form.email"
								outlined
								type="email"
								autocomplete="email"
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
						<div class="register-form__row">
							<PasswordInput class="col-12 col-md-6"
								v-model="form.password"
								autocomplete="new-password"
								:label="requiredLabel('auth.password')"
								:rules="[requiredRule]"
							/>
							<PasswordInput class="col-12 col-md-6"
								v-model="form.password_confirmation"
								autocomplete="new-password"
								:label="requiredLabel('auth.passwordConfirmation')"
								:rules="[requiredRule]"
							/>
						</div>
						<q-field
							v-model="form.consented"
							borderless
							dense
							class="register-consent-field"
							:rules="[consentRule]"
						>
							<template #control>
								<div class="register-consent">
									<q-checkbox
										v-model="form.consented"
										color="primary"
										:aria-label="t('auth.consentRequired')"
									/>
									<i18n-t keypath="auth.registerConsent" tag="span" class="register-consent__text">
										<template #terms>
											<router-link :to="{ name: 'terms' }" target="_blank" rel="noopener">{{ termsTitle }}</router-link>
										</template>
										<template #privacy>
											<router-link :to="{ name: 'privacy' }" target="_blank" rel="noopener">{{ privacyTitle }}</router-link>
										</template>
									</i18n-t>
								</div>
							</template>
						</q-field>
						<q-btn class="form-submit"
							color="primary"
							unelevated
							rounded
							type="submit"
							icon="person_add"
							:loading="authStore.loading"
							:label="t('nav.register')"
						/>
					</q-form>
				</div>
			</section>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.auth-page {
  display: block;
}

.auth-shell {
  display: grid;
  place-items: start center;
  max-width: 1280px;
  width: 100%;
  margin: 0 auto;
}

.auth-panel {
  width: 100%;
  padding: 28px;
}

.auth-panel__inner {
  width: min(1040px, 100%);
  margin: 0 auto;
}

.auth-panel__inner h1 {
  margin-bottom: 7px;
}

.auth-welcome {
  max-width: 620px;
  margin: 0 0 22px;
  color: rgba(17, 34, 45, 0.66);
  font-size: 0.98rem;
  line-height: 1.55;
}

.auth-google {
  margin-bottom: 20px;
}

.auth-divider {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  gap: 14px;
  align-items: center;
  margin: 0 0 24px;
  color: rgba(17, 34, 45, 0.5);
  font-size: 1.08rem;
  font-weight: 700;
  text-align: center;
}

.auth-divider::before,
.auth-divider::after {
  display: block;
  height: 1px;
  background: rgba(17, 34, 45, 0.14);
  content: "";
}

.register-form {
  display: grid;
  gap: 16px;
  width: 100%;
  min-width: 0;
}

.register-form__row {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: 16px;
  width: 100%;
  min-width: 0;
}

.register-form__row > :deep(.col-12) {
  grid-column: 1 / -1;
  min-width: 0;
}

.register-form__row > :deep(.col-md-4) {
  grid-column: span 4;
}

.register-form__row > :deep(.col-md-6) {
  grid-column: span 6;
}

.form-submit {
  width: min(220px, 100%);
  margin: 0 auto !important;
}

.register-consent-field {
  width: min(760px, 100%);
  margin: 0 auto;
}

.register-consent-field :deep(.q-field__control) {
  min-height: 42px;
}

.register-consent {
  display: flex;
  align-items: flex-start;
  justify-content: center;
  gap: 6px;
  width: 100%;
}

.register-consent :deep(.q-checkbox) {
  flex: 0 0 auto;
  margin-top: -7px;
  margin-inline-start: -10px;
}

.register-consent__text {
  color: rgba(17, 34, 45, 0.74);
  font-size: 0.95rem;
  line-height: 1.55;
}

.register-consent__text a {
  color: var(--q-primary);
  font-weight: 700;
  text-decoration: underline;
  text-decoration-thickness: 1px;
  text-underline-offset: 2px;
}

@media (max-width: 700px) {
  .auth-page {
    padding-inline: 10px;
  }

  .auth-panel {
    padding: 20px;
  }

  .auth-shell,
  .auth-panel,
  .auth-panel__inner,
  .register-form {
    width: 100%;
    min-width: 0;
    max-width: 100%;
  }

  .auth-panel {
    overflow: hidden;
  }

  .register-form__row {
    grid-template-columns: 1fr;
    gap: 12px;
    row-gap: 12px;
  }

  .register-form__row > * {
    grid-column: 1 / -1 !important;
    width: 100%;
    min-width: 0;
    max-width: 100%;
  }

  .auth-panel__inner :deep(.q-field),
  .auth-panel__inner :deep(.q-field__inner),
  .auth-panel__inner :deep(.q-field__control) {
    width: 100%;
    min-width: 0;
    max-width: 100%;
  }
}
</style>
