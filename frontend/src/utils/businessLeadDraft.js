// Keep unfinished input only during the form's in-app information-page round trip.
let draft = null

export function saveBusinessLeadDraft(form, businessGroup) {
	draft = { form: { ...form }, businessGroup }
}

export function takeBusinessLeadDraft() {
	const savedDraft = draft
	draft = null
	return savedDraft
}

export function clearBusinessLeadDraft() {
	draft = null
}
