import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import apiClient from '@/services/api/client'

let echo = null
let channelName = null

function websocketConfig() {
	const key = String(import.meta.env.VITE_REVERB_APP_KEY || '').trim()

	if (!key || typeof window === 'undefined') {
		return null
	}

	const scheme = String(import.meta.env.VITE_REVERB_SCHEME || window.location.protocol.replace(':', '') || 'https')
	const secure = scheme === 'https'
	const host = String(import.meta.env.VITE_REVERB_HOST || window.location.hostname)
	const port = Number(import.meta.env.VITE_REVERB_PORT || (secure ? 443 : 8080))

	return { key, secure, host, port }
}

export function connectNotificationRealtime({ userId, onNotification, onConnected }) {
	disconnectNotificationRealtime()

	const config = websocketConfig()

	if (!config || !userId) {
		return false
	}

	try {
		echo = new Echo({
			broadcaster: 'reverb',
			key: config.key,
			Pusher,
			wsHost: config.host,
			wsPort: config.port,
			wssPort: config.port,
			forceTLS: config.secure,
			enabledTransports: ['ws', 'wss'],
			disableStats: true,
			authorizer: (channel) => ({
				authorize: async(socketId, callback) => {
					try {
						const { data } = await apiClient.post('/broadcasting/auth', {
							socket_id: socketId,
							channel_name: channel.name
						}, { recaptcha: false })
						callback(null, data)
					} catch (error) {
						callback(error, null)
					}
				}
			})
		})
		channelName = `users.${userId}`
		echo.private(channelName)
			.listen('.account.notification.created', (event) => onNotification?.(event.notification))
		echo.connector?.pusher?.connection?.bind('connected', () => onConnected?.())

		return true
	} catch {
		disconnectNotificationRealtime()
		return false
	}
}

export function disconnectNotificationRealtime() {
	if (!echo) {
		return
	}

	if (channelName) {
		echo.leave(channelName)
	}

	echo.disconnect()
	echo = null
	channelName = null
}
