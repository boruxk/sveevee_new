<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useAuthStore } from '@/stores/auth'
	import { fetchPage } from '@/services/api/pages'
	import { findPresencePalette } from '@/constants/presencePalettes'
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
	const hasStoreProducts = computed(() => page.value?.type === 'business' && page.value?.products?.length > 0)
	const hasCommunityEvents = computed(() => page.value?.type === 'community' && page.value?.events?.length > 0)
	const hasPreviewContent = computed(() => hasStoreProducts.value || hasCommunityEvents.value)

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
				:has-after-info="hasPreviewContent"
				@show-ratings="ratingsDialogOpen = true"
				@rate="reviewDialogOpen = true"
			>
				<template #afterInfo>
					<section v-if="hasStoreProducts" class="preview-section">
						<h2>{{ t('products.storeTitle') }}</h2>
						<div class="product-grid">
							<ProductCard v-for="product in page.products" :key="product.id" :product="product" />
						</div>
					</section>
					<section v-else-if="hasCommunityEvents" class="preview-section">
						<h2>{{ t('events.eventsTitle') }}</h2>
						<div class="event-grid">
							<EventCard v-for="event in page.events" :key="event.id" :event="event" />
						</div>
					</section>
				</template>
			</PagePreview>

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

.product-grid,
.event-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  margin-top: 18px;
}

.preview-section h2 {
  margin: 0;
}

@media (max-width: 760px) {
  .product-grid,
  .event-grid {
    grid-template-columns: 1fr;
  }
}
</style>
