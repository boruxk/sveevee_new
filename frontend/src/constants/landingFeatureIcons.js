const iconBase = '/assets/landing/icons/'

const iconFiles = {
	campaign: 'feature-ads.v2.webp',
	storefront: 'feature-storefront.v2.webp',
	inventory_2: 'feature-products.v2.webp',
	design_services: 'feature-services.v2.webp',
	event: 'feature-events.v2.webp',
	star: 'feature-ratings.v2.webp',
	reviews: 'feature-ratings.v2.webp',
	forum: 'feature-chat.v2.webp',
	chat_bubble: 'feature-chat.v2.webp',
	diversity_3: 'feature-community.v2.webp',
	groups: 'feature-community.v2.webp',
	location: 'feature-location.v2.webp',
	schedule: 'feature-hours.v2.webp',
	palette: 'feature-palette.v2.webp',
	share: 'feature-share.v2.webp',
	search: 'feature-search.v2.webp',
	tune: 'feature-controls.v2.webp',
	public: 'feature-profile.v2.webp',
	person: 'feature-profile.v2.webp',
	edit: 'feature-controls.v2.webp',
	verified: 'feature-ratings.v2.webp'
}

const landingFeatureFallbacks = ['campaign', 'storefront', 'inventory_2', 'event', 'star', 'forum']
const landingStepIcons = ['person', 'campaign', 'search', 'verified']

export function landingIconImage(icon, fallback = 'storefront') {
	const filename = iconFiles[icon] ?? iconFiles[fallback] ?? iconFiles.storefront
	return `${iconBase}${filename}`
}

export function landingFeatureIconImage(item, index) {
	return landingIconImage(item?.icon ?? landingFeatureFallbacks[index])
}

export function landingStepIconImage(index) {
	return landingIconImage(landingStepIcons[index], 'verified')
}
