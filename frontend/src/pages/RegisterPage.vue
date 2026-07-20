<script setup>
	import { computed, reactive } from 'vue'
	import { useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'

	const { t } = useI18n()
	const $q = useQuasar()
	const router = useRouter()
	const authStore = useAuthStore()
	const form = reactive({
		email: '',
		password: '',
		password_confirmation: '',
		given_name: '',
		family_name: '',
		phone: '',
		city: '',
		neighborhood: '',
		languages: ['he'],
		locale: 'he'
	})
	const languageOptions = computed(() => [
		{ label: t('languages.he'), value: 'he' },
		{ label: t('languages.en'), value: 'en' },
		{ label: t('languages.ru'), value: 'ru' },
		{ label: t('languages.fr'), value: 'fr' }
	])

	async function submit() {
		try {
			await authStore.register(form)
			router.push({ name: 'home' })
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('auth.registerFailed') })
		}
	}
</script>

<template>
	<q-page padding class="auth-page">
		<div class="auth-shell">
			<section class="soz-section-card auth-panel">
				<div class="auth-panel__inner">
					<h1 class="soz-page-title">{{ t('auth.registerTitle') }}</h1>
					<p class="q-pb-md">{{ t('auth.simpleLogin') }}</p>
					<q-form class="column q-gutter-md q-pl-md" @submit.prevent="submit">
						<div class="row q-col-gutter-md q-pb-md">
							<q-input class="col-12 col-md-6" v-model="form.given_name" outlined :label="t('auth.givenName')" />
							<q-input class="col-12 col-md-6" v-model="form.family_name" outlined :label="t('auth.familyName')" />
						</div>
						<div class="row q-col-gutter-md q-pb-md">
							<q-input class="col-12 col-md-12" v-model="form.email" outlined type="email" :label="t('auth.email')" />
						</div>
						<div class="row q-col-gutter-md q-pb-md">
							<q-input class="col-12 col-md-6" v-model="form.password" outlined type="password" :label="t('auth.password')" />
							<q-input class="col-12 col-md-6" v-model="form.password_confirmation" outlined type="password" :label="t('auth.passwordConfirmation')" />
						</div>
						<div class="row q-col-gutter-md q-pb-md">
							<q-input class="col-12 col-md-3" v-model="form.phone" outlined :label="t('auth.phone')" />
							<q-input class="col-12 col-md-3" v-model="form.city" outlined :label="t('auth.city')" />
							<q-input class="col-12 col-md-3" v-model="form.neighborhood" outlined :label="t('auth.neighborhood')" />
							<q-select class="col-12 col-md-3"
								v-model="form.languages"
								outlined
								multiple
								emit-value
								map-options
								:options="languageOptions"
								:label="t('profile.languages')"
							/>
						</div>
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
  width: min(780px, 100%);
  margin: 0 auto;
}

.form-submit {
  margin-inline-start: 0 !important;
}
</style>
