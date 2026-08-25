<script setup>
	import { computed, onMounted, ref, watch } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useChatsStore } from '@/stores/chats'
	import { deleteAd, fetchAds } from '@/services/api/ads'
	import AdCard from '@/components/AdCard.vue'
	import AdComposer from '@/components/AdComposer.vue'
	import ChatBlock from '@/components/ChatBlock.vue'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import PageCreateDialog from '@/components/pages/PageCreateDialog.vue'
	import { adRoute } from '@/constants/catalogTopics'

	const route = useRoute()
	const router = useRouter()
	const { t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const chatsStore = useChatsStore()
	const loading = ref(false)
	const ads = ref([])
	const activeTab = ref('overview')
	const messagesListResetKey = ref(0)
	const openingChatFromQuery = ref(false)
	const adDialogOpen = ref(false)
	const editingAd = ref(null)
	const pageCreateDialogOpen = ref(false)
	const pageCreateType = ref('business')
	const showBusinessPageButton = computed(() => !authStore.user?.business_page)
	const showCommunityPageButton = computed(() => !authStore.user?.community_page)
	const adDialogTitle = computed(() => (editingAd.value ? t('actions.update') : t('actions.createAd')))
	const visibleAds = computed(() => (Array.isArray(ads.value) ? ads.value.filter((ad) => ad?.id) : []))
	const latestAds = computed(() => visibleAds.value.slice(0, 4))
	const recentConversations = computed(() => chatsStore.conversations.filter((conversation) => conversation.latest_message).slice(0, 5))
	const chatTargetUserId = computed(() => route.query.chatWith || null)
	const meTabs = computed(() => [
		{ name: 'overview', label: t('mePage.overview'), icon: 'dashboard' },
		{ name: 'ads', label: t('mePage.ads'), icon: 'campaign' },
		{ name: 'messages', label: t('mePage.messages'), icon: 'forum' }
	])

	function latestAdRoute(ad) {
		return adRoute(ad)
	}

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

	function openAdsTab() {
		activeTab.value = 'ads'
	}

	function clearChatQuery() {
		if (route.query.chatWith) {
			const query = { ...route.query }
			delete query.chatWith
			router.replace({ name: 'me', query })
		}
	}

	function resetMessagesList() {
		messagesListResetKey.value += 1
	}

	function openMessagesTab() {
		clearChatQuery()
		resetMessagesList()

		activeTab.value = 'messages'
	}

	function openRecentConversation(conversation) {
		const userId = conversation.other_user?.id

		activeTab.value = 'messages'

		if (userId) {
			router.push({ name: 'me', query: { ...route.query, chatWith: userId } })
		}
	}

	function adLocation(ad) {
		return [ad?.city, ad?.neighborhood].filter(Boolean).join(', ')
	}

	async function loadPage() {
		await Promise.all([loadAds(), chatsStore.loadConversations()])
	}

	watch(activeTab, (value, previous) => {
		if (value !== 'messages') {
			if (previous === 'messages') {
				resetMessagesList()
			}

			clearChatQuery()
			return
		}

		if (openingChatFromQuery.value) {
			openingChatFromQuery.value = false
			return
		}

		if (previous && previous !== 'messages') {
			clearChatQuery()
			resetMessagesList()
		}
	})

	watch(() => route.query.chatWith, (value) => {
		if (value) {
			openingChatFromQuery.value = true
			activeTab.value = 'messages'
		}
	}, { immediate: true })

	onMounted(loadPage)
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

			<q-tabs
				v-model="activeTab"
				class="me-tabs q-mt-lg"
				active-color="primary"
				indicator-color="primary"
				align="left"
				no-caps
				inline-label
			>
				<q-tab v-for="tab in meTabs"
					:key="tab.name"
					:name="tab.name"
					:icon="tab.icon"
					:label="tab.label"
				/>
			</q-tabs>

			<q-tab-panels v-model="activeTab" animated class="me-panels">
				<q-tab-panel name="overview" class="me-panel">
					<div class="overview-grid">
						<section class="overview-block">
							<div class="overview-block__head">
								<h2>{{ t('mePage.recentMessages') }}</h2>
								<q-btn flat
									dense
									rounded
									icon="forum"
									:label="t('mePage.openMessages')"
									@click="openMessagesTab"
								/>
							</div>
							<div v-if="chatsStore.loading" class="row justify-center q-pa-lg">
								<q-spinner color="primary" />
							</div>
							<div v-else-if="recentConversations.length === 0" class="empty-state">{{ t('chat.noMessages') }}</div>
							<div v-else class="overview-list">
								<button
									v-for="conversation in recentConversations"
									:key="conversation.id"
									type="button"
									class="overview-list__item"
									@click="openRecentConversation(conversation)"
								>
									<q-avatar size="42px" color="primary" text-color="white">
										<ResponsiveImage
											v-if="conversation.other_user?.profile?.photo_url"
											class="overview-avatar-image"
											:src="conversation.other_user.profile.photo_url"
											:alt="conversation.other_user?.display_name || ''"
											:avif-srcset="conversation.other_user.profile.photo_avif_srcset || ''"
											:webp-srcset="conversation.other_user.profile.photo_webp_srcset || ''"
											sizes="42px"
											:width="conversation.other_user.profile.photo_width || 96"
											:height="conversation.other_user.profile.photo_height || 96"
										/>
										<span v-else>{{ conversation.other_user?.display_name?.slice(0, 1) || 'S' }}</span>
									</q-avatar>
									<span class="overview-list__copy">
										<strong>{{ conversation.other_user?.display_name }}</strong>
										<small>{{ conversation.latest_message?.body || t('chat.noMessages') }}</small>
									</span>
									<q-badge v-if="conversation.unread_count" color="negative" rounded>{{ conversation.unread_count }}</q-badge>
								</button>
							</div>
						</section>

						<section class="overview-block">
							<div class="overview-block__head">
								<h2>{{ t('mePage.latestAds') }}</h2>
								<q-btn flat
									dense
									rounded
									icon="campaign"
									:label="t('mePage.openAds')"
									@click="openAdsTab"
								/>
							</div>
							<div v-if="loading" class="row justify-center q-pa-lg">
								<q-spinner color="primary" />
							</div>
							<div v-else-if="latestAds.length === 0" class="empty-state">{{ t('ads.empty') }}</div>
							<div v-else class="overview-list">
								<router-link
									v-for="ad in latestAds"
									:key="ad.id"
									:to="latestAdRoute(ad)"
									class="overview-list__item overview-list__item--link"
								>
									<ResponsiveImage
										v-if="ad.image_url"
										class="overview-ad-thumb"
										:src="ad.image_url"
										:alt="ad.image_alt || ad.title"
										:avif-srcset="ad.image_avif_srcset || ''"
										:webp-srcset="ad.image_webp_srcset || ''"
										sizes="46px"
										:width="ad.image_width || 768"
										:height="ad.image_height || 576"
									/>
									<q-icon v-else name="campaign" size="28px" color="primary" class="overview-ad-icon" />
									<span class="overview-list__copy">
										<strong>{{ ad.title }}</strong>
										<small>{{ adLocation(ad) || ad.text }}</small>
									</span>
								</router-link>
							</div>
						</section>
					</div>
				</q-tab-panel>

				<q-tab-panel name="ads" class="me-panel">
					<section class="soz-section-card panel">
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
				</q-tab-panel>

				<q-tab-panel name="messages" class="me-panel">
					<section class="soz-section-card panel panel--chat">
						<h2>{{ t('mePage.messages') }}</h2>
						<ChatBlock class="me-chat-block" :target-user-id="chatTargetUserId" :list-reset-key="messagesListResetKey" />
					</section>
				</q-tab-panel>
			</q-tab-panels>
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

.me-tabs {
  padding: 8px 14px;
  border: 1px solid var(--soz-line);
  border-radius: 30px;
  background: var(--soz-soft-white);
  backdrop-filter: blur(18px);
  box-shadow:
    0 18px 40px rgba(33, 18, 8, 0.04),
    inset 0 1px 0 rgba(255, 255, 255, 0.8);
}

.me-tabs :deep(.q-tabs__content) {
  gap: 18px;
}

.me-tabs :deep(.q-tabs__indicator),
.me-tabs :deep(.q-tab__indicator) {
  display: none;
}

.me-tabs :deep(.q-tab) {
  min-height: 54px;
  padding: 0 20px;
  border-radius: 999px;
  color: var(--soz-muted);
  transition:
    background-color 0.18s ease,
    box-shadow 0.18s ease,
    color 0.18s ease;
}

.me-tabs :deep(.q-tab:hover) {
  background: var(--soz-primary-tint);
}

.me-tabs :deep(.q-tab--active),
.me-tabs :deep(.q-tab--active:hover) {
  background: var(--soz-menu-gradient);
  color: #ffffff !important;
}

.me-tabs :deep(.q-tab--active .q-focus-helper) {
  opacity: 0 !important;
}

.me-tabs :deep(.q-tab--active .q-icon),
.me-tabs :deep(.q-tab--active .q-tab__label) {
  color: #ffffff !important;
}

.me-tabs :deep(.q-tab__content) {
  gap: 8px;
}

.me-tabs :deep(.q-tab__label) {
  font-size: 1.2rem;
}

.me-panels {
  margin: 18px -34px 0;
  padding: 4px 34px 58px;
  background: transparent;
  overflow: hidden;
}

.me-panel {
  padding: 0 0 52px;
  overflow: hidden;
}

.me-panels :deep(.q-panel) {
  width: calc(100% + 68px);
  margin-inline: -34px;
  padding-inline: 34px;
  overflow: hidden;
}

.overview-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 18px;
}

.overview-block {
  min-width: 0;
  padding: 24px;
  border: 1px solid var(--soz-line);
  border-radius: 30px;
  background: rgba(255, 255, 255, 0.78);
}

.overview-block__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 14px;
}

.overview-block__head h2 {
  margin: 0;
  font-size: 1.2rem;
  line-height: 1.2;
}

.overview-list {
  display: grid;
  gap: 10px;
}

.overview-list__item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
  width: 100%;
  min-width: 0;
  padding: 12px;
  border: 1px solid rgba(123, 63, 242, 0.13);
  border-radius: 8px;
  background: rgba(241, 232, 249, 0.82);
  color: var(--soz-ink);
  text-align: start;
  text-decoration: none;
  cursor: pointer;
}

.overview-list__item:hover {
  border-color: rgba(123, 63, 242, 0.25);
  background: rgba(232, 219, 247, 0.94);
}

.overview-avatar-image {
  width: 100%;
  height: 100%;
  --responsive-image-fit: cover;
}

.overview-list__item--link {
  grid-template-columns: auto minmax(0, 1fr);
}

.overview-list__copy {
  display: grid;
  gap: 3px;
  min-width: 0;
}

.overview-list__copy strong,
.overview-list__copy small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.overview-list__copy small {
  color: rgba(17, 34, 45, 0.58);
}

.overview-ad-thumb,
.overview-ad-icon {
  width: 46px;
  height: 46px;
  border-radius: 8px;
}

.overview-ad-thumb {
  display: block;
  --responsive-image-fit: cover;
  --responsive-image-position: center;
}

.overview-ad-icon {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: rgba(123, 63, 242, 0.1);
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

.panel--chat :deep(.me-chat-block.chat-block) {
  height: clamp(360px, 44dvh, 520px) !important;
  min-height: 360px !important;
  max-height: 520px !important;
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

  .overview-grid {
    grid-template-columns: 1fr;
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
  .panel,
  .overview-block {
    padding: 20px;
  }

  .page-actions,
  .page-actions .q-btn,
  .panel-head .q-btn,
  .overview-block__head .q-btn {
    width: 100%;
  }

  .page-actions,
  .panel-head,
  .overview-block__head {
    align-items: stretch;
  }

  .me-tabs {
    border-radius: 22px;
    padding: 6px 8px;
  }

  .overview-block {
    border-radius: 22px;
  }

  .me-tabs :deep(.q-tabs__content) {
    width: 100%;
    gap: 4px;
  }

  .me-tabs :deep(.q-tab) {
    flex: 1 1 0;
    min-width: 0;
    min-height: 40px;
    padding: 0 6px;
  }

  .me-tabs :deep(.q-tab__content) {
    display: inline-flex;
    flex-direction: row;
    align-items: center;
    justify-content: center;
    min-height: 40px;
    gap: 5px;
    line-height: 1;
  }

  .me-tabs :deep(.q-icon) {
    font-size: 18px;
    line-height: 1;
  }

  .me-tabs :deep(.q-tab__label) {
    overflow: visible;
    font-size: 0.8rem;
    font-weight: 700;
    text-overflow: clip;
    white-space: nowrap;
  }

  .overview-block__head {
    flex-direction: column;
  }

  .panel--chat :deep(.me-chat-block.chat-block) {
    height: 66dvh !important;
    min-height: min(360px, 66dvh) !important;
    max-height: none !important;
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
