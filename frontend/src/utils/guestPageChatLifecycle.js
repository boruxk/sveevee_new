const pendingStarts = new Map()

export function pendingGuestPageChatStart(pageId) {
	return pendingStarts.get(String(pageId)) || null
}

export async function runGuestPageChatStart(pageId, start) {
	const key = String(pageId)
	const existing = pendingStarts.get(key)
	if (existing) {
		return { response: await existing, messageSent: false }
	}

	const pending = Promise.resolve().then(start)
	pendingStarts.set(key, pending)
	try {
		return { response: await pending, messageSent: true }
	} finally {
		if (pendingStarts.get(key) === pending) pendingStarts.delete(key)
	}
}
