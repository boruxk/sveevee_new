<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'

	const props = defineProps({
		page: {
			type: Object,
			default: () => ({})
		},
		palette: {
			type: Object,
			required: true
		}
	})

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

		return [address.street, address.number, address.city].filter(Boolean).join(', ')
	})
	const previewOpeningHours = computed(() => props.page?.opening_hours || [])
	const previewLogoUrl = computed(() => props.page?.logo_url || null)
	const previewBannerUrl = computed(() => props.page?.banner_url || null)
	const previewStyle = computed(() => ({
		'--presence-accent': props.palette.accent,
		'--presence-surface': props.palette.surface,
		'--presence-hero': props.palette.hero,
		'--presence-ink': props.palette.ink,
		'--presence-muted': props.palette.muted
	}))
</script>

<template>
	<article class="page-preview" :style="previewStyle">
		<div class="page-preview__hero">
			<div class="page-preview__banner" :style="previewBannerUrl ? { backgroundImage: `url(${previewBannerUrl})` } : null" />
			<div class="page-preview__overlay" />

			<div class="page-preview__intro">
				<q-avatar class="page-preview__logo" size="96px" square>
					<img v-if="previewLogoUrl" :src="previewLogoUrl" alt="" />
					<span v-else>{{ previewTitle.slice(0, 1).toUpperCase() }}</span>
				</q-avatar>

				<div class="page-preview__copy">
					<q-chip dense square class="page-preview__badge">
						{{ pageTypeLabel }}
					</q-chip>
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
					<div v-else class="text-body2 text-grey-7">{{ t('pages.noContact') }}</div>
				</div>

				<div class="page-preview__detail-card">
					<div class="page-preview__section-title">{{ t('pages.sections.address') }}</div>
					<div class="text-body2" :class="{ 'text-grey-7': !previewAddress }">
						{{ previewAddress || t('pages.noAddress') }}
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
					<div v-else class="text-body2 text-grey-7">{{ t('pages.noOpeningHours') }}</div>
				</div>
			</div>
		</div>
	</article>
</template>

<style scoped lang="scss">
.page-preview {
  display: grid;
  gap: 26px;
  color: var(--presence-ink);
}

.page-preview__hero {
  position: relative;
  overflow: hidden;
  min-height: 320px;
  border: 1px solid rgba(17, 34, 45, 0.08);
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
  background:
    radial-gradient(circle at top right, rgba(255, 255, 255, 0.56), transparent 40%),
    linear-gradient(180deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.58));
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
  background: rgba(255, 255, 255, 0.92);
  color: var(--presence-accent);
  font-size: 38px;
  font-weight: 700;
  box-shadow: 0 18px 44px rgba(17, 34, 45, 0.14);
}

.page-preview__logo :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.page-preview__copy {
  max-width: 760px;
}

.page-preview__badge {
  margin-bottom: 14px;
  background: rgba(255, 255, 255, 0.9);
  color: var(--presence-ink);
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
  border: 1px solid rgba(17, 34, 45, 0.08);
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
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.78);
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

  .page-preview__detail-row {
    grid-template-columns: 1fr;
  }
}
</style>
