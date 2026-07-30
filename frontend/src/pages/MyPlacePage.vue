<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { deleteAd, fetchAds } from '@/services/api/ads'
	import AdCard from '@/components/AdCard.vue'
	import AdComposer from '@/components/AdComposer.vue'
	import ChatBlock from '@/components/ChatBlock.vue'

	const { t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const loading = ref(false)
	const ads = ref([])
	const showBusinessPageButton = computed(() => !authStore.user?.business_page)
	const showCommunityPageButton = computed(() => !authStore.user?.community_page)

	async function loadAds() {
		loading.value = true
		try {
			const { data } = await fetchAds({ scope: 'mine', type: 'private_ad' })
			ads.value = data.data || []
		} finally {
			loading.value = false
		}
	}

	async function removeAd(ad) {
		try {
			await deleteAd(ad.id)
			await loadAds()
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('ads.deleteFailed') })
		}
	}

	onMounted(loadAds)
</script>

<template>
	<q-page padding class="me-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<h1 class="soz-page-title">{{ t('nav.me') }}</h1>
				<div v-if="showBusinessPageButton || showCommunityPageButton" class="page-actions">
					<q-btn v-if="showBusinessPageButton"
						unelevated
						rounded
						color="primary"
						class="create-page-btn create-page-btn--pink"
						icon="storefront"
						:label="t('actions.createBusinessPage')"
						:to="{ name: 'business' }"
					/>
					<q-btn v-if="showCommunityPageButton"
						unelevated
						rounded
						color="primary"
						class="create-page-btn create-page-btn--pink"
						icon="diversity_3"
						:label="t('actions.createCommunityPage')"
						:to="{ name: 'community' }"
					/>
				</div>
			</section>

			<section class="soz-section-card panel q-mt-lg">
				<h2>{{ t('chat.title') }}</h2>
				<ChatBlock />
			</section>

			<section class="soz-section-card panel q-mt-lg">
				<h2>{{ t('actions.createAd') }}</h2>
				<AdComposer @saved="loadAds" />

				<div v-if="loading" class="row justify-center q-pa-lg">
					<q-spinner color="primary" />
				</div>
				<div v-else-if="ads.length === 0" class="empty-state">{{ t('ads.empty') }}</div>
				<div v-else class="ad-list">
					<AdCard v-for="ad in ads" :key="ad.id" :ad="ad" editable @delete="removeAd" />
				</div>
			</section>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.me-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 18px;
  padding: 28px;
}

.page-head h1 {
  margin: 0;
}

.page-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
}

.create-page-btn--purple.q-btn.bg-primary {
  background: var(--soz-menu-gradient) !important;
  box-shadow: 0 12px 24px rgba(123, 63, 242, 0.26) !important;
}

.create-page-btn--pink.q-btn.bg-primary {
  background: var(--soz-action-gradient) !important;
  box-shadow: 0 14px 28px rgba(245, 66, 145, 0.22) !important;
}

.panel {
  padding: 28px;
}

.panel h2 {
  margin: 0 0 18px;
}

.ad-list {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
  margin-top: 18px;
}

.empty-state {
  margin-top: 18px;
  color: rgba(17, 34, 45, 0.62);
}

@media (max-width: 900px) {
  .page-head {
    display: grid;
  }

  .page-actions {
    justify-content: flex-start;
  }

  .ad-list {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 700px) {
  .me-page {
    padding-inline: 10px;
  }

  .page-head,
  .panel {
    padding: 20px;
  }
}
</style>
