export const TITLE_MAX_LENGTH = 1000
export const TEXT_MAX_LENGTH = 5000
export const CHAT_MAX_LENGTH = 5000

export function characterCount(value) {
	return Array.from(String(value || '')).length
}

export function remainingCharacters(value, maxLength) {
	return Math.max(maxLength - characterCount(value), 0)
}

export function characterLimitHint(value, maxLength, t) {
	const count = characterCount(value)

	if (count > 0) {
		return t('validation.charactersRemaining', { count: remainingCharacters(value, maxLength), max: maxLength })
	}

	return t('validation.maxCharacters', { max: maxLength })
}
