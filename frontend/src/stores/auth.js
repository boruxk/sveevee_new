import { defineStore } from 'pinia'
import { aiLogin as aiLoginRequest, fetchMe, login, logout, register } from '@/services/api/auth'
import { tokenStorageKey } from '@/services/api/client'
import { setLocale } from '@/i18n'
import { getGuestLocale } from '@/stores/app'

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
		needsProfileCompletion: (state) => Boolean(
			state.token &&
			state.user &&
			state.user.profile_complete === false &&
			!normalizedRoles(state.user).some((role) => ['admin', 'ai_worker'].includes(role))
		),
		roles: (state) => normalizedRoles(state.user),
		isAdmin: (state) => normalizedRoles(state.user).includes('admin'),
		isAiWorker: (state) => normalizedRoles(state.user).includes('ai_worker'),
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
				await this.clearSession()
			} finally {
				this.initialized = true
			}
		},
		async login(payload) {
			this.loading = true

			try {
				const { data } = await login(payload)
				await this.persistSession(data.data)
				return data
			} finally {
				this.loading = false
			}
		},
		async loginAi(payload) {
			this.loading = true

			try {
				const { data } = await aiLoginRequest(payload)
				await this.persistSession(data.data)
				return data
			} finally {
				this.loading = false
			}
		},
		async register(payload) {
			this.loading = true

			try {
				const { data } = await register(payload)
				await this.persistSession(data.data)
				return data
			} finally {
				this.loading = false
			}
		},
		async loginWithToken(token) {
			this.loading = true

			try {
				this.token = token
				this.user = null
				this.initialized = true
				localStorage.setItem(tokenStorageKey, token)
				await this.refreshUser()

				return this.user
			} catch (error) {
				await this.clearSession()
				throw error
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
				await this.clearSession()
			}
		},
		async refreshUser() {
			if (!this.token) {
				return null
			}

			const { data } = await fetchMe()
			this.user = data.data
			await this.syncLocaleFromUser()

			return this.user
		},
		async persistSession(payload) {
			this.token = payload.token
			this.user = payload.user
			this.initialized = true
			localStorage.setItem(tokenStorageKey, payload.token)
			await this.syncLocaleFromUser()
		},
		async clearSession() {
			this.token = null
			this.user = null
			this.initialized = true
			localStorage.removeItem(tokenStorageKey)
			await setLocale(getGuestLocale())
		},
		async syncLocaleFromUser() {
			const preferredLocale = this.isAiWorker ? 'en' : this.user?.locale
			const locale = supportedLocales.includes(preferredLocale) ? preferredLocale : 'he'

			await setLocale(locale)
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
