import { defineStore } from 'pinia'
import { useAuthStore } from '@/stores/auth'
import { fetchSocialStates, putSocialLike, deleteSocialLike } from '@/services/api/community'

const emptyState = () => ({ likes_count: 0, liked: false, comments_count: 0 })
const requests = new WeakMap()
const queued = []
let scheduled = false

function normalizedState(value) {
	return {
		likes_count: Math.max(0, Number(value?.likes_count) || 0),
		liked: Boolean(value?.liked),
		comments_count: Math.max(0, Number(value?.comments_count) || 0)
	}
}

async function flushRequests() {
	scheduled = false
	const pending = queued.splice(0)
	for (let index = 0; index < pending.length; index += 100) {
		const batch = pending.slice(index, index + 100)
		try {
			const { data } = await fetchSocialStates({ targets: [...new Set(batch.map((entry) => entry.key))] })
			const items = new Map((data.data?.items || []).map((item) => [`${item.type}:${item.id}`, item]))
			for (const entry of batch) {
				const value = normalizedState(items.get(entry.key))
				if (entry.store.viewerId === entry.viewerId) entry.store.states[entry.key] = value
				entry.resolve(value)
			}
		} catch (error) {
			batch.forEach((entry) => entry.reject(error))
		} finally {
			batch.forEach((entry) => entry.pending.delete(entry.requestKey))
		}
	}
}

export const useCommunityStore = defineStore('community', {
	state: () => ({ states: {}, busy: {}, viewerId: null }),
	actions: {
		syncViewer() {
			const viewerId = String(useAuthStore().user?.id || 'guest')
			if (this.viewerId !== viewerId) {
				this.states = {}
				this.busy = {}
				this.viewerId = viewerId
			}
			return viewerId
		},
		getState(type, id) {
			return this.states[`${type}:${id}`] || emptyState()
		},
		seed(type, id, state) {
			this.syncViewer()
			const key = `${type}:${id}`
			if (state && !this.states[key]) this.states[key] = normalizedState(state)
		},
		ensure(type, id) {
			const viewerId = this.syncViewer()
			const key = `${type}:${id}`
			if (this.states[key]) return Promise.resolve(this.states[key])
			if (!requests.has(this)) requests.set(this, new Map())
			const pending = requests.get(this)
			const requestKey = `${viewerId}:${key}`
			if (pending.has(requestKey)) return pending.get(requestKey)
			const promise = new Promise((resolve, reject) => queued.push({ store: this, key, viewerId, resolve, reject, pending, requestKey }))
			pending.set(requestKey, promise)
			if (!scheduled) {
				scheduled = true
				queueMicrotask(flushRequests)
			}
			return promise
		},
		async toggleLike(type, id) {
			const viewerId = this.syncViewer()
			const key = `${type}:${id}`
			if (this.busy[key]) return
			this.busy[key] = true
			try {
				const current = await this.ensure(type, id)
				if (this.syncViewer() !== viewerId) return
				const { data } = await (current.liked ? deleteSocialLike(type, id) : putSocialLike(type, id))
				if (this.viewerId === viewerId) this.states[key] = normalizedState(data.data)
			} finally {
				if (this.viewerId === viewerId) this.busy[key] = false
			}
		},
		setCommentsCount(type, id, count) {
			this.syncViewer()
			const key = `${type}:${id}`
			this.states[key] = { ...this.getState(type, id), comments_count: Math.max(0, Number(count) || 0) }
		}
	}
})
