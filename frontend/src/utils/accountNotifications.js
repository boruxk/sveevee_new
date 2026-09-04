export const accountRefreshNotificationTypes = [
	'page_claim_approved',
	'page_assigned',
	'page_detached',
	'page_deleted'
]

export function notificationParameters(notification) {
	const data = notification?.data || {}

	return {
		page: data.page?.name || '',
		requester: data.requester_name || '',
		reviewer: data.reviewer_name || '',
		rating: data.rating || '',
		replacedPage: data.replaced_page_name || '',
		lead: data.lead_name || ''
	}
}

export function notificationTranslationKeys(notification) {
	const type = notification?.type || 'unknown'
	let body = `notifications.types.${type}.body`

	if (type === 'page_claim_approved' && notification?.data?.replaced_page_name) {
		body = `notifications.types.${type}.bodyReplaced`
	}

	if (type === 'page_claim_rejected' && notification?.data?.reason === 'claimed_by_another') {
		body = `notifications.types.${type}.bodyClaimed`
	}

	return {
		title: `notifications.types.${type}.title`,
		body
	}
}

export function notificationActionPath(notification) {
	const path = String(notification?.data?.action_path || '')

	return path.startsWith('/') ? path : '/me'
}
