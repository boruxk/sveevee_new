<script setup>
	import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useChatsStore } from '@/stores/chats'
	import {
		claimGuestSupportChat,
		fetchGuestSupportChat,
		fetchSupportChat,
		guestSupportTokenStorageKey,
		sendGuestSupportMessage,
		sendSupportChatMessage,
		startGuestSupportChat
	} from '@/services/api/chats'
	import { CHAT_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'
	import { apiErrorMessage } from '@/utils/apiErrors'

	const { locale, t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const chatsStore = useChatsStore()
	const panelOpen = ref(false)
	const loading = ref(false)
	const sending = ref(false)
	const claiming = ref(false)
	const claimPrompt = ref(false)
	const claimDismissed = ref(false)
	const conversation = ref(null)
	const draft = ref('')
	const guestName = ref('')
	const guestEmail = ref('')
	const guestToken = ref(localStorage.getItem(guestSupportTokenStorageKey) || '')
	const messagesEl = ref(null)
	let refreshTimer = null

	const visible = computed(() => authStore.initialized && !authStore.isAdmin)
	const messages = computed(() => conversation.value?.messages || [])
	const isGuest = computed(() => !authStore.isAuthenticated)
	const needsGuestStart = computed(() => isGuest.value && !guestToken.value && !conversation.value)
	const composerHint = computed(() => characterLimitHint(draft.value, CHAT_MAX_LENGTH, t))
	const intlLocale = computed(() => ({
		he: 'he-IL',
		en: 'en-US',
		ru: 'ru-RU',
		fr: 'fr-FR'
	}[locale.value] || locale.value))

	function isOwn(message) {
		if (conversation.value?.is_guest) {
			return message.sender_type === 'guest'
		}

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

	function clearGuestToken() {
		guestToken.value = ''
		localStorage.removeItem(guestSupportTokenStorageKey)
	}

	async function loadSupportChat({ silent = false } = {}) {
		if (isGuest.value && !guestToken.value) {
			return
		}

		if (!silent) {
			loading.value = true
		}

		try {
			const response = isGuest.value ? await fetchGuestSupportChat(guestToken.value) : await fetchSupportChat()

			conversation.value = response.data.data

			if (authStore.isAuthenticated) {
				await chatsStore.loadConversations()
			}

			await scrollToBottom()
		} catch (error) {
			if (isGuest.value && [404, 410].includes(error.response?.status)) {
				clearGuestToken()
				conversation.value = null

				if (!silent) {
					$q.notify({ type: 'info', message: t('chat.guestSessionExpired') })
				}
				return
			}

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

		if (authStore.isAuthenticated && guestToken.value && !claimDismissed.value) {
			claimPrompt.value = true
			return
		}

		claimPrompt.value = false
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
			if (
				panelOpen.value &&
				!claimPrompt.value &&
				conversation.value &&
				document.visibilityState === 'visible' &&
				!loading.value &&
				!sending.value
			) {
				loadSupportChat({ silent: true })
			}
		}, 6000)
	}

	async function startGuestConversation() {
		const name = guestName.value.trim()
		const email = guestEmail.value.trim()
		const body = draft.value.trim()

		if (!name || !body || sending.value) {
			$q.notify({ type: 'warning', message: t('validation.requiredFields') })
			return
		}

		sending.value = true

		try {
			const { data } = await startGuestSupportChat({
				name,
				email: email || null,
				locale: locale.value,
				body
			})

			guestToken.value = data.data.token
			localStorage.setItem(guestSupportTokenStorageKey, guestToken.value)
			conversation.value = data.data.conversation
			draft.value = ''
			await scrollToBottom()
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('chat.sendFailed')) })
		} finally {
			sending.value = false
		}
	}

	async function send() {
		const body = draft.value.trim()

		if (!body || sending.value) {
			return
		}

		sending.value = true

		try {
			const response = conversation.value?.is_guest ? await sendGuestSupportMessage(guestToken.value, body) : await sendSupportChatMessage(body)

			conversation.value = response.data.data
			draft.value = ''

			if (authStore.isAuthenticated) {
				await chatsStore.loadConversations()
			}

			await scrollToBottom()
		} catch (error) {
			if (conversation.value?.is_guest && [404, 410].includes(error.response?.status)) {
				clearGuestToken()
				conversation.value = null
				$q.notify({ type: 'info', message: t('chat.guestSessionExpired') })
			} else {
				$q.notify({ type: 'negative', message: apiErrorMessage(error, t('chat.sendFailed')) })
			}
		} finally {
			sending.value = false
		}
	}

	async function connectGuestConversation() {
		if (!guestToken.value || claiming.value) {
			return
		}

		claiming.value = true

		try {
			const { data } = await claimGuestSupportChat(guestToken.value)
			conversation.value = data.data
			clearGuestToken()
			claimPrompt.value = false
			claimDismissed.value = false
			await chatsStore.loadConversations()
			await scrollToBottom()
			$q.notify({ type: 'positive', message: t('chat.claimSuccess') })
		} catch (error) {
			if ([404, 409, 410].includes(error.response?.status)) {
				clearGuestToken()
				claimPrompt.value = false
				await loadSupportChat()
			}

			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('chat.claimFailed')) })
		} finally {
			claiming.value = false
		}
	}

	async function dismissClaim() {
		claimDismissed.value = true
		claimPrompt.value = false
		await loadSupportChat()
	}

	watch(visible, (isVisible) => {
		if (!isVisible) {
			panelOpen.value = false
			conversation.value = null
			draft.value = ''
			claimPrompt.value = false
			stopRefreshTimer()
		}
	})

	watch(() => authStore.isAuthenticated, async(isAuthenticated, wasAuthenticated) => {
		if (isAuthenticated === wasAuthenticated) {
			return
		}

		conversation.value = null
		claimPrompt.value = false
		claimDismissed.value = false

		if (!isAuthenticated) {
			guestName.value = ''
			guestEmail.value = ''
		}

		if (!panelOpen.value) {
			return
		}

		if (isAuthenticated && guestToken.value) {
			claimPrompt.value = true
			return
		}

		await loadSupportChat()
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
			rounded
			unelevated
			icon="support_agent"
			:label="t('chat.humanSupport')"
			class="support-widget__trigger"
			:aria-label="t('chat.supportOpen')"
			@click="openPanel"
		/>

		<q-card v-else class="support-widget__panel">
			<header class="support-widget__header">
				<div>
					<strong>{{ t('chat.humanSupport') }}</strong>
					<span>{{ t('chat.supportIntro') }}</span>
				</div>
				<q-btn flat
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

				<div v-if="claimPrompt && !loading" class="support-widget__claim">
					<q-icon name="link" size="32px" color="primary" />
					<strong>{{ t('chat.claimTitle') }}</strong>
					<p>{{ t('chat.claimBody') }}</p>
					<div>
						<q-btn color="primary"
							unelevated
							rounded
							icon="link"
							:label="t('chat.claimConfirm')"
							:loading="claiming"
							@click="connectGuestConversation"
						/>
						<q-btn flat rounded :label="t('chat.claimLater')" :disable="claiming" @click="dismissClaim" />
					</div>
				</div>

				<template v-else-if="!loading">
					<div v-if="messages.length === 0" class="support-widget__welcome">
						<strong>{{ t('chat.supportWelcome') }}</strong>
						<p>{{ t('chat.supportHumanReply') }}</p>
					</div>

					<div v-for="message in messages" :key="message.id" class="support-widget__message" :class="{ 'support-widget__message--own': isOwn(message) }">
						<div class="support-widget__bubble">
							{{ message.body }}
							<span>{{ formatMessageTime(message.created_at) }}</span>
						</div>
					</div>
				</template>
			</div>

			<footer v-if="needsGuestStart && !claimPrompt" class="support-widget__guest-form">
				<div class="support-widget__identity">
					<q-input v-model="guestName"
						outlined
						dense
						:label="t('chat.guestName')"
						maxlength="100"
						:disable="sending"
					/>
					<q-input v-model="guestEmail"
						outlined
						dense
						type="email"
						:label="t('chat.guestEmailOptional')"
						maxlength="254"
						:disable="sending"
					/>
				</div>
				<div class="support-widget__compose support-widget__compose--guest">
					<q-input
						v-model="draft"
						outlined
						type="textarea"
						autogrow
						:placeholder="t('chat.placeholder')"
						:disable="sending"
						:maxlength="CHAT_MAX_LENGTH"
						:hint="composerHint"
						counter
						persistent-hint
						class="support-widget__input"
						@keydown.enter.exact.prevent="startGuestConversation"
					/>
					<q-btn round
						unelevated
						color="primary"
						icon="send"
						:loading="sending"
						:disable="!guestName.trim() || !draft.trim()"
						@click="startGuestConversation"
					>
						<q-tooltip>{{ t('chat.startSupport') }}</q-tooltip>
					</q-btn>
				</div>
				<p class="support-widget__privacy">
					{{ t('chat.guestBrowserNote') }}
					<router-link :to="{ name: 'privacy' }" target="_blank">{{ t('footer.privacy') }}</router-link>
				</p>
			</footer>

			<footer v-else-if="conversation && !claimPrompt" class="support-widget__compose">
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
				<q-btn round
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
  min-height: 56px;
  padding-inline: 22px;
  background: linear-gradient(135deg, #7b3ff2 0%, #5f28c6 100%);
  color: #fff;
  font-weight: 800;
  box-shadow: 0 12px 24px rgba(66, 20, 143, 0.2);
}

.support-widget__panel {
  display: grid;
  grid-template-rows: auto minmax(0, 1fr) auto;
  width: min(410px, calc(100vw - 24px));
  height: min(640px, calc(100dvh - 100px));
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
  background: linear-gradient(135deg, rgba(255, 116, 38, 0.14), rgba(245, 66, 145, 0.12)), #fff;
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
  background: radial-gradient(circle at top left, rgba(123, 63, 242, 0.08), transparent 30%), rgba(255, 248, 251, 0.56);
}

.support-widget__welcome {
  display: grid;
  gap: 6px;
  justify-self: start;
  max-width: 88%;
  padding: 14px 16px;
  border-radius: 18px 18px 18px 6px;
  background: #fff;
  color: var(--soz-ink);
  box-shadow: 0 8px 20px rgba(17, 34, 45, 0.08);
}

.support-widget__welcome strong {
  font-size: 1.05rem;
  font-weight: 900;
}

.support-widget__welcome p,
.support-widget__claim p,
.support-widget__privacy {
  margin: 0;
  color: rgba(17, 34, 45, 0.64);
  line-height: 1.5;
}

.support-widget__claim {
  display: grid;
  gap: 12px;
  align-self: center;
  justify-self: center;
  max-width: 330px;
  padding: 22px;
  border: 1px solid rgba(123, 63, 242, 0.18);
  border-radius: 20px;
  background: #fff;
  text-align: center;
  box-shadow: 0 14px 30px rgba(40, 22, 93, 0.1);
}

.support-widget__claim > div {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  justify-content: center;
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

.support-widget__guest-form {
  display: grid;
  gap: 10px;
  padding: 12px;
  border-top: 1px solid rgba(245, 66, 145, 0.16);
  background: #fff;
}

.support-widget__identity {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.support-widget__compose--guest {
  padding: 0;
  border: 0;
}

.support-widget__privacy {
  font-size: 0.76rem;
  text-align: center;
}

.support-widget__privacy a {
  color: var(--soz-primary-deep);
  font-weight: 800;
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

  .support-widget__trigger {
    min-height: 48px;
    padding-inline: 16px;
    font-size: 0.82rem;
  }

  .support-widget__panel {
    width: calc(100vw - 24px);
    height: min(600px, calc(100dvh - 72px));
  }

  .support-widget__identity {
    grid-template-columns: 1fr;
  }
}
</style>
