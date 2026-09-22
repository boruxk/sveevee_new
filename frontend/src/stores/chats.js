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
		session = { token, userId, pending: null, queued: null, loadedAt: null, activeVersion: 0 }
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
			const response = opensPageChat ? await fetchPageConversation(id) : await fetchChat(id)
			const { data } = response

			if (!sessionIsCurrent(this, session) || session.activeVersion !== activeVersion) return null
			this.activeConversation = data.data
			await this.loadConversations({ force: true })
			return sessionIsCurrent(this, session) ? this.activeConversation : null
		},
		async openWithUser(userId) {
			const session = currentSession(this)
			if (!session.token || !session.userId) return null
			const activeVersion = ++session.activeVersion
			const { data } = await startChat(userId)
			if (!sessionIsCurrent(this, session) || session.activeVersion !== activeVersion) return null
			this.activeConversation = data.data
			await this.loadConversations({ force: true })
			return sessionIsCurrent(this, session) ? this.activeConversation : null
		},
		async refreshActiveConversation(shouldApply = () => true) {
			const session = currentSession(this)
			if (!this.activeConversation?.id) {
				return this.activeConversation
			}

			const requested = this.activeConversation
			const stillActive = () => sessionIsCurrent(this, session) && shouldApply() && this.activeConversation === requested

			try {
				const response = requested.is_page_chat ? await fetchPageConversation(requested.id) : await fetchChat(requested.id)

				if (stillActive()) this.activeConversation = response.data.data
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
				this.activeConversation = data.data
				await this.loadConversations({ force: true })
				return sessionIsCurrent(this, session) ? this.activeConversation : null
			} finally {
				if (sessionIsCurrent(this, session)) this.sending = false
			}
		},
		async markRead(id, kind = null) {
			const session = currentSession(this)
			if (!session.token || !session.userId) return
			const pageChat = kind === 'page' || (this.activeConversation?.id === id && this.activeConversation?.is_page_chat)
			const { data } = pageChat ? await markPageChatRead(id) : await markChatRead(id)

			if (!sessionIsCurrent(this, session)) return
			if (!pageChat) {
				this.syncUnread(data.data?.unread_count || 0)
			}
			await this.loadConversations({ force: true })
		},
		async deleteConversation(id, mode) {
			const session = currentSession(this)
			if (!session.token || !session.userId) return null
			const { data } = await deleteChat(id, mode)
			if (!sessionIsCurrent(this, session)) return null
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
