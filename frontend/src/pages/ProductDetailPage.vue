<script setup>
	import { computed, onMounted, ref, watch } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { setLocale } from '@/i18n'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { fetchProduct } from '@/services/api/products'
	import { absoluteUrl, cleanText, truncateText, useSeo } from '@/composables/useSeo'
	import { catalogLabel, catalogPath, catalogTopicByKey, productPath, publicPagePath } from '@/constants/catalogTopics'
	import { locationLabel } from '@/utils/locationLabels'

	const SEO_LOCALES = ['he', 'en', 'ru', 'fr']
	const route = useRoute()
	const { locale, t } = useI18n()
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const product = ref(null)
	const loading = ref(false)

	const routeLocale = computed(() => String(route.params.locale || ''))
	const seller = computed(() => product.value?.page || null)
	const sellerAddress = computed(() => seller.value?.address_details || {})
	const city = computed(() => sellerAddress.value.city || '')
	const neighborhood = computed(() => sellerAddress.value.neighborhood || '')
	const cityLabel = computed(() => (city.value ? locationLabel(city.value, 'city', locale.value) : ''))
	const neighborhoodLabel = computed(() => (neighborhood.value ? locationLabel(neighborhood.value, 'neighborhood', locale.value) : ''))
	const topic = computed(() => catalogTopicByKey(catalogGroups.value, product.value?.category_key))
	const topicLabel = computed(() => catalogLabel(topic.value?.labels, locale.value))
	const canonicalPath = computed(() => (product.value ? productPath(product.value, routeLocale.value || locale.value) : route.path))
	const sellerPath = computed(() => (seller.value ? publicPagePath(seller.value, routeLocale.value || locale.value) : ''))
	const localizedAlternates = computed(() => {
		if (!product.value) {
			return null
		}

		return {
			...Object.fromEntries(SEO_LOCALES.map((item) => [item, productPath(product.value, item)])),
			'x-default': productPath(product.value, 'he')
		}
	})
	const pageTitle = computed(() => {
		if (!product.value) {
			return t('seo.productFallbackTitle')
		}

		if (cityLabel.value) {
			return t('seo.productPageTitle', { name: product.value.name, city: cityLabel.value })
		}

		return product.value.name
	})
	const pageDescription = computed(() => {
		if (!product.value) {
			return t('seo.productFallbackDescription')
		}

		const summary = t('seo.productPageDescription', {
			name: product.value.name,
			price: product.value.price_label,
			seller: seller.value?.name || t('pages.businessTitle'),
			city: cityLabel.value || city.value || t('auth.city')
		})

		return truncateText([summary, cleanText(product.value.description)].filter(Boolean).join(' '))
	})
	const breadcrumbLinks = computed(() => {
		if (!product.value) {
			return []
		}

		return [
			topic.value && city.value ? {
				label: cityLabel.value,
				to: catalogPath(topic.value, city.value)
			} : null,
			topic.value && city.value && neighborhood.value ? {
				label: neighborhoodLabel.value,
				to: catalogPath(topic.value, city.value, neighborhood.value)
			} : null,
			topic.value ? {
				label: topicLabel.value,
				to: catalogPath(topic.value)
			} : null,
			{
				label: product.value.name,
				to: canonicalPath.value
			}
		].filter(Boolean)
	})
	const jsonLd = computed(() => {
		if (!product.value) {
			return null
		}

		const productSchema = {
			'@context': 'https://schema.org',
			'@type': 'Product',
			name: product.value.name,
			description: pageDescription.value,
			image: product.value.image_url ? absoluteUrl(product.value.image_url) : undefined,
			url: absoluteUrl(canonicalPath.value),
			category: topicLabel.value || undefined,
			offers: product.value.price !== null && product.value.price !== undefined ? {
				'@type': 'Offer',
				price: product.value.price,
				priceCurrency: 'ILS',
				availability: 'https://schema.org/InStock',
				url: absoluteUrl(canonicalPath.value),
				seller: seller.value ? {
					'@type': 'Organization',
					name: seller.value.name,
					url: absoluteUrl(sellerPath.value)
				} : undefined
			} : undefined
		}

		const breadcrumbSchema = breadcrumbLinks.value.length > 1 ? {
			'@context': 'https://schema.org',
			'@type': 'BreadcrumbList',
			itemListElement: breadcrumbLinks.value.map((link, index) => ({
				'@type': 'ListItem',
				position: index + 1,
				name: link.label,
				item: absoluteUrl(link.to)
			}))
		} : null

		return [productSchema, breadcrumbSchema].filter(Boolean)
	})

	useSeo(computed(() => ({
		title: pageTitle.value,
		description: pageDescription.value,
		image: product.value?.image_url,
		canonical: canonicalPath.value,
		alternates: localizedAlternates.value,
		type: 'product',
		robots: product.value ? 'index,follow' : 'noindex,follow',
		jsonLd: jsonLd.value
	})))

	async function load() {
		loading.value = true
		try {
			if (routeLocale.value && routeLocale.value !== locale.value) {
				await setLocale(routeLocale.value)
			}

			const { data } = await fetchProduct(route.params.id)
			product.value = data.data
		} finally {
			loading.value = false
		}
	}

	function locationText() {
		return [cityLabel.value, neighborhoodLabel.value].filter(Boolean).join(' / ')
	}

	watch(() => route.fullPath, load)
	onMounted(async() => {
		await Promise.all([load(), loadCatalogTopics()])
	})
</script>

<template>
	<q-page padding class="product-detail-page">
		<div v-if="product" class="product-detail-shell">
			<nav v-if="breadcrumbLinks.length > 1" class="product-detail-breadcrumb" aria-label="Breadcrumb">
				<router-link v-for="link in breadcrumbLinks" :key="link.to" :to="link.to">
					{{ link.label }}
				</router-link>
			</nav>

			<section class="product-detail">
				<div class="product-detail__media">
					<img v-if="product.image_url" :src="product.image_url" :alt="product.name" />
					<q-icon v-else name="inventory_2" size="62px" />
				</div>

				<div class="product-detail__body">
					<div class="product-detail__meta">
						<router-link v-if="topic" :to="catalogPath(topic, city, neighborhood)" class="product-detail__chip">
							<q-icon name="category" size="17px" />
							{{ topicLabel }}
						</router-link>
						<span v-if="locationText()" class="product-detail__chip product-detail__chip--muted">
							<q-icon name="place" size="17px" />
							{{ locationText() }}
						</span>
					</div>

					<h1>{{ product.name }}</h1>
					<div class="product-detail__price">{{ product.price_label }}</div>

					<div class="product-detail__actions">
						<q-btn
							v-if="product.link"
							rounded
							unelevated
							color="primary"
							icon="shopping_cart"
							:href="product.link"
							target="_blank"
							rel="noopener noreferrer"
							:label="t('products.buy')"
						/>
						<q-btn
							v-if="seller"
							rounded
							flat
							color="primary"
							icon="storefront"
							:to="sellerPath"
							:label="t('market.viewSeller')"
						/>
					</div>
				</div>

				<p class="product-detail__description">{{ product.description }}</p>
			</section>
		</div>
		<div v-else-if="loading" class="row justify-center q-pa-xl">
			<q-spinner color="primary" />
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.product-detail-page {
  padding: 0 20px 36px;
}

.product-detail-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.product-detail-breadcrumb {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-bottom: 14px;
}

.product-detail-breadcrumb a {
  color: var(--soz-primary-deep);
  font-weight: 780;
  text-decoration: none;
}

.product-detail-breadcrumb a + a::before {
  padding-inline: 8px;
  color: rgba(17, 34, 45, 0.36);
  content: "/";
}

.product-detail {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(320px, 0.78fr);
  grid-template-areas:
    "media body"
    "description description";
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 28px;
  background: rgba(255, 255, 255, 0.82);
  box-shadow: 0 20px 44px rgba(245, 66, 145, 0.1);
}

.product-detail__media {
  display: grid;
  place-items: center;
  grid-area: media;
  max-height: 420px;
  min-height: 280px;
  align-items: start;
  justify-items: start;
  padding: 20px;
  overflow: hidden;
  background: transparent;
  color: var(--soz-primary-deep);
}

.product-detail__media img {
  max-height: 380px;
  width: 100%;
  height: auto;
  object-fit: cover;
  border-radius: 24px;
}

.product-detail__body {
  grid-area: body;
  display: grid;
  align-content: start;
  gap: 18px;
  min-width: 0;
  padding: 34px;
}

.product-detail__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.product-detail__chip {
  display: inline-flex;
  max-width: 100%;
  min-height: 32px;
  gap: 6px;
  align-items: center;
  padding: 6px 11px;
  border-radius: 999px;
  background: rgba(123, 63, 242, 0.09);
  color: var(--soz-primary-deep);
  font-size: 0.86rem;
  font-weight: 820;
  text-decoration: none;
}

.product-detail__chip--muted {
  background: rgba(17, 34, 45, 0.06);
  color: rgba(17, 34, 45, 0.66);
}

.product-detail h1 {
  margin: 0;
  color: var(--soz-ink);
  font-size: clamp(2rem, 3.7vw, 3.8rem);
  line-height: 1.03;
  overflow-wrap: anywhere;
}

.product-detail__price {
  color: var(--soz-ink);
  font-size: clamp(1.6rem, 2.5vw, 2.4rem);
  font-weight: 880;
}

.product-detail__description {
  grid-area: description;
  padding: 0 34px 34px;
  margin: 32px 0 0;
  color: rgba(17, 34, 45, 0.72);
  font-size: 1.14rem;
  line-height: 1.7;
  white-space: pre-line;
}

.product-detail__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.product-detail__actions .q-btn.bg-primary {
  background: var(--soz-action-gradient) !important;
  box-shadow: 0 14px 28px rgba(245, 66, 145, 0.22) !important;
}

@media (max-width: 860px) {
  .product-detail {
    grid-template-columns: 1fr;
    grid-template-areas:
      "media"
      "body"
      "description";
  }

  .product-detail__media {
    min-height: 260px;
    max-height: 340px;
    padding: 16px;
  }

  .product-detail__body,
  .product-detail__description {
    padding-inline: 22px;
  }

  .product-detail__body {
    border-right: none;
    border-bottom: none;
  }

  .product-detail__media img {
    max-height: 308px;
    width: 100%;
    border-radius: 22px;
  }
}

@media (max-width: 700px) {
  .product-detail-page {
    padding-inline: 10px;
  }

  .product-detail__body {
    padding: 22px;
  }

  .product-detail__media {
    min-height: 260px;
  }

  .product-detail__actions .q-btn {
    width: 100%;
  }
}
</style>
