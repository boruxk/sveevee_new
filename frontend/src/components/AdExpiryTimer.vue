<script setup>
	import { computed, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useMinuteClock } from '@/composables/useMinuteClock'

	const props = defineProps({
		expiresAt: {
			type: String,
			default: ''
		}
	})
	const emit = defineEmits(['expired'])
	const { t, locale } = useI18n()
	const now = useMinuteClock()
	const expiredEmitted = ref(false)
	const expiryTime = computed(() => new Date(props.expiresAt).getTime())
	const remaining = computed(() => Number.isFinite(expiryTime.value) ? expiryTime.value - now.value : null)
	const isVisible = computed(() => remaining.value !== null && remaining.value > 0)
	const isCritical = computed(() => isVisible.value && remaining.value < 60 * 60 * 1000)
	const isWarning = computed(() => isVisible.value && !isCritical.value && remaining.value < 24 * 60 * 60 * 1000)
	const intlLocale = computed(() => ({ he: 'he-IL', en: 'en-US', ru: 'ru-RU', fr: 'fr-FR' }[locale.value] || locale.value))
	const exactExpiry = computed(() => {
		if (!Number.isFinite(expiryTime.value)) {
			return ''
		}

		return new Intl.DateTimeFormat(intlLocale.value, {
			dateStyle: 'medium',
			timeStyle: 'short'
		}).format(new Date(expiryTime.value))
	})
	const countdown = computed(() => {
		const totalMinutes = Math.max(1, Math.ceil((remaining.value || 0) / 60_000))
		const days = Math.floor(totalMinutes / 1440)
		const hours = Math.floor((totalMinutes % 1440) / 60)
		const minutes = totalMinutes % 60

		if (days > 0) {
			return `${days}${t('ads.timer.dayShort')} ${hours}${t('ads.timer.hourShort')}`
		}

		if (hours > 0) {
			return `${hours}${t('ads.timer.hourShort')} ${minutes}${t('ads.timer.minuteShort')}`
		}

		return `${minutes}${t('ads.timer.minuteShort')}`
	})

	watch(remaining, (value) => {
		if (value !== null && value <= 0 && !expiredEmitted.value) {
			expiredEmitted.value = true
			emit('expired')
		}
	}, { immediate: true })
</script>

<template>
	<span
		v-if="isVisible"
		class="ad-expiry"
		:class="{ 'ad-expiry--warning': isWarning, 'ad-expiry--critical': isCritical }"
	>
		<q-icon name="schedule" size="16px" />
		<span>{{ t('ads.timer.expiresIn', { time: countdown }) }}</span>
		<q-tooltip>{{ t('ads.timer.exact', { date: exactExpiry }) }}</q-tooltip>
	</span>
</template>

<style scoped lang="scss">
.ad-expiry {
  display: inline-flex;
  gap: 5px;
  align-items: center;
  width: fit-content;
  margin-top: 10px;
  color: rgba(17, 34, 45, 0.58);
  font-size: 13px;
  font-weight: 700;
}

.ad-expiry--warning {
  color: #b55b00;
}

.ad-expiry--critical {
  color: #c6284b;
}
</style>
