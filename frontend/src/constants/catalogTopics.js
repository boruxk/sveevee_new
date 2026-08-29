import { AD_CATEGORY_SELECT_OPTIONS } from '@/constants/adCategories'

export const CATALOG_SCOPES = {
	BUSINESS_PAGES: 'business_pages',
	COMMUNITY_PAGES: 'community_pages',
	ADS: 'ads',
	USERS: 'users',
	PRODUCTS: 'products',
	SERVICES: 'services',
	EVENTS: 'events'
}

export const POPULAR_CATALOG_KEYS = [
	'professionals.electricians',
	'professionals.renovation',
	'food_catering.restaurants',
	'products.home_garden.furniture',
	'products.electronics_computers.phones_tablets',
	'services.home_repairs.plumbing',
	'services.beauty_wellness.hair',
	'events.community_social.community_festival',
	'events.kids_family.kids_show',
	'community_pages.local.neighborhood_group',
	'legal_finance_business.lawyers',
	'education_courses.private_lessons'
]

const LOCALE_KEYS = ['he', 'en', 'ru', 'fr']
const AD_CATEGORY_KEYS = AD_CATEGORY_SELECT_OPTIONS
	.filter((option) => !option.group)
	.map((option) => option.value)

const AD_TOPIC_MAP = {
	'home_professionals.electrician': 'professionals.electricians',
	'home_professionals.renovation': 'professionals.renovation',
	'home_professionals.moving': 'professionals.moving',
	'home_professionals.pest_control': 'professionals.pest_control',
	'home_professionals.cleaning': 'professionals.cleaning_polish',
	'home_professionals.handyman': 'services.home_repairs.handyman',
	'home_professionals.plumbing': 'services.home_repairs.plumbing',
	'home_professionals.air_conditioning': 'services.home_repairs.air_conditioning',
	'home_professionals.painting': 'services.home_repairs.painting',
	'home_professionals.locksmith': 'services.home_repairs.locksmith',
	'home_professionals.windows_shutters': 'services.home_repairs.windows_shutters',
	'home_professionals.carpentry': 'services.home_repairs.carpentry',
	'home_professionals.pergolas': 'professionals.renovation',
	'home_professionals.gardening': 'services.home_repairs.gardening',
	'food_catering.restaurants': 'food_catering.restaurants',
	'food_catering.catering': 'professionals.catering',
	'food_catering.pizza_fast_food': 'professionals.fast_food',
	'food_catering.meat_butcher': 'products.food_grocery.meat_fish_deli',
	'food_catering.fish': 'professionals.fish_restaurants',
	'food_catering.grocery': 'professionals.grocery_food',
	'food_catering.food_for_events': 'professionals.catering',
	'fashion.mens_fashion': 'products.fashion_beauty.men_clothing',
	'fashion.womens_fashion': 'products.fashion_beauty.women_clothing',
	'fashion.childrens_fashion': 'products.fashion_beauty.kids_clothing',
	'fashion.suits_shirts': 'products.fashion_beauty.men_clothing',
	'fashion.dresses': 'products.fashion_beauty.women_clothing',
	'fashion.bridal_wedding_dresses': 'products.fashion_beauty.women_clothing',
	'fashion.wigs_head_coverings': 'products.fashion_beauty.wigs_head_coverings',
	'beauty_personal_care.hairdresser': 'professionals.hair_salons',
	'beauty_personal_care.hair_treatments': 'professionals.hair_salons',
	'beauty_personal_care.skin_care': 'professionals.cosmetics',
	'beauty_personal_care.hair_removal': 'professionals.beauticians',
	'health_wellness.therapy': 'health_care.therapy_counseling',
	'health_wellness.alternative_medicine': 'professionals.alternative_medicine',
	'health_wellness.massage': 'beauty_personal_care.spa_massage',
	'health_wellness.orthopedics_orthotics': 'health_care.medical_equipment',
	'health_wellness.opticians': 'health_care.clinics_doctors',
	'health_wellness.memory_senior_services': 'health_care.senior_care',
	'health_wellness.personal_care': 'health_care.caregivers_nursing',
	'kids_family.daycare': 'education_courses.daycare_kindergarten',
	'kids_family.babysitting': 'health_care.caregivers_nursing',
	'events_entertainment.music_dj': 'entertainers.dj',
	'events_entertainment.event_photography': 'professionals.photo_video',
	'events_entertainment.video': 'creators.video_editor',
	'events_entertainment.event_equipment': 'services.events_entertainment.party_equipment',
	'events_entertainment.attractions': 'entertainers.event_attractions',
	'events_entertainment.games': 'services.events_entertainment.attractions',
	'events_entertainment.event_venues': 'professionals.venues',
	'events_entertainment.party_rentals': 'services.events_entertainment.party_equipment',
	'kids_family.childrens_activities': 'professionals.kids_activities',
	'kids_family.toys_games': 'products.kids_baby.toys_games',
	'kids_family.inflatables': 'services.events_entertainment.attractions',
	'kids_family.camps': 'events.kids_family.camps',
	'kids_family.parenting_family_services': 'events.kids_family.parenting_event',
	'education_courses.schools': 'education_courses.courses_workshops',
	'education_courses.private_lessons': 'professionals.private_tutors',
	'education_courses.tutoring': 'professionals.private_tutors',
	'education_courses.courses_workshops': 'education_courses.courses_workshops',
	'education_courses.religious_studies': 'events.religious_jewish.shiur',
	'shopping_retail.general_retail': 'shopping_retail.sales_special_offers',
	'electronics_appliances.home_appliances': 'shopping_retail.appliances',
	'electronics_appliances.mobile_phones': 'products.electronics_computers.phones_tablets',
	'electronics_appliances.computers': 'products.electronics_computers.computers_laptops',
	'electronics_appliances.computer_repair': 'professionals.computer_technician',
	'electronics_appliances.electrical_products': 'shopping_retail.electronics',
	'electronics_appliances.small_appliances': 'products.appliances.coffee_small_appliances',
	'community_religious.community_events': 'events.community_social.community_festival',
	'beauty_personal_care.cosmetics': 'professionals.cosmetics'
}

export function normalizeCatalogLocale(locale = 'he') {
	const key = String(locale || 'he').split('-')[0]

	return LOCALE_KEYS.includes(key) ? key : 'he'
}

export function catalogLabel(labels, locale = 'he') {
	const normalizedLocale = normalizeCatalogLocale(locale)

	return labels?.[normalizedLocale] || labels?.he || labels?.en || ''
}

export function flattenCatalogGroups(groups = []) {
	return groups.flatMap((group) => (
		(group.topics || []).map((topic) => ({
			...topic,
			group_key: topic.group_key || group.key,
			group_labels: topic.group_labels || group.labels,
			color: topic.color || group.color
		}))
	))
}

export function catalogTopicByKey(groups = [], key) {
	return flattenCatalogGroups(groups).find((topic) => topic.key === key || topic.aliases?.includes(key)) || null
}

export function catalogTopicBySlug(groups = [], slug) {
	return flattenCatalogGroups(groups).find((topic) => topic.slug === slug || topic.aliases?.includes(slug)) || null
}

export function catalogTopicForAdCategory(groups = [], category) {
	return catalogTopicByKey(groups, AD_TOPIC_MAP[category] || category)
}

function catalogTopicHasAdCategory(topic) {
	const topicKeys = [topic.key, ...(topic.aliases || [])]

	return AD_CATEGORY_KEYS.some((category) => (
		topicKeys.includes(category) || topicKeys.includes(AD_TOPIC_MAP[category] || category)
	)) || Object.entries(AD_TOPIC_MAP).some(([category, mappedKey]) => (
		topicKeys.includes(category) || topicKeys.includes(mappedKey)
	))
}

export function buildCatalogSelectOptions(groups = [], scope, locale = 'he') {
	const scopes = Array.isArray(scope) ? scope.filter(Boolean) : (scope ? [scope] : [])

	return groups.flatMap((group) => {
		const topics = (group.topics || []).filter((topic) => catalogTopicMatchesScope(topic, scopes))

		if (topics.length === 0) {
			return []
		}

		const groupLabel = catalogLabel(group.labels, locale)

		return [
			{
				label: groupLabel,
				value: `${group.key}.__group`,
				disable: true,
				group: true,
				color: group.color
			},
			...topics.map((topic) => ({
				label: catalogLabel(topic.labels, locale),
				value: topic.key,
				slug: topic.slug,
				groupKey: group.key,
				groupLabel,
				color: topic.color || group.color,
				scopes: topic.scopes || []
			}))
		]
	})
}

export function catalogTopicMatchesScope(topic, scope) {
	const scopes = Array.isArray(scope) ? scope.filter(Boolean) : (scope ? [scope] : [])

	return scopes.length === 0 || scopes.some((item) => {
		if (item === CATALOG_SCOPES.ADS) {
			return topic.scopes?.includes(CATALOG_SCOPES.ADS) || catalogTopicHasAdCategory(topic)
		}

		return topic.scopes?.includes(item)
	})
}

export function catalogGroupsForScope(groups = [], scope) {
	return groups
		.map((group) => ({
			...group,
			topics: (group.topics || []).filter((topic) => catalogTopicMatchesScope(topic, scope))
		}))
		.filter((group) => group.topics.length > 0)
}

export function locationSlug(value) {
	return String(value || '')
		.normalize('NFD')
		.replace(/[\u0300-\u036f]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9]+/g, '-')
		.replace(/^-+|-+$/g, '')
}

export function catalogPath(topic, city = '', neighborhood = '') {
	const slug = typeof topic === 'string' ? topic : topic?.slug
	const parts = ['catalog']

	if (city) {
		parts.push(locationSlug(city))
	}

	if (city && neighborhood) {
		parts.push(locationSlug(neighborhood))
	}

	if (slug) {
		parts.push(slug)
	}

	return `/${parts.join('/')}`
}

export function marketPath(city = '', topic = '') {
	const slug = typeof topic === 'string' ? topic : (topic?.market_slug || topic?.slug)
	const parts = ['market']

	if (city) {
		parts.push(locationSlug(city))
	}

	if (slug) {
		parts.push(slug)
	}

	return `/${parts.join('/')}`
}

export function catalogHubPath(hubSlug, city = '', neighborhood = '') {
	const parts = ['catalog']

	if (city) {
		parts.push(locationSlug(city))
	}

	if (city && neighborhood) {
		parts.push(locationSlug(neighborhood))
	}

	if (hubSlug) {
		parts.push(hubSlug)
	}

	return `/${parts.join('/')}`
}

export function pageRouteParam(page) {
	return page?.slug || page?.public_slug || page?.id
}

function localePrefix(locale = '') {
	return locale ? `/${normalizeCatalogLocale(locale)}` : ''
}

export function publicPagePath(page, locale = '') {
	const id = pageRouteParam(page)

	if (!id) {
		return '/'
	}

	const segment = page?.type === 'community' ? 'community' : 'business'

	return `${localePrefix(locale)}/${segment}/${id}`
}

export function pageRoute(page) {
	if (page?.type === 'business') {
		return { name: 'business-detail', params: { id: pageRouteParam(page) } }
	}

	return { name: 'community-detail', params: { id: pageRouteParam(page) } }
}

export function productRouteParam(product) {
	return product?.slug || product?.public_slug || product?.id
}

export function productPath(product, locale = '') {
	const id = productRouteParam(product)

	return id ? `${localePrefix(locale)}/product/${id}` : '/'
}

export function productRoute(product) {
	return { name: 'product-detail', params: { id: productRouteParam(product) } }
}

export function userRouteParam(user) {
	return user?.slug || user?.public_slug || user?.id
}

export function publicUserPath(user) {
	const id = userRouteParam(user)

	return id ? `/users/${id}` : '/'
}

export function userRoute(user) {
	return { name: 'user-page', params: { id: userRouteParam(user) } }
}

export function adRouteParam(ad) {
	return ad?.slug || ad?.public_slug || ad?.id
}

export function publicAdPath(ad) {
	const id = adRouteParam(ad)

	return id ? `/ads/${id}` : '/'
}

export function adRoute(ad) {
	return { name: 'ad-detail', params: { id: adRouteParam(ad) } }
}

export function catalogResultPath(kind, item) {
	if (kind === 'user') {
		return userRoute(item)
	}

	if (kind === 'ad') {
		return adRoute(item)
	}

	return pageRoute(item.page || item)
}
