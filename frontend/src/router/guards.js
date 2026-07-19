import { useAuthStore } from '@/stores/auth'

export async function authGuard(to) {
	const authStore = useAuthStore()

	await authStore.initialize()

	if (!to.meta.requiresAuth) {
		return true
	}

	if (!authStore.isAuthenticated) {
		return {
			name: 'login',
			query: { redirect: to.fullPath }
		}
	}

	if (to.meta.roles && !authStore.canAccess(to.meta.roles)) {
		return { name: 'home' }
	}

	return true
}
