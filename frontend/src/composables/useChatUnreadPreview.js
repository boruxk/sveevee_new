import { onBeforeUnmount, onMounted, ref, watch } from 'vue'

// Poll an existing conversation without marking messages as read. Never start a
// chat to discover its count; callers supply a session only when one exists.
export function useChatUnreadPreview(session, fetchPreview) {
	const unreadCount = ref(0)
	let timer = null
	let version = 0
	let inFlight = false
	let disposed = false

	async function refresh() {
		const key = session.value
		if (!key || disposed || inFlight || typeof document === 'undefined' || document.visibilityState !== 'visible') return
		const requestVersion = version
		inFlight = true
		try {
			const response = await fetchPreview(key)
			if (disposed || requestVersion !== version || session.value !== key) return
			const count = Number(response.data?.data?.unread_count || 0)
			unreadCount.value = Number.isFinite(count) ? Math.max(0, Math.floor(count)) : 0
		} catch (error) {
			if (!disposed && requestVersion === version && [404, 410].includes(error.response?.status)) unreadCount.value = 0
		} finally {
			if (requestVersion === version) inFlight = false
		}
	}

	watch(session, () => {
		version++
		inFlight = false
		unreadCount.value = 0
		refresh()
	}, { immediate: true })
	onMounted(() => {
		timer = window.setInterval(refresh, 30000)
		document.addEventListener('visibilitychange', refresh)
		window.addEventListener('focus', refresh)
	})
	onBeforeUnmount(() => {
		disposed = true
		version++
		if (timer) window.clearInterval(timer)
		document.removeEventListener('visibilitychange', refresh)
		window.removeEventListener('focus', refresh)
	})

	return { unreadCount, refresh }
}
