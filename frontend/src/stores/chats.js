import { defineStore } from 'pinia'
import {
	fetchChat,
	fetchChats,
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

function conversationTimestamp(conversation) {
	const timestamp = new Date(conversation?.last_message_at || 0).getTime()

	return Number.isNaN(timestamp) ? 0 : timestamp
}

export const useChatsStore = defineStore('chats', {
	state: () => ({
		conversations: [],
		activeConversation: null,
		unreadCount: 0,
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
		async loadConversations() {
			this.loading = true

			try {
				const [privateResponse, pageResponse] = await Promise.all([
					fetchChats(),
					fetchVisitorPageChats()
				])
				const privatePayload = privateResponse.data?.data || {}
				const pagePayload = pageResponse.data?.data || {}

				this.conversations = [
					...(privatePayload.conversations || []),
					...(pagePayload.conversations || [])
				].sort((left, right) => conversationTimestamp(right) - conversationTimestamp(left))
				this.syncUnread(privatePayload.unread_count ?? pagePayload.unread_count ?? 0)
				return this.conversations
			} finally {
				this.loading = false
			}
		},
		async openConversation(conversationOrId, kind = null) {
			const suppliedConversation = typeof conversationOrId === 'object' ? conversationOrId : null
			const id = suppliedConversation?.id ?? conversationOrId
			const listedConversation = suppliedConversation || this.conversations.find((conversation) => (
				String(conversation.id) === String(id) &&
				(kind === 'page' ? conversation.is_page_chat : !conversation.is_page_chat)
			))
			const opensPageChat = kind === 'page' || Boolean(listedConversation?.is_page_chat)
			const response = opensPageChat ? await fetchPageConversation(id) : await fetchChat(id)
			const { data } = response

			this.activeConversation = data.data
			await this.loadConversations()
			return this.activeConversation
		},
		async openWithUser(userId) {
			const { data } = await startChat(userId)
			this.activeConversation = data.data
			await this.loadConversations()
			return this.activeConversation
		},
		async send(body, userId = null) {
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
				this.activeConversation = data.data
				await this.loadConversations()
				return this.activeConversation
			} finally {
				this.sending = false
			}
		},
		async markRead(id, kind = null) {
			const pageChat = kind === 'page' || (this.activeConversation?.id === id && this.activeConversation?.is_page_chat)
			const { data } = pageChat ? await markPageChatRead(id) : await markChatRead(id)

			if (!pageChat) {
				this.syncUnread(data.data?.unread_count || 0)
			}
			await this.loadConversations()
		},
		clearActive() {
			this.activeConversation = null
		}
	}
})
