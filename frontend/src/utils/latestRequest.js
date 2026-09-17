// Superseded responses must never update the current view, even if transport ignores abort.
export function createLatestRequest() {
	let active = null
	let disposed = false

	return {
		run(key, { request, onStart, onResult, onError, onSettled }) {
			if (disposed) return Promise.resolve()
			if (active?.key === key) return active.promise
			active?.controller.abort()
			const entry = { key, controller: new AbortController(), promise: null }
			active = entry
			const isCurrent = () => active === entry && !entry.controller.signal.aborted
			onStart?.()
			entry.promise = Promise.resolve()
				.then(() => isCurrent() ? request(entry.controller.signal) : undefined)
				.then((result) => {
					if (isCurrent()) onResult?.(result)
				})
				.catch((error) => {
					if (isCurrent()) onError?.(error)
				})
				.finally(() => {
					if (isCurrent()) {
						active = null
						onSettled?.()
					}
				})

			return entry.promise
		},
		dispose() {
			disposed = true
			active?.controller.abort()
			active = null
		}
	}
}
