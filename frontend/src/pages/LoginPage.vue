<script setup>
	import { reactive, ref } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import GoogleAuthButton from '@/components/GoogleAuthButton.vue'
	import PasswordInput from '@/components/PasswordInput.vue'

	const { t } = useI18n()
	const $q = useQuasar()
	const route = useRoute()
	const router = useRouter()
	const authStore = useAuthStore()
	const formRef = ref(null)
	const form = reactive({ email: '', password: '' })
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		try {
			await authStore.login(form)
			router.push(route.query.redirect || { name: 'home' })
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('auth.loginFailed') })
		}
	}
</script>

<template>
	<q-page padding class="auth-page">
		<div class="auth-shell">
			<section class="soz-section-card auth-panel">
				<div class="auth-panel__inner">
					<h1 class="soz-page-title">{{ t('auth.loginTitle') }}</h1>
					<GoogleAuthButton class="auth-google" />
					<div class="auth-divider">{{ t('auth.or') }}</div>
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
							autocomplete="current-password"
							:label="requiredLabel('auth.password')"
							:rules="[requiredRule]"
						/>
						<router-link class="forgot-link" :to="{ name: 'forgot-password' }">
							{{ t('auth.forgotPassword') }}
						</router-link>
						<q-btn color="primary"
							unelevated
							rounded
							type="submit"
							icon="login"
							:loading="authStore.loading"
							:label="t('nav.login')"
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

.auth-panel__inner h1 {
  margin-bottom: 24px;
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

.forgot-link {
  color: var(--soz-primary-deep);
  font-weight: 600;
  justify-self: start;
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
