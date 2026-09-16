export function pageSaveResult(response) {
	const data = response?.data?.data
	if (data?.save_outcome === 'claim_conflict' || data?.pending_claim === true) {
		return {
			outcome: 'claim_conflict',
			page: null,
			claimRequests: Array.isArray(data.claim_requests) ? data.claim_requests : []
		}
	}

	if (response?.status === 202 || !Number.isSafeInteger(Number(data?.id)) || Number(data.id) <= 0) {
		throw new Error('The page save response did not contain a saved page.')
	}

	return { outcome: data.save_outcome || 'updated', page: data, claimRequests: [] }
}
