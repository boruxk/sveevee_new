import PublicLayout from '@/layouts/PublicLayout.vue'
import UserLayout from '@/layouts/UserLayout.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

export default [
	{
		path: '/',
		component: PublicLayout,
		children: [
			{ path: '', name: 'landing', component: () => import('@/pages/LandingPage.vue') },
			{ path: 'login', name: 'login', component: () => import('@/pages/LoginPage.vue') },
			{ path: 'register', name: 'register', component: () => import('@/pages/RegisterPage.vue') },
			{ path: 'search', name: 'search', component: () => import('@/pages/SearchPage.vue') },
			{ path: 'users/:id', name: 'user-page', component: () => import('@/pages/UserPage.vue') },
			{ path: 'pages/:id', name: 'page-detail', component: () => import('@/pages/PageDetailPage.vue') }
		]
	},
	{
		path: '/',
		component: UserLayout,
		meta: { requiresAuth: true },
		children: [
			{ path: 'home', name: 'home', component: () => import('@/pages/HomePage.vue') },
			{ path: 'me', name: 'me', component: () => import('@/pages/MyPlacePage.vue') },
			{ path: 'profile', name: 'profile', component: () => import('@/pages/ProfilePage.vue') },
			{ path: 'business', name: 'business', component: () => import('@/pages/PageSetupPage.vue'), meta: { pageType: 'business' } },
			{ path: 'community', name: 'community', component: () => import('@/pages/PageSetupPage.vue'), meta: { pageType: 'community' } }
		]
	},
	{
		path: '/',
		component: AdminLayout,
		meta: { requiresAuth: true, roles: ['admin'] },
		children: [
			{ path: 'admin', name: 'admin-area', component: () => import('@/pages/AdminAreaPage.vue') }
		]
	}
]
