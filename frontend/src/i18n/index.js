import { createI18n } from 'vue-i18n'
import { Lang } from 'quasar'
import quasarHe from 'quasar/lang/he'
import he from '@/i18n/messages/he'
import { getGuestLocale, useAppStore } from '@/stores/app'

const supportedLocales = ['he', 'en', 'ru', 'fr']
const messageLoaders = {
	en: () => import('@/i18n/messages/en'),
	ru: () => import('@/i18n/messages/ru'),
	fr: () => import('@/i18n/messages/fr')
}
const quasarLocaleLoaders = {
	en: () => import('quasar/lang/en-US'),
	ru: () => import('quasar/lang/ru'),
	fr: () => import('quasar/lang/fr')
}
const initialLocale = getGuestLocale()
const loadedQuasarLocales = {
	he: quasarHe
}

const i18n = createI18n({
	legacy: false,
	locale: initialLocale,
	fallbackLocale: 'he',
	messages: { he }
})

export function getSupportedLocale(locale) {
	return supportedLocales.includes(locale) ? locale : 'he'
}

export function getQuasarLocale(locale) {
	return loadedQuasarLocales[getSupportedLocale(locale)] || loadedQuasarLocales.he
}

async function loadLocaleMessages(locale) {
	const nextLocale = getSupportedLocale(locale)

	if (!i18n.global.availableLocales.includes(nextLocale)) {
		const loader = messageLoaders[nextLocale]
		if (loader) {
			const messages = await loader()
			i18n.global.setLocaleMessage(nextLocale, messages.default)
		}
	}
}

async function loadQuasarLocale(locale) {
	const nextLocale = getSupportedLocale(locale)

	if (!loadedQuasarLocales[nextLocale]) {
		const loader = quasarLocaleLoaders[nextLocale]
		if (loader) {
			const quasarLocale = await loader()
			loadedQuasarLocales[nextLocale] = quasarLocale.default
		}
	}

	return getQuasarLocale(nextLocale)
}

export async function prepareLocale(locale = initialLocale) {
	const nextLocale = getSupportedLocale(locale)

	await loadLocaleMessages(nextLocale)
	const quasarLocale = await loadQuasarLocale(nextLocale)
	i18n.global.locale.value = nextLocale

	return {
		locale: nextLocale,
		quasarLocale
	}
}

export async function setLocale(locale) {
	const { locale: nextLocale, quasarLocale } = await prepareLocale(locale)

	Lang.set(quasarLocale)
	const appStore = useAppStore()
	appStore.setLocale(nextLocale)
	appStore.syncDocument(nextLocale)
}

export default i18n
