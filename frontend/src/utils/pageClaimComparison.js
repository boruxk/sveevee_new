function field(source, paths) {
	for (const path of paths) {
		let value = source
		let present = true
		for (const key of path.split('.')) {
			if (!value || typeof value !== 'object' || !Object.hasOwn(value, key)) {
				present = false
				break
			}
			value = value[key]
		}
		if (present) return { present: true, value }
	}
	return { present: false, value: null }
}

const text = (value) => value == null ? '' : String(value)

export function pageClaimComparison(claim, { t, categoryLabel = text, cityLabel = text, paletteLabel = text }) {
	const current = claim?.page || {}
	const proposed = claim?.proposed_data || {}
	const rows = []

	function add(key, labelKey, paths, format = text, image = false) {
		const incoming = field(proposed, paths)
		if (!incoming.present) return
		const before = format(field(current, paths).value)
		const after = format(incoming.value)
		if (!before && !after) return
		rows.push({ key, labelKey, before, after, image, changed: before !== after })
	}

	add('name', 'pages.name', ['name'])
	add('description', 'pages.description', ['public_description'])
	add('category', 'catalog.category', ['category_key'], categoryLabel)
	add('email', 'pages.email', ['contact_email', 'setup.contact.email'])
	add('phone', 'pages.tel', ['phone', 'setup.contact.tel'])
	add('whatsapp', 'pages.whatsapp', ['setup.contact.whatsapp'])
	add('website', 'pages.website', ['website', 'setup.website'])
	add('address', 'pages.sections.address', ['setup.address', 'address'], (address) => {
		if (!address || typeof address !== 'object') return text(address)
		return [address.street, address.number, address.neighborhood, cityLabel(address.city)].filter(Boolean).join(', ')
	})
	for (const network of ['facebook', 'instagram', 'tiktok', 'x', 'telegram']) {
		add(network, `pages.socials.${network}`, [`setup.socials.${network}`])
	}
	add('hours', 'pages.sections.openingHours', ['setup.opening_hours', 'opening_hours'], (hours) => {
		if (!Array.isArray(hours)) return ''
		return hours.map((day) => {
			const times = day.is_open === true || day.is_open === 1 || day.is_open === '1' ? [day.opens_at, day.closes_at].filter(Boolean).join('–') : t('pages.closed')
			return `${t(`pages.weekdays.${day.weekday}`)}: ${times}`
		}).join('\n')
	})
	add('areas', 'pages.sections.serviceAreas', ['setup.service_areas', 'service_areas'], (areas) => (
		Array.isArray(areas) ? areas.map(cityLabel).join(', ') : ''
	))
	add('specialties', 'pages.specialties', ['setup.specialties', 'specialties'], (items) => (
		Array.isArray(items) ? items.join(', ') : ''
	))
	for (const feature of ['store', 'services', 'events', 'price_list']) {
		add(feature, `businessFeatures.${feature === 'price_list' ? 'priceList' : feature}`, [`setup.features.${feature}`], (enabled) => (
			enabled == null ? '' : t(enabled === true || enabled === 1 || enabled === '1' ? 'admin.claimFeatureEnabled' : 'admin.claimFeatureDisabled')
		))
	}
	add('palette', 'pages.palette', ['palette_key', 'setup.palette_key'], paletteLabel)
	add('logo', 'pages.logo', ['logo_url'], text, true)
	add('banner', 'pages.banner', ['banner_url'], text, true)
	return rows
}
