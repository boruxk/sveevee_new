<script setup>
	import { reactive, ref } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useRequiredFields } from '@/composables/useRequiredFields'

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
					<p>{{ t('auth.simpleLogin') }}</p>
					<q-form ref="formRef" greedy class="column q-gutter-md" @submit.prevent="submit()">
						<q-input
							v-model="form.email"
							outlined
							type="email"
							:label="requiredLabel('auth.email')"
							:rules="[requiredRule]"
						/>
						<q-input
							v-model="form.password"
							outlined
							type="password"
							:label="requiredLabel('auth.password')"
							:rules="[requiredRule]"
						/>
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

@media (max-width: 700px) {
  .auth-page {
    padding-inline: 10px;
  }

  .auth-panel {
    padding: 20px;
  }

  .auth-panel__inner p {
    line-height: 1.6;
  }

  .auth-panel__inner .q-btn {
    width: 100%;
  }
}
</style>
