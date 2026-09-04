import { defineStore } from 'pinia'
import { fetchNotifications, markAllNotificationsRead, markNotificationRead } from '@/services/api/notifications'
import { connectNotificationRealtime, disconnectNotificationRealtime } from '@/services/notificationRealtime'

export const accountNotificationEventName = 'sveevee:account-notification'

const knownNotificationIds = new Set()

function announce(notification) {
	if (typeof window !== 'undefined') {
		window.dispatchEvent(new CustomEvent(accountNotificationEventName, { detail: notification }))
	}
}

export const useNotificationsStore = defineStore('notifications', {
	state: () => ({
		items: [],
		unreadCount: 0,
		latestId: null,
		pagination: {
			current_page: 1,
			last_page: 1,
			per_page: 15,
			total: 0
		},
		userId: null,
		initialized: false,
		loading: false,
		realtimeEnabled: false
	}),
	getters: {
		hasMore: (state) => state.pagination.current_page < state.pagination.last_page,
		badgeLabel: (state) => (state.unreadCount > 99 ? '99+' : String(state.unreadCount || ''))
	},
	actions: {
		async initialize(userId) {
			if (!userId) {
				this.reset()
				return
			}

			if (this.initialized && Number(this.userId) === Number(userId)) {
				return
			}

			this.reset()
			this.userId = Number(userId)

			try {
				await this.loadFirstPage(false)
			} catch {
				// The heartbeat retries automatically; app navigation stays available.
			}

			this.initialized = true
			this.realtimeEnabled = connectNotificationRealtime({
				userId: this.userId,
				onNotification: (notification) => this.receive(notification),
				onConnected: () => this.reconcileFromServer()
			})
		},
		reset() {
			disconnectNotificationRealtime()
			knownNotificationIds.clear()
			this.$reset()
		},
		async loadFirstPage(announceNew = false) {
			if (!this.userId) {
				return
			}

			this.loading = true
			try {
				const { data } = await fetchNotifications({ page: 1, per_page: this.pagination.per_page })
				const payload = data.data || {}
				const incomingItems = Array.isArray(payload.items) ? payload.items : []
				const newItems = announceNew ? incomingItems.filter((item) => item?.id && !knownNotificationIds.has(item.id)) : []

				incomingItems.forEach((item) => item?.id && knownNotificationIds.add(item.id))
				this.items = incomingItems
				this.unreadCount = Number(payload.unread_count || 0)
				this.latestId = payload.latest_id || null
				this.pagination = {
					...this.pagination,
					...(payload.pagination || {})
				}

				newItems.slice().reverse().forEach(announce)
			} finally {
				this.loading = false
			}
		},
		receive(notification) {
			if (!notification?.id || knownNotificationIds.has(notification.id)) {
				return
			}

			knownNotificationIds.add(notification.id)
			this.items = [notification, ...this.items].slice(0, Math.max(this.pagination.per_page, 15))
			this.latestId = notification.id
			this.pagination.total = Number(this.pagination.total || 0) + 1

			if (!notification.read_at) {
				this.unreadCount += 1
			}

			announce(notification)
		},
		async reconcileSummary(summary) {
			if (!this.userId || !summary) {
				return
			}

			const unreadCount = Number(summary.unread_count || 0)
			const latestId = summary.latest_id || null

			if (unreadCount !== this.unreadCount || latestId !== this.latestId) {
				try {
					await this.loadFirstPage(true)
				} catch {
					// A later heartbeat or focus event will retry.
				}
			}
		},
		async reconcileFromServer() {
			if (!this.initialized) {
				return
			}

			try {
				await this.loadFirstPage(true)
			} catch {
				// Reverb can reconnect before the API is reachable; polling remains active.
			}
		},
		async markRead(notification) {
			if (!notification?.id || notification.read_at) {
				return
			}

			const { data } = await markNotificationRead(notification.id)
			const payload = data.data || {}
			const updated = payload.notification

			if (updated) {
				this.items = this.items.map((item) => (item.id === updated.id ? updated : item))
			}

			this.unreadCount = Number(payload.unread_count || 0)
			this.latestId = payload.latest_id || this.latestId
		},
		async markAllRead() {
			if (!this.unreadCount) {
				return
			}

			const { data } = await markAllNotificationsRead()
			const now = new Date().toISOString()
			this.items = this.items.map((item) => ({ ...item, read_at: item.read_at || now }))
			this.unreadCount = Number(data.data?.unread_count || 0)
			this.latestId = data.data?.latest_id || this.latestId
		},
		async loadMore() {
			if (!this.hasMore || this.loading) {
				return
			}

			this.loading = true
			try {
				const nextPage = this.pagination.current_page + 1
				const { data } = await fetchNotifications({ page: nextPage, per_page: this.pagination.per_page })
				const payload = data.data || {}
				const existingIds = new Set(this.items.map((item) => item.id))
				const incomingItems = (payload.items || []).filter((item) => !existingIds.has(item.id))

				incomingItems.forEach((item) => knownNotificationIds.add(item.id))
				this.items = [...this.items, ...incomingItems]
				this.unreadCount = Number(payload.unread_count || 0)
				this.latestId = payload.latest_id || this.latestId
				this.pagination = { ...this.pagination, ...(payload.pagination || {}) }
			} finally {
				this.loading = false
			}
		}
	}
})
