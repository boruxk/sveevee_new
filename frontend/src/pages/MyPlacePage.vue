<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { deleteAd, fetchAds } from '@/services/api/ads'
	import AdCard from '@/components/AdCard.vue'
	import AdComposer from '@/components/AdComposer.vue'
	import ChatBlock from '@/components/ChatBlock.vue'
	import PageCreateDialog from '@/components/pages/PageCreateDialog.vue'

	const router = useRouter()
	const { t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const loading = ref(false)
	const ads = ref([])
	const adDialogOpen = ref(false)
	const editingAd = ref(null)
	const pageCreateDialogOpen = ref(false)
	const pageCreateType = ref('business')
	const showBusinessPageButton = computed(() => !authStore.user?.business_page)
	const showCommunityPageButton = computed(() => !authStore.user?.community_page)
	const adDialogTitle = computed(() => (editingAd.value ? t('actions.update') : t('actions.createAd')))
	const visibleAds = computed(() => (Array.isArray(ads.value) ? ads.value.filter((ad) => ad?.id) : []))

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

	function openCreateAd() {
		editingAd.value = null
		adDialogOpen.value = true
	}

	function openEditAd(ad) {
		editingAd.value = ad
		adDialogOpen.value = true
	}

	function mergeSavedAd(savedAd) {
		if (!savedAd?.id || savedAd.page_id) {
			return
		}

		const currentAds = Array.isArray(ads.value) ? ads.value : []
		const existingIndex = currentAds.findIndex((ad) => ad.id === savedAd.id)
		let nextAds = [savedAd, ...currentAds]

		if (existingIndex !== -1) {
			nextAds = currentAds.map((ad) => (ad.id === savedAd.id ? savedAd : ad))
		}

		ads.value = nextAds
	}

	async function handleAdSaved(savedAd) {
		adDialogOpen.value = false
		editingAd.value = null
		mergeSavedAd(savedAd)
		await loadAds()
		mergeSavedAd(savedAd)
	}

	function openPageCreate(type) {
		pageCreateType.value = type
		pageCreateDialogOpen.value = true
	}

	function handlePageCreated(page) {
		if (page?.type) {
			router.push({ name: page.type })
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
						@click="openPageCreate('business')"
					/>
					<q-btn v-if="showCommunityPageButton"
						unelevated
						rounded
						color="primary"
						class="create-page-btn create-page-btn--pink"
						icon="diversity_3"
						:label="t('actions.createCommunityPage')"
						@click="openPageCreate('community')"
					/>
				</div>
			</section>

			<section class="soz-section-card panel panel--chat q-mt-lg">
				<h2>{{ t('chat.title') }}</h2>
				<ChatBlock />
			</section>

			<section class="soz-section-card panel q-mt-lg">
				<div class="panel-head">
					<h2>{{ t('ads.listTitle') }}</h2>
					<q-btn rounded
						unelevated
						color="primary"
						icon="add"
						:label="t('actions.createAd')"
						@click="openCreateAd"
					/>
				</div>

				<div v-if="loading" class="row justify-center q-pa-lg">
					<q-spinner color="primary" />
				</div>
				<div v-else-if="visibleAds.length === 0" class="empty-state">{{ t('ads.empty') }}</div>
				<div v-else class="listing-list">
					<AdCard v-for="ad in visibleAds"
						:key="ad.id"
						:ad="ad"
						editable
						@edit="openEditAd"
						@delete="removeAd"
					/>
				</div>
			</section>
		</div>

		<q-dialog v-model="adDialogOpen">
			<q-card class="listing-dialog">
				<q-card-section class="dialog-head">
					<div class="text-h6">{{ adDialogTitle }}</div>
					<q-btn flat round icon="close" color="dark" v-close-popup />
				</q-card-section>
				<q-card-section>
					<AdComposer :ad="editingAd" @saved="handleAdSaved" />
				</q-card-section>
			</q-card>
		</q-dialog>
		<PageCreateDialog
			v-model="pageCreateDialogOpen"
			:type="pageCreateType"
			@created="handlePageCreated"
		/>
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
  margin: 0;
  font-size: clamp(1.42rem, 1.75vw, 1.88rem);
  line-height: 1.18;
}

.panel--chat h2 {
  margin-bottom: 24px;
}

.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  margin-bottom: 18px;
}

.listing-list {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
  margin-top: 18px;
}

.empty-state {
  margin-top: 18px;
  color: rgba(17, 34, 45, 0.62);
}

.listing-dialog {
  width: min(680px, calc(100vw - 24px));
  max-width: 680px;
  border-radius: 24px;
  background: #f9f2eb;
}

.dialog-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

@media (max-width: 900px) {
  .page-head {
    display: grid;
  }

  .page-actions {
    justify-content: flex-start;
  }

  .panel-head {
    align-items: flex-start;
    flex-direction: column;
  }

  .listing-list {
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

  .page-actions,
  .page-actions .q-btn,
  .panel-head .q-btn {
    width: 100%;
  }

  .page-actions,
  .panel-head {
    align-items: stretch;
  }

  .listing-dialog {
    width: calc(100vw - 20px);
    border-radius: 20px;
  }

  .dialog-head {
    align-items: flex-start;
  }
}
</style>
