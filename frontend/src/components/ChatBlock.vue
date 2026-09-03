<script setup>
	import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useChatsStore } from '@/stores/chats'
	import {
		fetchPageChat,
		fetchPageChats,
		fetchPageConversation,
		sendPageChatMessage,
		sendPageChatMessageToPage
	} from '@/services/api/pageChats'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import DeleteIcon from '@/components/icons/DeleteIcon.vue'
	import { CHAT_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'

	const MESSAGE_BATCH_SIZE = 10

	const props = defineProps({
		targetUserId: {
			type: [Number, String],
			default: null
		},
		compact: {
			type: Boolean,
			default: false
		},
		listResetKey: {
			type: [Number, String],
			default: 0
		},
		pageId: {
			type: [Number, String],
			default: null
		},
		pageOwner: {
			type: Boolean,
			default: false
		},
		targetPageConversationId: {
			type: [Number, String],
			default: null
		}
	})

	const { locale, t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const chatsStore = useChatsStore()
	const draft = ref('')
	const messagesEl = ref(null)
	const mobileThreadOpen = ref(false)
	const visibleMessageCount = ref(MESSAGE_BATCH_SIZE)
	const pageConversations = ref([])
	const pageActiveConversation = ref(null)
	const pageSending = ref(false)
	const deleteDialogOpen = ref(false)
	const deletingChat = ref(false)
	let chatRefreshTimer = null
	let refreshingChat = false

	const isPageChat = computed(() => Boolean(props.pageId))
	const conversations = computed(() => (isPageChat.value ? pageConversations.value : chatsStore.conversations))
	const active = computed(() => (isPageChat.value ? pageActiveConversation.value : chatsStore.activeConversation))
	const messages = computed(() => active.value?.messages || [])
	const chatSending = computed(() => (isPageChat.value ? pageSending.value : chatsStore.sending))
	const threadPanelName = computed(() => {
		if (!active.value?.id) {
			return 'thread-empty'
		}

		return `thread-${active.value.is_page_chat ? 'page' : 'person'}-${active.value.id}`
	})
	const isMobileChat = computed(() => $q.screen.width <= 760)
	const mobilePanel = computed({
		get: () => (mobileThreadOpen.value ? 'thread' : 'list'),
		set: (value) => {
			mobileThreadOpen.value = value === 'thread'
		}
	})
	const showMobileBack = computed(() => isMobileChat.value && !props.compact && mobileThreadOpen.value)
	const composerState = computed(() => active.value?.composer_state || { can_send: true, reason: null, message: null })
	const composerBlocked = computed(() => !composerState.value.can_send)
	const composerMessage = computed(() => localizedChatLimit(composerState.value.reason, composerState.value.message) || t('chat.placeholder'))
	const composerHint = computed(() => (composerBlocked.value ? '' : characterLimitHint(draft.value, CHAT_MAX_LENGTH, t)))
	const composerModel = computed({
		get: () => (composerBlocked.value ? composerMessage.value : draft.value),
		set: (value) => {
			if (!composerBlocked.value) {
				draft.value = value
			}
		}
	})
	const composerClass = computed(() => ({
		'chat-composer--blocked': composerBlocked.value,
		'chat-composer--danger': composerState.value.reason === 'daily_limit'
	}))
	const visibleMessages = computed(() => {
		const start = Math.max(messages.value.length - visibleMessageCount.value, 0)

		return messages.value.slice(start)
	})
	const hiddenMessageCount = computed(() => Math.max(messages.value.length - visibleMessages.value.length, 0))
	const olderMessageBatchCount = computed(() => Math.min(MESSAGE_BATCH_SIZE, hiddenMessageCount.value))
	const activeIsOnline = computed(() => Boolean(active.value?.other_user?.presence?.is_online))
	const canDeleteActive = computed(() => Boolean(
		active.value?.id && !active.value?.is_page_chat && !active.value?.is_support
	))
	const threadIsVisible = computed(() => !isMobileChat.value || props.compact || mobileThreadOpen.value)

	const chatLimitMessageKeys = {
		pending_reply: 'chat.pendingReply',
		page_pending_reply: 'chat.pagePendingReply',
		daily_limit: 'chat.dailyLimit'
	}
	const intlLocale = computed(() => ({
		he: 'he-IL',
		en: 'en-US',
		ru: 'ru-RU',
		fr: 'fr-FR'
	}[locale.value] || locale.value))

	function localizedChatLimit(reason) {
		const key = chatLimitMessageKeys[reason]

		return key ? t(key) : ''
	}

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

	function conversationKey(conversation) {
		return `${conversation?.is_page_chat ? 'page' : 'person'}-${conversation?.id}`
	}

	function isActiveConversation(conversation) {
		return Boolean(active.value) &&
			conversationKey(active.value) === conversationKey(conversation)
	}

	function currentMessagesEl() {
		return Array.isArray(messagesEl.value) ? messagesEl.value.at(-1) : messagesEl.value
	}

	function waitForFrame() {
		return new Promise((resolve) => {
			if (typeof window === 'undefined') {
				resolve()
				return
			}

			window.requestAnimationFrame(resolve)
		})
	}

	async function scrollToBottom() {
		await nextTick()
		await waitForFrame()

		const el = currentMessagesEl()

		if (el) {
			el.scrollTop = el.scrollHeight
		}
	}

	async function showOlderMessages() {
		const el = currentMessagesEl()
		const previousHeight = el?.scrollHeight || 0

		visibleMessageCount.value += MESSAGE_BATCH_SIZE
		await nextTick()

		const nextEl = currentMessagesEl()

		if (nextEl) {
			nextEl.scrollTop += nextEl.scrollHeight - previousHeight
		}
	}

	function onThreadAfterEnter() {
		scrollToBottom()
	}

	async function openConversation(conversation) {
		const id = conversation?.id ?? conversation

		if (isPageChat.value) {
			const { data } = await fetchPageConversation(id)
			pageActiveConversation.value = data.data
			if (props.pageOwner) {
				await refreshPageConversations()
			}
		} else {
			await chatsStore.openConversation(conversation)
		}

		if (isMobileChat.value) {
			mobileThreadOpen.value = true
		}
		await scrollToBottom()
	}

	async function refreshPageConversations() {
		const { data } = await fetchPageChats(props.pageId)
		pageConversations.value = data.data?.conversations || []
	}

	async function loadPageChat() {
		pageActiveConversation.value = null
		pageConversations.value = []

		if (props.pageOwner) {
			await refreshPageConversations()
			if (isMobileChat.value && !props.compact) {
				mobileThreadOpen.value = false
			} else if (pageConversations.value[0]) {
				await openConversation(pageConversations.value[0].id)
			}
		} else {
			const { data } = await fetchPageChat(props.pageId)
			pageActiveConversation.value = data.data
			mobileThreadOpen.value = true
			await chatsStore.loadConversations()
		}
	}

	async function load() {
		if (isPageChat.value) {
			await loadPageChat()
			await scrollToBottom()
			return
		}

		if (props.targetUserId) {
			await chatsStore.openWithUser(props.targetUserId)
			mobileThreadOpen.value = true
		} else if (props.targetPageConversationId) {
			await chatsStore.loadConversations()
			const target = chatsStore.conversations.find((conversation) => (
				conversation.is_page_chat &&
				String(conversation.id) === String(props.targetPageConversationId)
			))

			if (target) {
				await chatsStore.openConversation(target)
				mobileThreadOpen.value = true
			}
		} else {
			await chatsStore.loadConversations()
			if (isMobileChat.value && !props.compact) {
				mobileThreadOpen.value = false
			} else if (!active.value && conversations.value[0]) {
				await chatsStore.openConversation(conversations.value[0])
			}
		}

		await scrollToBottom()
	}

	function showConversationList() {
		mobileThreadOpen.value = false
	}

	async function send() {
		const body = draft.value.trim()

		if (!body || composerBlocked.value) {
			return
		}

		try {
			if (isPageChat.value) {
				pageSending.value = true
				let response
				if (active.value?.id) {
					response = await sendPageChatMessage(active.value.id, body)
				} else {
					response = await sendPageChatMessageToPage(props.pageId, body)
				}
				const { data } = response
				pageActiveConversation.value = data.data
				if (props.pageOwner) {
					await refreshPageConversations()
				} else {
					await chatsStore.loadConversations()
				}
			} else {
				await chatsStore.send(body, props.targetUserId)
			}
			draft.value = ''
			await scrollToBottom()
		} catch (error) {
			const reason = error.response?.data?.errors?.reason
			$q.notify({ type: 'negative', message: localizedChatLimit(reason) || t('chat.sendFailed') })
		} finally {
			pageSending.value = false
		}
	}

	function requestDeleteChat() {
		if (canDeleteActive.value) {
			deleteDialogOpen.value = true
		}
	}

	async function deleteActiveChat(mode) {
		if (!canDeleteActive.value || deletingChat.value) {
			return
		}

		deletingChat.value = true
		try {
			await chatsStore.deleteConversation(active.value.id, mode)
			deleteDialogOpen.value = false
			mobileThreadOpen.value = false
			$q.notify({ type: 'positive', message: t('chat.deleteSuccess') })
		} catch {
			$q.notify({ type: 'negative', message: t('chat.deleteFailed') })
		} finally {
			deletingChat.value = false
		}
	}

	async function refreshVisibleChat() {
		if (refreshingChat || (typeof document !== 'undefined' && document.visibilityState !== 'visible')) {
			return
		}

		refreshingChat = true
		const previousMessageCount = messages.value.length

		try {
			if (isPageChat.value) {
				if (props.pageOwner) {
					await refreshPageConversations()
				}

				if (active.value?.id && threadIsVisible.value) {
					const { data } = await fetchPageConversation(active.value.id)
					pageActiveConversation.value = data.data
				}
			} else {
				await chatsStore.loadConversations()
				if (threadIsVisible.value) {
					await chatsStore.refreshActiveConversation()
				}
			}

			if (messages.value.length > previousMessageCount) {
				await scrollToBottom()
			}
		} catch {
			// Polling is best-effort; the next refresh or user action will retry.
		} finally {
			refreshingChat = false
		}
	}

	onMounted(async() => {
		await load()
		chatRefreshTimer = window.setInterval(refreshVisibleChat, 30_000)
	})
	onBeforeUnmount(() => {
		if (chatRefreshTimer) {
			window.clearInterval(chatRefreshTimer)
		}
	})
	watch(() => props.targetUserId, load)
	watch(() => props.targetPageConversationId, load)
	watch(() => [props.pageId, props.pageOwner], load)
	watch(() => active.value?.id, () => {
		visibleMessageCount.value = MESSAGE_BATCH_SIZE
	})
	watch(() => props.listResetKey, () => {
		if (isMobileChat.value && !props.compact) {
			mobileThreadOpen.value = false
		}
	})
	watch(isMobileChat, async(mobile) => {
		if (mobile && !props.targetUserId && !props.compact) {
			mobileThreadOpen.value = false
		}

		if (!mobile && !active.value && conversations.value[0]) {
			await openConversation(conversations.value[0])
			await scrollToBottom()
		}
	})
</script>

<template>
	<section
		class="chat-block"
		:class="{
			'chat-block--compact': compact,
			'chat-block--mobile-thread': compact || mobileThreadOpen
		}"
	>
		<q-tab-panels v-if="isMobileChat && !compact"
			v-model="mobilePanel"
			animated
			class="chat-mobile-panels"
		>
			<q-tab-panel name="list" class="chat-mobile-panel">
				<aside class="chat-list">
					<button
						v-for="conversation in conversations"
						:key="conversationKey(conversation)"
						type="button"
						class="chat-list__item"
						:class="{ 'chat-list__item--active': isActiveConversation(conversation) }"
						@click="openConversation(conversation)"
					>
						<div class="chat-avatar-wrap">
							<q-avatar size="40px" color="primary" text-color="white">
								<ResponsiveImage
									v-if="conversation.other_user?.profile?.photo_url"
									class="chat-avatar-image"
									:src="conversation.other_user.profile.photo_url"
									:alt="conversation.other_user?.display_name || ''"
									:avif-srcset="conversation.other_user.profile.photo_avif_srcset || ''"
									:webp-srcset="conversation.other_user.profile.photo_webp_srcset || ''"
									sizes="40px"
									:width="conversation.other_user.profile.photo_width || 96"
									:height="conversation.other_user.profile.photo_height || 96"
								/>
								<span v-else>{{ conversation.other_user?.display_name?.slice(0, 1) || 'S' }}</span>
							</q-avatar>
							<span v-if="conversation.other_user?.presence?.is_online" class="chat-presence-dot" :aria-label="t('chat.online')">
								<q-tooltip>{{ t('chat.online') }}</q-tooltip>
							</span>
						</div>
						<span class="chat-list__copy">
							<strong>{{ conversation.other_user?.display_name }}</strong>
							<small>{{ conversation.latest_message?.body || t('chat.noMessages') }}</small>
						</span>
						<q-badge v-if="conversation.unread_count" color="negative" rounded>{{ conversation.unread_count }}</q-badge>
					</button>
				</aside>
			</q-tab-panel>

			<q-tab-panel name="thread" class="chat-mobile-panel">
				<div class="chat-main">
					<div class="chat-thread-shell">
						<header class="chat-main__head">
							<q-btn v-if="showMobileBack"
								flat
								round
								dense
								icon="arrow_back"
								class="chat-back-btn"
								@click="showConversationList"
							>
								<q-tooltip>{{ t('chat.backToList') }}</q-tooltip>
							</q-btn>
							<div class="chat-main__identity">
								<div class="text-h6">{{ active?.other_user?.display_name || t('chat.empty') }}</div>
								<div v-if="activeIsOnline" class="chat-online-label">
									<span class="chat-online-label__dot" />
									{{ t('chat.online') }}
								</div>
							</div>
							<q-btn v-if="canDeleteActive"
								flat
								round
								dense
								color="negative"
								class="chat-delete-btn"
								:aria-label="t('chat.deleteChat')"
								@click="requestDeleteChat"
							>
								<DeleteIcon :size="19" />
								<q-tooltip>{{ t('chat.deleteChat') }}</q-tooltip>
							</q-btn>
						</header>

						<div ref="messagesEl" class="chat-messages">
							<div v-if="!active" class="chat-empty">{{ t('chat.empty') }}</div>
							<div v-else-if="messages.length === 0" class="chat-empty">{{ t('chat.noMessages') }}</div>
							<template v-else>
								<div v-if="hiddenMessageCount" class="chat-load-older">
									<q-btn flat
										dense
										rounded
										icon="unfold_more"
										:label="t('chat.loadOlder', { count: olderMessageBatchCount })"
										@click="showOlderMessages"
									/>
								</div>
								<div
									v-for="message in visibleMessages"
									:key="message.id"
									class="chat-message"
									:class="{ 'chat-message--own': isOwn(message) }"
								>
									<div class="chat-message__bubble">
										{{ message.body }}
										<span>{{ formatMessageTime(message.created_at) }}</span>
									</div>
								</div>
							</template>
						</div>

						<footer class="chat-compose">
							<q-input
								v-model="composerModel"
								outlined
								type="textarea"
								autogrow
								:readonly="composerBlocked"
								:disable="!active || chatSending"
								:class="['chat-composer', composerClass]"
								:placeholder="t('chat.placeholder')"
								:maxlength="CHAT_MAX_LENGTH"
								:hint="composerHint"
								:counter="!composerBlocked"
								:persistent-hint="!composerBlocked"
								@keydown.enter.exact.prevent="send"
							/>
							<q-btn round
								unelevated
								color="primary"
								icon="send"
								:loading="chatSending"
								:disable="!active || composerBlocked || !draft.trim()"
								@click="send"
							>
								<q-tooltip>{{ t('actions.send') }}</q-tooltip>
							</q-btn>
						</footer>
					</div>
				</div>
			</q-tab-panel>
		</q-tab-panels>

		<template v-else>
			<aside v-if="!compact" class="chat-list">
				<button
					v-for="conversation in conversations"
					:key="conversationKey(conversation)"
					type="button"
					class="chat-list__item"
					:class="{ 'chat-list__item--active': isActiveConversation(conversation) }"
					@click="openConversation(conversation)"
				>
					<div class="chat-avatar-wrap">
						<q-avatar size="40px" color="primary" text-color="white">
							<ResponsiveImage
								v-if="conversation.other_user?.profile?.photo_url"
								class="chat-avatar-image"
								:src="conversation.other_user.profile.photo_url"
								:alt="conversation.other_user?.display_name || ''"
								:avif-srcset="conversation.other_user.profile.photo_avif_srcset || ''"
								:webp-srcset="conversation.other_user.profile.photo_webp_srcset || ''"
								sizes="40px"
								:width="conversation.other_user.profile.photo_width || 96"
								:height="conversation.other_user.profile.photo_height || 96"
							/>
							<span v-else>{{ conversation.other_user?.display_name?.slice(0, 1) || 'S' }}</span>
						</q-avatar>
						<span v-if="conversation.other_user?.presence?.is_online" class="chat-presence-dot" :aria-label="t('chat.online')">
							<q-tooltip>{{ t('chat.online') }}</q-tooltip>
						</span>
					</div>
					<span class="chat-list__copy">
						<strong>{{ conversation.other_user?.display_name }}</strong>
						<small>{{ conversation.latest_message?.body || t('chat.noMessages') }}</small>
					</span>
					<q-badge v-if="conversation.unread_count" color="negative" rounded>{{ conversation.unread_count }}</q-badge>
				</button>
			</aside>

			<div class="chat-main">
				<Transition name="chat-thread-switch" mode="out-in" @after-enter="onThreadAfterEnter">
					<div :key="threadPanelName" class="chat-thread-shell">
						<header class="chat-main__head">
							<q-btn v-if="showMobileBack"
								flat
								round
								dense
								icon="arrow_back"
								class="chat-back-btn"
								@click="showConversationList"
							>
								<q-tooltip>{{ t('chat.backToList') }}</q-tooltip>
							</q-btn>
							<div class="chat-main__identity">
								<div class="text-h6">{{ active?.other_user?.display_name || t('chat.empty') }}</div>
								<div v-if="activeIsOnline" class="chat-online-label">
									<span class="chat-online-label__dot" />
									{{ t('chat.online') }}
								</div>
							</div>
							<q-btn v-if="canDeleteActive"
								flat
								round
								dense
								color="negative"
								class="chat-delete-btn"
								:aria-label="t('chat.deleteChat')"
								@click="requestDeleteChat"
							>
								<DeleteIcon :size="19" />
								<q-tooltip>{{ t('chat.deleteChat') }}</q-tooltip>
							</q-btn>
						</header>

						<div ref="messagesEl" class="chat-messages">
							<div v-if="!active" class="chat-empty">{{ t('chat.empty') }}</div>
							<div v-else-if="messages.length === 0" class="chat-empty">{{ t('chat.noMessages') }}</div>
							<template v-else>
								<div v-if="hiddenMessageCount" class="chat-load-older">
									<q-btn flat
										dense
										rounded
										icon="unfold_more"
										:label="t('chat.loadOlder', { count: olderMessageBatchCount })"
										@click="showOlderMessages"
									/>
								</div>
								<div
									v-for="message in visibleMessages"
									:key="message.id"
									class="chat-message"
									:class="{ 'chat-message--own': isOwn(message) }"
								>
									<div class="chat-message__bubble">
										{{ message.body }}
										<span>{{ formatMessageTime(message.created_at) }}</span>
									</div>
								</div>
							</template>
						</div>

						<footer class="chat-compose">
							<q-input
								v-model="composerModel"
								outlined
								type="textarea"
								autogrow
								:readonly="composerBlocked"
								:disable="!active || chatSending"
								:class="['chat-composer', composerClass]"
								:placeholder="t('chat.placeholder')"
								:maxlength="CHAT_MAX_LENGTH"
								:hint="composerHint"
								:counter="!composerBlocked"
								:persistent-hint="!composerBlocked"
								@keydown.enter.exact.prevent="send"
							/>
							<q-btn round
								unelevated
								color="primary"
								icon="send"
								:loading="chatSending"
								:disable="!active || composerBlocked || !draft.trim()"
								@click="send"
							>
								<q-tooltip>{{ t('actions.send') }}</q-tooltip>
							</q-btn>
						</footer>
					</div>
				</Transition>
			</div>
		</template>

		<q-dialog v-model="deleteDialogOpen" persistent>
			<q-card class="chat-delete-dialog">
				<q-card-section class="chat-delete-dialog__head">
					<span class="chat-delete-dialog__icon"><DeleteIcon :size="24" /></span>
					<div>
						<h2>{{ t('chat.deleteTitle') }}</h2>
						<p>{{ t('chat.deleteMessage', { name: active?.other_user?.display_name || '' }) }}</p>
					</div>
				</q-card-section>
				<q-card-section class="chat-delete-dialog__choices">
					<q-btn
						outline
						no-caps
						color="primary"
						:disable="deletingChat"
						@click="deleteActiveChat('self')"
					>
						<span class="chat-delete-option">
							<svg viewBox="0 0 24 24" aria-hidden="true">
								<circle cx="12" cy="8" r="3.25" />
								<path d="M5.75 19c.55-3.25 2.63-5 6.25-5s5.7 1.75 6.25 5" />
							</svg>
							<span>{{ t('chat.deleteForMe') }}</span>
						</span>
					</q-btn>
					<q-btn
						unelevated
						no-caps
						color="negative"
						:loading="deletingChat"
						@click="deleteActiveChat('everyone')"
					>
						<span class="chat-delete-option">
							<svg viewBox="0 0 24 24" aria-hidden="true">
								<circle cx="8.5" cy="8.5" r="2.75" />
								<path d="M3.5 17.5c.45-2.75 2.12-4.25 5-4.25 1.2 0 2.2.25 3 .75" />
								<path d="m14.5 14.5 5 5m0-5-5 5" />
							</svg>
							<span>{{ t('chat.deleteForEveryone') }}</span>
						</span>
					</q-btn>
				</q-card-section>
				<q-card-actions align="right" class="chat-delete-dialog__actions">
					<q-btn flat
						no-caps
						color="dark"
						:label="t('actions.cancel')"
						:disable="deletingChat"
						v-close-popup
					/>
				</q-card-actions>
			</q-card>
		</q-dialog>
	</section>
</template>

<style scoped lang="scss">
.chat-block {
  display: grid;
  grid-template-columns: 280px minmax(0, 1fr);
  height: clamp(420px, calc(100dvh - 330px), 760px);
  min-height: 420px;
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.12);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.78);
}

.chat-block--compact {
  grid-template-columns: 1fr;
}

.chat-list {
  overflow-y: auto;
  border-inline-end: 1px solid rgba(17, 34, 45, 0.1);
  background: rgba(255, 248, 251, 0.72);
}

.chat-avatar-image {
  width: 100%;
  height: 100%;
  --responsive-image-fit: cover;
}

.chat-avatar-wrap {
  position: relative;
  width: 40px;
  height: 40px;
}

.chat-presence-dot {
  position: absolute;
  inset-inline-end: -1px;
  bottom: 0;
  width: 12px;
  height: 12px;
  border: 2px solid #ffffff;
  border-radius: 50%;
  background: #16a34a;
  box-shadow: 0 2px 6px rgba(22, 163, 74, 0.28);
}

.chat-list__item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 10px;
  align-items: center;
  width: 100%;
  padding: 12px;
  border: 0;
  border-bottom: 1px solid rgba(17, 34, 45, 0.08);
  background: transparent;
  color: var(--soz-ink);
  text-align: start;
  cursor: pointer;
}

.chat-list__item--active,
.chat-list__item:hover {
  background: rgba(123, 63, 242, 0.1);
}

.chat-list__copy {
  display: grid;
  gap: 3px;
  min-width: 0;
}

.chat-list__copy strong {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.chat-list__copy small {
  overflow: hidden;
  color: rgba(17, 34, 45, 0.58);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.chat-main {
  height: 100%;
  min-height: 0;
  min-width: 0;
  overflow: hidden;
}

.chat-thread-shell {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  height: 100%;
  min-height: 0;
  min-width: 0;
  overflow: hidden;
}

.chat-thread-switch-enter-active,
.chat-thread-switch-leave-active {
  transition: opacity 180ms ease, transform 180ms ease;
  will-change: opacity, transform;
}

.chat-thread-switch-enter-from {
  opacity: 0;
  transform: translateY(10px);
}

.chat-thread-switch-leave-to {
  opacity: 0;
  transform: translateY(-10px);
}

.chat-main__head {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 16px;
  border-bottom: 1px solid rgba(17, 34, 45, 0.1);
}

.chat-main__identity {
  min-width: 0;
}

.chat-main__identity .text-h6 {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.chat-online-label {
  display: flex;
  gap: 6px;
  align-items: center;
  margin-top: 2px;
  color: #15803d;
  font-size: 12px;
  font-weight: 700;
}

.chat-online-label__dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #16a34a;
}

.chat-delete-btn {
  flex: 0 0 auto;
  margin-inline-start: auto;
}

.chat-delete-dialog {
  width: min(430px, calc(100vw - 28px));
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 24px 64px rgba(17, 34, 45, 0.22);
}

.chat-delete-dialog__head {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 14px;
  align-items: start;
  padding: 22px 22px 14px;
}

.chat-delete-dialog__icon {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  border-radius: 50%;
  background: rgba(220, 38, 38, 0.1);
  color: #b91c1c;
}

.chat-delete-dialog h2 {
  margin: 0 0 5px;
  color: var(--soz-ink);
  font-size: 20px;
  line-height: 1.25;
}

.chat-delete-dialog p {
  margin: 0;
  color: rgba(17, 34, 45, 0.64);
  line-height: 1.45;
}

.chat-delete-dialog__choices {
  display: grid;
  gap: 10px;
  padding: 8px 22px 12px;
}

.chat-delete-dialog__choices .q-btn {
  min-height: 46px;
}

.chat-delete-option {
  display: inline-flex;
  gap: 9px;
  align-items: center;
  justify-content: center;
}

.chat-delete-option svg {
  width: 20px;
  height: 20px;
  flex: 0 0 auto;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}

.chat-delete-dialog__actions {
  padding: 4px 16px 14px;
}

.chat-back-btn {
  display: none;
}

.chat-messages {
  display: grid;
  align-content: start;
  gap: 10px;
  min-width: 0;
  min-height: 0;
  overflow-y: auto;
  padding: 16px;
}

.chat-empty {
  align-self: center;
  justify-self: center;
  color: rgba(17, 34, 45, 0.58);
}

.chat-load-older {
  display: flex;
  justify-content: center;
  padding: 2px 0 6px;
}

.chat-load-older .q-btn {
  color: var(--soz-primary-deep);
  font-weight: 700;
}

.chat-message {
  display: flex;
  min-width: 0;
  justify-content: flex-start;
}

.chat-message--own {
  justify-content: flex-end;
}

.chat-message__bubble {
  max-width: min(74%, 520px);
  min-width: 0;
  padding: 10px 12px;
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.96);
  box-shadow: 0 8px 18px rgba(17, 34, 45, 0.06);
  overflow-wrap: anywhere;
  white-space: pre-line;
  word-break: break-word;
}

.chat-message--own .chat-message__bubble {
  background: rgba(123, 63, 242, 0.16);
}

.chat-message__bubble span {
  display: block;
  margin-top: 4px;
  color: rgba(17, 34, 45, 0.48);
  font-size: 11px;
  text-align: end;
}

.chat-compose {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 10px;
  align-items: end;
  min-height: 0;
  padding: 12px;
  border-top: 1px solid rgba(17, 34, 45, 0.1);
}

.chat-composer {
  min-width: 0;
}

.chat-compose .chat-composer :deep(.q-field__control) {
  min-height: 54px;
  border-radius: 18px;
  padding-top: 0;
  padding-bottom: 0;
}

.chat-compose .chat-composer :deep(.q-field__native) {
  min-height: 52px;
  max-height: 96px;
  padding-top: 13px;
  padding-bottom: 13px;
  overflow-y: auto;
  resize: none;
}

.chat-composer--blocked :deep(textarea) {
  color: var(--soz-muted);
  font-weight: 700;
}

.chat-composer--danger :deep(textarea) {
  color: #b91c1c;
}

@media (max-width: 760px) {
  .chat-block {
    display: block;
    height: min(760px, calc(100dvh - 190px));
    min-height: 420px;
  }

  .chat-mobile-panels {
    background: transparent;
    height: 100%;
  }

  .chat-mobile-panel {
    height: 100%;
    padding: 0;
    overflow: hidden;
  }

  .chat-list,
  .chat-main {
    height: 100%;
    min-width: 0;
  }

  .chat-list {
    max-height: none;
    border-inline-end: 0;
    border-bottom: 0;
  }

  .chat-main__head {
    display: flex;
    padding: 10px 12px;
  }

	.chat-main__identity {
		flex: 1 1 auto;
	}

  .chat-back-btn {
    display: inline-flex;
  }
}

@media (max-width: 520px) {
  .chat-main__head,
  .chat-messages {
    padding: 12px;
  }

  .chat-message__bubble {
    max-width: 88%;
  }

  .chat-compose {
    gap: 8px;
    padding: 10px;
  }
}
</style>
