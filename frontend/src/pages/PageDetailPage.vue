<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useAuthStore } from '@/stores/auth'
	import { fetchPage } from '@/services/api/pages'
	import { findPresencePalette } from '@/constants/presencePalettes'
	import AdCard from '@/components/AdCard.vue'
	import EventCard from '@/components/events/EventCard.vue'
	import ProductCard from '@/components/products/ProductCard.vue'
	import PagePreview from '@/components/pages/PagePreview.vue'
	import PageRatingsDialog from '@/components/ratings/PageRatingsDialog.vue'
	import PageReviewDialog from '@/components/ratings/PageReviewDialog.vue'

	const route = useRoute()
	const { t } = useI18n()
	const authStore = useAuthStore()
	const page = ref(null)
	const loading = ref(false)
	const ratingsDialogOpen = ref(false)
	const reviewDialogOpen = ref(false)
	const selectedPalette = computed(() => findPresencePalette(page.value?.palette_key))
	const canRate = computed(() => authStore.isAuthenticated && page.value?.user_id !== authStore.user?.id)

	async function load() {
		loading.value = true
		try {
			const { data } = await fetchPage(route.params.id)
			page.value = data.data
		} finally {
			loading.value = false
		}
	}

	function syncRatingSummary(summary) {
		if (!page.value || !summary) {
			return
		}

		page.value = {
			...page.value,
			rating_summary: summary
		}
	}

	function handleRatingSaved(payload) {
		syncRatingSummary(payload.summary)
	}

	onMounted(load)
</script>

<template>
	<q-page padding class="detail-page">
		<div v-if="page" class="page-shell">
			<PagePreview
				:page="page"
				:palette="selectedPalette"
				:can-rate="canRate"
				@show-ratings="ratingsDialogOpen = true"
				@rate="reviewDialogOpen = true"
			/>

			<section v-if="page.owner" class="owner-card">
				<router-link :to="{ name: 'user-page', params: { id: page.owner.id } }">
					{{ page.owner.display_name }}
				</router-link>
			</section>

			<section v-if="page.type === 'business'" class="q-mt-lg">
				<h2>{{ t('products.storeTitle') }}</h2>
				<div v-if="!page.products?.length" class="empty-state">{{ t('products.empty') }}</div>
				<div v-else class="product-grid">
					<ProductCard v-for="product in page.products" :key="product.id" :product="product" />
				</div>
			</section>

			<section v-if="page.type === 'community'" class="q-mt-lg">
				<h2>{{ t('events.eventsTitle') }}</h2>
				<div v-if="!page.events?.length" class="empty-state">{{ t('events.empty') }}</div>
				<div v-else class="event-grid">
					<EventCard v-for="event in page.events" :key="event.id" :event="event" />
				</div>
			</section>

			<section class="q-mt-lg">
				<h2>{{ t('ads.listTitle') }}</h2>
				<div v-if="!page.ads?.length" class="empty-state">{{ t('ads.empty') }}</div>
				<div v-else class="ad-grid">
					<AdCard v-for="ad in page.ads" :key="ad.id" :ad="ad" />
				</div>
			</section>

			<PageRatingsDialog
				v-model="ratingsDialogOpen"
				:page-id="page.id"
				@loaded="syncRatingSummary"
			/>
			<PageReviewDialog
				v-model="reviewDialogOpen"
				:page-id="page.id"
				@saved="handleRatingSaved"
			/>
		</div>
		<div v-else-if="loading" class="row justify-center q-pa-xl">
			<q-spinner color="primary" />
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.detail-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.ad-grid,
.product-grid,
.event-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  margin-top: 18px;
}

.owner-card {
  margin-top: 18px;
  padding: 16px;
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.76);
}

@media (max-width: 760px) {
  .ad-grid,
  .product-grid,
  .event-grid {
    grid-template-columns: 1fr;
  }
}
</style>
