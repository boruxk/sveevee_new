<script setup>
	import { reactive, ref } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { resetPassword } from '@/services/api/auth'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import PasswordInput from '@/components/PasswordInput.vue'

	const { t } = useI18n()
	const $q = useQuasar()
	const route = useRoute()
	const router = useRouter()
	const formRef = ref(null)
	const saving = ref(false)
	const form = reactive({
		email: String(route.query.email || ''),
		password: '',
		password_confirmation: ''
	})
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		saving.value = true

		try {
			await resetPassword({
				...form,
				token: route.params.token
			})
			$q.notify({ type: 'positive', message: t('auth.passwordResetSaved') })
			router.push({ name: 'login' })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('auth.resetFailed')) })
		} finally {
			saving.value = false
		}
	}
</script>

<template>
	<q-page padding class="auth-page">
		<div class="auth-shell">
			<section class="soz-section-card auth-panel">
				<div class="auth-panel__inner">
					<h1 class="soz-page-title">{{ t('auth.resetPasswordTitle') }}</h1>
					<p>{{ t('auth.resetPasswordBody') }}</p>
					<q-form ref="formRef" greedy class="column q-gutter-md" @submit.prevent="submit()">
						<q-input
							v-model="form.email"
							outlined
							type="email"
							autocomplete="email"
							:label="requiredLabel('auth.email')"
							:rules="[requiredRule]"
						/>
						<PasswordInput
							v-model="form.password"
							autocomplete="new-password"
							:label="requiredLabel('auth.newPassword')"
							:rules="[requiredRule]"
						/>
						<PasswordInput
							v-model="form.password_confirmation"
							autocomplete="new-password"
							:label="requiredLabel('auth.passwordConfirmation')"
							:rules="[requiredRule]"
						/>
						<q-btn
							color="primary"
							unelevated
							rounded
							type="submit"
							icon="lock_reset"
							:loading="saving"
							:label="t('auth.resetPassword')"
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
  width: min(520px, 100%);
  margin: 0 auto;
}

@media (max-width: 700px) {
  .auth-page {
    padding-inline: 10px;
  }

  .auth-panel {
    padding: 20px;
  }

  .auth-panel__inner .q-btn {
    width: 100%;
  }
}
</style>
