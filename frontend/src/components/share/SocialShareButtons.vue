<script setup>
	import { computed, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { qrSvg } from '@/utils/qrCode'

	const props = defineProps({
		url: {
			type: String,
			required: true
		},
		title: {
			type: String,
			default: 'Sveevee'
		}
	})

	const { t } = useI18n()
	const $q = useQuasar()
	const qrOpen = ref(false)
	const shareText = computed(() => [props.title, props.url].filter(Boolean).join(' '))
	const whatsappUrl = computed(() => `https://wa.me/?text=${encodeURIComponent(shareText.value)}`)
	const facebookUrl = computed(() => `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(props.url)}`)
	const xUrl = computed(() => `https://x.com/intent/post?text=${encodeURIComponent(props.title)}&url=${encodeURIComponent(props.url)}`)
	const telegramUrl = computed(() => `https://t.me/share/url?url=${encodeURIComponent(props.url)}&text=${encodeURIComponent(props.title)}`)
	const qrCode = computed(() => qrSvg(props.url))

	function openUrl(url) {
		window.open(url, '_blank', 'noopener,noreferrer')
	}

	function fallbackCopy(value) {
		const input = document.createElement('textarea')
		input.value = value
		input.setAttribute('readonly', '')
		input.style.position = 'fixed'
		input.style.opacity = '0'
		document.body.appendChild(input)
		input.select()

		let copied = false
		try {
			copied = document.execCommand('copy')
		} finally {
			document.body.removeChild(input)
		}

		return copied
	}

	async function copyLink({ notify = true } = {}) {
		let copied = false

		try {
			if (window.isSecureContext && navigator.clipboard?.writeText) {
				await navigator.clipboard.writeText(props.url)
				copied = true
			}
		} catch {
			copied = false
		}

		if (!copied) {
			copied = fallbackCopy(props.url)
		}

		if (notify) {
			$q.notify({ type: copied ? 'positive' : 'negative', message: t(copied ? 'share.copied' : 'share.copyFailed') })
		}

		return copied
	}

	async function openCopyPlatform(url, messageKey) {
		if (await copyLink({ notify: false })) {
			openUrl(url)
			$q.notify({ type: 'positive', message: t(messageKey) })
		}
	}
</script>

<template>
	<div class="social-share-actions">
		<button type="button" class="social-share-button" aria-label="WhatsApp" @click="openUrl(whatsappUrl)">
			<svg class="social-share-icon social-share-icon--whatsapp" viewBox="0 0 24 24" aria-hidden="true">
				<path class="social-share-icon__brand-bg" d="M12 3.2a8.8 8.8 0 0 0-7.5 13.4l-.9 3.3 3.4-.9A8.8 8.8 0 1 0 12 3.2Z" />
				<path class="social-share-icon__brand-line" d="M12 5.1a6.9 6.9 0 0 1 5.9 10.5 6.9 6.9 0 0 1-8.1 2.7l-.4-.2-2 .5.5-1.9-.3-.4A6.9 6.9 0 0 1 12 5.1Z" />
				<path class="social-share-icon__brand-phone" d="M9.6 8.2c-.2 0-.4 0-.6.4-.2.3-.7.9-.7 1.7s.7 1.7.8 1.9c.1.1 1.4 2.2 3.4 3 .5.2.9.3 1.2.4.5.2 1 .1 1.3.1.4-.1 1.1-.5 1.2-.9.2-.4.2-.8.1-.9l-.5-.3-1.3-.6c-.2-.1-.4-.1-.6.1l-.6.7c-.2.2-.3.2-.6.1a5.6 5.6 0 0 1-2.8-2.5c-.1-.3 0-.4.1-.5l.4-.5c.1-.1.1-.3.2-.4 0-.1 0-.3-.1-.4l-.6-1.4c-.1-.3-.3-.3-.5-.3h-.3Z" />
			</svg>
			<q-tooltip>WhatsApp</q-tooltip>
		</button>

		<button type="button" class="social-share-button" aria-label="Facebook" @click="openUrl(facebookUrl)">
			<svg class="social-share-icon social-share-icon--facebook" viewBox="0 0 24 24" aria-hidden="true">
				<circle cx="12" cy="12" r="10" />
				<path d="M13.3 20v-7h2.3l.4-2.7h-2.7V8.6c0-.8.2-1.3 1.3-1.3H16V5c-.2 0-1.1-.1-2-.1-2.1 0-3.6 1.3-3.6 3.6v1.9H8V13h2.4v7h2.9Z" />
			</svg>
			<q-tooltip>Facebook</q-tooltip>
		</button>

		<button type="button" class="social-share-button" aria-label="Instagram" @click="openCopyPlatform('https://www.instagram.com/', 'share.instagramCopied')">
			<svg class="social-share-icon social-share-icon--instagram" viewBox="0 0 24 24" aria-hidden="true">
				<defs>
					<linearGradient id="homeInstagramGradient"
						x1="3"
						x2="21"
						y1="21"
						y2="3"
						gradientUnits="userSpaceOnUse"
					>
						<stop offset="0" stop-color="#feda75" />
						<stop offset=".35" stop-color="#fa7e1e" />
						<stop offset=".65" stop-color="#d62976" />
						<stop offset="1" stop-color="#4f5bd5" />
					</linearGradient>
				</defs>
				<rect x="3" y="3" width="18" height="18" rx="5" />
				<circle cx="12" cy="12" r="4" />
				<circle cx="17" cy="7" r="1.2" />
			</svg>
			<q-tooltip>Instagram</q-tooltip>
		</button>

		<button type="button" class="social-share-button" aria-label="TikTok" @click="openCopyPlatform('https://www.tiktok.com/', 'share.tiktokCopied')">
			<svg class="social-share-icon social-share-icon--tiktok" viewBox="0 0 24 24" aria-hidden="true">
				<path class="social-share-icon__shadow-a" d="M15.1 3.5c.4 2.5 1.8 4 4.2 4.3v3.1c-1.5 0-2.9-.5-4.2-1.3v5.4a5.4 5.4 0 1 1-5.4-5.4c.3 0 .7 0 1 .1v3.3a2.2 2.2 0 1 0 1.4 2V3.5h3Z" />
				<path class="social-share-icon__shadow-b" d="M14.1 2.6c.4 2.5 1.8 4 4.2 4.3V10c-1.5 0-2.9-.5-4.2-1.3v5.4a5.4 5.4 0 1 1-5.4-5.4c.3 0 .7 0 1 .1v3.3a2.2 2.2 0 1 0 1.4 2V2.6h3Z" />
				<path d="M14.6 3c.4 2.5 1.8 4 4.2 4.3v3.1c-1.5 0-2.9-.5-4.2-1.3v5.4a5.4 5.4 0 1 1-5.4-5.4c.3 0 .7 0 1 .1v3.3a2.2 2.2 0 1 0 1.4 2V3h3Z" />
			</svg>
			<q-tooltip>TikTok</q-tooltip>
		</button>

		<button type="button" class="social-share-button" :aria-label="t('pages.socials.x')" @click="openUrl(xUrl)">
			<svg class="social-share-icon social-share-icon--x" viewBox="0 0 24 24" aria-hidden="true">
				<path d="M18.24 2.25h3.31l-7.23 8.26 8.51 11.24h-6.66l-5.21-6.82-5.97 6.82H1.68l7.73-8.84L1.25 2.25h6.83l4.71 6.23 5.45-6.23Zm-1.16 17.52h1.83L7.08 4.13H5.12l11.96 15.64Z" />
			</svg>
			<q-tooltip>{{ t('pages.socials.x') }}</q-tooltip>
		</button>

		<button type="button" class="social-share-button" aria-label="Telegram" @click="openUrl(telegramUrl)">
			<svg class="social-share-icon social-share-icon--telegram" viewBox="0 0 24 24" aria-hidden="true">
				<circle cx="12" cy="12" r="10" />
				<path d="m6.2 11.7 10.6-4.1c.5-.2.9.1.7.8l-1.8 8.7c-.1.6-.5.7-1 .4l-2.8-2-1.3 1.3c-.2.2-.3.3-.6.3l.2-2.9 5.3-4.8c.2-.2 0-.4-.3-.2l-6.5 4.1-2.8-.9c-.6-.2-.6-.6.3-.7Z" />
			</svg>
			<q-tooltip>Telegram</q-tooltip>
		</button>

		<button type="button" class="social-share-button" :aria-label="t('share.copyLink')" @click="copyLink()">
			<svg class="social-share-icon social-share-icon--copy" viewBox="0 0 24 24" aria-hidden="true">
				<rect x="8" y="8" width="11" height="11" rx="2" />
				<path d="M5 15V6a1 1 0 0 1 1-1h9" />
			</svg>
			<q-tooltip>{{ t('share.copyLink') }}</q-tooltip>
		</button>

		<button type="button" class="social-share-button" :aria-label="t('share.qrCode')" @click="qrOpen = !qrOpen">
			<svg class="social-share-icon social-share-icon--qr" viewBox="0 0 24 24" aria-hidden="true">
				<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4z" />
				<path d="M14 14h2v2h-2zM18 14h2v6h-2zM14 18h2v2h-2z" />
			</svg>
			<q-tooltip>{{ t('share.qrCode') }}</q-tooltip>
		</button>

		<div v-if="qrOpen" class="social-share-qr">
			<div v-html="qrCode" />
			<small>{{ url }}</small>
		</div>
	</div>
</template>

<style scoped lang="scss">
.social-share-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.social-share-button {
  display: grid;
  place-items: center;
  width: 54px;
  height: 54px;
  padding: 0;
  border: 1px solid rgba(17, 34, 45, 0.09);
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.86);
  cursor: pointer;
  box-shadow: 0 10px 22px rgba(17, 34, 45, 0.06);
  transition: transform 0.18s ease, border-color 0.18s ease, background 0.18s ease;
}

.social-share-button:hover {
  border-color: rgba(245, 66, 145, 0.28);
  background: #ffffff;
  transform: translateY(-2px);
}

.social-share-icon {
  display: block;
  width: 29px;
  height: 29px;
}

.social-share-icon--whatsapp .social-share-icon__brand-bg { fill: #25d366; }
.social-share-icon--whatsapp .social-share-icon__brand-line { fill: none; stroke: #ffffff; stroke-width: 1.1; }
.social-share-icon--whatsapp .social-share-icon__brand-phone { fill: #ffffff; }
.social-share-icon--facebook circle { fill: #1877f2; }
.social-share-icon--facebook path { fill: #ffffff; }
.social-share-icon--instagram rect { fill: url("#homeInstagramGradient"); }
.social-share-icon--instagram circle { fill: none; stroke: #ffffff; stroke-width: 1.7; }
.social-share-icon--tiktok .social-share-icon__shadow-a { fill: #25f4ee; }
.social-share-icon--tiktok .social-share-icon__shadow-b { fill: #fe2c55; }
.social-share-icon--tiktok > path:last-child { fill: #111111; }
.social-share-icon--x path { fill: #111111; }
.social-share-icon--telegram circle { fill: #229ed9; }
.social-share-icon--telegram path { fill: #ffffff; }
.social-share-icon--copy rect,
.social-share-icon--copy path { fill: none; stroke: #6366f1; stroke-linejoin: round; stroke-width: 1.9; }
.social-share-icon--qr path { fill: #151f2d; }

.social-share-qr {
  display: grid;
  flex-basis: 100%;
  gap: 7px;
  justify-items: start;
  padding-top: 8px;
}

.social-share-qr > div {
  width: 150px;
  height: 150px;
  padding: 8px;
  border-radius: 12px;
  background: #ffffff;
}

.social-share-qr :deep(svg) {
  display: block;
  width: 100%;
  height: 100%;
}

.social-share-qr small {
  color: rgba(17, 34, 45, 0.58);
}

@media (max-width: 640px) {
  .social-share-button {
    width: 48px;
    height: 48px;
    border-radius: 16px;
  }

  .social-share-icon {
    width: 27px;
    height: 27px;
  }
}
</style>
