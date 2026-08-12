<script setup>
	import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { localizedAdCategoryMeta } from '@/constants/adCategories'

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

	const emit = defineEmits(['delete', 'edit'])
	const { t, locale } = useI18n()
	const $q = useQuasar()
	const textWrapRef = ref(null)
	const textRef = ref(null)
	const isExpanded = ref(false)
	const hasOverflow = ref(false)
	let resizeObserver = null
	const compactActionButtons = computed(() => $q.screen.width <= 700)

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
		community_ad: 'secondary'
	}[props.ad.type] || 'dark'))

	const imageStyle = computed(() => (props.ad.image_url ? { backgroundImage: `url("${props.ad.image_url}")` } : null))
	const locationLabel = computed(() => [props.ad.neighborhood, props.ad.city].filter(Boolean).join(', '))
	const ownerName = computed(() => props.ad.page?.name || props.ad.user?.display_name || '')
	const badgeLabel = computed(() => [badgeTypeLabel.value, ownerName.value].filter(Boolean).join(': '))
	const categoryMeta = computed(() => {
		const currentLocale = locale.value

		return localizedAdCategoryMeta(props.ad.category, (key) => t(key, { currentLocale }))
	})
	const cardStyle = computed(() => (categoryMeta.value ? {
		'--ad-category-color': categoryMeta.value.color,
		'--ad-category-soft': categoryMeta.value.soft,
		'--ad-category-x': locale.value === 'he' ? '0%' : '100%'
	} : null))

	function measureOverflow() {
		if (isExpanded.value || !textWrapRef.value || !textRef.value) {
			return
		}

		hasOverflow.value = textRef.value.scrollHeight > textWrapRef.value.clientHeight + 1
	}

	function expandCard() {
		isExpanded.value = true
	}

	function collapseCard() {
		isExpanded.value = false
		nextTick(measureOverflow)
	}

	watch(() => [props.ad.title, props.ad.text], async() => {
		isExpanded.value = false
		hasOverflow.value = false
		await nextTick()
		measureOverflow()
	})

	onMounted(async() => {
		await nextTick()
		measureOverflow()

		if (window.ResizeObserver && textWrapRef.value) {
			resizeObserver = new ResizeObserver(measureOverflow)
			resizeObserver.observe(textWrapRef.value)
		}
	})

	onBeforeUnmount(() => {
		resizeObserver?.disconnect()
	})
</script>

<template>
	<article
		class="listing-card"
		:class="{ 'listing-card--with-image': imageStyle, 'listing-card--expanded': isExpanded, 'listing-card--with-category': categoryMeta }"
		:style="cardStyle"
	>
		<div v-if="imageStyle" class="listing-card__image" :style="imageStyle" />
		<div class="listing-card__body">
			<div class="listing-card__head">
				<q-chip dense :color="typeColor" text-color="white" class="listing-card__badge">
					{{ badgeLabel }}
				</q-chip>
				<q-chip
					v-if="categoryMeta"
					dense
					text-color="white"
					class="listing-card__badge listing-card__category-badge"
					:style="{ backgroundColor: categoryMeta.color }"
				>
					{{ categoryMeta.label }}
				</q-chip>
			</div>
			<h3 class="listing-card__title">{{ ad.title }}</h3>
			<div v-if="locationLabel" class="listing-card__location">
				<q-icon name="place" size="16px" />
				<span>{{ locationLabel }}</span>
			</div>
			<div ref="textWrapRef" class="listing-card__text-wrap" :class="{ 'listing-card__text-wrap--overflow': hasOverflow && !isExpanded }">
				<p ref="textRef" class="listing-card__text">{{ ad.text }}</p>
			</div>
			<div v-if="hasOverflow || editable" class="listing-card__footer">
				<q-btn v-if="hasOverflow && !isExpanded"
					flat
					dense
					color="primary"
					icon="unfold_more"
					:label="t('actions.readMore')"
					@click="expandCard"
				/>
				<q-btn v-else-if="hasOverflow"
					flat
					dense
					color="primary"
					icon="unfold_less"
					:label="t('actions.readLess')"
					@click="collapseCard"
				/>
				<div v-if="editable" class="listing-card__actions">
					<q-btn
						class="listing-card__icon-btn"
						:round="compactActionButtons"
						:rounded="!compactActionButtons"
						unelevated
						color="secondary"
						icon="edit"
						:aria-label="t('actions.edit')"
						:label="compactActionButtons ? undefined : t('actions.edit')"
						@click="emit('edit', ad)"
					/>
					<q-btn
						class="listing-card__icon-btn"
						:round="compactActionButtons"
						:rounded="!compactActionButtons"
						unelevated
						color="negative"
						icon="delete"
						:aria-label="t('actions.delete')"
						:label="compactActionButtons ? undefined : t('actions.delete')"
						@click="emit('delete', ad)"
					/>
				</div>
			</div>
		</div>
	</article>
</template>

<style scoped lang="scss">
.listing-card {
  position: relative;
  isolation: isolate;
  display: grid;
  grid-template-columns: 1fr;
  height: 350px;
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.76);
}

.listing-card--expanded {
  height: auto;
  min-height: 350px;
}

.listing-card--with-image {
  grid-template-columns: minmax(220px, 32%) minmax(0, 1fr);
  direction: ltr;
}

.listing-card__image {
  position: relative;
  z-index: 1;
  min-height: 170px;
  background-size: cover;
  background-position: center;
}

.listing-card--with-image .listing-card__image {
  grid-column: 1;
  grid-row: 1;
  min-height: 350px;
}

.listing-card__body {
  position: relative;
  z-index: 1;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  padding: 22px;
}

.listing-card__body::after {
  position: absolute;
  inset: 0;
  z-index: 0;
  background:
    radial-gradient(ellipse 52% 72% at var(--ad-category-x, 100%) 100%, var(--ad-category-soft, transparent) 0%, rgba(255, 255, 255, 0) 76%),
    radial-gradient(ellipse 38% 54% at var(--ad-category-x, 100%) 100%, var(--ad-category-soft, transparent) 0%, rgba(255, 255, 255, 0) 68%);
  content: "";
  opacity: 0;
  pointer-events: none;
}

.listing-card--with-category .listing-card__body::after {
  opacity: 1;
}

.listing-card__head,
.listing-card__title,
.listing-card__location,
.listing-card__text-wrap,
.listing-card__footer {
  position: relative;
  z-index: 1;
}

.listing-card--with-image .listing-card__body {
  grid-column: 2;
  grid-row: 1;
}

.sveevee-ltr .listing-card__body {
  direction: ltr;
}

.sveevee-rtl .listing-card__body {
  direction: rtl;
}

.listing-card__head {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: flex-start;
}

.listing-card__badge {
  max-width: 100%;
  min-height: 30px;
  margin: 0;
  padding: 0 11px;
  border-radius: 999px;
  font-size: 13px;
  font-weight: 800;
}

.listing-card__badge :deep(.q-chip__content) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.listing-card__category-badge {
  box-shadow: 0 10px 22px rgba(17, 34, 45, 0.13);
}

.listing-card__title {
  margin: 16px 0 10px;
  font-size: 30px;
  line-height: 1.18;
}

.listing-card__text-wrap {
  position: relative;
  flex: 1;
  min-height: 0;
  overflow: visible;
}

.listing-card:not(.listing-card--expanded) .listing-card__text-wrap {
  overflow: hidden;
}

.listing-card:not(.listing-card--expanded) .listing-card__text-wrap--overflow {
  -webkit-mask-image: linear-gradient(180deg, #000 0, #000 calc(100% - 46px), rgba(0, 0, 0, 0) 100%);
  mask-image: linear-gradient(180deg, #000 0, #000 calc(100% - 46px), rgba(0, 0, 0, 0) 100%);
}

.listing-card__text {
  margin: 0;
  color: rgba(17, 34, 45, 0.72);
  font-size: 18px;
  line-height: 1.58;
  white-space: pre-line;
}

.listing-card__location {
  display: inline-flex;
  gap: 4px;
  align-items: center;
  color: rgba(17, 34, 45, 0.56);
  font-size: 14px;
}

.listing-card__footer {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  justify-content: flex-start;
  direction: ltr;
  margin-top: 16px;
}

.listing-card__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  justify-content: flex-end;
  margin-left: auto;
}

@media (max-width: 700px) {
  .listing-card {
    height: auto;
    min-height: 350px;
  }

  .listing-card--with-image {
    grid-template-columns: 1fr;
  }

  .listing-card--with-image .listing-card__image,
  .listing-card--with-image .listing-card__body {
    grid-column: 1;
  }

  .listing-card--with-image .listing-card__image {
    grid-row: 1;
    min-height: 190px;
  }

  .listing-card--with-image .listing-card__body {
    grid-row: 2;
  }

  .listing-card__body {
    padding: 18px;
  }

  .listing-card__title {
    font-size: 24px;
  }

  .listing-card__footer,
  .listing-card__actions {
    align-items: stretch;
    flex-direction: column;
  }

  .listing-card__actions {
    width: 100%;
    flex-direction: row;
    align-items: center;
    justify-content: flex-end;
    margin-left: 0;
  }

  .listing-card__footer > .q-btn,
  .listing-card__actions .q-btn:not(.listing-card__icon-btn) {
    width: 100%;
  }

  .listing-card__icon-btn {
    aspect-ratio: 1;
    width: 53px;
    min-width: 53px;
    height: 53px;
    min-height: 53px;
    padding: 0;
  }
}
</style>
