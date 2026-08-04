import { defineStore } from 'pinia'
import { fetchMe, login, logout, register } from '@/services/api/auth'
import { tokenStorageKey } from '@/services/api/client'
import { setLocale } from '@/i18n'

const supportedLocales = ['he', 'en', 'ru', 'fr']

function normalizedRoles(user) {
	const roleNames = Array.isArray(user?.role_names) ? user.role_names : []
	const role = user?.role
	const roles = role ? [...roleNames, role] : roleNames

	return [...new Set(roles.map((item) => String(item || '').trim().toLowerCase()).filter(Boolean))]
}

export const useAuthStore = defineStore('auth', {
	state: () => ({
		token: localStorage.getItem(tokenStorageKey),
		user: null,
		initialized: false,
		loading: false
	}),
	getters: {
		isAuthenticated: (state) => Boolean(state.token && state.user),
		roles: (state) => normalizedRoles(state.user),
		isAdmin: (state) => normalizedRoles(state.user).includes('admin'),
		unreadMessagesCount: (state) => state.user?.unread_messages_count || 0
	},
	actions: {
		async initialize() {
			if (this.initialized) {
				return
			}

			if (!this.token) {
				this.initialized = true
				return
			}

			try {
				await this.refreshUser()
			} catch {
				this.clearSession()
			} finally {
				this.initialized = true
			}
		},
		async login(payload) {
			this.loading = true

			try {
				const { data } = await login(payload)
				this.persistSession(data.data)
				return data
			} finally {
				this.loading = false
			}
		},
		async register(payload) {
			this.loading = true

			try {
				const { data } = await register(payload)
				this.persistSession(data.data)
				return data
			} finally {
				this.loading = false
			}
		},
		async logout() {
			try {
				if (this.token) {
					await logout()
				}
			} finally {
				this.clearSession()
			}
		},
		async refreshUser() {
			if (!this.token) {
				return null
			}

			const { data } = await fetchMe()
			this.user = data.data
			this.syncLocaleFromUser()

			return this.user
		},
		persistSession(payload) {
			this.token = payload.token
			this.user = payload.user
			this.initialized = true
			localStorage.setItem(tokenStorageKey, payload.token)
			this.syncLocaleFromUser()
		},
		clearSession() {
			this.token = null
			this.user = null
			this.initialized = true
			localStorage.removeItem(tokenStorageKey)
		},
		syncLocaleFromUser() {
			const savedLocale = localStorage.getItem('sveevee-locale')
			const preferredLocale = savedLocale || this.user?.locale || this.user?.profile?.languages?.[0]
			const locale = supportedLocales.includes(preferredLocale) ? preferredLocale : 'he'

			setLocale(locale)
		},
		canAccess(allowedRoles = []) {
			if (allowedRoles.length === 0) {
				return this.isAuthenticated
			}

			return allowedRoles.some((role) => this.roles.includes(String(role || '').trim().toLowerCase()))
		},
		setUnreadMessagesCount(count) {
			if (!this.user) {
				return
			}

			this.user = {
				...this.user,
				unread_messages_count: count
			}
		}
	}
})
