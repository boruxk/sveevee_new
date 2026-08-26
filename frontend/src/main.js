import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { Quasar, Dialog, Notify } from 'quasar'
import iconSet from 'quasar/icon-set/svg-material-icons'
import '@/styles/quasar.sass'
import '@/styles/app.scss'
import App from './App.vue'
import router from '@/router'
import i18n, { prepareLocale } from '@/i18n'
import { useAppStore } from '@/stores/app'
import { materialIconMapFn } from '@/utils/materialIconMap'

const localHosts = ['localhost', '127.0.0.1', '::1']

if (import.meta.env.PROD && window.location.protocol === 'http:' && !localHosts.includes(window.location.hostname)) {
	window.location.replace(`https://${window.location.host}${window.location.pathname}${window.location.search}${window.location.hash}`)
}

async function bootstrap() {
	const initialLocale = await prepareLocale()
	const app = createApp(App)
	const pinia = createPinia()

	app.use(pinia)
	app.use(router)
	app.use(i18n)
	app.use(Quasar, {
		plugins: { Dialog, Notify },
		iconSet,
		lang: initialLocale.quasarLocale,
		config: {
			iconMapFn: materialIconMapFn,
			notify: {
				position: 'top-right',
				timeout: 2200
			}
		}
	})

	const appStore = useAppStore(pinia)
	appStore.syncDocument(initialLocale.locale)

	app.mount('#app')
}

bootstrap()
