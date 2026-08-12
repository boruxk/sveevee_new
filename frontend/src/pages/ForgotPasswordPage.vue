<script setup>
	import { reactive, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { forgotPassword } from '@/services/api/auth'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { useRequiredFields } from '@/composables/useRequiredFields'

	const { t } = useI18n()
	const $q = useQuasar()
	const formRef = ref(null)
	const sending = ref(false)
	const sent = ref(false)
	const form = reactive({
		email: ''
	})
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		sending.value = true

		try {
			await forgotPassword(form)
			sent.value = true
			$q.notify({ type: 'positive', message: t('auth.resetLinkSent') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('auth.resetRequestFailed')) })
		} finally {
			sending.value = false
		}
	}
</script>

<template>
	<q-page padding class="auth-page">
		<div class="auth-shell">
			<section class="soz-section-card auth-panel">
				<div class="auth-panel__inner">
					<h1 class="soz-page-title">{{ t('auth.forgotPasswordTitle') }}</h1>
					<p>{{ t('auth.forgotPasswordBody') }}</p>
					<q-banner v-if="sent" rounded class="reset-banner">
						{{ t('auth.resetLinkSent') }}
					</q-banner>
					<q-form ref="formRef" greedy class="column q-gutter-md" @submit.prevent="submit()">
						<q-input
							v-model="form.email"
							outlined
							type="email"
							autocomplete="email"
							:label="requiredLabel('auth.email')"
							:rules="[requiredRule]"
						/>
						<q-btn
							color="primary"
							unelevated
							rounded
							type="submit"
							icon="mail"
							:loading="sending"
							:label="t('auth.sendResetLink')"
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

.reset-banner {
  margin: 16px 0;
  background: rgba(123, 63, 242, 0.1);
  color: var(--soz-primary-deep);
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
