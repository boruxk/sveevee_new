import { createRouter, createWebHistory } from 'vue-router'
import routes from '@/router/routes'
import { authGuard } from '@/router/guards'
import { useAuthStore } from '@/stores/auth'
import { usePlatformStore } from '@/stores/platform'

const router = createRouter({
	history: createWebHistory(),
	routes,
	scrollBehavior: () => ({ top: 0 })
})

router.beforeEach(authGuard)

window.addEventListener('sveevee:maintenance', (event) => {
	const platformStore = usePlatformStore()
	const authStore = useAuthStore()
	platformStore.setMaintenance(event.detail)

	if (!authStore.isAdmin && router.currentRoute.value.name !== 'maintenance') {
		router.push({
			name: 'maintenance',
			query: { redirect: router.currentRoute.value.fullPath }
		})
	}
})

export default router
