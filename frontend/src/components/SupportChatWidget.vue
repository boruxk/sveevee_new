<script setup>
	import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useChatsStore } from '@/stores/chats'
	import { fetchSupportChat, sendSupportChatMessage } from '@/services/api/chats'
	import { CHAT_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'

	const { locale, t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const chatsStore = useChatsStore()
	const panelOpen = ref(false)
	const loading = ref(false)
	const sending = ref(false)
	const conversation = ref(null)
	const draft = ref('')
	const messagesEl = ref(null)
	let refreshTimer = null

	const visible = computed(() => authStore.isAuthenticated && !authStore.isAdmin)
	const messages = computed(() => conversation.value?.messages || [])
	const composerHint = computed(() => characterLimitHint(draft.value, CHAT_MAX_LENGTH, t))
	const intlLocale = computed(() => ({
		he: 'he-IL',
		en: 'en-US',
		ru: 'ru-RU',
		fr: 'fr-FR'
	}[locale.value] || locale.value))

	function isOwn(message) {
		return message.sender_id === authStore.user?.id
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

	async function scrollToBottom() {
		await nextTick()

		if (messagesEl.value) {
			messagesEl.value.scrollTop = messagesEl.value.scrollHeight
		}
	}

	async function loadSupportChat({ silent = false } = {}) {
		if (!silent) {
			loading.value = true
		}

		try {
			const { data } = await fetchSupportChat()
			conversation.value = data.data
			await chatsStore.loadConversations()
			await scrollToBottom()
		} catch {
			if (!silent) {
				panelOpen.value = false
				$q.notify({ type: 'negative', message: t('chat.supportUnavailable') })
			}
		} finally {
			if (!silent) {
				loading.value = false
			}
		}
	}

	async function openPanel() {
		panelOpen.value = true
		await loadSupportChat()
	}

	function closePanel() {
		panelOpen.value = false
	}

	function stopRefreshTimer() {
		if (refreshTimer) {
			window.clearInterval(refreshTimer)
			refreshTimer = null
		}
	}

	function startRefreshTimer() {
		stopRefreshTimer()
		refreshTimer = window.setInterval(() => {
			if (panelOpen.value && !loading.value && !sending.value) {
				loadSupportChat({ silent: true })
			}
		}, 6000)
	}

	async function send() {
		const body = draft.value.trim()

		if (!body || sending.value) {
			return
		}

		sending.value = true

		try {
			const { data } = await sendSupportChatMessage(body)
			conversation.value = data.data
			draft.value = ''
			await chatsStore.loadConversations()
			await scrollToBottom()
		} catch {
			$q.notify({ type: 'negative', message: t('chat.sendFailed') })
		} finally {
			sending.value = false
		}
	}

	watch(visible, (isVisible) => {
		if (!isVisible) {
			panelOpen.value = false
			conversation.value = null
			draft.value = ''
			stopRefreshTimer()
		}
	})

	watch(panelOpen, (isOpen) => {
		if (isOpen) {
			startRefreshTimer()
		} else {
			stopRefreshTimer()
		}
	})

	onBeforeUnmount(stopRefreshTimer)
</script>

<template>
	<div v-if="visible" class="support-widget">
		<q-btn
			v-if="!panelOpen"
			round
			unelevated
			icon="support_agent"
			class="support-widget__trigger"
			:aria-label="t('chat.supportOpen')"
			@click="openPanel"
		>
			<q-tooltip>{{ t('chat.supportOpen') }}</q-tooltip>
		</q-btn>

		<q-card v-else class="support-widget__panel">
			<header class="support-widget__header">
				<div>
					<strong>{{ t('chat.supportTitle') }}</strong>
					<span>{{ t('chat.supportIntro') }}</span>
				</div>
				<q-btn
					flat
					round
					dense
					icon="close"
					:aria-label="t('chat.supportClose')"
					@click="closePanel"
				>
					<q-tooltip>{{ t('chat.supportClose') }}</q-tooltip>
				</q-btn>
			</header>

			<div ref="messagesEl" class="support-widget__messages">
				<q-inner-loading :showing="loading" color="primary" />
				<div v-if="!loading && messages.length === 0" class="support-widget__empty">{{ t('chat.noMessages') }}</div>
				<div
					v-for="message in messages"
					:key="message.id"
					class="support-widget__message"
					:class="{ 'support-widget__message--own': isOwn(message) }"
				>
					<div class="support-widget__bubble">
						{{ message.body }}
						<span>{{ formatMessageTime(message.created_at) }}</span>
					</div>
				</div>
			</div>

			<footer class="support-widget__compose">
				<q-input
					v-model="draft"
					outlined
					type="textarea"
					autogrow
					:placeholder="t('chat.placeholder')"
					:disable="loading || sending"
					:maxlength="CHAT_MAX_LENGTH"
					:hint="composerHint"
					counter
					persistent-hint
					class="support-widget__input"
					@keydown.enter.exact.prevent="send"
				/>
				<q-btn
					round
					unelevated
					color="primary"
					icon="send"
					:loading="sending"
					:disable="loading || !draft.trim()"
					@click="send"
				>
					<q-tooltip>{{ t('actions.send') }}</q-tooltip>
				</q-btn>
			</footer>
		</q-card>
	</div>
</template>

<style scoped lang="scss">
.support-widget {
  position: fixed;
  right: 22px;
  bottom: 22px;
  z-index: 3000;
}

.support-widget__trigger {
  width: 58px;
  height: 58px;
  background: var(--soz-action-gradient);
  color: #fff;
  box-shadow: 0 18px 34px rgba(245, 66, 145, 0.28);
}

.support-widget__panel {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  width: min(390px, calc(100vw - 24px));
  height: min(600px, calc(100dvh - 112px));
  overflow: hidden;
  border: 1px solid rgba(245, 66, 145, 0.24);
  border-radius: 24px;
  background: #fff;
  box-shadow: 0 24px 58px rgba(40, 22, 93, 0.2);
}

.support-widget__header {
  display: flex;
  gap: 12px;
  align-items: start;
  justify-content: space-between;
  padding: 16px 16px 14px;
  background:
    linear-gradient(135deg, rgba(255, 116, 38, 0.14), rgba(245, 66, 145, 0.12)),
    #fff;
  border-bottom: 1px solid rgba(245, 66, 145, 0.16);
}

.support-widget__header div {
  display: grid;
  gap: 4px;
  min-width: 0;
}

.support-widget__header strong {
  color: var(--soz-ink);
  font-size: 1.05rem;
  font-weight: 900;
}

.support-widget__header span {
  color: rgba(17, 34, 45, 0.62);
  font-size: 0.9rem;
  font-weight: 700;
}

.support-widget__messages {
  position: relative;
  display: grid;
  align-content: start;
  gap: 10px;
  min-width: 0;
  min-height: 0;
  overflow-y: auto;
  padding: 16px;
  background:
    radial-gradient(circle at top left, rgba(123, 63, 242, 0.08), transparent 30%),
    rgba(255, 248, 251, 0.56);
}

.support-widget__empty {
  align-self: center;
  justify-self: center;
  color: rgba(17, 34, 45, 0.54);
  font-weight: 700;
}

.support-widget__message {
  display: flex;
  min-width: 0;
  justify-content: flex-start;
}

.support-widget__message--own {
  justify-content: flex-end;
}

.support-widget__bubble {
  max-width: 82%;
  min-width: 0;
  padding: 10px 12px;
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.96);
  color: var(--soz-ink);
  box-shadow: 0 8px 18px rgba(17, 34, 45, 0.08);
  overflow-wrap: anywhere;
  white-space: pre-line;
  word-break: break-word;
}

.support-widget__message--own .support-widget__bubble {
  background: linear-gradient(135deg, rgba(255, 116, 38, 0.2), rgba(245, 66, 145, 0.2));
}

.support-widget__bubble span {
  display: block;
  margin-top: 4px;
  color: rgba(17, 34, 45, 0.5);
  font-size: 11px;
  text-align: end;
}

.support-widget__compose {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 10px;
  align-items: end;
  padding: 12px;
  border-top: 1px solid rgba(245, 66, 145, 0.16);
  background: #fff;
}

.support-widget__input {
  min-width: 0;
}

.support-widget__input :deep(.q-field__control) {
  min-height: 52px;
  border-radius: 18px;
}

.support-widget__input :deep(.q-field__native) {
  min-height: 50px;
  max-height: 108px;
  overflow-y: auto;
  resize: none;
}

@media (max-width: 700px) {
  .support-widget {
    right: 12px;
    bottom: 12px;
  }

  .support-widget__panel {
    width: calc(100vw - 24px);
    height: min(560px, calc(100dvh - 84px));
  }
}
</style>
