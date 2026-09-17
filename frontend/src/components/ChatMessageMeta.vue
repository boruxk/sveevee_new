<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'

	const props = defineProps({
		createdAt: { type: String, default: '' },
		readAt: { type: String, default: null },
		own: { type: Boolean, default: false }
	})
	const { locale, t } = useI18n()
	const dateTime = computed(() => {
		if (!props.createdAt) return ''
		const date = new Date(props.createdAt)
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
	const statusLabel = computed(() => t(props.readAt ? 'chat.messageRead' : 'chat.messageSent'))
</script>

<template>
	<div class="chat-message-meta">
		<time v-if="dateTime" :datetime="createdAt">{{ dateTime }}</time>
		<span v-if="own"
			class="chat-message-meta__status"
			:class="{ 'chat-message-meta__status--read': readAt }"
			role="img"
			:aria-label="statusLabel"
			:title="statusLabel"
		>
			<svg viewBox="0 0 24 16" aria-hidden="true" focusable="false">
				<path d="m2 8 4 4L16 2" />
				<path v-if="readAt" d="m12 10 2 2L24 2" />
			</svg>
		</span>
	</div>
</template>

<style scoped>
.chat-message-meta {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 4px;
  margin-top: 4px;
  color: rgba(17, 34, 45, 0.56);
  font-size: 11px;
  line-height: 1.3;
  white-space: nowrap;
}

.chat-message-meta time {
  direction: ltr;
  unicode-bidi: isolate;
}

.chat-message-meta__status {
  display: inline-flex;
  flex-shrink: 0;
  color: #687681;
}

.chat-message-meta__status--read {
  color: #087cbe;
}

.chat-message-meta__status svg {
  width: 22px;
  height: 15px;
  overflow: visible;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}
</style>
