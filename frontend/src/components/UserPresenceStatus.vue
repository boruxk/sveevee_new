<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'

	const props = defineProps({
		presence: { type: Object, default: null }
	})
	const { locale, t } = useI18n()
	const isOnline = computed(() => Boolean(props.presence?.is_online))
	const lastSeenAt = computed(() => props.presence?.last_seen_at || '')
	const dateTime = computed(() => {
		if (!lastSeenAt.value) return ''
		const date = new Date(lastSeenAt.value)
		if (Number.isNaN(date.getTime())) return ''
		const intlLocale = { he: 'he-IL', en: 'en-US', ru: 'ru-RU', fr: 'fr-FR' }[locale.value] || locale.value
		return new Intl.DateTimeFormat(intlLocale, {
			year: 'numeric',
			month: '2-digit',
			day: '2-digit',
			hour: 'numeric',
			minute: '2-digit',
			hour12: false
		}).format(date)
	})
</script>

<template>
	<div v-if="isOnline || dateTime" class="user-presence-status" :class="{ 'user-presence-status--online': isOnline }">
		<template v-if="isOnline">
			<span class="user-presence-status__dot" aria-hidden="true" />
			<span>{{ t('chat.online') }}</span>
		</template>
		<i18n-t v-else keypath="chat.lastSeenAt" tag="span" scope="global">
			<template #date><time :datetime="lastSeenAt" dir="ltr">{{ dateTime }}</time></template>
		</i18n-t>
	</div>
</template>

<style scoped>
.user-presence-status {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
  color: rgba(17, 34, 45, 0.62);
  font-size: 12px;
  line-height: 1.4;
  overflow-wrap: anywhere;
}

.user-presence-status--online {
  color: #15803d;
  font-weight: 750;
}

.user-presence-status__dot {
  flex-shrink: 0;
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #16a34a;
}

.user-presence-status time {
  unicode-bidi: isolate;
}
</style>
