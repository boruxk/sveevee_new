import PublicLayout from '@/layouts/PublicLayout.vue'
import UserLayout from '@/layouts/UserLayout.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

export default [
	{
		path: '/',
		component: PublicLayout,
		children: [
			{ path: '', name: 'landing', component: () => import('@/pages/LandingPage.vue'), meta: { seo: { titleKey: 'seo.landingTitle', descriptionKey: 'seo.landingDescription' } } },
			{ path: 'login', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { seo: { titleKey: 'seo.loginTitle', descriptionKey: 'seo.loginDescription', robots: 'noindex,nofollow' } } },
			{ path: 'auth/google/callback', name: 'google-auth-callback', component: () => import('@/pages/GoogleAuthCallbackPage.vue'), meta: { seo: { titleKey: 'seo.loginTitle', descriptionKey: 'seo.loginDescription', robots: 'noindex,nofollow' } } },
			{ path: 'forgot-password', name: 'forgot-password', component: () => import('@/pages/ForgotPasswordPage.vue'), meta: { seo: { titleKey: 'seo.forgotPasswordTitle', descriptionKey: 'seo.forgotPasswordDescription', robots: 'noindex,nofollow' } } },
			{ path: 'reset-password/:token', name: 'reset-password', component: () => import('@/pages/ResetPasswordPage.vue'), meta: { seo: { titleKey: 'seo.resetPasswordTitle', descriptionKey: 'seo.resetPasswordDescription', robots: 'noindex,nofollow' } } },
			{ path: 'register', name: 'register', component: () => import('@/pages/RegisterPage.vue'), meta: { seo: { titleKey: 'seo.registerTitle', descriptionKey: 'seo.registerDescription', robots: 'noindex,follow' } } },
			{ path: 'privacy', name: 'privacy', component: () => import('@/pages/PrivacyPolicyPage.vue'), meta: { seo: { titleKey: 'seo.privacyTitle', descriptionKey: 'seo.privacyDescription' } } },
			{ path: 'search', name: 'search', component: () => import('@/pages/SearchPage.vue'), meta: { seo: { titleKey: 'seo.searchTitle', descriptionKey: 'seo.searchDescription' } } },
			{ path: 'users/:id', name: 'user-page', component: () => import('@/pages/UserPage.vue'), meta: { seo: { titleKey: 'seo.userFallbackTitle', descriptionKey: 'seo.userFallbackDescription' } } },
			{ path: 'ads/:id', name: 'ad-detail', component: () => import('@/pages/AdDetailPage.vue'), meta: { seo: { titleKey: 'seo.adFallbackTitle', descriptionKey: 'seo.adFallbackDescription' } } },
			{ path: 'pages/:id', name: 'page-detail', component: () => import('@/pages/PageDetailPage.vue'), meta: { seo: { titleKey: 'seo.pageFallbackTitle', descriptionKey: 'seo.pageFallbackDescription' } } }
		]
	},
	{
		path: '/',
		component: UserLayout,
		meta: { requiresAuth: true },
		children: [
			{ path: 'home', name: 'home', component: () => import('@/pages/HomePage.vue'), meta: { seo: { titleKey: 'seo.homeTitle', descriptionKey: 'seo.homeDescription' } } },
			{ path: 'me', name: 'me', component: () => import('@/pages/MyPlacePage.vue'), meta: { seo: { titleKey: 'seo.meTitle', descriptionKey: 'seo.meDescription' } } },
			{ path: 'profile', name: 'profile', component: () => import('@/pages/ProfilePage.vue'), meta: { seo: { titleKey: 'seo.profileTitle', descriptionKey: 'seo.profileDescription' } } },
			{ path: 'business', name: 'business', component: () => import('@/pages/PageSetupPage.vue'), meta: { pageType: 'business', seo: { titleKey: 'seo.businessTitle', descriptionKey: 'seo.businessDescription' } } },
			{ path: 'community', name: 'community', component: () => import('@/pages/PageSetupPage.vue'), meta: { pageType: 'community', seo: { titleKey: 'seo.communityTitle', descriptionKey: 'seo.communityDescription' } } }
		]
	},
	{
		path: '/',
		component: AdminLayout,
		meta: { requiresAuth: true, roles: ['admin'] },
		children: [
			{ path: 'admin', name: 'admin-area', component: () => import('@/pages/AdminAreaPage.vue'), meta: { seo: { titleKey: 'seo.adminTitle', descriptionKey: 'seo.adminDescription', robots: 'noindex,nofollow' } } }
		]
	}
]
