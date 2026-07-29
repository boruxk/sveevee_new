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

	const typeColor = computed(() => ({
		private_ad: 'primary',
		business_ad: 'secondary',
		community_ad: 'positive'
	}[props.ad.type] || 'dark'))

	const locationLabel = computed(() => [props.ad.neighborhood, props.ad.city].filter(Boolean).join(', '))
</script>

<template>
	<article class="ad-card">
		<div v-if="ad.image_url" class="ad-card__image" :style="{ backgroundImage: `url(${ad.image_url})` }" />
		<div class="ad-card__body">
			<div class="row items-center justify-between q-gutter-sm">
				<q-chip dense :color="typeColor" text-color="white">{{ typeLabel }}</q-chip>
				<router-link v-if="ad.user" :to="{ name: 'user-page', params: { id: ad.user.id } }" class="text-caption text-grey-7">
					{{ ad.user.display_name }}
				</router-link>
			</div>
			<h3 class="ad-card__title">{{ ad.title }}</h3>
			<div v-if="locationLabel" class="ad-card__location">
				<q-icon name="place" size="16px" />
				<span>{{ locationLabel }}</span>
			</div>
			<p class="ad-card__text">{{ ad.text }}</p>
			<div class="row items-center justify-between q-gutter-sm">
				<router-link v-if="ad.page" :to="{ name: 'page-detail', params: { id: ad.page.id } }" class="text-caption text-primary">
					{{ ad.page.name }}
				</router-link>
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
  padding: 18px;
}

.ad-card__title {
  margin: 14px 0 8px;
  font-size: 21px;
  line-height: 1.25;
}

.ad-card__text {
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
</style>
