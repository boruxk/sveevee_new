<script setup>
	import { computed, nextTick, onMounted, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { banAdminUser, fetchAdminSupportChats, fetchAdminUserTable, restoreAdminUser } from '@/services/api/admin'
	import { fetchChat, sendChatMessage } from '@/services/api/chats'
	import { useAuthStore } from '@/stores/auth'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import { CHAT_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'

	const { locale, t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const supportLoading = ref(false)
	const tableLoading = ref(false)
	const activeTab = ref('communication')
	const supportConversations = ref([])
	const userRows = ref([])
	const selectedConversationId = ref(null)
	const activeSupportConversation = ref(null)
	const supportMessage = ref('')
	const userSearch = ref('')
	const appliedUserSearch = ref('')
	const messagesEl = ref(null)
	const tablePagination = ref({
		page: 1,
		rowsPerPage: 50,
		rowsNumber: 0
	})
	const landingPages = computed(() => [
		{
			key: 'businesses',
			icon: 'storefront',
			title: t('admin.landingPageBusinessTitle'),
			description: t('admin.landingPageBusinessDescription'),
			routeName: 'businesses-landing',
			exampleRouteName: 'business-example-page'
		},
		{
			key: 'communities',
			icon: 'diversity_3',
			title: t('admin.landingPageCommunityTitle'),
			description: t('admin.landingPageCommunityDescription'),
			routeName: 'communities-landing',
			exampleRouteName: 'community-example-page'
		}
	])
	const supportMessages = computed(() => activeSupportConversation.value?.messages || [])
	const selectedSupportConversation = computed(() =>
		supportConversations.value.find((conversation) => conversation.id === selectedConversationId.value) || activeSupportConversation.value || null
	)
	const activeSupportUser = computed(() => activeSupportConversation.value?.other_user || selectedSupportConversation.value?.other_user || null)
	const supportComposerHint = computed(() => characterLimitHint(supportMessage.value, CHAT_MAX_LENGTH, t))
	const intlLocale = computed(() => ({
		he: 'he-IL',
		en: 'en-US',
		ru: 'ru-RU',
		fr: 'fr-FR'
	}[locale.value] || locale.value))
	const userColumns = computed(() => [
		{
			name: 'name',
			label: t('admin.name'),
			align: 'left',
			field: (user) => user.display_name || user.name || '-',
			sortable: false
		},
		{
			name: 'email',
			label: t('auth.email'),
			align: 'left',
			field: (user) => user.email || '-',
			sortable: false
		},
		{
			name: 'city',
			label: t('auth.city'),
			align: 'left',
			field: (user) => user.profile?.city || '-',
			sortable: false
		},
		{
			name: 'status',
			label: t('admin.status'),
			align: 'left',
			field: (user) => user.banned_at ? t('admin.banned') : t('admin.active'),
			sortable: false
		},
		{
			name: 'actions',
			label: t('admin.actions'),
			align: 'right',
			field: 'actions',
			sortable: false
		}
	])

	function formatMessageTime(value) {
		if (!value) {
			return ''
		}

		const date = new Date(value)

		if (Number.isNaN(date.getTime())) {
			return ''
		}

		return new Intl.DateTimeFormat(intlLocale.value, {
			hour: 'numeric',
			minute: '2-digit',
			hour12: false
		}).format(date)
	}

	function isOwn(message) {
		return message.sender_id === authStore.user?.id
	}

	async function scrollToBottom() {
		await nextTick()
		if (messagesEl.value) {
			messagesEl.value.scrollTop = messagesEl.value.scrollHeight
		}
	}

	async function refreshSupportConversations() {
		const { data } = await fetchAdminSupportChats()
		supportConversations.value = data.data?.conversations || []
	}

	async function openSupportConversation(id, { refresh = true } = {}) {
		if (!id) {
			activeSupportConversation.value = null
			selectedConversationId.value = null
			return
		}

		selectedConversationId.value = id
		const { data } = await fetchChat(id)
		activeSupportConversation.value = data.data

		if (refresh) {
			await refreshSupportConversations()
		}

		await scrollToBottom()
	}

	async function loadSupportConversations() {
		supportLoading.value = true
		try {
			await refreshSupportConversations()

			const selectedStillExists = supportConversations.value.some((conversation) => conversation.id === selectedConversationId.value)
			const nextId = selectedStillExists ? selectedConversationId.value : supportConversations.value[0]?.id

			await openSupportConversation(nextId, { refresh: false })
		} finally {
			supportLoading.value = false
		}
	}

	async function loadUserTable(page = tablePagination.value.page) {
		tableLoading.value = true
		try {
			const { data } = await fetchAdminUserTable({
				page,
				q: appliedUserSearch.value || undefined
			})
			const payload = data.data || {}
			const pagination = payload.pagination || {}

			userRows.value = payload.items || []
			tablePagination.value = {
				page: pagination.current_page || page,
				rowsPerPage: pagination.per_page || 50,
				rowsNumber: pagination.total || userRows.value.length
			}
		} finally {
			tableLoading.value = false
		}
	}

	async function applyUserSearch() {
		appliedUserSearch.value = String(userSearch.value || '').trim()
		await loadUserTable(1)
	}

	async function clearUserSearch() {
		userSearch.value = ''
		await applyUserSearch()
	}

	async function reloadAfterModeration() {
		await loadUserTable(tablePagination.value.page)
	}

	async function ban(user) {
		await banAdminUser(user.id, {})
		await reloadAfterModeration()
	}

	async function restore(user) {
		await restoreAdminUser(user.id)
		await reloadAfterModeration()
	}

	function onTableRequest({ pagination }) {
		loadUserTable(pagination.page || 1)
	}

	async function sendSupportReply() {
		const body = supportMessage.value.trim()

		if (!body || !activeSupportConversation.value) {
			return
		}

		try {
			const { data } = await sendChatMessage(activeSupportConversation.value.id, body)
			activeSupportConversation.value = data.data
			supportMessage.value = ''
			await refreshSupportConversations()
			await scrollToBottom()
			$q.notify({ type: 'positive', message: t('admin.messageSent') })
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('admin.messageFailed') })
		}
	}

	onMounted(() => {
		loadSupportConversations()
		loadUserTable()
	})
</script>

<template>
	<q-page padding class="admin-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<div>
					<h1 class="soz-page-title">{{ t('admin.users') }}</h1>
				</div>
			</section>

			<q-tabs
				v-model="activeTab"
				class="admin-tabs q-mt-lg"
				active-color="primary"
				indicator-color="primary"
				align="left"
				no-caps
				inline-label
				mobile-arrows
				outside-arrows
			>
				<q-tab name="communication" icon="forum" :label="t('admin.communication')" />
				<q-tab name="users" icon="manage_accounts" :label="t('admin.userTable')" />
				<q-tab name="landing-pages" icon="dashboard" :label="t('admin.landingPages')" />
			</q-tabs>

			<q-tab-panels v-model="activeTab" animated class="admin-panels">
				<q-tab-panel name="communication" class="admin-panel">
					<div class="admin-grid">
						<section class="soz-section-card support-list">
							<button
								v-for="conversation in supportConversations"
								:key="conversation.id"
								type="button"
								class="support-row"
								:class="{ 'support-row--active': selectedConversationId === conversation.id }"
								@click="openSupportConversation(conversation.id)"
							>
								<q-avatar size="38px" color="primary" text-color="white">
									<ResponsiveImage
										v-if="conversation.other_user?.profile?.photo_url"
										class="support-avatar-image"
										:src="conversation.other_user.profile.photo_url"
										:alt="conversation.other_user?.display_name || ''"
										:avif-srcset="conversation.other_user.profile.photo_avif_srcset || ''"
										:webp-srcset="conversation.other_user.profile.photo_webp_srcset || ''"
										sizes="38px"
										:width="conversation.other_user.profile.photo_width || 96"
										:height="conversation.other_user.profile.photo_height || 96"
									/>
									<span v-else>{{ conversation.other_user?.display_name?.slice(0, 1) || 'S' }}</span>
								</q-avatar>
								<span class="support-row__copy">
									<strong>{{ conversation.other_user?.display_name }}</strong>
									<small>{{ conversation.latest_message?.body || t('chat.noMessages') }}</small>
								</span>
								<q-badge v-if="conversation.unread_count" color="negative" rounded>{{ conversation.unread_count }}</q-badge>
							</button>
							<div v-if="!supportLoading && supportConversations.length === 0" class="support-empty">
								<q-icon name="forum" size="28px" />
								<strong>{{ t('admin.noSupportChats') }}</strong>
							</div>
							<q-inner-loading :showing="supportLoading" />
						</section>

						<section class="soz-section-card detail-panel support-detail">
							<template v-if="activeSupportConversation">
								<header class="support-detail__head">
									<div>
										<h2>{{ activeSupportUser?.display_name }}</h2>
										<p>{{ activeSupportUser?.email }}</p>
										<p>{{ activeSupportUser?.profile?.city || '-' }} / {{ activeSupportUser?.profile?.neighborhood || '-' }}</p>
									</div>
									<q-chip color="primary" text-color="white">{{ t('admin.supportInbox') }}</q-chip>
								</header>

								<div ref="messagesEl" class="support-messages">
									<div v-if="supportMessages.length === 0" class="support-empty">{{ t('chat.noMessages') }}</div>
									<template v-else>
										<div
											v-for="chatMessage in supportMessages"
											:key="chatMessage.id"
											class="support-message"
											:class="{ 'support-message--own': isOwn(chatMessage) }"
										>
											<div class="support-message__bubble">
												<small class="support-message__meta">
													{{ chatMessage.sender?.display_name || activeSupportUser?.display_name || '-' }}
													<span>{{ formatMessageTime(chatMessage.created_at) }}</span>
												</small>
												{{ chatMessage.body }}
											</div>
										</div>
									</template>
								</div>

								<footer class="support-compose">
									<q-input
										v-model="supportMessage"
										outlined
										type="textarea"
										autogrow
										:label="t('admin.message')"
										:maxlength="CHAT_MAX_LENGTH"
										:hint="supportComposerHint"
										counter
										persistent-hint
										class="support-compose__input"
										@keydown.enter.exact.prevent="sendSupportReply"
									/>
									<q-btn
										color="primary"
										unelevated
										rounded
										icon="send"
										:label="t('actions.send')"
										:disable="!supportMessage.trim()"
										@click="sendSupportReply"
									/>
								</footer>
							</template>
							<div v-else class="support-empty support-empty--panel">
								<q-icon name="forum" size="32px" />
								<strong>{{ t('admin.noSupportChats') }}</strong>
							</div>
						</section>
					</div>
				</q-tab-panel>

				<q-tab-panel name="users" class="admin-panel">
					<section class="soz-section-card table-panel">
						<div class="user-table-tools">
							<q-input
								v-model="userSearch"
								outlined
								dense
								rounded
								clearable
								debounce="300"
								class="user-search"
								:label="t('admin.userSearch')"
								:placeholder="t('admin.userSearchPlaceholder')"
								@keyup.enter="applyUserSearch"
								@update:model-value="applyUserSearch"
								@clear="clearUserSearch"
							>
								<template #prepend>
									<q-icon name="search" />
								</template>
							</q-input>
						</div>
						<q-table
							v-model:pagination="tablePagination"
							flat
							class="user-table"
							:rows="userRows"
							:columns="userColumns"
							row-key="id"
							:loading="tableLoading"
							:rows-per-page-options="[50]"
							binary-state-sort
							@request="onTableRequest"
						>
							<template #body-cell-name="props">
								<q-td :props="props">
									<div class="table-name">
										<strong>{{ props.row.display_name || props.row.name || '-' }}</strong>
										<small>{{ props.row.role }}</small>
									</div>
								</q-td>
							</template>

							<template #body-cell-email="props">
								<q-td :props="props" class="table-email">
									{{ props.row.email || '-' }}
								</q-td>
							</template>

							<template #body-cell-status="props">
								<q-td :props="props">
									<q-chip dense :color="props.row.banned_at ? 'negative' : 'positive'" text-color="white">
										{{ props.row.banned_at ? t('admin.banned') : t('admin.active') }}
									</q-chip>
								</q-td>
							</template>

							<template #body-cell-actions="props">
								<q-td :props="props">
									<q-btn v-if="!props.row.banned_at"
										color="negative"
										flat
										dense
										rounded
										size="sm"
										icon="block"
										:label="t('actions.ban')"
										:disable="props.row.role === 'admin'"
										class="moderation-btn"
										@click="ban(props.row)"
									/>
									<q-btn v-else
										color="positive"
										flat
										dense
										rounded
										size="sm"
										icon="restart_alt"
										:label="t('admin.unban')"
										class="moderation-btn"
										@click="restore(props.row)"
									/>
								</q-td>
							</template>
						</q-table>
					</section>
				</q-tab-panel>

				<q-tab-panel name="landing-pages" class="admin-panel">
					<section class="soz-section-card landing-pages-panel">
						<div class="landing-pages-panel__head">
							<div>
								<h2>{{ t('admin.landingPages') }}</h2>
								<p>{{ t('admin.landingPagesIntro') }}</p>
							</div>
							<q-chip color="primary" text-color="white">{{ t('promoLanding.freeBadge') }}</q-chip>
						</div>

						<div class="landing-page-list">
							<article v-for="page in landingPages" :key="page.key" class="landing-page-row">
								<span class="landing-page-row__icon">
									<q-icon :name="page.icon" size="30px" />
								</span>
								<div class="landing-page-row__copy">
									<strong>{{ page.title }}</strong>
									<p>{{ page.description }}</p>
								</div>
								<q-btn
									color="primary"
									unelevated
									rounded
									icon="open_in_new"
									:label="t('admin.openLandingPage')"
									:to="{ name: page.routeName }"
								/>
								<q-btn
									color="primary"
									unelevated
									rounded
									class="landing-page-row__example-btn"
									icon="visibility"
									:label="t('promoLanding.examplePageCta')"
									:to="{ name: page.exampleRouteName }"
								/>
							</article>
						</div>
					</section>
				</q-tab-panel>
			</q-tab-panels>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.admin-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.page-head,
.support-list,
.detail-panel {
  padding: 28px;
}

.admin-tabs {
  padding: 8px 14px;
  border: 1px solid var(--soz-line);
  border-radius: 30px;
  background: var(--soz-soft-white);
  backdrop-filter: blur(18px);
  box-shadow:
    0 18px 40px rgba(33, 18, 8, 0.04),
    inset 0 1px 0 rgba(255, 255, 255, 0.8);
}

.admin-tabs :deep(.q-tabs__content) {
  gap: 18px;
}

.admin-tabs :deep(.q-tabs__indicator),
.admin-tabs :deep(.q-tab__indicator) {
  display: none;
}

.admin-tabs :deep(.q-tab) {
  min-height: 54px;
  padding: 0 20px;
  border-radius: 999px;
  color: var(--soz-muted);
  transition:
    background-color 0.18s ease,
    color 0.18s ease;
}

.admin-tabs :deep(.q-tab:hover) {
  background: var(--soz-primary-tint);
}

.admin-tabs :deep(.q-tab--active),
.admin-tabs :deep(.q-tab--active:hover) {
  background: var(--soz-menu-gradient);
  color: #ffffff !important;
}

.admin-tabs :deep(.q-tab--active .q-focus-helper) {
  opacity: 0 !important;
}

.admin-tabs :deep(.q-tab--active .q-icon),
.admin-tabs :deep(.q-tab--active .q-tab__label) {
  color: #ffffff !important;
}

.admin-tabs :deep(.q-tab__content) {
  gap: 8px;
}

.admin-tabs :deep(.q-tab__label) {
  font-size: 1.08rem;
  font-weight: 760;
}

.admin-panels {
  background: transparent;
}

.admin-panel {
  padding: 18px 0 0;
}

.table-panel {
  display: grid;
  gap: 14px;
  padding: 24px;
  overflow: visible;
}

.user-table-tools {
  display: flex;
  justify-content: flex-start;
}

.user-search {
  width: min(100%, 460px);
}

.user-table {
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 22px;
  background: rgba(255, 255, 255, 0.72);
  box-shadow: 0 14px 32px rgba(17, 34, 45, 0.05);
}

.user-table :deep(.q-table__top),
.user-table :deep(.q-table__bottom),
.user-table :deep(thead tr),
.user-table :deep(tbody tr) {
  background: transparent;
}

.user-table :deep(.q-table__middle) {
  border-radius: 22px;
}

.landing-pages-panel {
  display: grid;
  gap: 18px;
  padding: 26px;
}

.landing-pages-panel__head {
  display: flex;
  gap: 18px;
  align-items: flex-start;
  justify-content: space-between;
}

.landing-pages-panel__head h2 {
  margin: 0 0 6px;
  color: var(--soz-ink);
  font-size: 26px;
  line-height: 1.16;
}

.landing-pages-panel__head p {
  max-width: 720px;
  margin: 0;
  color: rgba(17, 34, 45, 0.62);
  font-size: 15px;
  line-height: 1.6;
}

.landing-page-list {
  display: grid;
  gap: 12px;
}

.landing-page-row {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto auto;
  gap: 16px;
  align-items: center;
  padding: 18px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.66);
  box-shadow: 0 12px 28px rgba(17, 34, 45, 0.05);
}

.landing-page-row__example-btn.q-btn.bg-primary {
  background: var(--soz-menu-gradient) !important;
}

.landing-page-row__icon {
  display: grid;
  place-items: center;
  width: 58px;
  height: 58px;
  border-radius: 8px;
  background: var(--soz-menu-gradient);
  color: #ffffff;
  box-shadow: 0 12px 24px rgba(123, 63, 242, 0.18);
}

.landing-page-row__copy {
  min-width: 0;
}

.landing-page-row__copy strong {
  display: block;
  margin-bottom: 4px;
  color: var(--soz-ink);
  font-size: 18px;
  line-height: 1.24;
}

.landing-page-row__copy p {
  margin: 0;
  color: rgba(17, 34, 45, 0.62);
  line-height: 1.56;
}

.table-name {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.table-name small {
  color: rgba(17, 34, 45, 0.54);
}

.table-email {
  overflow-wrap: anywhere;
}

.admin-grid {
  display: grid;
  grid-template-columns: 360px minmax(0, 1fr);
  gap: 18px;
}

.support-list {
  position: relative;
  display: grid;
  gap: 8px;
  align-content: start;
  min-height: 420px;
}

.support-row {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  align-items: center;
  gap: 10px;
  padding: 14px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.64);
  color: var(--soz-ink);
  text-align: start;
  cursor: pointer;
}

.support-row--active,
.support-row:hover {
  border-color: rgba(123, 63, 242, 0.38);
  background: rgba(123, 63, 242, 0.08);
}

.support-avatar-image {
  width: 100%;
  height: 100%;
  --responsive-image-fit: cover;
}

.support-row__copy {
  display: grid;
  gap: 3px;
  min-width: 0;
}

.support-row__copy strong,
.support-row__copy small {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.support-row__copy small {
  color: rgba(17, 34, 45, 0.56);
}

.support-detail {
  display: grid;
  min-height: 520px;
  overflow: hidden;
}

.support-detail > template,
.support-detail > :not(.support-empty--panel) {
  min-height: 0;
}

.support-detail__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding-bottom: 16px;
  border-bottom: 1px solid rgba(17, 34, 45, 0.1);
}

.support-detail__head h2 {
  margin: 0 0 4px;
  font-size: 24px;
}

.support-detail__head p {
  margin: 0;
  color: rgba(17, 34, 45, 0.62);
  overflow-wrap: anywhere;
}

.support-messages {
  display: grid;
  align-content: start;
  gap: 10px;
  min-height: 0;
  max-height: 420px;
  overflow-y: auto;
  padding: 18px 0;
}

.support-message {
  display: flex;
  min-width: 0;
  justify-content: flex-start;
}

.support-message--own {
  justify-content: flex-end;
}

.support-message__bubble {
  max-width: min(76%, 620px);
  padding: 10px 12px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.96);
  box-shadow: 0 8px 18px rgba(17, 34, 45, 0.06);
  overflow-wrap: anywhere;
  white-space: pre-line;
  word-break: break-word;
}

.support-message--own .support-message__bubble {
  background: rgba(123, 63, 242, 0.14);
}

.support-message__meta {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 4px;
  color: rgba(17, 34, 45, 0.5);
  font-size: 11px;
}

.support-compose {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 10px;
  align-items: end;
  padding-top: 14px;
  border-top: 1px solid rgba(17, 34, 45, 0.1);
}

.support-compose__input {
  min-width: 0;
}

.support-empty {
  display: grid;
  gap: 8px;
  place-items: center;
  min-height: 160px;
  color: rgba(17, 34, 45, 0.56);
  text-align: center;
}

.support-empty--panel {
  min-height: 460px;
}

.moderation-btn {
  min-height: 28px;
  padding: 4px 8px;
  font-size: 12px;
  opacity: 0.78;
}

@media (max-width: 900px) {
  .admin-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 700px) {
  .admin-page {
    padding-inline: 10px;
  }

  .page-head,
  .support-list,
  .detail-panel {
    padding: 20px;
  }

  .admin-tabs {
    border-radius: 22px;
    padding: 6px 38px;
  }

  .admin-tabs :deep(.q-tabs__content) {
    gap: 4px;
  }

  .admin-tabs :deep(.q-tab) {
    min-height: 40px;
    padding: 0 8px;
  }

  .admin-tabs :deep(.q-icon) {
    font-size: 18px;
  }

  .admin-tabs :deep(.q-tab__label) {
    font-size: 0.82rem;
    font-weight: 700;
  }

  .admin-tabs :deep(.q-tabs__arrow) {
    z-index: 2;
    min-width: 30px;
    color: var(--soz-ink);
    text-shadow: none;
  }

  .admin-tabs :deep(.q-tabs__arrow--left) {
    inset-inline-start: 4px;
  }

  .admin-tabs :deep(.q-tabs__arrow--right) {
    inset-inline-end: 4px;
  }

  .support-row {
    grid-template-columns: auto minmax(0, 1fr) auto;
  }

  .support-messages {
    max-height: 360px;
  }

  .support-compose {
    grid-template-columns: 1fr;
  }

  .support-compose .q-btn {
    width: 100%;
  }

  .table-panel {
    padding: 18px;
  }

  .user-search {
    width: 100%;
  }

  .landing-pages-panel {
    padding: 20px;
  }

  .landing-pages-panel__head,
  .landing-page-row {
    align-items: stretch;
    grid-template-columns: 1fr;
  }

  .landing-pages-panel__head {
    flex-direction: column;
  }

  .landing-page-row .q-btn {
    width: 100%;
  }
}
</style>
