import { defineStore } from 'pinia'

const rtlLocales = ['he']
const supportedLocales = ['he', 'en', 'ru', 'fr']
const guestLocaleStorageKey = 'sveevee-guest-locale'

function normalizedLocale(locale) {
	return supportedLocales.includes(locale) ? locale : 'he'
}

export function getGuestLocale() {
	return normalizedLocale(localStorage.getItem(guestLocaleStorageKey))
}

export const useAppStore = defineStore('app', {
	state: () => ({
		locale: getGuestLocale()
	}),
	getters: {
		isRtl: (state) => rtlLocales.includes(state.locale)
	},
	actions: {
		setLocale(locale) {
			const nextLocale = normalizedLocale(locale)
			this.locale = nextLocale
		},
		setGuestLocale(locale) {
			const nextLocale = normalizedLocale(locale)
			localStorage.setItem(guestLocaleStorageKey, nextLocale)
			this.locale = nextLocale
		},
		syncDocument(locale = this.locale) {
			const nextLocale = normalizedLocale(locale)
			const isRtl = rtlLocales.includes(nextLocale)
			const dir = isRtl ? 'rtl' : 'ltr'
			const root = document.documentElement

			root.lang = nextLocale
			root.dir = dir
			root.classList.toggle('sveevee-rtl', isRtl)
			root.classList.toggle('sveevee-ltr', !isRtl)

			if (document.body) {
				document.body.dir = dir
				document.body.classList.toggle('sveevee-rtl', isRtl)
				document.body.classList.toggle('sveevee-ltr', !isRtl)
			}
		}
	}
})
