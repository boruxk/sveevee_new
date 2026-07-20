import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { Quasar, Notify } from 'quasar'
import iconSet from 'quasar/icon-set/material-icons'
import '@quasar/extras/material-icons/material-icons.css'
import 'quasar/src/css/index.sass'
import '@/styles/app.scss'
import App from './App.vue'
import router from '@/router'
import i18n, { getQuasarLocale } from '@/i18n'
import { useAppStore } from '@/stores/app'

const app = createApp(App)
const pinia = createPinia()

app.use(pinia)
app.use(router)
app.use(i18n)
app.use(Quasar, {
	plugins: { Notify },
	iconSet,
	lang: getQuasarLocale(i18n.global.locale.value),
	config: {
		notify: {
			position: 'top-right',
			timeout: 2200
		}
	}
})

const appStore = useAppStore(pinia)
appStore.syncDocument(i18n.global.locale.value)

app.mount('#app')
