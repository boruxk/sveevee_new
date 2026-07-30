<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'

	const props = defineProps({
		ad: {
			type: Object,
			required: true
		},
		editable: {
			type: Boolean,
			default: false
		}
	})

	const emit = defineEmits(['delete'])
	const { t } = useI18n()

	const typeLabel = computed(() => ({
		private_ad: t('ads.private'),
		business_ad: t('ads.business'),
		community_ad: t('ads.community')
	}[props.ad.type] || props.ad.type))

	const badgeTypeLabel = computed(() => ({
		private_ad: t('ads.badges.private'),
		business_ad: t('ads.badges.business'),
		community_ad: t('ads.badges.community')
	}[props.ad.type] || typeLabel.value))

	const typeColor = computed(() => ({
		private_ad: 'primary',
		business_ad: 'secondary',
		community_ad: 'positive'
	}[props.ad.type] || 'dark'))

	const locationLabel = computed(() => [props.ad.neighborhood, props.ad.city].filter(Boolean).join(', '))
	const ownerName = computed(() => props.ad.page?.name || props.ad.user?.display_name || '')
	const badgeLabel = computed(() => [badgeTypeLabel.value, ownerName.value].filter(Boolean).join(': '))
	const detailRoute = computed(() => {
		if (props.ad.page?.id) {
			return { name: 'page-detail', params: { id: props.ad.page.id } }
		}

		if (props.ad.user?.id) {
			return { name: 'user-page', params: { id: props.ad.user.id } }
		}

		return null
	})
</script>

<template>
	<article class="ad-card">
		<div v-if="ad.image_url" class="ad-card__image" :style="{ backgroundImage: `url(${ad.image_url})` }" />
		<div class="ad-card__body">
			<div class="ad-card__head">
				<q-chip dense :color="typeColor" text-color="white" class="ad-card__badge">
					{{ badgeLabel }}
				</q-chip>
			</div>
			<h3 class="ad-card__title">{{ ad.title }}</h3>
			<div v-if="locationLabel" class="ad-card__location">
				<q-icon name="place" size="16px" />
				<span>{{ locationLabel }}</span>
			</div>
			<p class="ad-card__text">{{ ad.text }}</p>
			<div class="ad-card__actions">
				<q-btn v-if="detailRoute"
					rounded
					unelevated
					color="primary"
					icon="arrow_forward"
					:label="t('actions.learnMore')"
					:to="detailRoute"
				/>
				<q-btn v-if="editable"
					flat
					dense
					color="negative"
					icon="delete"
					:label="t('actions.delete')"
					@click="emit('delete', ad)"
				/>
			</div>
		</div>
	</article>
</template>

<style scoped lang="scss">
.ad-card {
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.76);
}

.ad-card__image {
  min-height: 170px;
  background-size: cover;
  background-position: center;
}

.ad-card__body {
  display: flex;
  flex-direction: column;
  padding: 18px;
}

.ad-card__head {
  display: flex;
  align-items: flex-start;
}

.ad-card__badge {
  max-width: 100%;
}

.ad-card__badge :deep(.q-chip__content) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ad-card__title {
  margin: 14px 0 8px;
  font-size: 21px;
  line-height: 1.25;
}

.ad-card__text {
  flex: 1;
  color: rgba(17, 34, 45, 0.72);
  white-space: pre-line;
}

.ad-card__location {
  display: inline-flex;
  gap: 4px;
  align-items: center;
  color: rgba(17, 34, 45, 0.56);
  font-size: 13px;
}

.ad-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  justify-content: space-between;
  margin-top: 16px;
}
</style>
