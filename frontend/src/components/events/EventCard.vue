<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'

	const props = defineProps({
		event: {
			type: Object,
			required: true
		}
	})

	const { locale } = useI18n()
	const formattedDate = computed(() => {
		if (!props.event.date) {
			return ''
		}

		const date = new Date(`${props.event.date}T00:00:00`)

		if (Number.isNaN(date.getTime())) {
			return props.event.date
		}

		return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(date)
	})
</script>

<template>
	<article class="event-card">
		<div v-if="event.image_url" class="event-card__image" :style="{ backgroundImage: `url(${event.image_url})` }" />
		<div class="event-card__body">
			<div>
				<h3 class="event-card__title">{{ event.name }}</h3>
				<p class="event-card__description">{{ event.description }}</p>
			</div>
			<div class="event-card__meta">
				<div class="event-card__meta-row">
					<q-icon name="event" size="20px" />
					<span>{{ formattedDate }}</span>
				</div>
				<div class="event-card__meta-row">
					<q-icon name="schedule" size="20px" />
					<span>{{ event.time }}</span>
				</div>
				<div class="event-card__meta-row">
					<q-icon name="place" size="20px" />
					<span>{{ event.address }}</span>
				</div>
			</div>
		</div>
	</article>
</template>

<style scoped lang="scss">
.event-card {
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.78);
}

.event-card__image {
  min-height: 180px;
  background-position: center;
  background-size: cover;
}

.event-card__body {
  display: flex;
  flex-direction: column;
  gap: 18px;
  min-height: 230px;
  padding: 18px;
}

.event-card__title {
  margin: 0 0 8px;
  font-size: 21px;
  line-height: 1.25;
}

.event-card__description {
  margin: 0;
  color: rgba(17, 34, 45, 0.72);
  line-height: 1.55;
  white-space: pre-line;
}

.event-card__meta {
  display: grid;
  gap: 8px;
  margin-top: auto;
  color: rgba(17, 34, 45, 0.72);
  font-weight: 650;
}

.event-card__meta-row {
  display: flex;
  gap: 8px;
  align-items: center;
}
</style>
