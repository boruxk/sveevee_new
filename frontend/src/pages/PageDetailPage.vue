<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { fetchPage } from '@/services/api/pages'
	import { findPresencePalette } from '@/constants/presencePalettes'
	import AdCard from '@/components/AdCard.vue'
	import PagePreview from '@/components/pages/PagePreview.vue'

	const route = useRoute()
	const { t } = useI18n()
	const page = ref(null)
	const loading = ref(false)
	const selectedPalette = computed(() => findPresencePalette(page.value?.palette_key))

	async function load() {
		loading.value = true
		try {
			const { data } = await fetchPage(route.params.id)
			page.value = data.data
		} finally {
			loading.value = false
		}
	}

	onMounted(load)
</script>

<template>
	<q-page padding class="detail-page">
		<div v-if="page" class="page-shell">
			<PagePreview :page="page" :palette="selectedPalette" />

			<section v-if="page.owner" class="owner-card">
				<router-link :to="{ name: 'user-page', params: { id: page.owner.id } }">
					{{ page.owner.display_name }}
				</router-link>
			</section>

			<section class="q-mt-lg">
				<h2>{{ t('ads.listTitle') }}</h2>
				<div v-if="!page.ads?.length" class="empty-state">{{ t('ads.empty') }}</div>
				<div v-else class="ad-grid">
					<AdCard v-for="ad in page.ads" :key="ad.id" :ad="ad" />
				</div>
			</section>
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

.ad-grid {
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
  .ad-grid {
    grid-template-columns: 1fr;
  }
}
</style>
