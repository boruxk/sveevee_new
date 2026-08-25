<script setup>
	import { computed, onMounted, ref, watch } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { setLocale } from '@/i18n'
	import { fetchMarket } from '@/services/api/market'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import { absoluteUrl, cleanText, truncateText, useSeo } from '@/composables/useSeo'
	import { catalogLabel, catalogTopicByKey, marketPath, normalizeCatalogLocale, productPath, publicPagePath } from '@/constants/catalogTopics'
	import { locationLabel } from '@/utils/locationLabels'

	const SEO_LOCALES = ['he', 'en', 'ru', 'fr']
	const route = useRoute()
	const { locale, t } = useI18n()
	const loading = ref(false)
	const market = ref(null)

	const city = computed(() => market.value?.city || '')
	const cityLabel = computed(() => locationLabel(city.value, 'city', locale.value))
	const routeLocale = computed(() => String(route.params.locale || ''))
	const topic = computed(() => market.value?.topic || null)
	const topicLabel = computed(() => catalogLabel(topic.value?.labels, locale.value))
	const products = computed(() => market.value?.products?.items || [])
	const totalCount = computed(() => Number(market.value?.total_count || 0))
	const marketTopics = computed(() => market.value?.related_topics || market.value?.market_topics || [])
	const canonicalPath = computed(() => (
		city.value ? localizedMarketPath(city.value, topic.value, routeLocale.value || locale.value) : route.path
	))
	const alternates = computed(() => {
		if (!city.value) {
			return null
		}

		const links = Object.fromEntries(SEO_LOCALES.map((item) => [
			item,
			localizedMarketPath(city.value, topic.value, item)
		]))
		links['x-default'] = links.he

		return links
	})
	const titleLinks = computed(() => [
		city.value ? {
			label: cityLabel.value,
			to: localizedMarketPath(city.value)
		} : null,
		topic.value ? {
			label: topicLabel.value,
			to: localizedMarketPath(city.value, topic.value)
		} : null
	].filter(Boolean))
	const pageTitle = computed(() => (
		topic.value ? t('market.topicTitle', { topic: topicLabel.value, city: cityLabel.value }) : t('market.cityTitle', { city: cityLabel.value })
	))
	const seoTitle = computed(() => (
		topic.value ? t('market.topicSeoTitle', { topic: topicLabel.value, city: cityLabel.value }) : t('market.citySeoTitle', { city: cityLabel.value })
	))
	const pageDescription = computed(() => (
		topic.value ? t('market.topicDescription', { topic: topicLabel.value, city: cityLabel.value }) : t('market.cityDescription', { city: cityLabel.value })
	))
	const jsonLd = computed(() => [
		{
			'@context': 'https://schema.org',
			'@type': 'CollectionPage',
			name: pageTitle.value,
			description: pageDescription.value,
			url: absoluteUrl(canonicalPath.value)
		},
		{
			'@context': 'https://schema.org',
			'@type': 'ItemList',
			itemListElement: products.value.map((product, index) => ({
				'@type': 'ListItem',
				position: index + 1,
				item: {
					'@type': 'Product',
					name: product.name,
					description: truncateText(cleanText(product.description), 120),
					image: product.image_url ? absoluteUrl(product.image_url) : undefined,
					url: absoluteUrl(productPath(product, routeLocale.value || locale.value)),
					offers: product.price !== null && product.price !== undefined ? {
						'@type': 'Offer',
						price: product.price,
						priceCurrency: 'ILS',
						availability: 'https://schema.org/InStock'
					} : undefined
				}
			}))
		}
	])

	useSeo(computed(() => ({
		title: seoTitle.value,
		description: pageDescription.value,
		canonical: canonicalPath.value,
		alternates: alternates.value,
		robots: market.value?.indexable ? 'index,follow' : 'noindex,follow',
		type: 'website',
		jsonLd: jsonLd.value
	})))

	async function load() {
		loading.value = true
		try {
			if (routeLocale.value && routeLocale.value !== locale.value) {
				await setLocale(routeLocale.value)
			}

			const { data } = await fetchMarket({
				citySlug: route.params.citySlug,
				topicSlug: route.params.topicSlug,
				limit: 48
			})
			market.value = data.data
		} finally {
			loading.value = false
		}
	}

	function localizedMarketPath(cityValue = '', topicValue = '', targetLocale = routeLocale.value || locale.value) {
		const path = marketPath(cityValue, topicValue)

		return `/${normalizeCatalogLocale(targetLocale)}${path}`
	}

	function localizedProductPath(product) {
		return productPath(product, routeLocale.value || locale.value)
	}

	function sellerPath(page) {
		return publicPagePath(page, routeLocale.value || locale.value)
	}

	function productTopic(product) {
		return catalogTopicByKey(market.value?.groups || [], product.category_key)
	}

	function productMarketType(product) {
		return (market.value?.market_topics || [])
			.find((item) => item.topic_keys?.includes(product.category_key)) || null
	}

	function productTopicLabel(product) {
		return catalogLabel((productMarketType(product) || productTopic(product))?.labels, locale.value)
	}

	function productTopicPath(product) {
		const resolvedTopic = productMarketType(product) || productTopic(product)

		return resolvedTopic ? localizedMarketPath(city.value, resolvedTopic) : localizedMarketPath(city.value)
	}

	function productLocation(product) {
		const address = product.page?.address_details || {}

		return [address.city, address.neighborhood]
			.filter(Boolean)
			.map((value, index) => locationLabel(value, index === 0 ? 'city' : 'neighborhood', locale.value))
			.join(' / ')
	}

	function productDescription(product) {
		return truncateText(cleanText(product.description), 145)
	}

	watch(() => route.fullPath, load)
	onMounted(load)
</script>

<template>
	<q-page padding class="market-page">
		<div class="market-shell">
			<section class="soz-section-card market-head">
				<div>
					<div class="market-kicker">{{ t('market.kicker') }}</div>
					<h1 class="soz-page-title market-title">
						<template v-if="titleLinks.length">
							<template v-for="(link, index) in titleLinks" :key="link.to">
								<span v-if="index > 0" class="market-title__separator">/</span>
								<router-link :to="link.to">{{ link.label }}</router-link>
							</template>
						</template>
						<template v-else>
							{{ pageTitle }}
						</template>
					</h1>
					<p>{{ pageDescription }}</p>
				</div>
				<div class="market-head__stat">
					<q-icon name="inventory_2" size="26px" />
					<span>{{ t('market.resultsCount', { count: totalCount }) }}</span>
				</div>
			</section>

			<div v-if="loading" class="row justify-center q-pa-xl">
				<q-spinner color="primary" />
			</div>

			<template v-else-if="market">
				<section v-if="marketTopics.length" class="market-section">
					<div class="market-section__head">
						<h2>{{ topic ? t('market.relatedTypes') : t('market.typeTitle') }}</h2>
					</div>
					<div class="market-topic-grid">
						<router-link
							v-for="item in marketTopics"
							:key="item.key"
							class="market-topic-chip"
							:to="localizedMarketPath(city, item)"
							:style="{ '--topic-color': item.color }"
						>
							<span class="market-topic-chip__dot" />
							<span>{{ catalogLabel(item.labels, locale) }}</span>
						</router-link>
					</div>
				</section>

				<section v-if="products.length" class="market-section">
					<div class="market-section__head">
						<h2>{{ t('market.productsTitle') }}</h2>
						<span>{{ t('market.resultsCount', { count: totalCount }) }}</span>
					</div>
					<div class="market-grid">
						<article v-for="product in products" :key="product.id" class="market-product">
							<div class="market-product__media">
								<ResponsiveImage
									v-if="product.image_url"
									class="market-product__image"
									:src="product.image_url"
									:alt="product.image_alt || product.name"
									:avif-srcset="product.image_avif_srcset || ''"
									:webp-srcset="product.image_webp_srcset || ''"
									:sizes="product.image_sizes || '(max-width: 760px) calc(100vw - 36px), (max-width: 1100px) calc((100vw - 72px) / 2), 390px'"
									:width="product.image_width || 768"
									:height="product.image_height || 576"
									loading="lazy"
									decoding="async"
								/>
								<q-icon v-else name="inventory_2" size="40px" />
							</div>
							<div class="market-product__body">
								<div class="market-product__meta">
									<router-link
										v-if="productTopicLabel(product)"
										class="market-product__category"
										:to="productTopicPath(product)"
									>
										{{ productTopicLabel(product) }}
									</router-link>
									<span v-if="productLocation(product)" class="market-product__location">
										<q-icon name="place" size="16px" />
										{{ productLocation(product) }}
									</span>
								</div>
								<h3>
									<router-link :to="localizedProductPath(product)">{{ product.name }}</router-link>
								</h3>
								<p>{{ productDescription(product) }}</p>
								<div class="market-product__seller">
									<router-link v-if="product.page" :to="sellerPath(product.page)">
										<q-icon name="storefront" size="18px" />
										{{ product.page.name }}
									</router-link>
								</div>
								<div class="market-product__footer">
									<strong>{{ product.price_label }}</strong>
									<div class="market-product__actions">
										<q-btn
											round
											unelevated
											color="primary"
											icon="visibility"
											:to="localizedProductPath(product)"
											:aria-label="t('products.open')"
										>
											<q-tooltip>{{ t('products.open') }}</q-tooltip>
										</q-btn>
										<q-btn
											v-if="product.link"
											round
											unelevated
											color="primary"
											icon="shopping_cart"
											:href="product.link"
											target="_blank"
											rel="noopener noreferrer"
											:aria-label="t('products.buy')"
										>
											<q-tooltip>{{ t('products.buy') }}</q-tooltip>
										</q-btn>
										<q-btn
											v-if="product.page"
											round
											flat
											color="primary"
											icon="open_in_new"
											:to="sellerPath(product.page)"
											:aria-label="t('market.viewSeller')"
										>
											<q-tooltip>{{ t('market.viewSeller') }}</q-tooltip>
										</q-btn>
									</div>
								</div>
							</div>
						</article>
					</div>
				</section>

				<section v-else class="market-empty">
					<q-icon name="inventory_2" size="32px" />
					<span>{{ t('market.empty') }}</span>
				</section>
			</template>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.market-page {
  padding: 0 20px 36px;
}

.market-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.market-head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 18px;
  align-items: center;
  padding: 30px;
}

.market-kicker {
  margin-bottom: 8px;
  color: var(--soz-primary-deep);
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0.08em;
  text-transform: uppercase;
}

.market-title {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: baseline;
}

.market-title a {
  color: inherit;
  text-decoration: none;
}

.market-title a:hover {
  color: var(--soz-primary-deep);
}

.market-title__separator {
  color: rgba(17, 34, 45, 0.34);
}

.market-head p {
  max-width: 900px;
  margin: 12px 0 0;
  color: rgba(17, 34, 45, 0.66);
  font-size: 1.05rem;
  line-height: 1.65;
}

.market-head__stat {
  display: inline-flex;
  gap: 10px;
  align-items: center;
  align-self: start;
  padding: 13px 16px;
  border: 1px solid rgba(123, 63, 242, 0.14);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.72);
  color: var(--soz-primary-deep);
  font-weight: 800;
  white-space: nowrap;
}

.market-section {
  margin-top: 22px;
}

.market-section__head {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: baseline;
  justify-content: space-between;
  margin-bottom: 14px;
}

.market-section__head h2 {
  margin: 0;
  font-size: clamp(1.35rem, 1.9vw, 1.9rem);
  line-height: 1.2;
}

.market-section__head span {
  color: rgba(17, 34, 45, 0.58);
  font-weight: 750;
}

.market-topic-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.market-topic-chip {
  display: inline-flex;
  max-width: 100%;
  min-height: 42px;
  gap: 8px;
  align-items: center;
  padding: 9px 14px;
  border: 1px solid color-mix(in srgb, var(--topic-color, #f54291) 28%, rgba(17, 34, 45, 0.08));
  border-radius: 999px;
  background: color-mix(in srgb, var(--topic-color, #f54291) 10%, rgba(255, 255, 255, 0.86));
  color: #152033;
  font-weight: 780;
  text-decoration: none;
}

.market-topic-chip__dot {
  flex: 0 0 auto;
  width: 10px;
  height: 10px;
  border-radius: 999px;
  background: var(--topic-color, #f54291);
}

.market-topic-chip span:last-child {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.market-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}

.market-product {
  display: grid;
  min-width: 0;
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.78);
  color: #152033;
  box-shadow: 0 18px 38px rgba(245, 66, 145, 0.08);
}

.market-product__media {
  display: grid;
  place-items: center;
  aspect-ratio: 16 / 10;
  overflow: hidden;
  background: linear-gradient(135deg, rgba(255, 116, 38, 0.14), rgba(245, 66, 145, 0.12), rgba(123, 63, 242, 0.1));
  color: var(--soz-primary-deep);
}

.market-product__image {
  width: 100%;
  height: 100%;
  --responsive-image-fit: cover;
  --responsive-image-position: center;
}

.market-product__body {
  display: grid;
  gap: 10px;
  min-width: 0;
  padding: 18px;
}

.market-product__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
}

.market-product__category,
.market-product__location {
  display: inline-flex;
  min-height: 30px;
  gap: 5px;
  align-items: center;
  padding: 6px 10px;
  border-radius: 999px;
  background: rgba(123, 63, 242, 0.09);
  color: var(--soz-primary-deep);
  font-size: 0.82rem;
  font-weight: 800;
}

.market-product__location {
  background: rgba(17, 34, 45, 0.06);
  color: rgba(17, 34, 45, 0.62);
}

.market-product h3 {
  overflow: hidden;
  margin: 0;
  font-size: 1.16rem;
  line-height: 1.25;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.market-product h3 a {
  color: inherit;
  text-decoration: none;
}

.market-product h3 a:hover {
  color: var(--soz-primary-deep);
}

.market-product p {
  display: -webkit-box;
  min-height: 48px;
  overflow: hidden;
  margin: 0;
  color: rgba(17, 34, 45, 0.66);
  line-height: 1.5;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.market-product__seller a {
  display: inline-flex;
  max-width: 100%;
  gap: 6px;
  align-items: center;
  color: rgba(17, 34, 45, 0.7);
  font-weight: 780;
}

.market-product__seller span,
.market-product__seller a {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.market-product__footer {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-top: 4px;
}

.market-product__footer strong {
  overflow: hidden;
  color: var(--soz-ink);
  font-size: 1.25rem;
  font-weight: 850;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.market-product__actions {
  display: flex;
  flex: 0 0 auto;
  gap: 8px;
}

.market-product__actions .q-btn {
  width: 44px;
  min-width: 44px;
  height: 44px;
  min-height: 44px;
}

.market-empty {
  display: grid;
  gap: 10px;
  place-items: center;
  min-height: 220px;
  margin-top: 22px;
  padding: 42px 24px;
  border: 1px dashed rgba(245, 66, 145, 0.24);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.72);
  color: var(--soz-primary-deep);
  font-size: 1.2rem;
  font-weight: 760;
  text-align: center;
}

@media (max-width: 980px) {
  .market-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 700px) {
  .market-page {
    padding-inline: 10px;
  }

  .market-head,
  .market-grid {
    grid-template-columns: 1fr;
  }

  .market-head {
    padding: 22px;
  }

  .market-head__stat {
    width: 100%;
    justify-content: center;
  }

  .market-product__footer {
    align-items: stretch;
    flex-direction: column;
  }

  .market-product__actions {
    justify-content: flex-start;
  }
}
</style>
