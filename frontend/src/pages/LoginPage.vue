<script setup>
	import { reactive } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'

	const { t } = useI18n()
	const $q = useQuasar()
	const route = useRoute()
	const router = useRouter()
	const authStore = useAuthStore()
	const form = reactive({ email: 'user@sveevee.local', password: 'password' })

	async function submit() {
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
					<q-form class="column q-gutter-md" @submit.prevent="submit">
						<q-input v-model="form.email" outlined type="email" :label="t('auth.email')" />
						<q-input v-model="form.password" outlined type="password" :label="t('auth.password')" />
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
</style>
