<script setup>
	import { computed, reactive, ref } from 'vue'
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
	const isAiLogin = computed(() => route.name === 'ai-worker-login')
	const aiLoginCopy = Object.freeze({
		title: 'AI Works login',
		intro: 'Sign in to continue to the private AI workspace.',
		identifier: 'Login',
		password: 'Password',
		submit: 'Sign in',
		failed: 'AI Works login failed.'
	})
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		try {
			if (isAiLogin.value) {
				await authStore.loginAi(form)
				await router.replace({ name: 'ai-works' })
				return
			}

			await authStore.login(form)
			const defaultRoute = authStore.isAdmin ? { name: 'admin-area' } : (authStore.isAiWorker ? { name: 'ai-works' } : { name: 'home' })
			await router.push(route.query.redirect || defaultRoute)
		} catch {
			$q.notify({ type: 'negative', message: isAiLogin.value ? aiLoginCopy.failed : t('auth.loginFailed') })
		}
	}
</script>

<template>
	<q-page padding class="auth-page">
		<div class="auth-shell">
			<section class="soz-section-card auth-panel">
				<div class="auth-panel__inner">
					<h1 class="soz-page-title">{{ isAiLogin ? aiLoginCopy.title : t('auth.loginTitle') }}</h1>
					<p v-if="isAiLogin" class="auth-panel__intro">{{ aiLoginCopy.intro }}</p>
					<GoogleAuthButton v-if="!isAiLogin" class="auth-google" />
					<div v-if="!isAiLogin" class="auth-divider">{{ t('auth.or') }}</div>
					<q-form ref="formRef" greedy class="column q-gutter-md" @submit.prevent="submit()">
						<q-input
							v-model="form.email"
							outlined
							name="username"
							type="text"
							autocomplete="username"
							:label="isAiLogin ? `${aiLoginCopy.identifier} *` : requiredLabel('auth.identifier')"
							:rules="[requiredRule]"
						/>
						<PasswordInput
							v-model="form.password"
							name="password"
							autocomplete="current-password"
							:label="isAiLogin ? `${aiLoginCopy.password} *` : requiredLabel('auth.password')"
							:rules="[requiredRule]"
						/>
						<router-link v-if="!isAiLogin" class="forgot-link" :to="{ name: 'forgot-password' }">
							{{ t('auth.forgotPassword') }}
						</router-link>
						<q-btn color="primary"
							unelevated
							rounded
							type="submit"
							icon="login"
							:loading="authStore.loading"
							:label="isAiLogin ? aiLoginCopy.submit : t('nav.login')"
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

.auth-panel__intro {
  margin: -12px 0 24px;
  color: var(--soz-muted);
  font-size: 1rem;
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
