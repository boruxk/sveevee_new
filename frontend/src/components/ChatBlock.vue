<script setup>
	import { computed, nextTick, onMounted, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useChatsStore } from '@/stores/chats'
	import { CHAT_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'

	const props = defineProps({
		targetUserId: {
			type: [Number, String],
			default: null
		},
		compact: {
			type: Boolean,
			default: false
		}
	})

	const { locale, t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const chatsStore = useChatsStore()
	const draft = ref('')
	const messagesEl = ref(null)

	const conversations = computed(() => chatsStore.conversations)
	const active = computed(() => chatsStore.activeConversation)
	const messages = computed(() => chatsStore.activeMessages)
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

	async function scrollToBottom() {
		await nextTick()
		if (messagesEl.value) {
			messagesEl.value.scrollTop = messagesEl.value.scrollHeight
		}
	}

	async function openConversation(id) {
		await chatsStore.openConversation(id)
		await scrollToBottom()
	}

	async function load() {
		if (props.targetUserId) {
			await chatsStore.openWithUser(props.targetUserId)
		} else {
			await chatsStore.loadConversations()
			if (!active.value && conversations.value[0]) {
				await chatsStore.openConversation(conversations.value[0].id)
			}
		}

		await scrollToBottom()
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
</script>

<template>
	<section class="chat-block" :class="{ 'chat-block--compact': compact }">
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
			<header class="chat-main__head">
				<div>
					<div class="text-h6">{{ active?.other_user?.display_name || t('chat.empty') }}</div>
				</div>
			</header>

			<div ref="messagesEl" class="chat-messages">
				<div v-if="!active" class="chat-empty">{{ t('chat.empty') }}</div>
				<div v-else-if="messages.length === 0" class="chat-empty">{{ t('chat.noMessages') }}</div>
				<template v-else>
					<div
						v-for="message in messages"
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
	</section>
</template>

<style scoped lang="scss">
.chat-block {
  display: grid;
  grid-template-columns: 280px minmax(0, 1fr);
  height: 350px;
  min-height: 350px;
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
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  min-height: 0;
  min-width: 0;
  overflow: hidden;
}

.chat-main__head {
  padding: 14px 16px;
  border-bottom: 1px solid rgba(17, 34, 45, 0.1);
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
    grid-template-columns: 1fr;
    height: min(560px, calc(100dvh - 180px));
    min-height: 420px;
  }

  .chat-list {
    max-height: 150px;
    border-inline-end: 0;
    border-bottom: 1px solid rgba(17, 34, 45, 0.1);
  }
}

@media (max-width: 520px) {
  .chat-block {
    height: 520px;
  }

  .chat-list {
    max-height: 132px;
  }

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
