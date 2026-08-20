<script setup>
	import { computed, nextTick, onMounted, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useChatsStore } from '@/stores/chats'
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

	const conversations = computed(() => chatsStore.conversations)
	const active = computed(() => chatsStore.activeConversation)
	const messages = computed(() => chatsStore.activeMessages)
	const threadPanelName = computed(() => (active.value?.id ? `thread-${active.value.id}` : 'thread-empty'))
	const isMobileChat = computed(() => $q.screen.width <= 760)
	const mobilePanel = computed({
		get: () => (mobileThreadOpen.value ? 'thread' : 'list'),
		set: (value) => {
			mobileThreadOpen.value = value === 'thread'
		}
	})
	const showMobileBack = computed(() => isMobileChat.value && !props.compact && mobileThreadOpen.value)
	const composerState = computed(() => chatsStore.composerState)
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

	const chatLimitMessageKeys = {
		pending_reply: 'chat.pendingReply',
		daily_limit: 'chat.dailyLimit'
	}
	const intlLocale = computed(() => ({
		he: 'he-IL',
		en: 'en-US',
		ru: 'ru-RU',
		fr: 'fr-FR'
	}[locale.value] || locale.value))

	function localizedChatLimit(reason, fallback = null) {
		const key = chatLimitMessageKeys[reason]

		return key ? t(key) : fallback
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

	async function openConversation(id) {
		await chatsStore.openConversation(id)
		if (isMobileChat.value) {
			mobileThreadOpen.value = true
		}
		await scrollToBottom()
	}

	async function load() {
		if (props.targetUserId) {
			await chatsStore.openWithUser(props.targetUserId)
			mobileThreadOpen.value = true
		} else {
			await chatsStore.loadConversations()
			if (isMobileChat.value && !props.compact) {
				mobileThreadOpen.value = false
			} else if (!active.value && conversations.value[0]) {
				await chatsStore.openConversation(conversations.value[0].id)
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
			await chatsStore.send(body, props.targetUserId)
			draft.value = ''
			await scrollToBottom()
		} catch (error) {
			const reason = error.response?.data?.errors?.reason
			$q.notify({ type: 'negative', message: localizedChatLimit(reason, error.response?.data?.message) || t('chat.sendFailed') })
		}
	}

	onMounted(load)
	watch(() => props.targetUserId, load)
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
			await chatsStore.openConversation(conversations.value[0].id)
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
						:key="conversation.id"
						type="button"
						class="chat-list__item"
						:class="{ 'chat-list__item--active': active?.id === conversation.id }"
						@click="openConversation(conversation.id)"
					>
						<q-avatar size="40px" color="primary" text-color="white">
							<img v-if="conversation.other_user?.profile?.photo_url" :src="conversation.other_user.profile.photo_url" alt="" />
							<span v-else>{{ conversation.other_user?.display_name?.slice(0, 1) || 'S' }}</span>
						</q-avatar>
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
							<div>
								<div class="text-h6">{{ active?.other_user?.display_name || t('chat.empty') }}</div>
							</div>
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
								:disable="!active || chatsStore.sending"
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
								:loading="chatsStore.sending"
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
					:key="conversation.id"
					type="button"
					class="chat-list__item"
					:class="{ 'chat-list__item--active': active?.id === conversation.id }"
					@click="openConversation(conversation.id)"
				>
					<q-avatar size="40px" color="primary" text-color="white">
						<img v-if="conversation.other_user?.profile?.photo_url" :src="conversation.other_user.profile.photo_url" alt="" />
						<span v-else>{{ conversation.other_user?.display_name?.slice(0, 1) || 'S' }}</span>
					</q-avatar>
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
							<div>
								<div class="text-h6">{{ active?.other_user?.display_name || t('chat.empty') }}</div>
							</div>
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
								:disable="!active || chatsStore.sending"
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
								:loading="chatsStore.sending"
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

.chat-list :deep(.q-avatar__content img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
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
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    padding: 10px 12px;
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
