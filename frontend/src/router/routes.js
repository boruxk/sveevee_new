import PublicLayout from '@/layouts/PublicLayout.vue'
import UserLayout from '@/layouts/UserLayout.vue'
import AdminLayout from '@/layouts/AdminLayout.vue'

const catalogPage = () => import('@/pages/CatalogPage.vue')
const marketPage = () => import('@/pages/MarketPage.vue')
const pageDetailPage = () => import('@/pages/PageDetailPage.vue')
const pageExamplePage = () => import('@/pages/PageExamplePage.vue')
const pagePromoLandingPage = () => import('@/pages/PagePromoLandingPage.vue')
const productDetailPage = () => import('@/pages/ProductDetailPage.vue')
const legalPage = () => import('@/pages/PrivacyPolicyPage.vue')
const catalogSeo = { titleKey: 'seo.catalogTitle', descriptionKey: 'seo.catalogDescription' }
const marketSeo = { titleKey: 'seo.marketTitle', descriptionKey: 'seo.marketDescription' }
const pageSeo = { titleKey: 'seo.pageFallbackTitle', descriptionKey: 'seo.pageFallbackDescription' }
const productSeo = { titleKey: 'seo.productFallbackTitle', descriptionKey: 'seo.productFallbackDescription' }
const catalogHubRoutes = ['businesses', 'communities', 'products', 'services', 'events', 'ads', 'people'].flatMap((slug) => [
	{ path: `catalog/${slug}`, name: `catalog-${slug}`, component: catalogPage, meta: { catalogScopeSlug: slug, seo: catalogSeo } },
	{ path: `catalog/:citySlug/${slug}`, name: `catalog-${slug}-city`, component: catalogPage, meta: { catalogScopeSlug: slug, seo: catalogSeo } },
	{ path: `catalog/:citySlug/:neighborhoodSlug/${slug}`, name: `catalog-${slug}-neighborhood`, component: catalogPage, meta: { catalogScopeSlug: slug, seo: catalogSeo } }
])

export default [
	{
		path: '/',
		component: PublicLayout,
		children: [
			{ path: '', name: 'landing', component: () => import('@/pages/LandingPage.vue'), meta: { seo: { titleKey: 'seo.landingTitle', descriptionKey: 'seo.landingDescription' } } },
			{ path: 'maintenance', name: 'maintenance', component: () => import('@/pages/MaintenancePage.vue'), meta: { seo: { titleKey: 'maintenance.title', descriptionKey: 'maintenance.defaultMessage', robots: 'noindex,nofollow' } } },
			{ path: 'login', name: 'login', component: () => import('@/pages/LoginPage.vue'), meta: { seo: { titleKey: 'seo.loginTitle', descriptionKey: 'seo.loginDescription', robots: 'noindex,nofollow' } } },
			{ path: 'auth/google/callback', name: 'google-auth-callback', component: () => import('@/pages/GoogleAuthCallbackPage.vue'), meta: { seo: { titleKey: 'seo.loginTitle', descriptionKey: 'seo.loginDescription', robots: 'noindex,nofollow' } } },
			{ path: 'forgot-password', name: 'forgot-password', component: () => import('@/pages/ForgotPasswordPage.vue'), meta: { seo: { titleKey: 'seo.forgotPasswordTitle', descriptionKey: 'seo.forgotPasswordDescription', robots: 'noindex,nofollow' } } },
			{ path: 'reset-password/:token', name: 'reset-password', component: () => import('@/pages/ResetPasswordPage.vue'), meta: { seo: { titleKey: 'seo.resetPasswordTitle', descriptionKey: 'seo.resetPasswordDescription', robots: 'noindex,nofollow' } } },
			{ path: 'register', name: 'register', component: () => import('@/pages/RegisterPage.vue'), meta: { seo: { titleKey: 'seo.registerTitle', descriptionKey: 'seo.registerDescription', robots: 'noindex,follow' } } },
			{ path: 'privacy', name: 'privacy', component: legalPage, meta: { legalDocument: 'privacy', seo: { titleKey: 'seo.privacyTitle', descriptionKey: 'seo.privacyDescription' } } },
			{ path: 'terms', name: 'terms', component: legalPage, meta: { legalDocument: 'terms' } },
			{ path: 'disclaimer', name: 'disclaimer', component: legalPage, meta: { legalDocument: 'disclaimer' } },
			{ path: 'businesses', name: 'businesses-landing', component: pagePromoLandingPage, meta: { promoType: 'business', seo: { titleKey: 'seo.businessLandingTitle', descriptionKey: 'seo.businessLandingDescription', image: '/assets/landing/promo-business-hero-1360.v3.webp', imageAltKey: 'seo.businessLandingTitle', imageWidth: 1360, imageHeight: 765 } } },
			{ path: 'communities', name: 'communities-landing', component: pagePromoLandingPage, meta: { promoType: 'community', seo: { titleKey: 'seo.communityLandingTitle', descriptionKey: 'seo.communityLandingDescription', image: '/assets/landing/promo-community-hero-1360.v3.webp', imageAltKey: 'seo.communityLandingTitle', imageWidth: 1360, imageHeight: 765 } } },
			{ path: 'business-example-page', name: 'business-example-page', component: pageExamplePage, meta: { exampleType: 'business', seo: { titleKey: 'seo.examplePageTitle', descriptionKey: 'seo.examplePageDescription', image: '/assets/landing/example-business-banner-1440.v1.webp', imageAltKey: 'seo.examplePageTitle', imageWidth: 1440, imageHeight: 640 } } },
			{ path: 'community-example-page', name: 'community-example-page', component: pageExamplePage, meta: { exampleType: 'community', seo: { titleKey: 'seo.examplePageTitle', descriptionKey: 'seo.examplePageDescription', image: '/assets/landing/example-community-banner-1440.v1.webp', imageAltKey: 'seo.examplePageTitle', imageWidth: 1440, imageHeight: 640 } } },
			{ path: 'example-page', redirect: { name: 'business-example-page' } },
			{ path: 'catalog', redirect: { name: 'catalog-businesses' } },
			...catalogHubRoutes,
			{ path: ':locale(he|en|ru|fr)/market/:citySlug', name: 'localized-market-city', component: marketPage, meta: { seo: marketSeo } },
			{ path: ':locale(he|en|ru|fr)/market/:citySlug/:topicSlug', name: 'localized-market-city-topic', component: marketPage, meta: { seo: marketSeo } },
			{ path: 'market/:citySlug', name: 'market-city', component: marketPage, meta: { seo: marketSeo } },
			{ path: 'market/:citySlug/:topicSlug', name: 'market-city-topic', component: marketPage, meta: { seo: marketSeo } },
			{ path: 'catalog/:topicSlug', name: 'catalog-topic', component: catalogPage, meta: { seo: catalogSeo } },
			{ path: 'catalog/:citySlug/:topicSlug', name: 'catalog-topic-city', component: catalogPage, meta: { seo: catalogSeo } },
			{ path: 'catalog/:citySlug/:neighborhoodSlug/:topicSlug', name: 'catalog-topic-neighborhood', component: catalogPage, meta: { seo: catalogSeo } },
			{ path: 'search', name: 'search', component: () => import('@/pages/SearchPage.vue'), meta: { seo: { titleKey: 'seo.searchTitle', descriptionKey: 'seo.searchDescription' } } },
			{ path: 'users/:id', name: 'user-page', component: () => import('@/pages/UserPage.vue'), meta: { seo: { titleKey: 'seo.userFallbackTitle', descriptionKey: 'seo.userFallbackDescription' } } },
			{ path: 'ads/:id', name: 'ad-detail', component: () => import('@/pages/AdDetailPage.vue'), meta: { seo: { titleKey: 'seo.adFallbackTitle', descriptionKey: 'seo.adFallbackDescription' } } },
			{ path: ':locale(he|en|ru|fr)/business/:id', name: 'localized-business-detail', component: pageDetailPage, meta: { seo: pageSeo } },
			{ path: 'business/:id', name: 'business-detail', component: pageDetailPage, meta: { seo: pageSeo } },
			{ path: ':locale(he|en|ru|fr)/product/:id', name: 'localized-product-detail', component: productDetailPage, meta: { seo: productSeo } },
			{ path: 'product/:id', name: 'product-detail', component: productDetailPage, meta: { seo: productSeo } },
			{ path: 'pages/:id', name: 'page-detail', component: pageDetailPage, meta: { seo: pageSeo } }
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
