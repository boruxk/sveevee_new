const LINK_PATTERN = /(?:https?:\/\/|www\.)[^\s<>"`]+/giu
const LINK_PREFIX_CHARACTER = /[\p{L}\p{N}_@:/\\]/u
const TRAILING_PUNCTUATION = /[.,!?;:'\u2019\u201d\u05c3]+$/u
const CLOSING_BRACKETS = { ')': '(', ']': '[', '}': '{' }

function trimLink(value) {
	let candidate = value
	let previous
	do {
		previous = candidate
		candidate = candidate.replace(TRAILING_PUNCTUATION, '')
		const closer = candidate.at(-1)
		const opener = CLOSING_BRACKETS[closer]
		if (opener && candidate.split(closer).length > candidate.split(opener).length) {
			candidate = candidate.slice(0, -1)
		}
	} while (candidate !== previous)
	return candidate
}

function readableLink(value) {
	// Decode Unicode for display without changing the actual URL or its reserved characters.
	return value.replace(/(?:%[\da-f]{2})+/giu, (encoded) => {
		try {
			return decodeURI(encoded).replace(/[\p{Cc}\u202a-\u202e\u2066-\u2069]/gu, encodeURIComponent)
		} catch {
			return encoded
		}
	})
}

export function chatMessageSegments(body) {
	const text = String(body ?? '')
	const segments = []
	let consumed = 0
	for (const match of text.matchAll(LINK_PATTERN)) {
		if (match.index > 0 && LINK_PREFIX_CHARACTER.test(text[match.index - 1])) continue
		const candidate = trimLink(match[0])
		const href = /^www\./iu.test(candidate) ? `https://${candidate}` : candidate
		try {
			const url = new URL(href)
			if (!['http:', 'https:'].includes(url.protocol) || !url.hostname || /[\p{Cc}\\]/u.test(href)) continue
		} catch {
			continue
		}
		if (match.index > consumed) segments.push({ type: 'text', text: text.slice(consumed, match.index) })
		segments.push({ type: 'link', text: readableLink(candidate), href })
		consumed = match.index + candidate.length
	}
	if (consumed < text.length) segments.push({ type: 'text', text: text.slice(consumed) })
	return segments
}
