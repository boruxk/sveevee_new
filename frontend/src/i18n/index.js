import { createI18n } from 'vue-i18n'
import { Lang } from 'quasar'
import quasarEn from 'quasar/lang/en-US'
import quasarFr from 'quasar/lang/fr'
import quasarHe from 'quasar/lang/he'
import quasarRu from 'quasar/lang/ru'
import en from '@/i18n/messages/en'
import he from '@/i18n/messages/he'
import ru from '@/i18n/messages/ru'
import fr from '@/i18n/messages/fr'
import { getGuestLocale, useAppStore } from '@/stores/app'

const supportedLocales = ['he', 'en', 'ru', 'fr']
const quasarLocales = {
	he: quasarHe,
	en: quasarEn,
	ru: quasarRu,
	fr: quasarFr
}
const initialLocale = getGuestLocale()

const i18n = createI18n({
	legacy: false,
	locale: initialLocale,
	fallbackLocale: 'he',
	messages: { he, en, ru, fr }
})

export function getSupportedLocale(locale) {
	return supportedLocales.includes(locale) ? locale : 'he'
}

export function getQuasarLocale(locale) {
	return quasarLocales[getSupportedLocale(locale)]
}

export function setLocale(locale) {
	const nextLocale = getSupportedLocale(locale)
	i18n.global.locale.value = nextLocale
	Lang.set(getQuasarLocale(nextLocale))
	const appStore = useAppStore()
	appStore.setLocale(nextLocale)
	appStore.syncDocument(nextLocale)
}

export default i18n
