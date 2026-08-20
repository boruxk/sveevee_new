<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useAuthStore } from '@/stores/auth'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { fetchUser } from '@/services/api/users'
	import { catalogHubPath, catalogLabel, catalogPath, catalogTopicByKey, pageRoute } from '@/constants/catalogTopics'
	import { locationLabel } from '@/utils/locationLabels'
	import { absoluteUrl, truncateText, useSeo } from '@/composables/useSeo'
	import AdCard from '@/components/AdCard.vue'

	const route = useRoute()
	const router = useRouter()
	const { t, locale } = useI18n()
	const authStore = useAuthStore()
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const loading = ref(false)
	const user = ref(null)

	const pages = computed(() => [user.value?.business_page, user.value?.community_page].filter(Boolean))
	const profileLocation = computed(() => [user.value?.profile?.city, user.value?.profile?.neighborhood].filter(Boolean).join(', '))
	const userTopic = computed(() => catalogTopicByKey(catalogGroups.value, user.value?.profile?.user_type))

	function userCatalogPath(city = '', neighborhood = '') {
		return userTopic.value ? catalogPath(userTopic.value, city, neighborhood) : catalogHubPath('people', city, neighborhood)
	}

	const userCatalogLinks = computed(() => {
		const city = user.value?.profile?.city || ''
		const neighborhood = user.value?.profile?.neighborhood || ''

		return [
			city ? {
				label: locationLabel(city, 'city', locale.value),
				to: userCatalogPath(city)
			} : null,
			city && neighborhood ? {
				label: locationLabel(neighborhood, 'neighborhood', locale.value),
				to: userCatalogPath(city, neighborhood)
			} : null,
			userTopic.value ? {
				label: catalogLabel(userTopic.value.labels, locale.value),
				to: catalogPath(userTopic.value)
			} : null
		].filter(Boolean)
	})
	const seoDescription = computed(() => {
		if (!user.value) {
			return t('seo.userFallbackDescription')
		}

		return truncateText([t('seo.userDescription', { name: user.value.display_name }), profileLocation.value].filter(Boolean).join(' '))
	})
	useSeo(computed(() => ({
		title: user.value?.display_name || t('seo.userFallbackTitle'),
		description: seoDescription.value,
		image: user.value?.profile?.photo_url,
		canonical: route.path,
		type: 'profile',
		robots: user.value ? 'index,follow' : 'noindex,follow',
		jsonLd: user.value ? {
			'@context': 'https://schema.org',
			'@type': 'Person',
			name: user.value.display_name,
			url: absoluteUrl(route.path),
			image: user.value.profile?.photo_url || undefined,
			address: user.value.profile?.city ? {
				'@type': 'PostalAddress',
				addressLocality: user.value.profile.city,
				addressRegion: user.value.profile.neighborhood || undefined
			} : undefined
		} : null
	})))

	async function load() {
		loading.value = true
		try {
			const { data } = await fetchUser(route.params.id)
			user.value = data.data
		} finally {
			loading.value = false
		}
	}

	function openChat() {
		if (!user.value) {
			return
		}

		router.push({ name: 'me', query: { chatWith: user.value.id } })
	}

	onMounted(async() => {
		await Promise.all([load(), loadCatalogTopics()])
	})
</script>

<template>
	<q-page padding class="user-page">
		<div class="page-shell">
			<section v-if="user" class="soz-section-card person-head">
				<q-avatar size="96px" color="primary" text-color="white">
					<img v-if="user.profile?.photo_url" :src="user.profile.photo_url" alt="" />
					<span v-else>{{ user.display_name.slice(0, 1) }}</span>
				</q-avatar>
				<div>
					<h1 class="soz-page-title">{{ user.display_name }}</h1>
					<nav v-if="userCatalogLinks.length" class="detail-catalog-links person-catalog-links" aria-label="Catalog">
						<router-link v-for="link in userCatalogLinks" :key="link.to" :to="link.to">
							{{ link.label }}
						</router-link>
					</nav>
					<p v-else>{{ profileLocation }}</p>
				</div>
				<q-btn v-if="authStore.isAuthenticated && authStore.user?.id !== user.id"
					color="primary"
					unelevated
					rounded
					icon="chat"
					:label="t('actions.chat')"
					@click="openChat"
				/>
			</section>

			<section v-if="pages.length" class="page-row q-mt-lg">
				<router-link v-for="page in pages" :key="page.id" :to="pageRoute(page)" class="public-page-card">
					<q-icon :name="page.type === 'business' ? 'storefront' : 'diversity_3'" size="34px" color="primary" />
					<div>
						<h2>{{ page.name }}</h2>
						<p>{{ page.public_description }}</p>
					</div>
				</router-link>
			</section>

			<section v-if="user?.private_ads?.length" class="q-mt-lg">
				<h2>{{ t('ads.private') }}</h2>
				<div class="listing-grid">
					<AdCard v-for="ad in user.private_ads" :key="ad.id" :ad="ad" />
				</div>
			</section>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.user-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.person-head {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 20px;
  align-items: center;
  padding: 28px;
}

.person-head :deep(.q-avatar__content img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.detail-catalog-links {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  margin-top: 14px;
}

.person-catalog-links {
  margin-top: 8px;
}

.detail-catalog-links a {
  color: var(--soz-primary-deep);
  font-weight: 780;
  text-decoration: none;
}

.detail-catalog-links a + a::before {
  padding-inline: 8px;
  color: rgba(17, 34, 45, 0.36);
  content: "/";
}

.page-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.listing-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

.public-page-card {
  padding: 24px;
  border: 1px solid var(--soz-line);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.78);
}

.public-page-card {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 14px;
}

.public-page-card h2 {
  margin: 0 0 8px;
}

@media (max-width: 900px) {
  .person-head {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .person-head .q-btn {
    grid-column: 1 / -1;
    justify-self: start;
  }
}

@media (max-width: 760px) {
  .user-page {
    padding-inline: 10px;
  }

  .person-head,
  .page-row,
  .listing-grid {
    grid-template-columns: 1fr;
  }

  .person-head,
  .public-page-card {
    padding: 20px;
  }

  .person-head .q-btn {
    width: 100%;
  }

  .public-page-card p,
  .person-head p {
    overflow-wrap: anywhere;
  }
}
</style>
