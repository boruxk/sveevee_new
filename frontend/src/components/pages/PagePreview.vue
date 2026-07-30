<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'
	import RatingStars from '@/components/ratings/RatingStars.vue'

	const props = defineProps({
		page: {
			type: Object,
			default: () => ({})
		},
		palette: {
			type: Object,
			required: true
		},
		canRate: {
			type: Boolean,
			default: false
		}
	})

	const emit = defineEmits(['show-ratings', 'rate'])
	const { t } = useI18n()

	const pageType = computed(() => props.page?.type || 'business')
	const pageTypeLabel = computed(() => t(`pages.kinds.${pageType.value}`))
	const previewTitle = computed(() => props.page?.name?.trim() || pageTypeLabel.value)
	const previewDescription = computed(() => props.page?.public_description?.trim() || t(`pages.previewFallbacks.${pageType.value}`))
	const previewContact = computed(() => {
		const contact = props.page?.contact || {}

		return [
			{ label: t('pages.tel'), value: contact.tel || props.page?.phone || null },
			{ label: t('pages.email'), value: contact.email || props.page?.contact_email || null },
			{ label: t('pages.whatsapp'), value: contact.whatsapp || null }
		].filter((item) => item.value)
	})
	const previewAddress = computed(() => {
		const address = props.page?.address_details || props.page?.address || {}

		if (typeof address === 'string') {
			return address
		}

		return [address.street, address.number, address.neighborhood, address.city].filter(Boolean).join(', ')
	})
	const previewOpeningHours = computed(() => props.page?.opening_hours || [])
	const previewLogoUrl = computed(() => props.page?.logo_url || null)
	const previewBannerUrl = computed(() => props.page?.banner_url || null)
	const ratingSummary = computed(() => props.page?.rating_summary || { average: 0, count: 0 })
	const ratingAverage = computed(() => Number(ratingSummary.value.average || 0))
	const ratingCount = computed(() => Number(ratingSummary.value.count || 0))
	const ratingText = computed(() => {
		if (ratingCount.value === 0) {
			return t('ratings.noRatings')
		}

		return t('ratings.summary', {
			average: ratingAverage.value.toFixed(1),
			count: ratingCount.value
		})
	})
	const previewStyle = computed(() => ({
		'--presence-accent': props.palette.accent,
		'--presence-surface': props.palette.surface,
		'--presence-card': props.palette.card || 'rgba(255, 255, 255, 0.78)',
		'--presence-border': props.palette.border || 'rgba(17, 34, 45, 0.08)',
		'--presence-hero': props.palette.hero,
		'--presence-overlay': props.palette.overlay || 'radial-gradient(circle at top right, rgba(255, 255, 255, 0.56), transparent 40%), linear-gradient(180deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.58))',
		'--presence-ink': props.palette.ink,
		'--presence-muted': props.palette.muted,
		'--presence-banner-border': props.palette.dark ? 'rgba(255, 255, 255, 0.22)' : 'rgba(17, 34, 45, 0.12)',
		'--presence-logo-bg': props.palette.dark ? 'rgba(255, 255, 255, 0.12)' : 'rgba(255, 255, 255, 0.92)',
		'--presence-shadow': props.palette.dark ? 'rgba(0, 0, 0, 0.34)' : 'rgba(17, 34, 45, 0.14)'
	}))
	const previewClasses = computed(() => ({
		'page-preview--dark': Boolean(props.palette.dark)
	}))
</script>

<template>
	<article class="page-preview" :class="previewClasses" :style="previewStyle">
		<div class="page-preview__hero">
			<div class="page-preview__banner" :style="previewBannerUrl ? { backgroundImage: `url(${previewBannerUrl})` } : null" />
			<div class="page-preview__overlay" />

			<div class="page-preview__intro">
				<q-avatar class="page-preview__logo" size="96px" square>
					<img v-if="previewLogoUrl" :src="previewLogoUrl" alt="" />
					<span v-else>{{ previewTitle.slice(0, 1).toUpperCase() }}</span>
				</q-avatar>

				<div class="page-preview__copy">
					<h2 class="page-preview__title">{{ previewTitle }}</h2>
					<p class="page-preview__description">{{ previewDescription }}</p>
				</div>
			</div>
		</div>

		<div class="page-preview__body">
			<div class="page-preview__column">
				<div class="page-preview__detail-card">
					<div class="page-preview__section-title">{{ t('pages.sections.contact') }}</div>
					<div v-if="previewContact.length > 0" class="page-preview__detail-list">
						<div v-for="item in previewContact" :key="item.label" class="page-preview__detail-row">
							<span class="page-preview__detail-label">{{ item.label }}</span>
							<span>{{ item.value }}</span>
						</div>
					</div>
					<div v-else class="text-body2 page-preview__empty">{{ t('pages.noContact') }}</div>
				</div>

				<div class="page-preview__detail-card">
					<div class="page-preview__section-title">{{ t('pages.sections.address') }}</div>
					<div class="text-body2" :class="{ 'page-preview__empty': !previewAddress }">
						{{ previewAddress || t('pages.noAddress') }}
					</div>
				</div>

				<div class="page-preview__detail-card">
					<div class="page-preview__section-title">{{ t('ratings.title') }}</div>
					<div class="page-preview__rating-row">
						<div class="page-preview__rating-score">
							<RatingStars readonly :value="ratingAverage" />
							<div class="text-body2 page-preview__empty">{{ ratingText }}</div>
						</div>
						<div class="page-preview__rating-actions">
							<q-btn rounded
								unelevated
								color="primary"
								class="page-preview__ratings-btn"
								icon="reviews"
								:disable="!page?.id"
								:label="t('ratings.allRatings')"
								@click="emit('show-ratings')"
							/>
							<q-btn v-if="canRate"
								rounded
								unelevated
								color="primary"
								icon="star"
								:label="t('ratings.rate')"
								@click="emit('rate')"
							/>
						</div>
					</div>
				</div>
			</div>

			<div class="page-preview__column">
				<div class="page-preview__detail-card">
					<div class="page-preview__section-title">{{ t('pages.sections.openingHours') }}</div>
					<div v-if="previewOpeningHours.length > 0" class="page-preview__hours-list">
						<div v-for="item in previewOpeningHours" :key="item.weekday" class="page-preview__detail-row">
							<span class="page-preview__detail-label">{{ t(`pages.weekdays.${item.weekday}`) }}</span>
							<span>{{ item.is_open ? `${item.opens_at} - ${item.closes_at}` : t('pages.closed') }}</span>
						</div>
					</div>
					<div v-else class="text-body2 page-preview__empty">{{ t('pages.noOpeningHours') }}</div>
				</div>
			</div>
		</div>
	</article>
</template>

<style scoped lang="scss">
.page-preview {
  display: grid;
  gap: 26px;
  padding: 24px;
  border: 1px solid var(--presence-border);
  border-radius: 36px;
  background: var(--presence-surface);
  color: var(--presence-ink);
}

.page-preview__hero {
  position: relative;
  overflow: hidden;
  min-height: 320px;
  border: 1px solid var(--presence-banner-border);
  border-radius: 36px;
  background: var(--presence-hero);
}

.page-preview__banner,
.page-preview__overlay {
  position: absolute;
  inset: 0;
}

.page-preview__banner {
  background: var(--presence-hero);
  background-size: cover;
  background-position: center;
}

.page-preview__overlay {
  background: var(--presence-overlay);
}

.page-preview__intro {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 26px;
  align-items: end;
  min-height: 320px;
  padding: 36px;
}

.page-preview__logo {
  border-radius: 28px;
  background: var(--presence-logo-bg);
  color: var(--presence-accent);
  font-size: 38px;
  font-weight: 700;
  box-shadow: 0 18px 44px var(--presence-shadow);
}

.page-preview__logo :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.page-preview__copy {
  max-width: 760px;
}

.page-preview__title {
  margin: 0;
  font-size: clamp(2.2rem, 4vw, 3.6rem);
  line-height: 1;
}

.page-preview__description {
  max-width: 620px;
  margin: 14px 0 0;
  color: var(--presence-muted);
  font-size: 1.02rem;
  line-height: 1.7;
}

.page-preview__body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(360px, 0.8fr);
  gap: 18px;
}

.page-preview__column {
  display: grid;
  align-content: start;
  gap: 14px;
  padding: 24px;
  border: 1px solid var(--presence-border);
  border-radius: 28px;
  background: var(--presence-surface);
}

.page-preview__section-title {
  color: var(--presence-muted);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.page-preview__detail-card {
  padding: 20px;
  border: 1px solid var(--presence-border);
  border-radius: 24px;
  background: var(--presence-card);
}

.page-preview__detail-list,
.page-preview__hours-list {
  display: grid;
  gap: 10px;
  margin-top: 14px;
}

.page-preview__detail-row {
  display: grid;
  grid-template-columns: 120px 1fr;
  gap: 12px;
  color: var(--presence-ink);
}

.page-preview__detail-label {
  color: var(--presence-muted);
}

.page-preview__empty {
  color: var(--presence-muted);
}

.page-preview__rating-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 14px;
  align-items: center;
  margin-top: 14px;
}

.page-preview__rating-score,
.page-preview__rating-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.page-preview__rating-actions {
  justify-content: flex-end;
}

.page-preview__ratings-btn.q-btn.bg-primary {
  background: var(--soz-action-gradient) !important;
  box-shadow: 0 14px 28px rgba(245, 66, 145, 0.22) !important;
}

@media (max-width: 900px) {
  .page-preview__intro,
  .page-preview__body {
    grid-template-columns: 1fr;
  }

  .page-preview__intro {
    align-items: flex-start;
  }
}

@media (max-width: 640px) {
  .page-preview__intro {
    padding: 24px;
  }

  .page-preview__rating-row,
  .page-preview__detail-row {
    grid-template-columns: 1fr;
  }

  .page-preview__rating-actions {
    justify-content: flex-start;
  }
}
</style>
