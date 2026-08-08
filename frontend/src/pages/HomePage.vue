<script setup>
	import { onMounted, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { fetchHomeFeed } from '@/services/api/home'
	import AdCard from '@/components/AdCard.vue'

	const { t } = useI18n()
	const loading = ref(false)
	const ads = ref([])
	const currentPage = ref(1)
	const pagination = ref({
		current_page: 1,
		last_page: 1,
		per_page: 20,
		total: 0
	})

	async function load(page = currentPage.value) {
		loading.value = true
		try {
			const { data } = await fetchHomeFeed({ page })
			const feed = data.data || {}
			ads.value = Array.isArray(feed) ? feed : feed.items || []
			pagination.value = feed.pagination || {
				current_page: 1,
				last_page: 1,
				per_page: ads.value.length || 20,
				total: ads.value.length
			}
			currentPage.value = pagination.value.current_page
		} finally {
			loading.value = false
		}
	}

	onMounted(load)
</script>

<template>
	<q-page padding class="home-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<div>
					<h1 class="soz-page-title">{{ t('ads.neighborhoodFeed') }}</h1>
				</div>
			</section>

			<div v-if="loading" class="row justify-center q-pa-xl">
				<q-spinner color="primary" size="40px" />
			</div>
			<div v-else-if="ads.length === 0" class="empty-state">{{ t('ads.empty') }}</div>
			<div v-else>
				<div class="listing-grid">
					<AdCard v-for="ad in ads" :key="ad.id" :ad="ad" />
				</div>
				<div v-if="pagination.last_page > 1" class="pagination-row">
					<q-pagination
						v-model="currentPage"
						:max="pagination.last_page"
						:max-pages="7"
						direction-links
						boundary-links
						color="primary"
						active-color="secondary"
						:disable="loading"
						@update:model-value="load"
					/>
				</div>
			</div>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.home-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.page-head {
  padding: 28px;
}

.listing-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
  margin-top: 18px;
}

.empty-state {
  margin-top: 18px;
  padding: 24px;
  border: 1px dashed rgba(17, 34, 45, 0.16);
  border-radius: 8px;
}

.pagination-row {
  display: flex;
  justify-content: center;
  margin-top: 24px;
}

@media (max-width: 980px) {
  .listing-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 680px) {
  .home-page {
    padding-inline: 10px;
  }

  .listing-grid {
    grid-template-columns: 1fr;
  }
}
</style>
