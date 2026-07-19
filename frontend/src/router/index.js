import { createRouter, createWebHistory } from 'vue-router'
import routes from '@/router/routes'
import { authGuard } from '@/router/guards'

const router = createRouter({
	history: createWebHistory(),
	routes,
	scrollBehavior: () => ({ top: 0 })
})

router.beforeEach(authGuard)

export default router
