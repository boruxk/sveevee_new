import { onBeforeUnmount, onMounted, readonly, ref } from 'vue'

const now = ref(Date.now())
let intervalId = null
let subscribers = 0

function startClock() {
	now.value = Date.now()

	if (intervalId === null) {
		intervalId = window.setInterval(() => {
			now.value = Date.now()
		}, 60_000)
	}
}

function stopClock() {
	if (subscribers === 0 && intervalId !== null) {
		window.clearInterval(intervalId)
		intervalId = null
	}
}

export function useMinuteClock() {
	onMounted(() => {
		subscribers += 1
		startClock()
	})

	onBeforeUnmount(() => {
		subscribers = Math.max(0, subscribers - 1)
		stopClock()
	})

	return readonly(now)
}
