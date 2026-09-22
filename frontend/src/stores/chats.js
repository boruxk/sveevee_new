import { defineStore } from 'pinia'
import { toRaw } from 'vue'
import {
	fetchChat,
	fetchChats,
	deleteChat,
	markChatRead,
	sendChatMessage,
	sendChatMessageToUser,
	startChat
} from '@/services/api/chats'
import {
	fetchPageConversation,
	fetchVisitorPageChats,
	markPageChatRead,
	sendPageChatMessage
} from '@/services/api/pageChats'
import { useAuthStore } from '@/stores/auth'

const LIST_FRESHNESS_MS = 5000
const sessions = new WeakMap()

function currentSession(store) {
	const auth = useAuthStore()
	const token = auth.token || null
	const userId = auth.user?.id == null ? null : String(auth.user.id)
	let session = sessions.get(toRaw(store))

	if (!session || session.token !== token || session.userId !== userId) {
		store.reset()
		session = { token, userId, pending: null, queued: null, loadedAt: null, activeVersion: 0, detailVersion: 0 }
		sessions.set(toRaw(store), session)
	}
	return session
}

function sessionIsCurrent(store, session) {
	const auth = useAuthStore()

	return sessions.get(toRaw(store)) === session && auth.token === session.token && String(auth.user?.id ?? '') === session.userId
}

function fetchConversationList(store, session) {
	store.loading = !store.hasLoaded
	session.loadedAt = null
	const pending = (async() => {
		try {
			const [privateResponse, pageResponse] = await Promise.all([fetchChats(), fetchVisitorPageChats()])
			if (!sessionIsCurrent(store, session)) return []
			// A mutation completed after this request began; its queued refresh is authoritative.
			if (session.queued) return store.conversations
			const privatePayload = privateResponse.data?.data || {}
			const pagePayload = pageResponse.data?.data || {}

			store.conversations = [
				...(privatePayload.conversations || []),
				...(pagePayload.conversations || [])
			].sort((left, right) => conversationTimestamp(right) - conversationTimestamp(left))
			if (store.activeConversation?.id) {
				const listedActive = store.conversations.find((conversation) => (
					String(conversation.id) === String(store.activeConversation.id) &&
					Boolean(conversation.is_page_chat) === Boolean(store.activeConversation.is_page_chat)
				))
				if (listedActive) {
					store.activeConversation = {
						...store.activeConversation,
						other_user: listedActive.other_user,
						latest_message: listedActive.latest_message,
						last_message_at: listedActive.last_message_at,
						unread_count: listedActive.unread_count
					}
				}
			}
			store.syncUnread(privatePayload.unread_count ?? pagePayload.unread_count ?? 0)
			store.hasLoaded = true
			session.loadedAt = Date.now()
			return store.conversations
		} finally {
			if (sessionIsCurrent(store, session) && session.pending === pending) {
				session.pending = null
				store.loading = !store.hasLoaded && Boolean(session.queued)
			}
		}
	})()
	session.pending = pending
	return pending
}

function documentCanReadMessages() {
	return typeof document === 'undefined' || document.visibilityState === 'visible'
}

function sameConversation(left, right) {
	return String(left?.id) === String(right?.id) && Boolean(left?.is_page_chat) === Boolean(right?.is_page_chat)
}

function applyDetailUnread(store, detail, session) {
	const listed = store.conversations.find((entry) => sameConversation(entry, detail))
	if (!listed || !Number.isFinite(Number(detail?.unread_count))) return
	// A newer list snapshot can contain a message that arrived after this detail
	// request. Keep its unread badge until that message is actually fetched/read.
	const listedTime = conversationTimestamp(listed)
	const detailTime = conversationTimestamp(detail)
	if (listedTime > detailTime || (listedTime === detailTime && Number(listed.latest_message?.id || 0) > Number(detail.latest_message?.id || 0))) return
	const previousUnread = Number(listed.unread_count) || 0
	const unread = Math.max(0, Number(detail.unread_count))
	Object.assign(listed, {
		unread_count: unread,
		other_user: detail.other_user,
		latest_message: detail.latest_message,
		last_message_at: detail.last_message_at
	})
	store.syncUnread(Math.max(0, store.unreadCount + unread - previousUnread))
	// Do not let an older in-flight list request restore the badge we just read.
	if (session.pending) store.loadConversations({ force: true }).catch(() => {})
}

function conversationTimestamp(conversation) {
	const timestamp = new Date(conversation?.last_message_at || 0).getTime()

	return Number.isNaN(timestamp) ? 0 : timestamp
}

export const useChatsStore = defineStore('chats', {
	state: () => ({
		conversations: [],
		activeConversation: null,
		unreadCount: 0,
		hasLoaded: false,
		loading: false,
		sending: false
	}),
	getters: {
		activeMessages: (state) => state.activeConversation?.messages || [],
		composerState: (state) => state.activeConversation?.composer_state || { can_send: true, reason: null, message: null }
	},
	actions: {
		syncUnread(count) {
			this.unreadCount = count || 0
			const authStore = useAuthStore()
			authStore.setUnreadMessagesCount(this.unreadCount)
		},
		loadConversations({ force = false } = {}) {
			const session = currentSession(this)
			if (!session.token || !session.userId) return Promise.resolve([])
			if (session.queued) return session.queued
			if (session.pending) {
				if (!force) return session.pending
				// Coalesce mutations while the old request settles, then read their updated state.
				session.queued = session.pending.catch(() => {}).then(() => {
					if (!sessionIsCurrent(this, session)) return []
					session.queued = null
					return fetchConversationList(this, session)
				})
				return session.queued
			}
			if (!force && session.loadedAt !== null && Date.now() - session.loadedAt < LIST_FRESHNESS_MS) {
				return Promise.resolve(this.conversations)
			}
			return fetchConversationList(this, session)
		},
		async openConversation(conversationOrId, kind = null) {
			const session = currentSession(this)
			if (!session.token || !session.userId) return null
			const activeVersion = ++session.activeVersion
			const suppliedConversation = typeof conversationOrId === 'object' ? conversationOrId : null
			const id = suppliedConversation?.id ?? conversationOrId
			const listedConversation = suppliedConversation || this.conversations.find((conversation) => (
				String(conversation.id) === String(id) &&
				(kind === 'page' ? conversation.is_page_chat : !conversation.is_page_chat)
			))
			const opensPageChat = kind === 'page' || Boolean(listedConversation?.is_page_chat)
			const options = { markRead: documentCanReadMessages() }
			const response = opensPageChat ? await fetchPageConversation(id, options) : await fetchChat(id, options)
			const { data } = response

			if (!sessionIsCurrent(this, session) || session.activeVersion !== activeVersion) return null
			this.activeConversation = data.data
			applyDetailUnread(this, data.data, session)
			await this.loadConversations({ force: true })
			return sessionIsCurrent(this, session) ? this.activeConversation : null
		},
		async openWithUser(userId) {
			const session = currentSession(this)
			if (!session.token || !session.userId) return null
			const activeVersion = ++session.activeVersion
			const { data } = await startChat(userId, { markRead: documentCanReadMessages() })
			if (!sessionIsCurrent(this, session) || session.activeVersion !== activeVersion) return null
			this.activeConversation = data.data
			applyDetailUnread(this, data.data, session)
			await this.loadConversations({ force: true })
			return sessionIsCurrent(this, session) ? this.activeConversation : null
		},
		async refreshActiveConversation(shouldApply = () => true) {
			const session = currentSession(this)
			if (!this.activeConversation?.id || !documentCanReadMessages() || !shouldApply()) {
				return this.activeConversation
			}

			const requested = this.activeConversation
			const activeVersion = session.activeVersion
			const detailVersion = session.detailVersion
			const stillActive = () => sessionIsCurrent(this, session) && session.activeVersion === activeVersion && session.detailVersion === detailVersion && shouldApply() && documentCanReadMessages() && sameConversation(this.activeConversation, requested)

			try {
				const response = requested.is_page_chat ? await fetchPageConversation(requested.id, { markRead: true }) : await fetchChat(requested.id, { markRead: true })

				if (stillActive()) {
					this.activeConversation = response.data.data
					applyDetailUnread(this, response.data.data, session)
				}
				return this.activeConversation
			} catch (error) {
				if (stillActive() && error.response?.status === 404) {
					this.activeConversation = null
					return null
				}

				throw error
			}
		},
		async send(body, userId = null) {
			const session = currentSession(this)
			if (!session.token || !session.userId) return null
			if (!String(body || '').trim()) {
				return this.activeConversation
			}

			const requested = this.activeConversation
			const activeVersion = session.activeVersion
			session.detailVersion++
			this.sending = true

			try {
				let response

				if (this.activeConversation?.is_page_chat) {
					response = await sendPageChatMessage(this.activeConversation.id, body)
				} else if (this.activeConversation?.id) {
					response = await sendChatMessage(this.activeConversation.id, body)
				} else {
					response = await sendChatMessageToUser(userId, body)
				}

				const { data } = response
				if (!sessionIsCurrent(this, session)) return null
				// A poll started before or during this send must not remove the
				// message returned by the mutation. Navigation still owns the thread.
				session.detailVersion++
				if (session.activeVersion === activeVersion && sameConversation(this.activeConversation, requested)) {
					this.activeConversation = data.data
				}
				await this.loadConversations({ force: true })
				return sessionIsCurrent(this, session) ? this.activeConversation : null
			} finally {
				if (sessionIsCurrent(this, session)) this.sending = false
			}
		},
		async markRead(id, kind = null) {
			const session = currentSession(this)
			if (!session.token || !session.userId) return
			const pageChat = kind === 'page' || (String(this.activeConversation?.id) === String(id) && this.activeConversation?.is_page_chat)
			session.detailVersion++
			const { data } = pageChat ? await markPageChatRead(id) : await markChatRead(id)

			if (!sessionIsCurrent(this, session)) return
			session.detailVersion++
			if (Number.isFinite(Number(data.data?.unread_count))) {
				this.syncUnread(Number(data.data.unread_count))
			}
			await this.loadConversations({ force: true })
		},
		async deleteConversation(id, mode) {
			const session = currentSession(this)
			if (!session.token || !session.userId) return null
			session.detailVersion++
			const { data } = await deleteChat(id, mode)
			if (!sessionIsCurrent(this, session)) return null
			session.detailVersion++
			this.conversations = this.conversations.filter((conversation) => (
				conversation.is_page_chat || String(conversation.id) !== String(id)
			))
			if (!this.activeConversation?.is_page_chat && String(this.activeConversation?.id) === String(id)) {
				this.activeConversation = null
			}
			this.syncUnread(data.data?.unread_count || 0)
			await this.loadConversations({ force: true })
			return data.data
		},
		reset() {
			sessions.delete(toRaw(this))
			this.conversations = []
			this.activeConversation = null
			this.unreadCount = 0
			this.hasLoaded = false
			this.loading = false
			this.sending = false
		},
		clearActive() {
			const session = sessions.get(toRaw(this))
			if (session) session.activeVersion++
			this.activeConversation = null
		}
	}
})
