const iconBase = '/assets/landing/icons/'

const iconFiles = {
	campaign: 'feature-ads',
	storefront: 'feature-storefront',
	inventory_2: 'feature-products',
	receipt_long: 'feature-products',
	sell: 'feature-ads',
	design_services: 'feature-services',
	event: 'feature-events',
	star: 'feature-ratings',
	reviews: 'feature-ratings',
	forum: 'feature-chat',
	chat_bubble: 'feature-chat',
	diversity_3: 'feature-community',
	groups: 'feature-community',
	location: 'feature-location',
	schedule: 'feature-hours',
	palette: 'feature-palette',
	share: 'feature-share',
	search: 'feature-search',
	tune: 'feature-controls',
	public: 'feature-profile',
	person: 'feature-profile',
	edit: 'feature-controls',
	verified: 'feature-ratings'
}

const landingFeatureFallbacks = ['campaign', 'storefront', 'inventory_2', 'event', 'star', 'forum']
const landingStepIcons = ['person', 'campaign', 'search', 'verified']

export function landingIconImage(icon, fallback = 'storefront') {
	return `${landingIconBase(icon, fallback)}.v2.webp`
}

export function landingIconBase(icon, fallback = 'storefront') {
	const filename = iconFiles[icon] ?? iconFiles[fallback] ?? iconFiles.storefront
	return `${iconBase}${filename}`
}

export function landingIconAvifSrcset(icon, fallback = 'storefront') {
	const base = landingIconBase(icon, fallback)

	return `${base}-160.v2.avif 160w, ${base}.v2.avif 320w`
}

export function landingIconWebpSrcset(icon, fallback = 'storefront') {
	const base = landingIconBase(icon, fallback)

	return `${base}-160.v2.webp 160w, ${base}.v2.webp 320w`
}

export function landingFeatureIconImage(item, index) {
	return landingIconImage(item?.icon ?? landingFeatureFallbacks[index])
}

export function landingFeatureIconAvifSrcset(item, index) {
	return landingIconAvifSrcset(item?.icon ?? landingFeatureFallbacks[index])
}

export function landingFeatureIconWebpSrcset(item, index) {
	return landingIconWebpSrcset(item?.icon ?? landingFeatureFallbacks[index])
}

export function landingStepIconImage(index) {
	return landingIconImage(landingStepIcons[index], 'verified')
}

export function landingStepIconAvifSrcset(index) {
	return landingIconAvifSrcset(landingStepIcons[index], 'verified')
}

export function landingStepIconWebpSrcset(index) {
	return landingIconWebpSrcset(landingStepIcons[index], 'verified')
}
