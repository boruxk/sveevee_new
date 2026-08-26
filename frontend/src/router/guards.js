import { useAuthStore } from '@/stores/auth'
import { usePlatformStore } from '@/stores/platform'

export async function authGuard(to) {
	const authStore = useAuthStore()
	const platformStore = usePlatformStore()

	await Promise.all([authStore.initialize(), platformStore.initialize()])

	const maintenanceAccessRoutes = ['maintenance', 'login', 'google-auth-callback']

	if (platformStore.isMaintenance && !authStore.isAdmin && !maintenanceAccessRoutes.includes(to.name)) {
		return {
			name: 'maintenance',
			query: { redirect: to.fullPath }
		}
	}

	if (to.name === 'maintenance' && (!platformStore.isMaintenance || authStore.isAdmin)) {
		return { name: authStore.isAdmin ? 'admin-area' : 'landing' }
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
