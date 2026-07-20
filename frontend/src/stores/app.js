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
			const nextLocale = supportedLocales.includes(locale) ? locale : 'he'
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
