<script setup>
	import { computed, ref } from 'vue'
	import { useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useNotificationsStore } from '@/stores/notifications'
	import {
		notificationActionPath,
		notificationParameters,
		notificationTranslationKeys
	} from '@/utils/accountNotifications'
	import NotificationBellIcon from '@/components/icons/NotificationBellIcon.vue'
	import NotificationTypeIcon from '@/components/icons/NotificationTypeIcon.vue'

	defineProps({
		mobile: {
			type: Boolean,
			default: false
		}
	})

	const router = useRouter()
	const $q = useQuasar()
	const { t, locale } = useI18n()
	const notificationsStore = useNotificationsStore()
	const menuOpen = ref(false)
	const unreadLabel = computed(() => notificationsStore.badgeLabel)

	function itemTitle(notification) {
		const keys = notificationTranslationKeys(notification)
		return t(keys.title, notificationParameters(notification))
	}

	function itemBody(notification) {
		const keys = notificationTranslationKeys(notification)
		return t(keys.body, notificationParameters(notification))
	}

	function itemTime(value) {
		const date = new Date(value)

		if (Number.isNaN(date.getTime())) {
			return ''
		}

		const seconds = Math.round((date.getTime() - Date.now()) / 1000)
		const formatter = new Intl.RelativeTimeFormat(locale.value, { numeric: 'auto' })

		if (Math.abs(seconds) < 60) {
			return formatter.format(seconds, 'second')
		}

		const minutes = Math.round(seconds / 60)
		if (Math.abs(minutes) < 60) {
			return formatter.format(minutes, 'minute')
		}

		const hours = Math.round(minutes / 60)
		if (Math.abs(hours) < 24) {
			return formatter.format(hours, 'hour')
		}

		const days = Math.round(hours / 24)
		if (Math.abs(days) < 7) {
			return formatter.format(days, 'day')
		}

		return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(date)
	}

	async function openNotification(notification) {
		try {
			await notificationsStore.markRead(notification)
		} catch {
			$q.notify({ type: 'negative', message: t('notifications.readFailed') })
		}

		menuOpen.value = false
		await router.push(notificationActionPath(notification))
	}

	async function markAllRead() {
		try {
			await notificationsStore.markAllRead()
		} catch {
			$q.notify({ type: 'negative', message: t('notifications.readFailed') })
		}
	}
</script>

<template>
	<q-btn
		flat
		round
		dense
		color="dark"
		class="notification-trigger"
		:class="{ 'notification-trigger--mobile': mobile }"
		:aria-label="t('notifications.label')"
	>
		<NotificationBellIcon :size="mobile ? 21 : 23" />
		<q-badge
			v-if="notificationsStore.unreadCount"
			floating
			rounded
			class="notification-badge"
		>{{ unreadLabel }}</q-badge>
		<q-menu
			v-model="menuOpen"
			anchor="bottom end"
			self="top end"
			class="notification-menu"
		>
			<header class="notification-menu__header">
				<div>
					<strong>{{ t('notifications.title') }}</strong>
					<span v-if="notificationsStore.unreadCount">
						{{ t('notifications.unread', { count: notificationsStore.unreadCount }) }}
					</span>
				</div>
				<q-btn
					v-if="notificationsStore.unreadCount"
					flat
					round
					dense
					color="primary"
					:aria-label="t('notifications.markAllRead')"
					@click.stop="markAllRead"
				>
					<svg class="notification-action-icon" viewBox="0 0 24 24" aria-hidden="true">
						<path d="m2 12 5 5L18 6" />
						<path d="m13 16 2 2 7-8" />
					</svg>
					<q-tooltip v-if="!mobile">{{ t('notifications.markAllRead') }}</q-tooltip>
				</q-btn>
			</header>

			<q-separator />

			<div v-if="notificationsStore.loading && !notificationsStore.items.length" class="notification-state">
				<q-spinner color="primary" size="28px" />
			</div>
			<div v-else-if="!notificationsStore.items.length" class="notification-state notification-state--empty">
				<NotificationBellIcon :size="28" />
				<span>{{ t('notifications.empty') }}</span>
			</div>
			<q-list v-else class="notification-list">
				<q-item
					v-for="notification in notificationsStore.items"
					:key="notification.id"
					clickable
					class="notification-item"
					:class="{ 'notification-item--unread': !notification.read_at }"
					@click="openNotification(notification)"
				>
					<q-item-section avatar class="notification-item__icon">
						<span><NotificationTypeIcon :type="notification.type" /></span>
					</q-item-section>
					<q-item-section class="notification-item__copy">
						<div class="notification-item__title">
							<i v-if="!notification.read_at" aria-hidden="true" />
							<strong>{{ itemTitle(notification) }}</strong>
						</div>
						<p>{{ itemBody(notification) }}</p>
						<time :datetime="notification.created_at">{{ itemTime(notification.created_at) }}</time>
					</q-item-section>
				</q-item>
			</q-list>

			<div v-if="notificationsStore.hasMore" class="notification-menu__more">
				<q-btn
					flat
					no-caps
					color="primary"
					:loading="notificationsStore.loading"
					@click.stop="notificationsStore.loadMore()"
				>
					<svg class="notification-action-icon notification-action-icon--chevron" viewBox="0 0 24 24" aria-hidden="true">
						<path d="m6 9 6 6 6-6" />
					</svg>
					<span>{{ t('notifications.loadMore') }}</span>
				</q-btn>
			</div>
		</q-menu>
	</q-btn>
</template>

<style scoped lang="scss">
.notification-trigger {
  position: relative;
  flex: 0 0 auto;
  width: 46px;
  height: 46px;
  color: rgba(21, 31, 59, 0.78);
}

.notification-trigger--mobile {
  width: 44px;
  height: 44px;
  background: rgba(255, 255, 255, 0.86);
  box-shadow: 0 12px 26px rgba(40, 22, 93, 0.12);
}

.notification-badge {
  top: 2px;
  inset-inline-end: 0;
  right: auto;
  min-width: 19px;
  height: 19px;
  padding-inline: 4px;
  border: 2px solid #fff;
  background: var(--soz-action-gradient) !important;
  color: #fff !important;
  font-size: 10px;
  font-weight: 850;
  line-height: 15px;
}

.notification-action-icon {
  width: 21px;
  height: 21px;
  fill: none;
  stroke: currentColor;
  stroke-width: 2;
  stroke-linecap: round;
  stroke-linejoin: round;
}

.notification-action-icon--chevron {
  margin-inline-end: 5px;
}

:global(.notification-menu) {
  width: min(410px, calc(100vw - 20px));
  max-height: min(620px, calc(100vh - 88px));
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 8px;
  box-shadow: 0 20px 52px rgba(35, 25, 64, 0.2);
}

.notification-menu__header {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  min-height: 68px;
  padding: 13px 16px;
  background: #fff;
}

.notification-menu__header > div {
  display: grid;
  gap: 2px;
}

.notification-menu__header strong {
  color: var(--soz-ink);
  font-size: 16px;
  line-height: 1.25;
}

.notification-menu__header span {
  color: var(--soz-muted);
  font-size: 12px;
}

.notification-list {
  max-height: min(480px, calc(100vh - 190px));
  overflow-y: auto;
  background: #fff;
}

.notification-item {
  min-height: 92px;
  padding: 13px 15px;
  border-bottom: 1px solid rgba(17, 34, 45, 0.07);
  align-items: flex-start;
}

.notification-item--unread {
  background: rgba(123, 63, 242, 0.055);
}

.notification-item__icon {
  min-width: 44px;
  padding-inline-end: 10px;
}

.notification-item__icon > span {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: rgba(123, 63, 242, 0.1);
  color: var(--soz-primary);
}

.notification-item__copy {
  min-width: 0;
}

.notification-item__title {
  display: flex;
  gap: 7px;
  align-items: flex-start;
}

.notification-item__title i {
  flex: 0 0 auto;
  width: 7px;
  height: 7px;
  margin-top: 6px;
  border-radius: 50%;
  background: var(--soz-orange);
}

.notification-item__title strong {
  min-width: 0;
  color: var(--soz-ink);
  font-size: 13px;
  line-height: 1.35;
  overflow-wrap: anywhere;
}

.notification-item p {
  margin: 4px 0 5px;
  color: rgba(17, 34, 45, 0.66);
  font-size: 12px;
  line-height: 1.42;
  overflow-wrap: anywhere;
}

.notification-item time {
  color: rgba(17, 34, 45, 0.48);
  font-size: 11px;
}

.notification-state {
  display: grid;
  place-items: center;
  min-height: 150px;
  padding: 24px;
  background: #fff;
  color: var(--soz-primary);
}

.notification-state--empty {
  gap: 10px;
  color: var(--soz-muted);
  font-size: 13px;
  text-align: center;
}

.notification-menu__more {
  display: flex;
  justify-content: center;
  padding: 6px;
  border-top: 1px solid rgba(17, 34, 45, 0.07);
  background: #fff;
}

@media (max-width: 700px) {
  :global(.notification-menu) {
    max-height: calc(100dvh - 76px);
  }

  .notification-list {
    max-height: calc(100dvh - 210px);
  }
}
</style>
