<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { localizedAdCategoryMeta } from '@/constants/adCategories'
	import { adRoute, catalogLabel, catalogTopicByKey, pageRoute, productRoute, userRoute } from '@/constants/catalogTopics'
	import { findPresencePalette } from '@/constants/presencePalettes'
	import AdExpiryTimer from '@/components/AdExpiryTimer.vue'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import { locationLabel } from '@/utils/locationLabels'

	const props = defineProps({
		item: {
			type: Object,
			required: true
		},
		catalogGroups: {
			type: Array,
			default: () => []
		}
	})
	const emit = defineEmits(['expired'])
	const { t, locale } = useI18n()

	const value = computed(() => props.item.value || {})
	const resultRoute = computed(() => {
		if (props.item.kind === 'ad') {
			return adRoute(value.value)
		}

		if (props.item.kind === 'user') {
			return userRoute(value.value)
		}

		if (props.item.kind === 'page') {
			return pageRoute(value.value)
		}

		if (props.item.kind === 'event' && !value.value.page && value.value.user) {
			return userRoute(value.value.user)
		}

		return props.item.kind === 'product' ? productRoute(value.value) : pageRoute(value.value.page)
	})
	const typeLabel = computed(() => {
		if (props.item.kind === 'page') {
			return t(`pages.kinds.${value.value.type === 'community' ? 'community' : 'business'}`)
		}

		return t(`catalog.sections.${{
			ad: 'ads',
			product: 'products',
			service: 'services',
			event: 'events',
			user: 'users'
		}[props.item.kind] || 'pages'}`)
	})
	const typeIcon = computed(() => ({
		ad: 'campaign',
		product: 'inventory_2',
		service: 'design_services',
		event: 'event',
		user: 'person'
	}[props.item.kind] || (value.value.type === 'community' ? 'diversity_3' : 'storefront')))
	const resultCategory = computed(() => {
		if (props.item.kind === 'ad') {
			return localizedAdCategoryMeta(value.value.category, t)
		}

		if (['page', 'service', 'event'].includes(props.item.kind)) {
			return catalogTopicByKey(props.catalogGroups, value.value.category_key)
		}

		return null
	})
	const resultCategoryLabel = computed(() => (
		resultCategory.value?.label || catalogLabel(resultCategory.value?.labels, locale.value)
	))
	const title = computed(() => {
		if (props.item.kind === 'ad') {
			return value.value.title || ''
		}

		if (props.item.kind === 'user') {
			return value.value.display_name || ''
		}

		return value.value.name || ''
	})
	const pagePalette = computed(() => findPresencePalette(value.value.palette_key))
	const resultCategoryStyle = computed(() => ({
		'--category-color': resultCategory.value?.color || 'var(--result-accent)'
	}))
	const pageBanner = computed(() => imagePayload(value.value.banner_url, value.value, 'banner', title.value))
	const pageLogo = computed(() => imagePayload(
		value.value.logo_url,
		value.value,
		'logo',
		t('pages.logoAlt', { name: title.value }),
		true
	))
	const pageInitials = computed(() => String(title.value || 'S')
		.trim()
		.split(/\s+/)
		.slice(0, 2)
		.map((part) => Array.from(part)[0] || '')
		.join('')
		.toLocaleUpperCase(locale.value))
	const cardStyle = computed(() => {
		if (props.item.kind === 'page') {
			return { '--result-accent': pagePalette.value.accent }
		}

		return undefined
	})
	const pageMediaStyle = computed(() => ({
		'--page-banner': pagePalette.value.hero,
		'--page-overlay': pagePalette.value.overlay,
		'--page-logo-bg': pagePalette.value.dark ? 'rgba(255, 255, 255, 0.16)' : 'rgba(255, 255, 255, 0.92)',
		'--page-logo-color': pagePalette.value.dark ? pagePalette.value.surface : pagePalette.value.accent
	}))
	const description = computed(() => {
		if (props.item.kind === 'ad') {
			return value.value.text || ''
		}

		if (props.item.kind === 'page') {
			return value.value.public_description || ''
		}

		if (props.item.kind === 'user') {
			return ''
		}

		return value.value.description || value.value.page?.name || ''
	})
	const location = computed(() => {
		let address = {}

		if (props.item.kind === 'ad') {
			address = value.value
		} else if (props.item.kind === 'user') {
			address = value.value.profile || {}
		} else if (props.item.kind === 'page') {
			address = value.value.address_details || {}
		} else if (props.item.kind === 'event' && !value.value.page) {
			address = value.value.user?.profile || {}
		} else {
			address = value.value.page?.address_details || {}
		}

		return [
			locationLabel(address.city, 'city', locale.value),
			locationLabel(address.neighborhood, 'neighborhood', locale.value)
		].filter(Boolean).join(' / ')
	})

	function formatEventDate(value) {
		if (!value) {
			return ''
		}

		const date = new Date(`${value}T00:00:00`)

		if (Number.isNaN(date.getTime())) {
			return value
		}

		return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(date)
	}

	const detail = computed(() => {
		if (props.item.kind === 'product') {
			return value.value.price_label || ''
		}

		if (props.item.kind === 'event') {
			return [formatEventDate(value.value.date), value.value.time].filter(Boolean).join(' · ')
		}

		if (props.item.kind === 'service') {
			return value.value.page?.name || ''
		}

		return ''
	})
	const media = computed(() => {
		if (props.item.kind === 'user') {
			const profile = value.value.profile || {}
			return imagePayload(profile.photo_url, profile, 'photo', title.value, true)
		}

		return imagePayload(value.value.image_url, value.value, 'image', title.value)
	})

	function imagePayload(src, source, prefix, alt, contain = false) {
		return {
			src: src || '',
			alt: source?.[`${prefix}_alt`] || alt,
			avifSrcset: source?.[`${prefix}_avif_srcset`] || '',
			webpSrcset: source?.[`${prefix}_webp_srcset`] || '',
			width: source?.[`${prefix}_width`] || (contain ? 96 : 768),
			height: source?.[`${prefix}_height`] || (contain ? 96 : 576),
			contain
		}
	}
</script>

<template>
	<router-link
		:to="resultRoute"
		class="search-result-card"
		:class="`search-result-card--${item.kind}`"
		:style="cardStyle"
	>
		<div
			class="search-result-card__media"
			:class="{
				'search-result-card__media--contain': item.kind !== 'page' && media.contain,
				'search-result-card__media--page': item.kind === 'page',
				'search-result-card__media--page-has-banner': item.kind === 'page' && pageBanner.src
			}"
			:style="item.kind === 'page' ? pageMediaStyle : undefined"
		>
			<template v-if="item.kind === 'page'">
				<ResponsiveImage
					v-if="pageBanner.src"
					class="search-result-card__page-banner"
					:src="pageBanner.src"
					:alt="pageBanner.alt"
					:avif-srcset="pageBanner.avifSrcset"
					:webp-srcset="pageBanner.webpSrcset"
					sizes="(max-width: 700px) 112px, 190px"
					:width="pageBanner.width"
					:height="pageBanner.height"
				/>
				<div class="search-result-card__page-overlay" />
				<div class="search-result-card__page-logo">
					<ResponsiveImage
						v-if="pageLogo.src"
						class="search-result-card__page-logo-image"
						:src="pageLogo.src"
						:alt="pageLogo.alt"
						:avif-srcset="pageLogo.avifSrcset"
						:webp-srcset="pageLogo.webpSrcset"
						sizes="(max-width: 700px) 68px, 92px"
						:width="pageLogo.width"
						:height="pageLogo.height"
					/>
					<span v-else>{{ pageInitials }}</span>
				</div>
			</template>
			<template v-else>
				<ResponsiveImage
					v-if="media.src"
					class="search-result-card__image"
					:src="media.src"
					:alt="media.alt"
					:avif-srcset="media.avifSrcset"
					:webp-srcset="media.webpSrcset"
					sizes="(max-width: 700px) 112px, 190px"
					:width="media.width"
					:height="media.height"
				/>
				<q-icon v-else :name="typeIcon" size="42px" />
			</template>
		</div>

		<div class="search-result-card__copy">
			<div class="search-result-card__badges">
				<div class="search-result-card__type">
					<q-icon :name="typeIcon" size="17px" />
					<span>{{ typeLabel }}</span>
				</div>
				<div
					v-if="resultCategoryLabel"
					class="search-result-card__category"
					:style="resultCategoryStyle"
				>
					<span>{{ resultCategoryLabel }}</span>
				</div>
			</div>
			<strong class="search-result-card__title">{{ title }}</strong>
			<p v-if="description" class="search-result-card__description">{{ description }}</p>
			<div v-if="location || detail || (item.kind === 'ad' && value.expires_at)" class="search-result-card__meta">
				<span v-if="location" class="search-result-card__location">
					<q-icon name="place" size="16px" />
					<span>{{ location }}</span>
				</span>
				<span v-if="detail" class="search-result-card__detail">{{ detail }}</span>
				<AdExpiryTimer
					v-if="item.kind === 'ad'"
					:expires-at="value.expires_at || ''"
					@expired="emit('expired', value.id)"
				/>
			</div>
		</div>
	</router-link>
</template>

<style scoped lang="scss">
.search-result-card {
  --result-accent: #7b3ff2;
  display: grid;
  grid-template-columns: 190px minmax(0, 1fr);
  height: 190px;
  overflow: hidden;
  border: 1px solid color-mix(in srgb, var(--result-accent) 12%, rgba(17, 34, 45, 0.08));
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.76);
  color: inherit;
  text-decoration: none;
  transition: border-color 0.18s ease, box-shadow 0.18s ease, transform 0.18s ease;
}

.search-result-card:hover {
  border-color: color-mix(in srgb, var(--result-accent) 28%, transparent);
  box-shadow: 0 16px 34px rgba(41, 30, 82, 0.1);
  transform: translateY(-2px);
}

.search-result-card:focus-visible {
  outline: 3px solid color-mix(in srgb, var(--result-accent) 48%, transparent);
  outline-offset: 3px;
}

.search-result-card--ad { --result-accent: #f54291; }
.search-result-card--product { --result-accent: #ff7426; }
.search-result-card--service { --result-accent: #0f9f93; }
.search-result-card--event { --result-accent: #6654d9; }
.search-result-card--user { --result-accent: #1677b8; }

.search-result-card__media {
  display: grid;
  place-items: center;
  min-width: 0;
  height: 100%;
  overflow: hidden;
  background: color-mix(in srgb, var(--result-accent) 7%, rgba(255, 255, 255, 0.86));
  color: var(--result-accent);
}

.search-result-card__media--page {
  position: relative;
  isolation: isolate;
  background: var(--page-banner);
}

.search-result-card__page-banner,
.search-result-card__page-overlay {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
}

.search-result-card__page-banner {
  --responsive-image-fit: cover;
}

.search-result-card__page-overlay {
  z-index: 1;
  background: var(--page-overlay);
}

.search-result-card__media--page-has-banner .search-result-card__page-overlay {
  background: linear-gradient(135deg, rgba(17, 34, 45, 0.08), rgba(17, 34, 45, 0.32));
}

.search-result-card__page-logo {
  position: relative;
  z-index: 2;
  display: grid;
  width: 92px;
  height: 92px;
  overflow: hidden;
  place-items: center;
  border: 3px solid rgba(255, 255, 255, 0.76);
  border-radius: 26px;
  background: var(--page-logo-bg);
  box-shadow: 0 14px 30px rgba(17, 34, 45, 0.22);
  color: var(--page-logo-color);
  font-size: 2rem;
  font-weight: 850;
  letter-spacing: 0;
  line-height: 1;
  backdrop-filter: blur(10px);
}

.search-result-card__page-logo-image {
  width: 100%;
  height: 100%;
  background: transparent;
  --responsive-image-fit: cover;
}

.search-result-card__image {
  width: 100%;
  height: 100%;
  --responsive-image-fit: cover;
}

.search-result-card__media--contain {
  padding: 22px;
}

.search-result-card__media--contain .search-result-card__image {
  --responsive-image-fit: contain;
}

.search-result-card__copy {
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  padding: 18px 22px;
}

.search-result-card__badges {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  align-self: flex-start;
  max-width: 100%;
}

.search-result-card__type,
.search-result-card__category {
  display: inline-flex;
  gap: 6px;
  align-items: center;
  max-width: 100%;
  padding: 5px 10px;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 760;
}

.search-result-card__type {
  background: color-mix(in srgb, var(--result-accent) 10%, transparent);
  color: var(--result-accent);
}

.search-result-card__category {
  background: color-mix(in srgb, var(--category-color) 13%, transparent);
  color: var(--category-color);
}

.search-result-card__type span,
.search-result-card__category span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.search-result-card__title {
  overflow: hidden;
  margin-top: 10px;
  font-size: 1.28rem;
  line-height: 1.2;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.search-result-card__description {
  display: -webkit-box;
  overflow: hidden;
  margin: 6px 0 0;
  color: rgba(17, 34, 45, 0.64);
  line-height: 1.4;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.search-result-card__meta {
  display: flex;
  gap: 12px;
  align-items: center;
  min-width: 0;
  margin-top: auto;
  color: rgba(17, 34, 45, 0.58);
  font-size: 0.82rem;
  font-weight: 680;
}

.search-result-card__location {
  display: inline-flex;
  gap: 4px;
  align-items: center;
  overflow: hidden;
  min-width: 0;
}

.search-result-card__location span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.search-result-card__detail {
  flex: 0 0 auto;
  color: var(--result-accent);
  font-weight: 800;
}

.search-result-card__meta :deep(.ad-expiry) {
  flex: 0 0 auto;
  margin-top: 0;
  font-size: 0.8rem;
}

@media (max-width: 700px) {
  .search-result-card {
    grid-template-columns: 112px minmax(0, 1fr);
    height: 156px;
    border-radius: 20px;
  }

  .search-result-card__media--contain {
    padding: 16px;
  }

  .search-result-card__page-logo {
    width: 68px;
    height: 68px;
    border-width: 2px;
    border-radius: 19px;
    font-size: 1.45rem;
  }

  .search-result-card__copy {
    padding: 13px 14px;
  }

  .search-result-card__type,
  .search-result-card__category {
    padding: 4px 8px;
    font-size: 0.7rem;
  }

  .search-result-card__title {
    margin-top: 7px;
    font-size: 1.05rem;
  }

  .search-result-card__description {
    margin-top: 4px;
    font-size: 0.86rem;
    line-height: 1.3;
  }

  .search-result-card__meta {
    gap: 8px;
    font-size: 0.74rem;
  }

  .search-result-card__meta :deep(.ad-expiry) {
    font-size: 0.74rem;
  }
}

@media (max-width: 430px) {
  .search-result-card {
    grid-template-columns: 96px minmax(0, 1fr);
    height: 150px;
  }

  .search-result-card__description {
    -webkit-line-clamp: 1;
  }

  .search-result-card__meta {
    flex-wrap: wrap;
    row-gap: 2px;
  }
}
</style>
