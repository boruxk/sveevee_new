<script setup>
	import { computed, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'

	const props = defineProps({
		event: {
			type: Object,
			required: true
		},
		editable: {
			type: Boolean,
			default: false
		},
		palette: {
			type: Object,
			default: null
		}
	})

	const emit = defineEmits(['delete', 'edit'])
	const { locale, t } = useI18n()
	const $q = useQuasar()
	const detailOpen = ref(false)
	const compactActionButtons = computed(() => $q.screen.width <= 700)
	const intlLocale = computed(() => ({
		he: 'he-IL',
		en: 'en-US',
		ru: 'ru-RU',
		fr: 'fr-FR'
	}[locale.value] || locale.value))
	const formattedDate = computed(() => {
		if (!props.event.date) {
			return ''
		}

		const date = new Date(`${props.event.date}T00:00:00`)

		if (Number.isNaN(date.getTime())) {
			return props.event.date
		}

		return new Intl.DateTimeFormat(intlLocale.value, { dateStyle: 'medium' }).format(date)
	})

	function parseTime(value) {
		const match = String(value || '').match(/^(\d{1,2}):(\d{2})/)

		if (!match) {
			return null
		}

		return new Date(1970, 0, 1, Number(match[1]), Number(match[2]))
	}

	function formatTime(value) {
		const date = parseTime(value)

		if (!date) {
			return value || ''
		}

		return new Intl.DateTimeFormat(intlLocale.value, {
			hour: 'numeric',
			minute: '2-digit',
			hour12: false
		}).format(date)
	}

	const formattedTime = computed(() => {
		const startDate = parseTime(props.event.time)
		const endDate = parseTime(props.event.end_time)

		if (startDate && endDate) {
			const formatter = new Intl.DateTimeFormat(intlLocale.value, {
				hour: 'numeric',
				minute: '2-digit',
				hour12: false
			})

			if (typeof formatter.formatRange === 'function') {
				return formatter.formatRange(startDate, endDate)
			}
		}

		const start = startDate ? formatTime(props.event.time) : props.event.time
		const end = endDate ? formatTime(props.event.end_time) : props.event.end_time

		return [start, end].filter(Boolean).join(' - ')
	})
	const formattedDateTime = computed(() => [formattedDate.value, formattedTime.value].filter(Boolean).join(' · '))
	const mapsUrl = computed(() => {
		const address = String(props.event.address || '').trim()

		if (!address) {
			return ''
		}

		return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(address)}`
	})
	const eventMeta = computed(() => [
		{ icon: 'event', value: formattedDateTime.value },
		{ icon: 'place', value: props.event.address, href: mapsUrl.value }
	].filter((item) => item.value))
	const themeStyle = computed(() => {
		if (!props.palette) {
			return null
		}

		return {
			'--presence-accent': props.palette.accent,
			'--presence-surface': props.palette.surface,
			'--presence-card': props.palette.card || 'rgba(255, 255, 255, 0.82)',
			'--presence-border': props.palette.border || 'rgba(17, 34, 45, 0.1)',
			'--presence-ink': props.palette.ink || '#151f2d',
			'--presence-muted': props.palette.muted || 'rgba(17, 34, 45, 0.72)'
		}
	})
</script>

<template>
	<article class="event-card" :style="themeStyle">
		<div v-if="event.image_url" class="event-card__image" :style="{ backgroundImage: `url(${event.image_url})` }" />
		<div class="event-card__body">
			<div class="event-card__copy">
				<h3 class="event-card__title">{{ event.name }}</h3>
				<p class="event-card__description">{{ event.description }}</p>
			</div>
			<div class="event-card__footer">
				<div class="event-card__meta">
					<div v-for="item in eventMeta" :key="item.icon" class="event-card__meta-row">
						<q-icon :name="item.icon" size="20px" />
						<a v-if="item.href"
							class="event-card__meta-link"
							:href="item.href"
							target="_blank"
							rel="noopener noreferrer"
						>
							{{ item.value }}
						</a>
						<span v-else>{{ item.value }}</span>
					</div>
				</div>
				<div class="event-card__actions">
					<q-btn
						class="event-card__view-btn"
						:round="compactActionButtons"
						:rounded="!compactActionButtons"
						unelevated
						color="primary"
						icon="visibility"
						:aria-label="t('events.open')"
						:label="compactActionButtons ? undefined : t('events.open')"
						@click="detailOpen = true"
					/>
					<q-btn v-if="editable"
						class="event-card__icon-btn"
						round
						unelevated
						color="secondary"
						icon="edit"
						:aria-label="t('actions.edit')"
						@click="emit('edit', event)"
					>
						<q-tooltip>{{ t('actions.edit') }}</q-tooltip>
					</q-btn>
					<q-btn v-if="editable"
						class="event-card__icon-btn"
						round
						unelevated
						color="negative"
						icon="delete"
						:aria-label="t('actions.delete')"
						@click="emit('delete', event)"
					>
						<q-tooltip>{{ t('actions.delete') }}</q-tooltip>
					</q-btn>
				</div>
			</div>
		</div>
	</article>
	<q-dialog v-model="detailOpen">
		<q-card class="event-detail-dialog" :style="themeStyle">
			<div v-if="event.image_url" class="event-detail-dialog__image" :style="{ backgroundImage: `url(${event.image_url})` }" />
			<q-card-section class="event-detail-dialog__body">
				<div class="event-detail-dialog__head">
					<div>
						<h3>{{ event.name }}</h3>
						<div class="event-detail-dialog__meta">
							<div v-for="item in eventMeta" :key="item.icon" class="event-detail-dialog__meta-row">
								<q-icon :name="item.icon" size="20px" />
								<a v-if="item.href"
									class="event-detail-dialog__meta-link"
									:href="item.href"
									target="_blank"
									rel="noopener noreferrer"
								>
									{{ item.value }}
								</a>
								<span v-else>{{ item.value }}</span>
							</div>
						</div>
					</div>
					<q-btn flat round icon="close" class="event-detail-dialog__close" v-close-popup />
				</div>
				<p class="event-detail-dialog__description">{{ event.description }}</p>
			</q-card-section>
		</q-card>
	</q-dialog>
</template>

<style scoped lang="scss">
.event-card {
  display: flex;
  flex-direction: column;
  max-height: 450px;
  overflow: hidden;
  border: 1px solid var(--presence-border, rgba(17, 34, 45, 0.1));
  border-radius: 8px;
  background: var(--presence-card, rgba(255, 255, 255, 0.78));
  color: var(--presence-ink, #151f2d);
}

.event-card__image {
  flex: 0 0 180px;
  min-height: 180px;
  background-position: center;
  background-size: cover;
}

.event-card__body {
  display: flex;
  flex-direction: column;
  gap: 18px;
  flex: 1;
  min-height: 220px;
  min-width: 0;
  overflow: hidden;
  padding: 18px;
}

.event-card__copy {
  min-height: 0;
  overflow: hidden;
}

.event-card__title {
  margin: 0 0 8px;
  font-size: 21px;
  line-height: 1.25;
}

.event-card__description {
  display: -webkit-box;
  overflow: hidden;
  margin: 0;
  color: var(--presence-muted, rgba(17, 34, 45, 0.72));
  line-height: 1.55;
  white-space: pre-line;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.event-card__footer {
  display: grid;
  gap: 14px;
  margin-top: auto;
}

.event-card__meta {
  display: grid;
  gap: 8px;
  color: var(--presence-muted, rgba(17, 34, 45, 0.72));
  font-weight: 650;
}

.event-card__meta-row {
  display: flex;
  gap: 8px;
  align-items: center;
  min-width: 0;
}

.event-card__meta-row span,
.event-card__meta-link {
  overflow: hidden;
  min-width: 0;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.event-card__meta-link,
.event-detail-dialog__meta-link {
  color: var(--presence-accent, #5f35f5);
  font-weight: 800;
  text-decoration: none;
}

.event-card__meta-link:hover,
.event-detail-dialog__meta-link:hover {
  color: color-mix(in srgb, var(--presence-accent, #f54291) 72%, var(--presence-ink, #151f2d));
}

.event-card__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
}

.event-card__icon-btn {
  aspect-ratio: 1;
  width: 53px;
  min-width: 53px;
  height: 53px;
  min-height: 53px;
  padding: 0;
}

.event-detail-dialog {
  overflow: hidden;
  width: min(720px, calc(100vw - 24px));
  max-width: 720px;
  max-height: calc(100vh - 32px);
  border: 1px solid color-mix(in srgb, var(--presence-accent, #f97316) 28%, var(--presence-border, rgba(17, 34, 45, 0.1)));
  border-radius: 24px;
  background:
    radial-gradient(circle at top left, color-mix(in srgb, var(--presence-accent, #f97316) 16%, transparent), transparent 42%),
    color-mix(in srgb, var(--presence-surface, #fffaf6) 88%, var(--presence-accent, #f97316) 12%);
  color: var(--presence-ink, #151f2d);
  box-shadow: 0 24px 58px color-mix(in srgb, var(--presence-accent, #f97316) 18%, transparent);
}

.event-detail-dialog__image {
  min-height: 260px;
  background-position: center;
  background-size: cover;
}

.event-detail-dialog__body {
  display: grid;
  gap: 18px;
}

.event-detail-dialog__head {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  justify-content: space-between;
}

.event-detail-dialog__head h3 {
  margin: 0 0 12px;
  color: var(--presence-ink, #151f2d);
  font-size: 28px;
  line-height: 1.2;
}

.event-detail-dialog__meta {
  display: grid;
  gap: 8px;
  color: var(--presence-muted, rgba(17, 34, 45, 0.72));
  font-weight: 650;
}

.event-detail-dialog__meta-row {
  display: flex;
  gap: 8px;
  align-items: center;
  min-width: 0;
}

.event-detail-dialog__meta-row span,
.event-detail-dialog__meta-link {
  min-width: 0;
  overflow-wrap: anywhere;
}

.event-detail-dialog__description {
  overflow-y: auto;
  max-height: 34vh;
  margin: 0;
  color: var(--presence-muted, rgba(17, 34, 45, 0.76));
  line-height: 1.65;
  white-space: pre-line;
}

.event-detail-dialog__close {
  color: var(--presence-ink, #151f2d) !important;
}

@media (max-width: 700px) {
  .event-card {
    max-height: none;
  }

  .event-card__image {
    flex-basis: 160px;
    min-height: 160px;
  }

  .event-card__body {
    min-height: auto;
    padding: 16px;
  }

  .event-card__meta-row span,
  .event-card__meta-link {
    white-space: normal;
    overflow-wrap: anywhere;
  }

  .event-card__actions {
    justify-content: flex-start;
  }

  .event-card__view-btn {
    aspect-ratio: 1;
    width: 53px;
    min-width: 53px;
    height: 53px;
    min-height: 53px;
    padding: 0;
  }

  .event-detail-dialog {
    width: calc(100vw - 20px);
    max-height: calc(100dvh - 20px);
    border-radius: 20px;
  }

  .event-detail-dialog__image {
    min-height: 190px;
  }

  .event-detail-dialog__body {
    padding: 18px;
  }

  .event-detail-dialog__head h3 {
    font-size: 24px;
    overflow-wrap: anywhere;
  }
}
</style>
