<script setup>
	import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
	import { useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { createGuestPageChat, fetchGuestPageChat, sendGuestPageChatMessage } from '@/services/api/guestPageChats'
	import { readGuestPageChatToken, storeGuestPageChatToken, removeGuestPageChatToken, rememberGuestPageChatClaim } from '@/utils/guestPageChatSession'
	import { pendingGuestPageChatStart } from '@/utils/guestPageChatLifecycle'
	import { clearLeadsPage001Registration } from '@/utils/leadsPageCompletion'
	import { CHAT_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { containsGuestChatLink } from '@/utils/guestChatLinks'
	import { isGuestChatLinkError } from '@/utils/guestChatPolicy'
	import ChatMessageBody from '@/components/ChatMessageBody.vue'
	import ChatMessageMeta from '@/components/ChatMessageMeta.vue'

	const props = defineProps({
		pageId: { type: [Number, String], required: true },
		returnTo: { type: String, required: true }
	})
	const emit = defineEmits(['presence'])
	const { t, locale } = useI18n()
	const $q = useQuasar()
	const router = useRouter()
	const token = ref(readGuestPageChatToken(props.pageId))
	const conversation = ref(null)
	const draft = ref('')
	const rejectedLinkDraft = ref(null)
	const loading = ref(Boolean(pendingGuestPageChatStart(props.pageId)))
	const sending = ref(false)
	const loadError = ref(false)
	const unavailable = ref(false)
	const messagesEl = ref(null)
	let disposed = false
	let refreshing = false
	let refreshTimer = null

	watch(() => conversation.value?.other_user?.presence, (presence) => {
		emit('presence', presence || null)
	}, { immediate: true })

	const messages = computed(() => conversation.value?.messages || [])
	const composerBlocked = computed(() => unavailable.value || loadError.value || conversation.value?.composer_state?.can_send === false)
	const composerMessage = computed(() => {
		if (unavailable.value) return t('chat.guestPageUnavailable')
		if (conversation.value?.composer_state?.reason === 'page_pending_reply') return t('chat.pagePendingReply')
		return t('chat.placeholder')
	})
	const draftContainsLink = computed(() => containsGuestChatLink(draft.value) || rejectedLinkDraft.value === draft.value)
	const composerHint = computed(() => `${t('chat.guestLinksHint')} ${characterLimitHint(draft.value, CHAT_MAX_LENGTH, t)}`)

	async function scrollToBottom() {
		await nextTick()
		if (!disposed && messagesEl.value) messagesEl.value.scrollTop = messagesEl.value.scrollHeight
	}

	async function applyConversation(value, forceScroll = false) {
		const el = messagesEl.value
		const nearBottom = !el || el.scrollHeight - el.scrollTop - el.clientHeight < 64
		const previousLastId = messages.value.at(-1)?.id
		conversation.value = value
		if (forceScroll || (nearBottom && previousLastId !== messages.value.at(-1)?.id)) await scrollToBottom()
	}

	function resetExpiredSession() {
		removeGuestPageChatToken(props.pageId)
		token.value = ''
		conversation.value = null
		$q.notify({ type: 'info', message: t('chat.guestPageSessionExpired') })
	}

	async function loadChat({ silent = false } = {}) {
		if (!token.value || refreshing || sending.value || disposed) return
		const previousConversation = conversation.value
		const requestedToken = token.value
		refreshing = true
		if (!silent) loading.value = true
		try {
			const { data } = await fetchGuestPageChat(props.pageId, token.value, { markRead: document.visibilityState === 'visible' })
			if (disposed || conversation.value !== previousConversation || token.value !== requestedToken) return
			loadError.value = false
			unavailable.value = false
			await applyConversation(data.data, !silent)
		} catch (error) {
			if (disposed || conversation.value !== previousConversation || token.value !== requestedToken) return
			if ([404, 410].includes(error.response?.status)) {
				resetExpiredSession()
			} else if (error.response?.status === 409) {
				unavailable.value = true
			} else if (!silent) {
				loadError.value = true
			}
		} finally {
			refreshing = false
			loading.value = false
		}
	}

	async function send() {
		const body = draft.value.trim()
		if (!body || sending.value || loading.value || composerBlocked.value) return
		if (draftContainsLink.value) {
			$q.notify({ type: 'warning', message: t('chat.guestLinksNotAllowed') })
			return
		}
		sending.value = true
		try {
			token.value = token.value || readGuestPageChatToken(props.pageId)
			let nextConversation
			if (token.value) {
				const { data } = await sendGuestPageChatMessage(props.pageId, token.value, { body })
				nextConversation = data.data
			} else {
				const { response: { data }, messageSent } = await createGuestPageChat(props.pageId, { body, locale: locale.value })
				// Keep the access token even if the popup closed while the first message was sent.
				const stored = storeGuestPageChatToken(props.pageId, data.data.token)
				token.value = data.data.token
				nextConversation = data.data.conversation
				if (!stored && !disposed) $q.notify({ type: 'warning', message: t('chat.guestPageStorageUnavailable') })
				if (!messageSent) {
					// Another popup sent the first message; this popup's draft has not been sent.
					if (!disposed) await applyConversation(nextConversation, true)
					return
				}
			}
			if (disposed) return
			draft.value = ''
			await applyConversation(nextConversation, true)
		} catch (error) {
			if (disposed) return
			if (isGuestChatLinkError(error)) {
				rejectedLinkDraft.value = draft.value
				$q.notify({ type: 'warning', message: t('chat.guestLinksNotAllowed') })
				return
			}
			const reason = error.response?.data?.errors?.reason
			if (token.value && [404, 410].includes(error.response?.status)) {
				resetExpiredSession()
			} else {
				if (reason === 'page_pending_reply' && conversation.value) {
					conversation.value = { ...conversation.value, composer_state: { can_send: false, reason } }
				} else if (error.response?.status === 409) {
					unavailable.value = true
				}
				$q.notify({
					type: 'negative',
					message: reason === 'page_pending_reply' ? t('chat.pagePendingReply') : apiErrorMessage(error, t('chat.sendFailed'))
				})
			}
		} finally {
			sending.value = false
		}
	}

	async function registerToKeepChat() {
		if (sending.value || loading.value) return
		token.value = token.value || readGuestPageChatToken(props.pageId)
		if (token.value && !storeGuestPageChatToken(props.pageId, token.value)) {
			$q.notify({ type: 'negative', message: t('chat.guestPageStorageUnavailable') })
			return
		}
		const intent = rememberGuestPageChatClaim({ pageId: props.pageId, redirect: props.returnTo })
		if (!intent) {
			$q.notify({ type: 'negative', message: t('chat.guestPageStorageUnavailable') })
			return
		}
		clearLeadsPage001Registration()
		await router.push({ name: 'register', query: { redirect: intent.redirect } })
	}

	function refreshVisibleChat() {
		if (document.visibilityState === 'visible' && !unavailable.value) loadChat({ silent: true })
	}

	onMounted(async() => {
		const pendingStart = pendingGuestPageChatStart(props.pageId)
		if (pendingStart) {
			loading.value = true
			try {
				const { data } = await pendingStart
				if (disposed) return
				token.value = data.data.token
				await applyConversation(data.data.conversation, true)
			} catch (error) {
				if (!disposed) $q.notify({ type: 'negative', message: isGuestChatLinkError(error) ? t('chat.guestLinksNotAllowed') : apiErrorMessage(error, t('chat.sendFailed')) })
			} finally {
				loading.value = false
			}
		}
		if (disposed) return
		loading.value = false
		token.value = token.value || readGuestPageChatToken(props.pageId)
		await loadChat()
		await scrollToBottom()
		if (disposed) return
		refreshTimer = window.setInterval(refreshVisibleChat, 6000)
		document.addEventListener('visibilitychange', refreshVisibleChat)
		window.addEventListener('focus', refreshVisibleChat)
	})
	onBeforeUnmount(() => {
		disposed = true
		document.removeEventListener('visibilitychange', refreshVisibleChat)
		window.removeEventListener('focus', refreshVisibleChat)
		if (refreshTimer) window.clearInterval(refreshTimer)
	})
</script>

<template>
	<section class="guest-page-chat" :aria-label="t('chat.title')">
		<div ref="messagesEl" class="guest-page-chat__messages" role="log" aria-live="polite" aria-relevant="additions">
			<div class="guest-page-chat__timeline">
				<section class="guest-page-chat__registration" data-testid="guest-chat-registration">
					<strong>{{ t('chat.guestKeepTitle') }}</strong>
					<p>{{ t('chat.guestKeepBody') }}</p>
					<q-btn
						unelevated
						no-caps
						color="primary"
						:label="t('chat.guestKeepAction')"
						:disable="sending || loading"
						@click="registerToKeepChat"
					/>
				</section>
				<div v-if="loading" class="guest-page-chat__status"><q-spinner color="primary" size="24px" /></div>
				<div v-if="loadError" class="guest-page-chat__status" role="alert">
					<p>{{ t('chat.guestPageLoadFailed') }}</p>
					<q-btn flat color="primary" :label="t('chat.guestPageClaimRetry')" @click="loadChat()" />
				</div>
				<div
					v-for="message in messages"
					:key="message.id"
					class="guest-page-chat__message"
					:class="{ 'guest-page-chat__message--own': !message.sender_as_page }"
				>
					<div class="guest-page-chat__bubble">
						<ChatMessageBody :body="message.body" />
						<ChatMessageMeta :created-at="message.created_at" :read-at="message.read_at" :own="!message.sender_as_page" />
					</div>
				</div>
			</div>
		</div>
		<form class="guest-page-chat__compose" @submit.prevent="send">
			<q-input
				v-model="draft"
				outlined
				type="textarea"
				autogrow
				:readonly="composerBlocked"
				:disable="loading || sending"
				:placeholder="composerMessage"
				:aria-label="t('chat.placeholder')"
				:maxlength="CHAT_MAX_LENGTH"
				:hint="composerBlocked ? composerMessage : composerHint"
				:error="draftContainsLink"
				:error-message="t('chat.guestLinksNotAllowed')"
				persistent-hint
				@keydown.enter.exact.prevent="send"
			/>
			<q-btn
				round
				unelevated
				color="primary"
				icon="send"
				type="submit"
				:aria-label="t('actions.send')"
				:loading="sending"
				:disable="loading || composerBlocked || draftContainsLink || !draft.trim()"
			/>
		</form>
	</section>
</template>

<style scoped lang="scss">
.guest-page-chat {
  display: grid;
  grid-template-rows: minmax(0, 1fr) auto;
  min-width: 0;
  min-height: 0;
  height: 100%;
}

.guest-page-chat__messages {
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
}

.guest-page-chat__timeline {
  display: flex;
  flex-direction: column;
  gap: 12px;
  min-height: 100%;
  padding: 16px;
}

.guest-page-chat__registration {
  flex: 0 0 auto;
  margin-block-start: auto;
  padding: 16px;
  border: 1px solid rgba(123, 63, 242, 0.16);
  border-radius: 16px;
  background: rgba(123, 63, 242, 0.07);
  color: var(--soz-ink);
}

.guest-page-chat__registration strong {
  font-size: 1rem;
}

.guest-page-chat__registration p {
  margin: 6px 0 12px;
  line-height: 1.55;
}

.guest-page-chat__status {
  text-align: center;
  color: var(--soz-muted);
}

.guest-page-chat__message {
  display: flex;
  flex: 0 0 auto;
  justify-content: flex-start;
  min-width: 0;
}

.guest-page-chat__message--own {
  justify-content: flex-end;
}

.guest-page-chat__bubble {
  max-width: 85%;
  padding: 10px 12px;
  border-radius: 10px;
  background: #fff;
  box-shadow: 0 4px 16px rgba(17, 34, 45, 0.06);
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}

.guest-page-chat__message--own .guest-page-chat__bubble {
  background: rgba(123, 63, 242, 0.16);
}

.guest-page-chat__compose {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: start;
  gap: 10px;
  padding: 12px;
  border-top: 1px solid rgba(17, 34, 45, 0.1);
}

.guest-page-chat__compose > .q-btn {
  margin-top: 6px;
}

.guest-page-chat__compose :deep(textarea) {
  max-height: 96px;
  overflow-y: auto;
}

@media (max-width: 520px) {
  .guest-page-chat__timeline,
  .guest-page-chat__compose {
    padding: 10px;
  }

  .guest-page-chat__registration {
    padding: 12px;
  }
}
</style>
