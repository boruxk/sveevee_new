import { defineStore } from 'pinia'
import {
	fetchChat,
	fetchChats,
	markChatRead,
	sendChatMessage,
	sendChatMessageToUser,
	startChat
} from '@/services/api/chats'
import { useAuthStore } from '@/stores/auth'

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
				const { data } = await fetchChats()
				this.conversations = data.data?.conversations || []
				this.syncUnread(data.data?.unread_count || 0)
				return this.conversations
			} finally {
				this.loading = false
			}
		},
		async openConversation(id) {
			const { data } = await fetchChat(id)
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
				const { data } = this.activeConversation?.id ? await sendChatMessage(this.activeConversation.id, body) : await sendChatMessageToUser(userId, body)
				this.activeConversation = data.data
				await this.loadConversations()
				return this.activeConversation
			} finally {
				this.sending = false
			}
		},
		async markRead(id) {
			const { data } = await markChatRead(id)
			this.syncUnread(data.data?.unread_count || 0)
			await this.loadConversations()
		},
		clearActive() {
			this.activeConversation = null
		}
	}
})
