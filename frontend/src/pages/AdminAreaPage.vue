<script setup>
	import { computed, nextTick, onMounted, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useRoute } from 'vue-router'
	import { useQuasar } from 'quasar'
	import {
		banAdminUser,
		createBlockedTerm,
		deleteAdminUser,
		deleteBlockedTerm,
		fetchAdminSettings,
		fetchAdminSupportChat,
		fetchAdminSupportChats,
		fetchAdminUser,
		fetchAdminUserTable,
		fetchBlockedTerms,
		restoreAdminUser,
		sendAdminSupportMessage,
		updateAdminSettings,
		updateBlockedTerm
	} from '@/services/api/admin'
	import { useAuthStore } from '@/stores/auth'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import { CHAT_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'
	import { catalogLabel } from '@/constants/catalogTopics'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { locationLabel } from '@/utils/locationLabels'

	const defaultSettings = () => ({
		ads: {
			visibility_days: 7,
			private_active_limit: 10,
			page_active_limit: 30,
			purge_after_expiry_days: 30
		},
		labels: {
			new_days: 3,
			popular_views: 100,
			popular_contacts: 10,
			highly_rated_average: 4.7,
			highly_rated_min_ratings: 3
		},
		chat: {
			new_recipients_per_day: 10,
			messages_per_minute: 30,
			guest_retention_days: 90
		},
		moderation: {
			products_per_business_page: 100,
			future_events_per_community_page: 50
		},
		platform: {
			maintenance_enabled: false,
			maintenance_messages: { he: '', en: '', ru: '', fr: '' },
			popular_topic_keys: []
		}
	})
	const clone = (value) => JSON.parse(JSON.stringify(value))

	const { locale, t } = useI18n()
	const route = useRoute()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const supportLoading = ref(false)
	const tableLoading = ref(false)
	const adminTabNames = ['communication', 'users', 'landing-pages', 'settings']
	const activeTab = ref(adminTabNames.includes(String(route.query.tab || '')) ? String(route.query.tab) : 'communication')
	const supportConversations = ref([])
	const userRows = ref([])
	const totalUsers = ref(0)
	const selectedUser = ref(null)
	const userDetailsOpen = ref(false)
	const userDetailsLoading = ref(false)
	const deletingUserId = ref(null)
	const selectedSupportKey = ref(null)
	const activeSupportConversation = ref(null)
	const supportMessage = ref('')
	const userSearch = ref('')
	const appliedUserSearch = ref('')
	const messagesEl = ref(null)
	const settingsLoading = ref(false)
	const settingsForms = ref(defaultSettings())
	const savedSettings = ref(defaultSettings())
	const savingSections = ref({})
	const catalogTopics = ref([])
	const popularTopicToAdd = ref(null)
	const blockedTerms = ref([])
	const blockedTermsLoading = ref(false)
	const blockedTermSaving = ref(false)
	const blockedTermRowSaving = ref({})
	const newBlockedTerm = ref({ term: '', locale: 'all', active: true })
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
		supportConversations.value.find((conversation) => conversation.support_key === selectedSupportKey.value) || activeSupportConversation.value || null
	)
	const activeSupportUser = computed(() => activeSupportConversation.value?.participant || selectedSupportConversation.value?.participant || null)
	const supportComposerHint = computed(() => characterLimitHint(supportMessage.value, CHAT_MAX_LENGTH, t))
	const intlLocale = computed(() => ({
		he: 'he-IL',
		en: 'en-US',
		ru: 'ru-RU',
		fr: 'fr-FR'
	}[locale.value] || locale.value))
	const localizedLocation = (value, type) => locationLabel(value, type, locale.value)
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
			field: (user) => localizedLocation(user.profile?.city, 'city') || '-',
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
	const blockedLocaleOptions = computed(() => [
		{ label: t('admin.settings.allLanguages'), value: 'all' },
		{ label: t('languages.he'), value: 'he' },
		{ label: t('languages.en'), value: 'en' },
		{ label: t('languages.ru'), value: 'ru' },
		{ label: t('languages.fr'), value: 'fr' }
	])
	const catalogTopicOptions = computed(() => catalogTopics.value
		.map((topic) => ({
			label: catalogLabel(topic.labels, locale.value),
			value: topic.key
		}))
		.filter((option) => !settingsForms.value.platform.popular_topic_keys.includes(option.value))
		.sort((left, right) => left.label.localeCompare(right.label, intlLocale.value)))
	const selectedPopularTopics = computed(() => settingsForms.value.platform.popular_topic_keys.map((key) => ({
		key,
		label: catalogLabel(catalogTopics.value.find((topic) => topic.key === key)?.labels, locale.value) || key
	})))
	const adminPageTitle = computed(() => ({
		communication: t('admin.communication'),
		users: t('admin.userTable'),
		'landing-pages': t('admin.landingPages'),
		settings: t('admin.settings.title')
	}[activeTab.value] || t('admin.users')))

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

	function formatDateTime(value) {
		if (!value) {
			return '-'
		}

		const date = new Date(value)

		if (Number.isNaN(date.getTime())) {
			return '-'
		}

		return new Intl.DateTimeFormat(intlLocale.value, {
			dateStyle: 'medium',
			timeStyle: 'short'
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

	async function openSupportConversation(conversation, { refresh = true } = {}) {
		if (!conversation?.id) {
			activeSupportConversation.value = null
			selectedSupportKey.value = null
			return
		}

		const source = conversation.source || 'account'
		selectedSupportKey.value = conversation.support_key || `${source}:${conversation.id}`
		const { data } = await fetchAdminSupportChat(source, conversation.id)
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

			const selectedStillExists = supportConversations.value.some((conversation) => conversation.support_key === selectedSupportKey.value)
			const nextConversation = selectedStillExists ? supportConversations.value.find((conversation) => conversation.support_key === selectedSupportKey.value) : supportConversations.value[0]

			await openSupportConversation(nextConversation, { refresh: false })
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
			totalUsers.value = Number(payload.total_users || pagination.total || userRows.value.length)
			tablePagination.value = {
				page: pagination.current_page || page,
				rowsPerPage: pagination.per_page || 50,
				rowsNumber: pagination.total || userRows.value.length
			}
		} finally {
			tableLoading.value = false
		}
	}

	async function openUserDetails(_, row) {
		if (!row?.id) {
			return
		}

		selectedUser.value = row
		userDetailsOpen.value = true
		userDetailsLoading.value = true

		try {
			const { data } = await fetchAdminUser(row.id)
			selectedUser.value = data.data
		} catch {
			$q.notify({ type: 'negative', message: t('admin.userDetailsFailed') })
		} finally {
			userDetailsLoading.value = false
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

	async function deleteUser(user) {
		deletingUserId.value = user.id

		try {
			await deleteAdminUser(user.id)

			if (selectedUser.value?.id === user.id) {
				userDetailsOpen.value = false
				selectedUser.value = null
			}

			let page = tablePagination.value.page
			if (userRows.value.length === 1 && page > 1) {
				page -= 1
			}

			await loadUserTable(page)
			$q.notify({ type: 'positive', message: t('admin.deleteUserSuccess') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('admin.deleteUserFailed')) })
		} finally {
			deletingUserId.value = null
		}
	}

	function confirmDeleteUser(user) {
		if (!user?.id || user.role === 'admin') {
			return
		}

		const name = user.display_name || user.name || user.email || `#${user.id}`

		$q.dialog({
			title: t('admin.deleteUserTitle'),
			message: t('admin.deleteUserMessage', { name }),
			persistent: true,
			ok: {
				label: t('actions.delete'),
				color: 'negative',
				unelevated: true
			},
			cancel: {
				label: t('actions.cancel'),
				color: 'primary',
				flat: true
			}
		}).onOk(() => deleteUser(user))
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
			const { data } = await sendAdminSupportMessage(
				activeSupportConversation.value.source || 'account',
				activeSupportConversation.value.id,
				body
			)
			activeSupportConversation.value = data.data
			supportMessage.value = ''
			await refreshSupportConversations()
			await scrollToBottom()
			$q.notify({ type: 'positive', message: t('admin.messageSent') })
		} catch {
			$q.notify({ type: 'negative', message: t('admin.messageFailed') })
		}
	}

	function sectionDirty(section) {
		return JSON.stringify(settingsForms.value[section]) !== JSON.stringify(savedSettings.value[section])
	}

	async function loadSettings() {
		settingsLoading.value = true
		blockedTermsLoading.value = true

		try {
			const [settingsResponse, termsResponse] = await Promise.all([
				fetchAdminSettings(),
				fetchBlockedTerms()
			])
			const payload = settingsResponse.data.data || {}
			const nextSettings = defaultSettings()

			Object.keys(nextSettings).forEach((section) => {
				nextSettings[section] = {
					...nextSettings[section],
					...(payload.settings?.[section] || {})
				}
			})

			settingsForms.value = clone(nextSettings)
			savedSettings.value = clone(nextSettings)
			catalogTopics.value = payload.catalog_topics || []
			blockedTerms.value = clone(termsResponse.data.data?.items || [])
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('admin.settings.loadFailed')) })
		} finally {
			settingsLoading.value = false
			blockedTermsLoading.value = false
		}
	}

	async function saveSettings(section) {
		const enablingMaintenance = section === 'platform' &&
			settingsForms.value.platform.maintenance_enabled &&
			!savedSettings.value.platform.maintenance_enabled

		if (enablingMaintenance && !window.confirm(t('admin.settings.maintenanceConfirm'))) {
			settingsForms.value.platform.maintenance_enabled = false
			return
		}

		savingSections.value = { ...savingSections.value, [section]: true }
		try {
			const { data } = await updateAdminSettings(section, settingsForms.value[section])
			const saved = clone(data.data?.settings || settingsForms.value[section])
			settingsForms.value[section] = saved
			savedSettings.value[section] = clone(saved)
			$q.notify({ type: 'positive', message: t('admin.settings.saved') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('admin.settings.saveFailed')) })
		} finally {
			savingSections.value = { ...savingSections.value, [section]: false }
		}
	}

	function addPopularTopic() {
		const key = popularTopicToAdd.value
		const selected = settingsForms.value.platform.popular_topic_keys

		if (!key || selected.includes(key) || selected.length >= 12) {
			return
		}

		selected.push(key)
		popularTopicToAdd.value = null
	}

	function movePopularTopic(index, direction) {
		const keys = settingsForms.value.platform.popular_topic_keys
		const target = index + direction

		if (target < 0 || target >= keys.length) {
			return
		}

		const [key] = keys.splice(index, 1)
		keys.splice(target, 0, key)
	}

	function removePopularTopic(index) {
		settingsForms.value.platform.popular_topic_keys.splice(index, 1)
	}

	async function addBlockedTerm() {
		if (!newBlockedTerm.value.term.trim()) {
			return
		}

		blockedTermSaving.value = true
		try {
			const { data } = await createBlockedTerm({
				...newBlockedTerm.value,
				term: newBlockedTerm.value.term.trim()
			})
			blockedTerms.value.push(data.data)
			newBlockedTerm.value = { term: '', locale: 'all', active: true }
			$q.notify({ type: 'positive', message: t('admin.settings.termAdded') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('admin.settings.termSaveFailed')) })
		} finally {
			blockedTermSaving.value = false
		}
	}

	async function saveBlockedTerm(term) {
		blockedTermRowSaving.value = { ...blockedTermRowSaving.value, [term.id]: true }
		try {
			const { data } = await updateBlockedTerm(term.id, {
				term: term.term,
				locale: term.locale,
				active: term.active
			})
			Object.assign(term, data.data)
			$q.notify({ type: 'positive', message: t('admin.settings.termSaved') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('admin.settings.termSaveFailed')) })
		} finally {
			blockedTermRowSaving.value = { ...blockedTermRowSaving.value, [term.id]: false }
		}
	}

	async function removeBlockedTerm(term) {
		if (!window.confirm(t('admin.settings.termDeleteConfirm', { term: term.term }))) {
			return
		}

		blockedTermRowSaving.value = { ...blockedTermRowSaving.value, [term.id]: true }
		try {
			await deleteBlockedTerm(term.id)
			blockedTerms.value = blockedTerms.value.filter((item) => item.id !== term.id)
			$q.notify({ type: 'positive', message: t('admin.settings.termDeleted') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('admin.settings.termDeleteFailed')) })
		} finally {
			blockedTermRowSaving.value = { ...blockedTermRowSaving.value, [term.id]: false }
		}
	}

	onMounted(() => {
		loadSupportConversations()
		loadUserTable()
		loadSettings()
	})
</script>

<template>
	<q-page padding class="admin-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<div>
					<h1 class="soz-page-title">{{ adminPageTitle }}</h1>
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
				<q-tab name="settings" icon="tune" :label="t('admin.settings.title')" />
			</q-tabs>

			<q-tab-panels v-model="activeTab" animated class="admin-panels">
				<q-tab-panel name="communication" class="admin-panel">
					<div class="admin-grid">
						<section class="soz-section-card support-list">
							<button
								v-for="conversation in supportConversations"
								:key="conversation.support_key"
								type="button"
								class="support-row"
								:class="{ 'support-row--active': selectedSupportKey === conversation.support_key }"
								@click="openSupportConversation(conversation)"
							>
								<q-avatar size="38px" color="primary" text-color="white">
									<ResponsiveImage
										v-if="conversation.participant?.profile?.photo_url"
										class="support-avatar-image"
										:src="conversation.participant.profile.photo_url"
										:alt="conversation.participant?.display_name || ''"
										:avif-srcset="conversation.participant.profile.photo_avif_srcset || ''"
										:webp-srcset="conversation.participant.profile.photo_webp_srcset || ''"
										sizes="38px"
										:width="conversation.participant.profile.photo_width || 96"
										:height="conversation.participant.profile.photo_height || 96"
									/>
									<span v-else>{{ conversation.participant?.display_name?.slice(0, 1) || 'S' }}</span>
								</q-avatar>
								<span class="support-row__copy">
									<strong>
										{{ conversation.participant?.display_name }}
										<q-badge v-if="conversation.is_guest" color="secondary" rounded>{{ t('admin.guest') }}</q-badge>
									</strong>
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
										<p v-if="activeSupportUser?.email">{{ activeSupportUser.email }}</p>
										<p v-if="!activeSupportConversation.is_guest">{{ localizedLocation(activeSupportUser?.profile?.city, 'city') || '-' }} / {{ localizedLocation(activeSupportUser?.profile?.neighborhood, 'neighborhood') || '-' }}</p>
									</div>
									<q-chip color="primary" text-color="white">{{ activeSupportConversation.is_guest ? t('admin.guest') : t('admin.supportInbox') }}</q-chip>
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
							<div class="user-total" aria-live="polite">
								<strong>{{ totalUsers.toLocaleString(intlLocale) }}</strong>
								<span>{{ t('admin.totalUsers') }}</span>
							</div>
						</div>
						<q-table
							v-model:pagination="tablePagination"
							flat
							:rows="userRows"
							:columns="userColumns"
							row-key="id"
							:loading="tableLoading"
							:rows-per-page-options="[50]"
							binary-state-sort
							class="user-table user-table--clickable"
							@row-click="openUserDetails"
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
									<div class="user-actions">
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
											@click.stop="ban(props.row)"
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
											@click.stop="restore(props.row)"
										/>
										<q-btn
											v-if="props.row.role !== 'admin'"
											flat
											round
											dense
											color="negative"
											icon="delete"
											:aria-label="t('admin.deleteUser')"
											:loading="deletingUserId === props.row.id"
											@click.stop="confirmDeleteUser(props.row)"
										>
											<q-tooltip>{{ t('admin.deleteUser') }}</q-tooltip>
										</q-btn>
									</div>
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

				<q-tab-panel name="settings" class="admin-panel settings-panel">
					<section class="soz-section-card settings-section">
						<header class="settings-section__head">
							<div><h2>{{ t('admin.settings.adsTitle') }}</h2><p>{{ t('admin.settings.adsIntro') }}</p></div>
							<q-badge v-if="sectionDirty('ads')" color="warning" text-color="dark">{{ t('admin.settings.unsaved') }}</q-badge>
						</header>
						<div class="settings-fields settings-fields--four">
							<q-input v-model.number="settingsForms.ads.visibility_days"
								outlined
								type="number"
								:label="t('admin.settings.adVisibility')"
								:suffix="t('admin.settings.days')"
								min="1"
								max="365"
							/>
							<q-input v-model.number="settingsForms.ads.private_active_limit"
								outlined
								type="number"
								:label="t('admin.settings.privateAdLimit')"
								:suffix="t('admin.settings.adsUnit')"
								min="1"
								max="1000"
							/>
							<q-input v-model.number="settingsForms.ads.page_active_limit"
								outlined
								type="number"
								:label="t('admin.settings.pageAdLimit')"
								:suffix="t('admin.settings.adsUnit')"
								min="1"
								max="5000"
							/>
							<q-input v-model.number="settingsForms.ads.purge_after_expiry_days"
								outlined
								type="number"
								:label="t('admin.settings.adPurgeDelay')"
								:suffix="t('admin.settings.days')"
								min="0"
								max="3650"
							/>
						</div>
						<footer class="settings-section__footer">
							<q-btn color="primary"
								unelevated
								rounded
								icon="save"
								:label="t('actions.save')"
								:loading="savingSections.ads"
								:disable="!sectionDirty('ads')"
								@click="saveSettings('ads')"
							/>
						</footer>
					</section>

					<section class="soz-section-card settings-section">
						<header class="settings-section__head">
							<div><h2>{{ t('admin.settings.labelsTitle') }}</h2><p>{{ t('admin.settings.labelsIntro') }}</p></div>
							<q-badge v-if="sectionDirty('labels')" color="warning" text-color="dark">{{ t('admin.settings.unsaved') }}</q-badge>
						</header>
						<div class="settings-fields">
							<q-input v-model.number="settingsForms.labels.new_days"
								outlined
								type="number"
								:label="t('admin.settings.newLabelDays')"
								:suffix="t('admin.settings.days')"
								min="1"
								max="365"
							/>
							<q-input v-model.number="settingsForms.labels.popular_views"
								outlined
								type="number"
								:label="t('admin.settings.popularViews')"
								:suffix="t('admin.settings.views')"
								min="1"
								max="10000000"
							/>
							<q-input v-model.number="settingsForms.labels.popular_contacts"
								outlined
								type="number"
								:label="t('admin.settings.popularContacts')"
								:suffix="t('admin.settings.contacts')"
								min="1"
								max="1000000"
							/>
							<q-input v-model.number="settingsForms.labels.highly_rated_average"
								outlined
								type="number"
								step="0.1"
								:label="t('admin.settings.ratingAverage')"
								min="1"
								max="5"
							/>
							<q-input v-model.number="settingsForms.labels.highly_rated_min_ratings"
								outlined
								type="number"
								:label="t('admin.settings.ratingCount')"
								:suffix="t('admin.settings.ratings')"
								min="1"
								max="100000"
							/>
						</div>
						<footer class="settings-section__footer">
							<q-btn color="primary"
								unelevated
								rounded
								icon="save"
								:label="t('actions.save')"
								:loading="savingSections.labels"
								:disable="!sectionDirty('labels')"
								@click="saveSettings('labels')"
							/>
						</footer>
					</section>

					<section class="soz-section-card settings-section">
						<header class="settings-section__head">
							<div><h2>{{ t('admin.settings.chatTitle') }}</h2><p>{{ t('admin.settings.chatIntro') }}</p></div>
							<q-badge v-if="sectionDirty('chat')" color="warning" text-color="dark">{{ t('admin.settings.unsaved') }}</q-badge>
						</header>
						<div class="settings-fields">
							<q-input v-model.number="settingsForms.chat.new_recipients_per_day"
								outlined
								type="number"
								:label="t('admin.settings.newRecipients')"
								:suffix="t('admin.settings.perDay')"
								min="1"
								max="10000"
							/>
							<q-input v-model.number="settingsForms.chat.messages_per_minute"
								outlined
								type="number"
								:label="t('admin.settings.messagesPerMinute')"
								:suffix="t('admin.settings.perMinute')"
								min="1"
								max="1000"
							/>
							<q-input v-model.number="settingsForms.chat.guest_retention_days"
								outlined
								type="number"
								:label="t('admin.settings.guestRetention')"
								:suffix="t('admin.settings.days')"
								min="1"
								max="3650"
							/>
						</div>
						<footer class="settings-section__footer">
							<q-btn color="primary"
								unelevated
								rounded
								icon="save"
								:label="t('actions.save')"
								:loading="savingSections.chat"
								:disable="!sectionDirty('chat')"
								@click="saveSettings('chat')"
							/>
						</footer>
					</section>

					<section class="soz-section-card settings-section">
						<header class="settings-section__head">
							<div><h2>{{ t('admin.settings.moderationTitle') }}</h2><p>{{ t('admin.settings.moderationIntro') }}</p></div>
							<q-badge v-if="sectionDirty('moderation')" color="warning" text-color="dark">{{ t('admin.settings.unsaved') }}</q-badge>
						</header>
						<div class="settings-fields">
							<q-input v-model.number="settingsForms.moderation.products_per_business_page"
								outlined
								type="number"
								:label="t('admin.settings.productLimit')"
								:suffix="t('admin.settings.products')"
								min="1"
								max="100000"
							/>
							<q-input v-model.number="settingsForms.moderation.future_events_per_community_page"
								outlined
								type="number"
								:label="t('admin.settings.eventLimit')"
								:suffix="t('admin.settings.events')"
								min="1"
								max="10000"
							/>
						</div>
						<footer class="settings-section__footer">
							<q-btn color="primary"
								unelevated
								rounded
								icon="save"
								:label="t('actions.save')"
								:loading="savingSections.moderation"
								:disable="!sectionDirty('moderation')"
								@click="saveSettings('moderation')"
							/>
						</footer>

						<div class="blocked-terms">
							<div class="settings-subhead"><h3>{{ t('admin.settings.blockedTerms') }}</h3><p>{{ t('admin.settings.blockedTermsIntro') }}</p></div>
							<div class="blocked-term-row blocked-term-row--new">
								<q-input v-model="newBlockedTerm.term"
									outlined
									dense
									:label="t('admin.settings.wordOrPhrase')"
									maxlength="200"
									@keyup.enter="addBlockedTerm"
								/>
								<q-select v-model="newBlockedTerm.locale"
									outlined
									dense
									emit-value
									map-options
									:options="blockedLocaleOptions"
									:label="t('admin.settings.language')"
								/>
								<q-toggle v-model="newBlockedTerm.active" :label="t('admin.settings.activeTerm')" />
								<q-btn color="primary"
									unelevated
									round
									icon="add"
									:aria-label="t('admin.settings.addTerm')"
									:loading="blockedTermSaving"
									:disable="!newBlockedTerm.term.trim()"
									@click="addBlockedTerm"
								/>
							</div>
							<div v-for="term in blockedTerms" :key="term.id" class="blocked-term-row">
								<q-input v-model="term.term" outlined dense :label="t('admin.settings.wordOrPhrase')" maxlength="200" />
								<q-select v-model="term.locale"
									outlined
									dense
									emit-value
									map-options
									:options="blockedLocaleOptions"
									:label="t('admin.settings.language')"
								/>
								<q-toggle v-model="term.active" :label="t('admin.settings.activeTerm')" />
								<div class="blocked-term-actions">
									<q-btn flat
										round
										color="primary"
										icon="save"
										:aria-label="t('actions.save')"
										:loading="blockedTermRowSaving[term.id]"
										@click="saveBlockedTerm(term)"
									/>
									<q-btn flat
										round
										color="negative"
										icon="delete"
										:aria-label="t('actions.delete')"
										:disable="blockedTermRowSaving[term.id]"
										@click="removeBlockedTerm(term)"
									/>
								</div>
							</div>
							<div v-if="!blockedTermsLoading && blockedTerms.length === 0" class="settings-empty">{{ t('admin.settings.noBlockedTerms') }}</div>
							<q-inner-loading :showing="blockedTermsLoading" />
						</div>
					</section>

					<section class="soz-section-card settings-section">
						<header class="settings-section__head">
							<div><h2>{{ t('admin.settings.platformTitle') }}</h2><p>{{ t('admin.settings.platformIntro') }}</p></div>
							<q-badge v-if="sectionDirty('platform')" color="warning" text-color="dark">{{ t('admin.settings.unsaved') }}</q-badge>
						</header>
						<div class="maintenance-setting">
							<div><strong>{{ t('admin.settings.maintenanceMode') }}</strong><p>{{ t('admin.settings.maintenanceHint') }}</p></div>
							<q-toggle v-model="settingsForms.platform.maintenance_enabled" color="negative" />
						</div>
						<div class="settings-fields settings-fields--messages">
							<q-input v-for="language in ['he', 'en', 'ru', 'fr']"
								:key="language"
								v-model="settingsForms.platform.maintenance_messages[language]"
								outlined
								type="textarea"
								autogrow
								:label="`${t('admin.settings.maintenanceMessage')} - ${t(`languages.${language}`)}`"
								maxlength="500"
							/>
						</div>

						<div class="popular-settings">
							<div class="settings-subhead"><h3>{{ t('admin.settings.popularCategories') }}</h3><p>{{ t('admin.settings.popularCategoriesIntro') }}</p></div>
							<div class="popular-add">
								<q-select v-model="popularTopicToAdd"
									outlined
									emit-value
									map-options
									use-input
									input-debounce="0"
									:options="catalogTopicOptions"
									:label="t('admin.settings.chooseCategory')"
									:disable="selectedPopularTopics.length >= 12"
								/>
								<q-btn color="primary"
									unelevated
									round
									icon="add"
									:aria-label="t('admin.settings.addCategory')"
									:disable="!popularTopicToAdd || selectedPopularTopics.length >= 12"
									@click="addPopularTopic"
								/>
							</div>
							<div class="popular-list">
								<div v-for="(topic, index) in selectedPopularTopics" :key="topic.key" class="popular-row">
									<strong><span>{{ index + 1 }}</span>{{ topic.label }}</strong>
									<div>
										<q-btn flat
											round
											icon="arrow_upward"
											:aria-label="t('admin.settings.moveUp')"
											:disable="index === 0"
											@click="movePopularTopic(index, -1)"
										/>
										<q-btn flat
											round
											icon="arrow_downward"
											:aria-label="t('admin.settings.moveDown')"
											:disable="index === selectedPopularTopics.length - 1"
											@click="movePopularTopic(index, 1)"
										/>
										<q-btn flat
											round
											color="negative"
											icon="close"
											:aria-label="t('actions.delete')"
											@click="removePopularTopic(index)"
										/>
									</div>
								</div>
								<div v-if="selectedPopularTopics.length === 0" class="settings-empty">{{ t('admin.settings.popularEmpty') }}</div>
							</div>
						</div>
						<footer class="settings-section__footer">
							<q-btn color="primary"
								unelevated
								rounded
								icon="save"
								:label="t('actions.save')"
								:loading="savingSections.platform"
								:disable="!sectionDirty('platform')"
								@click="saveSettings('platform')"
							/>
						</footer>
					</section>
					<q-inner-loading :showing="settingsLoading" />
				</q-tab-panel>
			</q-tab-panels>

			<q-dialog v-model="userDetailsOpen">
				<q-card class="user-detail-dialog">
					<header class="user-detail-head">
						<div>
							<h2>{{ selectedUser?.display_name || t('admin.userDetails') }}</h2>
							<p>{{ selectedUser?.email || '-' }}</p>
						</div>
						<q-btn
							flat
							round
							dense
							icon="close"
							:aria-label="t('actions.close')"
							v-close-popup
						/>
					</header>

					<div class="user-detail-body">
						<q-inner-loading :showing="userDetailsLoading" />
						<template v-if="selectedUser">
							<section class="user-detail-section">
								<h3>{{ t('admin.accountDetails') }}</h3>
								<dl class="user-detail-grid">
									<div><dt>ID</dt><dd>{{ selectedUser.id }}</dd></div>
									<div><dt>{{ t('admin.name') }}</dt><dd>{{ selectedUser.display_name || selectedUser.name || '-' }}</dd></div>
									<div><dt>{{ t('auth.email') }}</dt><dd>{{ selectedUser.email || '-' }}</dd></div>
									<div><dt>{{ t('admin.login') }}</dt><dd>{{ selectedUser.login || '-' }}</dd></div>
									<div><dt>{{ t('admin.role') }}</dt><dd>{{ selectedUser.role || '-' }}</dd></div>
									<div><dt>{{ t('admin.locale') }}</dt><dd>{{ selectedUser.locale || '-' }}</dd></div>
									<div><dt>{{ t('auth.phone') }}</dt><dd>{{ selectedUser.profile?.phone || '-' }}</dd></div>
									<div><dt>{{ t('auth.city') }}</dt><dd>{{ localizedLocation(selectedUser.profile?.city, 'city') || '-' }}</dd></div>
									<div><dt>{{ t('auth.neighborhood') }}</dt><dd>{{ localizedLocation(selectedUser.profile?.neighborhood, 'neighborhood') || '-' }}</dd></div>
									<div><dt>{{ t('admin.registeredAt') }}</dt><dd>{{ formatDateTime(selectedUser.created_at) }}</dd></div>
									<div><dt>{{ t('admin.emailVerified') }}</dt><dd>{{ formatDateTime(selectedUser.email_verified_at) }}</dd></div>
									<div><dt>{{ t('admin.status') }}</dt><dd>{{ selectedUser.banned_at ? t('admin.banned') : t('admin.active') }}</dd></div>
									<div v-if="selectedUser.banned_reason" class="user-detail-grid__wide"><dt>{{ t('admin.banReason') }}</dt><dd>{{ selectedUser.banned_reason }}</dd></div>
								</dl>
							</section>

							<section class="user-detail-section">
								<h3>{{ t('admin.userPages') }}</h3>
								<div v-if="selectedUser.pages?.length" class="user-page-list">
									<article v-for="userPage in selectedUser.pages" :key="userPage.id" class="user-page-row">
										<div>
											<strong>{{ userPage.name || t(`pages.kinds.${userPage.type}`) }}</strong>
											<span>{{ t(`pages.kinds.${userPage.type}`) }} - {{ localizedLocation(userPage.address_details?.city, 'city') || '-' }}</span>
											<small>
												{{ t('admin.pageContentCounts', {
													products: userPage.products?.length || 0,
													services: userPage.services?.length || 0,
													events: userPage.events?.length || 0,
													ads: userPage.ads?.length || 0
												}) }}
											</small>
										</div>
										<q-btn flat round icon="open_in_new" :aria-label="t('admin.openLandingPage')" :to="userPage.public_path" />
									</article>
								</div>
								<div v-else class="support-empty user-pages-empty">{{ t('admin.noUserPages') }}</div>
							</section>
						</template>
					</div>
				</q-card>
			</q-dialog>
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
  gap: 18px;
  align-items: center;
  justify-content: space-between;
}

.user-search {
  width: min(100%, 460px);
}

.user-total {
  display: flex;
  gap: 9px;
  align-items: baseline;
  color: var(--soz-muted);
  white-space: nowrap;
}

.user-total strong {
  color: var(--soz-ink);
  font-size: 1.5rem;
  line-height: 1;
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

.user-table--clickable :deep(tbody tr) {
  cursor: pointer;
}

.user-table--clickable :deep(tbody tr:hover) {
  background: rgba(123, 63, 242, 0.06);
}

.user-detail-dialog {
  width: min(900px, calc(100vw - 32px));
  max-width: 900px;
  max-height: min(820px, calc(100vh - 48px));
  border-radius: 24px !important;
  background: #fff8fb;
  overflow: hidden;
}

.user-detail-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  padding: 22px 24px;
  border-bottom: 1px solid rgba(17, 34, 45, 0.1);
  background: rgba(255, 255, 255, 0.76);
}

.user-detail-head h2,
.user-detail-head p,
.user-detail-section h3 {
  margin: 0;
}

.user-detail-head h2 {
  font-size: 24px;
}

.user-detail-head p {
  margin-top: 4px;
  color: var(--soz-muted);
}

.user-detail-body {
  position: relative;
  display: grid;
  gap: 20px;
  max-height: calc(min(820px, 100vh - 48px) - 92px);
  padding: 22px 24px 28px;
  overflow-y: auto;
}

.user-detail-section {
  display: grid;
  gap: 14px;
}

.user-detail-section h3 {
  font-size: 19px;
}

.user-detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
  margin: 0;
}

.user-detail-grid > div,
.user-page-row {
  padding: 13px 14px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.7);
}

.user-detail-grid__wide {
  grid-column: 1 / -1;
}

.user-detail-grid dt {
  color: var(--soz-muted);
  font-size: 12px;
  font-weight: 700;
}

.user-detail-grid dd {
  margin: 4px 0 0;
  color: var(--soz-ink);
  overflow-wrap: anywhere;
}

.user-page-list {
  display: grid;
  gap: 10px;
}

.user-page-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
}

.user-page-row > div {
  display: grid;
  gap: 3px;
  min-width: 0;
}

.user-page-row span,
.user-page-row small {
  color: var(--soz-muted);
}

.user-pages-empty {
  min-height: 90px;
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

.user-actions {
  display: flex;
  gap: 4px;
  align-items: center;
  justify-content: flex-end;
  min-width: 116px;
}

.moderation-btn {
  min-height: 28px;
  padding: 4px 8px;
  font-size: 12px;
  opacity: 0.78;
}

.settings-panel {
  position: relative;
  display: grid;
  gap: 18px;
}

.settings-section {
  padding: 26px;
}

.settings-section__head,
.settings-section__footer,
.maintenance-setting,
.popular-row {
  display: flex;
  gap: 18px;
  align-items: center;
  justify-content: space-between;
}

.settings-section__head {
  align-items: flex-start;
  margin-bottom: 22px;
}

.settings-section__head h2,
.settings-section__head p,
.settings-subhead h3,
.settings-subhead p,
.maintenance-setting p {
  margin: 0;
}

.settings-section__head h2 {
  font-size: 24px;
}

.settings-section__head p,
.settings-subhead p,
.maintenance-setting p {
  margin-top: 5px;
  color: var(--soz-muted);
}

.settings-fields {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
}

.settings-fields--four {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.settings-fields--messages {
  grid-template-columns: repeat(2, minmax(0, 1fr));
  margin-top: 16px;
}

.settings-section__footer {
  justify-content: flex-end;
  margin-top: 20px;
}

.blocked-terms,
.popular-settings {
  position: relative;
  margin-top: 28px;
  padding-top: 24px;
  border-top: 1px solid rgba(17, 34, 45, 0.08);
}

.settings-subhead {
  margin-bottom: 16px;
}

.blocked-term-row {
  display: grid;
  grid-template-columns: minmax(180px, 1fr) 180px auto auto;
  gap: 12px;
  align-items: center;
  padding: 11px 0;
  border-bottom: 1px solid rgba(17, 34, 45, 0.06);
}

.blocked-term-row--new {
  padding-top: 0;
}

.blocked-term-actions {
  display: flex;
  align-items: center;
}

.maintenance-setting {
  padding: 16px 18px;
  border-radius: 18px;
  background: rgba(198, 40, 75, 0.055);
}

.popular-add {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
  max-width: 720px;
}

.popular-list {
  display: grid;
  gap: 6px;
  margin-top: 14px;
}

.popular-row {
  min-height: 52px;
  padding: 5px 7px 5px 14px;
  border-radius: 16px;
  background: rgba(17, 34, 45, 0.045);
}

.popular-row strong {
  display: inline-flex;
  gap: 10px;
  align-items: center;
}

.popular-row strong span {
  display: grid;
  width: 26px;
  height: 26px;
  border-radius: 50%;
  background: var(--soz-primary-tint);
  color: var(--soz-primary-deep);
  font-size: 12px;
  place-items: center;
}

.settings-empty {
  padding: 18px;
  color: var(--soz-muted);
  text-align: center;
}

@media (max-width: 900px) {
  .admin-grid {
    grid-template-columns: 1fr;
  }

  .settings-fields,
  .settings-fields--four {
    grid-template-columns: repeat(2, minmax(0, 1fr));
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

  .settings-section {
    padding: 20px 16px;
  }

  .settings-section__head {
    gap: 10px;
  }

  .settings-section__head h2 {
    font-size: 21px;
  }

  .settings-fields,
  .settings-fields--four,
  .settings-fields--messages,
  .blocked-term-row {
    grid-template-columns: 1fr;
  }

  .blocked-term-row {
    padding: 16px 0;
  }

  .blocked-term-actions {
    justify-content: flex-end;
  }

  .maintenance-setting {
    align-items: flex-start;
  }

  .popular-row {
    align-items: flex-start;
    flex-direction: column;
  }

  .user-search {
    width: 100%;
  }

  .user-table-tools {
    align-items: stretch;
    flex-direction: column;
  }

  .user-total {
    justify-content: flex-end;
  }

  .user-detail-dialog {
    width: calc(100vw - 20px);
    max-height: calc(100dvh - 20px);
    border-radius: 18px !important;
  }

  .user-detail-head,
  .user-detail-body {
    padding-inline: 18px;
  }

  .user-detail-grid {
    grid-template-columns: 1fr;
  }

  .user-detail-grid__wide {
    grid-column: auto;
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
