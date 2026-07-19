import { createI18n } from 'vue-i18n'
import en from '@/i18n/messages/en'
import he from '@/i18n/messages/he'
import ru from '@/i18n/messages/ru'
import fr from '@/i18n/messages/fr'
import { useAppStore } from '@/stores/app'

const supportedLocales = ['he', 'en', 'ru', 'fr']
const savedLocale = localStorage.getItem('sveevee-locale')
const initialLocale = supportedLocales.includes(savedLocale) ? savedLocale : 'he'

const i18n = createI18n({
	legacy: false,
	locale: initialLocale,
	fallbackLocale: 'he',
	messages: { he, en, ru, fr }
})

export function setLocale(locale) {
	const nextLocale = supportedLocales.includes(locale) ? locale : 'he'
	i18n.global.locale.value = nextLocale
	const appStore = useAppStore()
	appStore.setLocale(nextLocale)
	appStore.syncDocument(nextLocale)
}

export default i18n
