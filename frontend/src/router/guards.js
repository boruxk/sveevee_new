import { useAuthStore } from '@/stores/auth'
import { usePlatformStore } from '@/stores/platform'

export async function authGuard(to) {
	const authStore = useAuthStore()
	const platformStore = usePlatformStore()

	await Promise.all([authStore.initialize(), platformStore.initialize()])

	const maintenanceAccessRoutes = ['maintenance', 'login', 'google-auth-callback']
	const isPrivilegedWorker = authStore.isAdmin || authStore.isAiWorker

	if (platformStore.isMaintenance && !isPrivilegedWorker && !maintenanceAccessRoutes.includes(to.name)) {
		return {
			name: 'maintenance',
			query: { redirect: to.fullPath }
		}
	}

	if (to.name === 'maintenance' && (!platformStore.isMaintenance || isPrivilegedWorker)) {
		return { name: authStore.isAdmin ? 'admin-area' : (authStore.isAiWorker ? 'ai-works' : 'landing') }
	}

	if (authStore.needsProfileCompletion && to.name !== 'profile') {
		return {
			name: 'profile',
			query: { complete: '1', redirect: to.fullPath }
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
		return {
			name: authStore.isAiWorker ? 'ai-works' : (authStore.isAdmin ? 'admin-area' : 'home')
		}
	}

	return true
}
