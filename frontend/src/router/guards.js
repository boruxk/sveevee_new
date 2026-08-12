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

	if (authStore.needsProfileCompletion && to.name !== 'profile') {
		return {
			name: 'profile',
			query: { complete: '1' }
		}
	}

	if (authStore.needsProfileCompletion && to.name === 'profile' && to.query.complete !== '1') {
		return {
			name: 'profile',
			query: { ...to.query, complete: '1' }
		}
	}

	if (to.meta.roles && !authStore.canAccess(to.meta.roles)) {
		return { name: 'home' }
	}

	return true
}
