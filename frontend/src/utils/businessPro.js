export function canPreviewBusinessPro(auth) {
	// Offers are visible to every account; the API separately authorizes checkout and paid features.
	return Boolean(auth?.isAuthenticated && ['user', 'admin'].includes(auth.user?.role || 'user'))
}

export function businessProFeatureAvailable(feature) {
	return feature?.available === true && feature?.implemented === true && feature?.enabled === true
}

export function localizedProText(values, locale) {
	return String(values?.[locale] || values?.en || values?.he || '').trim()
}

export function proMoney(amountMinor, currency, locale) {
	if (!Number.isSafeInteger(amountMinor) || amountMinor < 0 || !/^[A-Z]{3}$/.test(currency || '')) return '—'
	return new Intl.NumberFormat(locale || 'en', { style: 'currency', currency }).format(amountMinor / 100)
}

export function proStatusKey(status) {
	const known = ['inactive', 'unknown', 'pending', 'pending_confirmation', 'active', 'past_due', 'cancelled', 'expired', 'paid', 'failed', 'processing', 'refunded', 'voided', 'created']
	return known.includes(status) ? status : 'unknown'
}

export function validCardcomCheckoutUrl(value) {
	try {
		const url = new URL(value)
		const host = url.hostname.toLowerCase()
		return url.protocol === 'https:' && !url.username && !url.password && !url.port && (host === 'secure.cardcom.solutions' || host === 'test.cardcom.solutions') ? url.href : null
	} catch { return null }
}
