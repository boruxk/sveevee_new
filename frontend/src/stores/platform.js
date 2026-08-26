import { defineStore } from 'pinia'
import { fetchPlatformStatus } from '@/services/api/platform'

const emptyMaintenance = () => ({
	enabled: false,
	messages: {}
})

export const usePlatformStore = defineStore('platform', {
	state: () => ({
		maintenance: emptyMaintenance(),
		initialized: false,
		loading: false
	}),
	getters: {
		isMaintenance: (state) => Boolean(state.maintenance?.enabled),
		messageFor: (state) => (locale) => state.maintenance?.messages?.[locale] ||
			state.maintenance?.messages?.en ||
			''
	},
	actions: {
		async initialize(force = false) {
			if ((this.initialized && !force) || this.loading) {
				return
			}

			this.loading = true
			try {
				const { data } = await fetchPlatformStatus()
				this.setMaintenance(data.data?.maintenance)
			} catch {
				if (!this.initialized) {
					this.maintenance = emptyMaintenance()
				}
			} finally {
				this.initialized = true
				this.loading = false
			}
		},
		setMaintenance(maintenance) {
			this.maintenance = {
				enabled: Boolean(maintenance?.enabled),
				messages: maintenance?.messages || {}
			}
			this.initialized = true
		}
	}
})
