function firstValidationMessage(errors) {
	if (!errors) {
		return ''
	}

	if (typeof errors === 'string') {
		return errors
	}

	if (Array.isArray(errors)) {
		return errors.find(Boolean) || ''
	}

	for (const [field, messages] of Object.entries(errors)) {
		const message = Array.isArray(messages) ? messages.find(Boolean) : messages

		if (message) {
			return `${field}: ${message}`
		}
	}

	return ''
}

export function apiErrorMessage(error, fallback, reasonMessages = {}) {
	const reason = error?.response?.data?.data?.reason

	if (reason && reasonMessages[reason]) {
		return reasonMessages[reason]
	}

	return firstValidationMessage(error?.response?.data?.errors) || error?.response?.data?.message || fallback
}
