<script setup>
	import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
	import { RouterLink } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { localizedAdCategoryMeta } from '@/constants/adCategories'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import AdExpiryTimer from '@/components/AdExpiryTimer.vue'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { adRoute, catalogHubPath, catalogLabel, catalogPath, catalogTopicForAdCategory, pageRoute, userRoute } from '@/constants/catalogTopics'

	const props = defineProps({
		ad: {
			type: Object,
			required: true
		},
		editable: {
			type: Boolean,
			default: false
		},
		detailLinks: {
			type: Boolean,
			default: true
		}
	})

	const emit = defineEmits(['delete', 'edit', 'expired'])
	const { t, locale } = useI18n()
	const $q = useQuasar()
	const textWrapRef = ref(null)
	const textRef = ref(null)
	const isExpanded = ref(false)
	const hasOverflow = ref(false)
	const isVisible = ref(true)
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
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

	const hasImage = computed(() => Boolean(props.ad.image_url))
	const imageAlt = computed(() => props.ad.image_alt || props.ad.title || '')
	const imageSizes = computed(() => props.ad.image_sizes || '(max-width: 700px) calc(100vw - 36px), 360px')
	const locationLabel = computed(() => [props.ad.city, props.ad.neighborhood].filter(Boolean).join(', '))
	const ownerName = computed(() => props.ad.page?.name || props.ad.user?.display_name || '')
	const badgeLabel = computed(() => [badgeTypeLabel.value, ownerName.value].filter(Boolean).join(': '))
	const categoryTopic = computed(() => catalogTopicForAdCategory(catalogGroups.value, props.ad.category))
	const categoryMeta = computed(() => {
		const currentLocale = locale.value
		const legacyMeta = localizedAdCategoryMeta(props.ad.category, (key) => t(key, { currentLocale }))

		if (legacyMeta) {
			return legacyMeta
		}

		const topic = categoryTopic.value

		return topic ? {
			label: catalogLabel(topic.labels, locale.value),
			color: topic.color,
			soft: `${topic.color}24`
		} : null
	})
	const cardStyle = computed(() => (categoryMeta.value ? {
		'--ad-category-color': categoryMeta.value.color,
		'--ad-category-soft': categoryMeta.value.soft,
		'--ad-category-x': locale.value === 'he' ? '0%' : '100%'
	} : null))
	const ownerRoute = computed(() => {
		if (props.ad.page) {
			return pageRoute(props.ad.page)
		}

		if (props.ad.user?.id) {
			return userRoute(props.ad.user)
		}

		return null
	})
	const categoryRoute = computed(() => {
		if (categoryTopic.value) {
			return catalogPath(categoryTopic.value)
		}

		if (props.ad.category) {
			return { name: 'search', query: { scope: 'ads', category: props.ad.category } }
		}

		return null
	})
	const locationRoute = computed(() => {
		const city = props.ad.city || ''
		const neighborhood = props.ad.neighborhood || ''

		if (!city) {
			return null
		}

		if (categoryTopic.value) {
			return catalogPath(categoryTopic.value, city, neighborhood)
		}

		return catalogHubPath('ads', city, neighborhood)
	})
	const adDetailRoute = computed(() => {
		if (!props.detailLinks || props.editable || !props.ad.id) {
			return null
		}

		return adRoute(props.ad)
	})

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

	watch(() => [props.ad.title, props.ad.text, props.ad.expires_at], async() => {
		isVisible.value = true
		isExpanded.value = false
		hasOverflow.value = false
		await nextTick()
		measureOverflow()
	})

	function handleExpired() {
		isVisible.value = false
		emit('expired', props.ad)
	}

	onMounted(async() => {
		await loadCatalogTopics()
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
		v-if="isVisible"
		class="listing-card"
		:class="{ 'listing-card--with-image': hasImage, 'listing-card--expanded': isExpanded, 'listing-card--with-category': categoryMeta }"
		:style="cardStyle"
	>
		<RouterLink
			v-if="hasImage && adDetailRoute"
			:to="adDetailRoute"
			class="listing-card__image listing-card__image-link"
		>
			<ResponsiveImage
				class="listing-card__image-media"
				:src="ad.image_url"
				:alt="imageAlt"
				:avif-srcset="ad.image_avif_srcset || ''"
				:webp-srcset="ad.image_webp_srcset || ''"
				:sizes="imageSizes"
				:width="ad.image_width || 768"
				:height="ad.image_height || 576"
			/>
		</RouterLink>
		<ResponsiveImage
			v-else-if="hasImage"
			class="listing-card__image"
			:src="ad.image_url"
			:alt="imageAlt"
			:avif-srcset="ad.image_avif_srcset || ''"
			:webp-srcset="ad.image_webp_srcset || ''"
			:sizes="imageSizes"
			:width="ad.image_width || 768"
			:height="ad.image_height || 576"
		/>
		<div class="listing-card__body">
			<div class="listing-card__head">
				<RouterLink v-if="ownerRoute" :to="ownerRoute" class="listing-card__badge-link">
					<q-chip dense clickable :color="typeColor" text-color="white" class="listing-card__badge">
						{{ badgeLabel }}
					</q-chip>
				</RouterLink>
				<q-chip v-else dense :color="typeColor" text-color="white" class="listing-card__badge">
					{{ badgeLabel }}
				</q-chip>
				<RouterLink v-if="categoryMeta && categoryRoute" :to="categoryRoute" class="listing-card__badge-link">
					<q-chip
						dense
						clickable
						text-color="white"
						class="listing-card__badge listing-card__category-badge"
						:style="{ backgroundColor: categoryMeta.color }"
					>
						{{ categoryMeta.label }}
					</q-chip>
				</RouterLink>
				<q-chip
					v-else-if="categoryMeta"
					dense
					text-color="white"
					class="listing-card__badge listing-card__category-badge"
					:style="{ backgroundColor: categoryMeta.color }"
				>
					{{ categoryMeta.label }}
				</q-chip>
			</div>
			<h3 class="listing-card__title">
				<RouterLink v-if="adDetailRoute" :to="adDetailRoute">{{ ad.title }}</RouterLink>
				<span v-else>{{ ad.title }}</span>
			</h3>
			<RouterLink v-if="locationLabel && locationRoute" :to="locationRoute" class="listing-card__location listing-card__location--link">
				<q-icon name="place" size="16px" />
				<span>{{ locationLabel }}</span>
			</RouterLink>
			<div v-else-if="locationLabel" class="listing-card__location">
				<q-icon name="place" size="16px" />
				<span>{{ locationLabel }}</span>
			</div>
			<div ref="textWrapRef" class="listing-card__text-wrap" :class="{ 'listing-card__text-wrap--overflow': hasOverflow && !isExpanded }">
				<p ref="textRef" class="listing-card__text">{{ ad.text }}</p>
			</div>
			<AdExpiryTimer :expires-at="ad.expires_at || ''" @expired="handleExpired" />
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

.listing-card--with-category {
  border-color: color-mix(in srgb, var(--ad-category-color) 10%, rgba(17, 34, 45, 0.08));
  background:
    linear-gradient(
      180deg,
      color-mix(in srgb, var(--ad-category-color) 4%, rgba(255, 255, 255, 0.78)) 0%,
      color-mix(in srgb, var(--ad-category-color) 2%, rgba(255, 255, 255, 0.76)) 100%
    );
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
  display: block;
  height: 100%;
  min-height: 170px;
  --responsive-image-fit: cover;
  --responsive-image-position: center;
}

.listing-card__image-link {
  display: block;
}

.listing-card__image-media {
  width: 100%;
  height: 100%;
  --responsive-image-fit: cover;
  --responsive-image-position: center;
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
    radial-gradient(
      ellipse 118% 112% at var(--ad-category-x, 100%) 100%,
      color-mix(in srgb, var(--ad-category-color) 5%, transparent) 0%,
      color-mix(in srgb, var(--ad-category-color) 2.8%, transparent) 54%,
      transparent 92%
    ),
    radial-gradient(
      ellipse 82% 84% at var(--ad-category-x, 100%) 100%,
      color-mix(in srgb, var(--ad-category-color) 4%, transparent) 0%,
      transparent 88%
    );
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
.ad-expiry,
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

.listing-card__badge-link {
  display: inline-flex;
  max-width: 100%;
  border-radius: 999px;
  color: inherit;
  text-decoration: none;
}

.listing-card__badge-link:focus-visible,
.listing-card__location--link:focus-visible,
.listing-card__title a:focus-visible,
.listing-card__image-link:focus-visible {
  outline: 2px solid var(--soz-primary);
  outline-offset: 3px;
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

.listing-card__title a {
  color: inherit;
  text-decoration: none;
}

.listing-card__title a:hover {
  color: var(--soz-primary-deep);
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

.listing-card__location--link {
  width: fit-content;
  text-decoration: none;
}

.listing-card__location--link:hover {
  color: var(--soz-primary-deep);
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
