export function apiErrorMessage(error, fallback, reasonMessages = {}) {
	const reason = error?.response?.data?.data?.reason

	if (reason && reasonMessages[reason]) {
		return reasonMessages[reason]
	}

	// Backend response copy is not locale-safe; only explicit reason mappings may reach the UI.
	return fallback
}
