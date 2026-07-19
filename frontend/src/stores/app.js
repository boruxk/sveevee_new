import { defineStore } from 'pinia'

const rtlLocales = ['he']
const supportedLocales = ['he', 'en', 'ru', 'fr']
const savedLocale = localStorage.getItem('sveevee-locale')

export const useAppStore = defineStore('app', {
	state: () => ({
		locale: supportedLocales.includes(savedLocale) ? savedLocale : 'he'
	}),
	getters: {
		isRtl: (state) => rtlLocales.includes(state.locale)
	},
	actions: {
		setLocale(locale) {
			const nextLocale = supportedLocales.includes(locale) ? locale : 'he'
			this.locale = nextLocale
			localStorage.setItem('sveevee-locale', nextLocale)
		},
		syncDocument(locale = this.locale) {
			document.documentElement.lang = locale
			document.documentElement.dir = rtlLocales.includes(locale) ? 'rtl' : 'ltr'
		}
	}
})
