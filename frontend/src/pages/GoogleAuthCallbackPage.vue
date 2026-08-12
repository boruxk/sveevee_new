<script setup>
	import { onMounted } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'

	const route = useRoute()
	const router = useRouter()
	const { t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()

	const errorMessages = {
		google_login_failed: 'auth.googleLoginFailed',
		google_missing_email: 'auth.googleMissingEmail',
		email_banned: 'auth.emailBanned',
		account_banned: 'auth.accountBanned'
	}

	function cleanCallbackUrl() {
		window.history.replaceState({}, document.title, route.path)
	}

	function callbackErrorMessage(error) {
		return t(errorMessages[error] || 'auth.googleLoginFailed')
	}

	onMounted(async() => {
		const fragment = new URLSearchParams(window.location.hash.replace(/^#/, ''))
		const token = fragment.get('token')
		const error = String(route.query.error || '')

		cleanCallbackUrl()

		if (error || !token) {
			$q.notify({ type: 'negative', message: callbackErrorMessage(error) })
			router.replace({ name: 'login' })
			return
		}

		try {
			const user = await authStore.loginWithToken(token)
			router.replace(user?.profile_complete === false ? { name: 'profile', query: { complete: '1' } } : { name: 'home' })
		} catch {
			$q.notify({ type: 'negative', message: t('auth.googleLoginFailed') })
			router.replace({ name: 'login' })
		}
	})
</script>

<template>
	<q-page padding class="google-callback-page">
		<div class="google-callback-page__inner">
			<q-spinner color="primary" size="42px" />
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.google-callback-page {
  min-height: 52vh;
}

.google-callback-page__inner {
  display: grid;
  min-height: 42vh;
  place-items: center;
}
</style>
