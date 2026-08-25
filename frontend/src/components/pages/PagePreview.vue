<script setup>
	import { computed, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import RatingStars from '@/components/ratings/RatingStars.vue'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import { useAppStore } from '@/stores/app'
	import { qrSvg } from '@/utils/qrCode'

	const props = defineProps({
		page: {
			type: Object,
			default: () => ({})
		},
		palette: {
			type: Object,
			required: true
		},
		canRate: {
			type: Boolean,
			default: false
		},
		hasAfterInfo: {
			type: Boolean,
			default: false
		},
		titleTag: {
			type: String,
			default: 'h2'
		},
		descriptionFallback: {
			type: String,
			default: ''
		},
		shareUrl: {
			type: String,
			default: ''
		},
		canChat: {
			type: Boolean,
			default: false
		}
	})

	const emit = defineEmits(['show-ratings', 'rate', 'chat'])
	const { t } = useI18n()
	const $q = useQuasar()
	const appStore = useAppStore()
	const qrOpen = ref(false)
	const SOCIAL_LABELS = {
		facebook: 'Facebook',
		instagram: 'Instagram',
		tiktok: 'TikTok',
		telegram: 'Telegram'
	}

	const pageType = computed(() => props.page?.type || 'business')
	const pageTypeLabel = computed(() => t(`pages.kinds.${pageType.value}`))
	const previewTitle = computed(() => props.page?.name?.trim() || pageTypeLabel.value)
	const safeTitleTag = computed(() => ['h1', 'h2', 'h3'].includes(props.titleTag) ? props.titleTag : 'h2')
	const previewDescription = computed(() => props.page?.public_description?.trim() || props.descriptionFallback || t(`pages.previewFallbacks.${pageType.value}`))

	function phoneHref(value) {
		const phone = String(value || '').trim()

		if (!phone) {
			return ''
		}

		return `tel:${phone.replace(/[^\d+]/g, '')}`
	}

	function whatsappHref(value) {
		const raw = String(value || '').trim()

		if (!raw) {
			return ''
		}

		if (/^https?:\/\//i.test(raw)) {
			return raw
		}

		const digits = raw.replace(/\D/g, '')
		const normalized = digits.startsWith('0') ? `972${digits.slice(1)}` : digits

		return normalized ? `https://wa.me/${normalized}` : ''
	}

	function socialHref(platform, value) {
		const raw = String(value || '').trim()

		if (!raw) {
			return ''
		}

		if (/^https?:\/\//i.test(raw)) {
			return raw
		}

		const handle = raw.replace(/^@+/, '').replace(/^\/+/, '')
		const bases = {
			facebook: 'https://www.facebook.com/',
			instagram: 'https://www.instagram.com/',
			tiktok: 'https://www.tiktok.com/@',
			telegram: 'https://t.me/'
		}

		return bases[platform] && handle ? `${bases[platform]}${handle}` : ''
	}

	const previewContact = computed(() => {
		const contact = props.page?.contact || {}
		const phone = contact.tel || props.page?.phone || null
		const email = contact.email || props.page?.contact_email || null
		const whatsapp = contact.whatsapp || null

		return [
			{ label: t('pages.tel'), value: phone, href: phoneHref(phone) },
			{ label: t('pages.email'), value: email, href: email ? `mailto:${email}` : '' },
			{ label: t('pages.whatsapp'), value: whatsapp, href: whatsappHref(whatsapp), external: true }
		].filter((item) => item.value)
	})
	const previewSocials = computed(() => {
		const socials = props.page?.socials || props.page?.setup?.socials || {}

		return ['facebook', 'instagram', 'tiktok', 'telegram']
			.map((platform) => ({
				platform,
				label: SOCIAL_LABELS[platform],
				href: socialHref(platform, socials[platform])
			}))
			.filter((item) => item.href)
	})
	const previewAddress = computed(() => {
		const address = props.page?.address_details || props.page?.address || {}

		if (typeof address === 'string') {
			return address
		}

		return [address.street, address.number, address.neighborhood, address.city].filter(Boolean).join(', ')
	})
	const previewAddressMapsUrl = computed(() => {
		if (!previewAddress.value) {
			return ''
		}

		return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(previewAddress.value)}`
	})
	const previewOpeningHours = computed(() => props.page?.opening_hours || [])
	const previewLogoUrl = computed(() => props.page?.logo_url || null)
	const previewBannerUrl = computed(() => props.page?.banner_url || null)
	const previewLogoAlt = computed(() => props.page?.logo_alt || `${previewTitle.value} logo`)
	const previewBannerAlt = computed(() => props.page?.banner_alt || previewTitle.value)
	const ratingSummary = computed(() => props.page?.rating_summary || { average: 0, count: 0 })
	const ratingAverage = computed(() => Number(ratingSummary.value.average || 0))
	const ratingCount = computed(() => Number(ratingSummary.value.count || 0))
	const ratingText = computed(() => {
		if (ratingCount.value === 0) {
			return t('ratings.noRatings')
		}

		return t('ratings.summary', {
			average: ratingAverage.value.toFixed(1),
			count: ratingCount.value
		})
	})
	const previewStyle = computed(() => ({
		'--presence-accent': props.palette.accent,
		'--presence-surface': props.palette.surface,
		'--presence-card': props.palette.card || 'rgba(255, 255, 255, 0.78)',
		'--presence-border': props.palette.border || 'rgba(17, 34, 45, 0.08)',
		'--presence-hero': props.palette.hero,
		'--presence-overlay': props.palette.overlay || 'radial-gradient(circle at top right, rgba(255, 255, 255, 0.56), transparent 40%), linear-gradient(180deg, rgba(255, 255, 255, 0.12), rgba(255, 255, 255, 0.58))',
		'--presence-ink': props.palette.ink,
		'--presence-muted': props.palette.muted,
		'--presence-banner-border': props.palette.dark ? 'rgba(255, 255, 255, 0.22)' : 'rgba(17, 34, 45, 0.12)',
		'--presence-logo-bg': props.palette.dark ? 'rgba(255, 255, 255, 0.12)' : 'rgba(255, 255, 255, 0.92)',
		'--presence-shadow': props.palette.dark ? 'rgba(0, 0, 0, 0.34)' : 'rgba(17, 34, 45, 0.14)'
	}))
	const previewClasses = computed(() => ({
		'page-preview--dark': Boolean(props.palette.dark),
		'page-preview--rtl': appStore.isRtl,
		'page-preview--has-banner': Boolean(previewBannerUrl.value)
	}))
	const shareTargetUrl = computed(() => props.shareUrl.trim())
	const shareMenuAnchor = computed(() => (appStore.isRtl ? 'top left' : 'top right'))
	const shareMenuSelf = computed(() => (appStore.isRtl ? 'bottom left' : 'bottom right'))
	const shareText = computed(() => [previewTitle.value, shareTargetUrl.value].filter(Boolean).join(' '))
	const whatsappShareUrl = computed(() => `https://wa.me/?text=${encodeURIComponent(shareText.value)}`)
	const facebookShareUrl = computed(() => `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(shareTargetUrl.value)}`)
	const telegramShareUrl = computed(() => `https://t.me/share/url?url=${encodeURIComponent(shareTargetUrl.value)}&text=${encodeURIComponent(previewTitle.value)}`)
	const qrCodeSvg = computed(() => (shareTargetUrl.value ? qrSvg(shareTargetUrl.value) : ''))

	function openShareUrl(url) {
		if (!url || typeof window === 'undefined') {
			return
		}

		window.open(url, '_blank', 'noopener,noreferrer')
	}

	function fallbackCopy(value) {
		const input = document.createElement('textarea')
		input.value = value
		input.setAttribute('readonly', '')
		input.style.position = 'fixed'
		input.style.top = '0'
		input.style.left = '0'
		input.style.width = '1px'
		input.style.height = '1px'
		input.style.padding = '0'
		input.style.border = '0'
		input.style.opacity = '0'
		const activeElement = document.activeElement
		document.body.appendChild(input)
		input.focus({ preventScroll: true })
		input.select()
		input.setSelectionRange(0, input.value.length)
		let copied = false

		try {
			copied = document.execCommand('copy')
		} catch {
			copied = false
		} finally {
			document.body.removeChild(input)
		}

		try {
			if (activeElement && typeof activeElement.focus === 'function') {
				activeElement.focus({ preventScroll: true })
			}
		} catch {
			// Restoring focus is only a nicety; failed focus should not mark copy as failed.
		}

		return copied
	}

	async function copyShareLink(options = {}) {
		if (!shareTargetUrl.value || typeof window === 'undefined') {
			return false
		}

		const url = shareTargetUrl.value
		let copied = false

		try {
			if (window.isSecureContext && navigator.clipboard?.writeText) {
				await navigator.clipboard.writeText(url)
				copied = true
			}
		} catch {
			copied = false
		}

		if (!copied) {
			copied = fallbackCopy(url)
		}

		if (!copied) {
			$q.notify({ type: 'negative', message: t('share.copyFailed') })
			return false
		}

		if (options.notify !== false) {
			$q.notify({ type: 'positive', message: t('share.copied') })
		}

		return true
	}

	async function shareToInstagram() {
		await copyShareLink({ notify: false })
		openShareUrl('https://www.instagram.com/')
		$q.notify({ type: 'positive', message: t('share.instagramCopied') })
	}

	async function shareToTikTok() {
		await copyShareLink({ notify: false })
		openShareUrl('https://www.tiktok.com/')
		$q.notify({ type: 'positive', message: t('share.tiktokCopied') })
	}
</script>

<template>
	<article class="page-preview" :class="previewClasses" :style="previewStyle">
		<div class="page-preview__hero">
			<ResponsiveImage
				v-if="previewBannerUrl"
				class="page-preview__banner"
				:src="previewBannerUrl"
				:alt="previewBannerAlt"
				:avif-srcset="props.page?.banner_avif_srcset || ''"
				:webp-srcset="props.page?.banner_webp_srcset || ''"
				:sizes="props.page?.banner_sizes || '(max-width: 700px) calc(100vw - 28px), 1180px'"
				:width="props.page?.banner_width || 1440"
				:height="props.page?.banner_height || 640"
				loading="eager"
				fetchpriority="high"
			/>
			<div v-else class="page-preview__banner" />
			<div class="page-preview__overlay" />

			<div class="page-preview__intro">
				<q-avatar class="page-preview__logo" size="96px" square>
					<ResponsiveImage
						v-if="previewLogoUrl"
						class="page-preview__logo-image"
						:src="previewLogoUrl"
						:alt="previewLogoAlt"
						:avif-srcset="props.page?.logo_avif_srcset || ''"
						:webp-srcset="props.page?.logo_webp_srcset || ''"
						:sizes="props.page?.logo_sizes || '96px'"
						:width="props.page?.logo_width || 512"
						:height="props.page?.logo_height || 512"
						loading="eager"
					/>
					<span v-else>{{ previewTitle.slice(0, 1).toUpperCase() }}</span>
				</q-avatar>

				<div class="page-preview__copy">
					<component :is="safeTitleTag" class="page-preview__title">{{ previewTitle }}</component>
					<p class="page-preview__description">{{ previewDescription }}</p>
				</div>
			</div>

			<div v-if="shareTargetUrl || canChat" class="page-preview__hero-actions">
				<div v-if="shareTargetUrl" class="page-preview__share">
					<q-btn
						round
						unelevated
						color="primary"
						class="page-preview__share-button"
						:aria-label="t('share.title')"
					>
						<svg class="page-share-icon page-share-icon--share" viewBox="0 0 24 24" aria-hidden="true">
							<circle cx="18" cy="5" r="3" />
							<circle cx="6" cy="12" r="3" />
							<circle cx="18" cy="19" r="3" />
							<path d="M8.7 10.7 15.3 7M8.7 13.3l6.6 3.7" />
						</svg>
						<q-tooltip>{{ t('share.title') }}</q-tooltip>
						<q-menu :anchor="shareMenuAnchor" :self="shareMenuSelf" class="page-share-menu" :offset="[0, 12]">
							<div class="page-share-menu__content">
								<button type="button" class="page-share-menu__button" aria-label="WhatsApp" @click="openShareUrl(whatsappShareUrl)" v-close-popup>
									<svg class="page-share-icon page-share-icon--whatsapp" viewBox="0 0 24 24" aria-hidden="true">
										<path class="page-share-icon__brand-bg" d="M12 3.2a8.8 8.8 0 0 0-7.5 13.4l-.9 3.3 3.4-.9A8.8 8.8 0 1 0 12 3.2Z" />
										<g class="page-share-icon__brand-inner">
											<path class="page-share-icon__brand-line" d="M12 5.1a6.9 6.9 0 0 1 5.9 10.5 6.9 6.9 0 0 1-8.1 2.7l-.4-.2-2 .5.5-1.9-.3-.4A6.9 6.9 0 0 1 12 5.1Z" />
											<path class="page-share-icon__brand-phone" d="M9.6 8.2c-.2 0-.4 0-.6.4-.2.3-.7.9-.7 1.7s.7 1.7.8 1.9c.1.1 1.4 2.2 3.4 3 .5.2.9.3 1.2.4.5.2 1 .1 1.3.1.4-.1 1.1-.5 1.2-.9.2-.4.2-.8.1-.9l-.5-.3-1.3-.6c-.2-.1-.4-.1-.6.1l-.6.7c-.2.2-.3.2-.6.1a5.6 5.6 0 0 1-2.8-2.5c-.1-.3 0-.4.1-.5l.4-.5c.1-.1.1-.3.2-.4 0-.1 0-.3-.1-.4l-.6-1.4c-.1-.3-.3-.3-.5-.3h-.3Z" />
										</g>
									</svg>
									<q-tooltip>WhatsApp</q-tooltip>
								</button>
								<button type="button" class="page-share-menu__button" aria-label="Facebook" @click="openShareUrl(facebookShareUrl)" v-close-popup>
									<svg class="page-share-icon page-share-icon--facebook" viewBox="0 0 24 24" aria-hidden="true">
										<circle cx="12" cy="12" r="10" />
										<path d="M13.3 20v-7h2.3l.4-2.7h-2.7V8.6c0-.8.2-1.3 1.3-1.3H16V5c-.2 0-1.1-.1-2-.1-2.1 0-3.6 1.3-3.6 3.6v1.9H8V13h2.4v7h2.9Z" />
									</svg>
									<q-tooltip>Facebook</q-tooltip>
								</button>
								<button type="button" class="page-share-menu__button" aria-label="Instagram" @click="shareToInstagram" v-close-popup>
									<svg class="page-share-icon page-share-icon--instagram" viewBox="0 0 24 24" aria-hidden="true">
										<defs>
											<linearGradient
												id="instagramGradient"
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
								<button type="button" class="page-share-menu__button" aria-label="TikTok" @click="shareToTikTok" v-close-popup>
									<svg class="page-share-icon page-share-icon--tiktok" viewBox="0 0 24 24" aria-hidden="true">
										<path class="page-share-icon__shadow-a" d="M15.1 3.5c.4 2.5 1.8 4 4.2 4.3v3.1c-1.5 0-2.9-.5-4.2-1.3v5.4a5.4 5.4 0 1 1-5.4-5.4c.3 0 .7 0 1 .1v3.3a2.2 2.2 0 1 0 1.4 2V3.5h3Z" />
										<path class="page-share-icon__shadow-b" d="M14.1 2.6c.4 2.5 1.8 4 4.2 4.3V10c-1.5 0-2.9-.5-4.2-1.3v5.4a5.4 5.4 0 1 1-5.4-5.4c.3 0 .7 0 1 .1v3.3a2.2 2.2 0 1 0 1.4 2V2.6h3Z" />
										<path d="M14.6 3c.4 2.5 1.8 4 4.2 4.3v3.1c-1.5 0-2.9-.5-4.2-1.3v5.4a5.4 5.4 0 1 1-5.4-5.4c.3 0 .7 0 1 .1v3.3a2.2 2.2 0 1 0 1.4 2V3h3Z" />
									</svg>
									<q-tooltip>TikTok</q-tooltip>
								</button>
								<button type="button" class="page-share-menu__button" aria-label="Telegram" @click="openShareUrl(telegramShareUrl)" v-close-popup>
									<svg class="page-share-icon page-share-icon--telegram" viewBox="0 0 24 24" aria-hidden="true">
										<circle cx="12" cy="12" r="10" />
										<path d="m6.2 11.7 10.6-4.1c.5-.2.9.1.7.8l-1.8 8.7c-.1.6-.5.7-1 .4l-2.8-2-1.3 1.3c-.2.2-.3.3-.6.3l.2-2.9 5.3-4.8c.2-.2 0-.4-.3-.2l-6.5 4.1-2.8-.9c-.6-.2-.6-.6.3-.7Z" />
									</svg>
									<q-tooltip>Telegram</q-tooltip>
								</button>
								<button type="button" class="page-share-menu__button" :aria-label="t('share.copyLink')" @click.stop.prevent="copyShareLink">
									<svg class="page-share-icon page-share-icon--copy" viewBox="0 0 24 24" aria-hidden="true">
										<rect x="8" y="8" width="11" height="11" rx="2" />
										<path d="M5 15V6a1 1 0 0 1 1-1h9" />
									</svg>
									<q-tooltip>{{ t('share.copyLink') }}</q-tooltip>
								</button>
								<button type="button" class="page-share-menu__button" :aria-label="t('share.qrCode')" @click="qrOpen = !qrOpen">
									<svg class="page-share-icon page-share-icon--qr" viewBox="0 0 24 24" aria-hidden="true">
										<path d="M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4z" />
										<path d="M14 14h2v2h-2zM18 14h2v6h-2zM14 18h2v2h-2z" />
									</svg>
									<q-tooltip>{{ t('share.qrCode') }}</q-tooltip>
								</button>
								<div v-if="qrOpen" class="page-share-menu__qr">
									<div class="page-share-menu__qr-code" v-html="qrCodeSvg" />
									<div class="page-share-menu__url">{{ shareTargetUrl }}</div>
								</div>
							</div>
						</q-menu>
					</q-btn>
				</div>
				<q-btn
					v-if="canChat"
					round
					unelevated
					color="primary"
					class="page-preview__chat-button"
					:aria-label="t('chat.title')"
					@click="emit('chat')"
				>
					<svg class="page-chat-icon" viewBox="0 0 24 24" aria-hidden="true">
						<path d="M5.2 5.2h13.6v10.4H10l-4.8 3.2v-3.2Z" />
						<path d="M8.2 9.2h7.6M8.2 12.2h5.2" />
					</svg>
					<q-tooltip>{{ t('chat.title') }}</q-tooltip>
				</q-btn>
			</div>
		</div>

		<div class="page-preview__body" :class="{ 'page-preview__body--with-content': hasAfterInfo }">
			<div v-if="hasAfterInfo" class="page-preview__content">
				<slot name="afterInfo" />
			</div>

			<div class="page-preview__info">
				<div class="page-preview__column">
					<div class="page-preview__detail-card">
						<div class="page-preview__section-title">{{ t('pages.sections.contact') }}</div>
						<div v-if="previewContact.length > 0" class="page-preview__detail-list">
							<div v-for="item in previewContact" :key="item.label" class="page-preview__detail-row">
								<span class="page-preview__detail-label">{{ item.label }}</span>
								<a v-if="item.href"
									class="page-preview__detail-link"
									:href="item.href"
									:target="item.external ? '_blank' : undefined"
									:rel="item.external ? 'noopener noreferrer' : undefined"
								>
									{{ item.value }}
								</a>
								<span v-else>{{ item.value }}</span>
							</div>
						</div>
						<div v-else class="text-body2 page-preview__empty">{{ t('pages.noContact') }}</div>
					</div>

					<div class="page-preview__detail-card">
						<div class="page-preview__section-title">{{ t('pages.sections.address') }}</div>
						<a v-if="previewAddress"
							class="page-preview__address-link"
							:href="previewAddressMapsUrl"
							target="_blank"
							rel="noopener noreferrer"
						>
							<q-icon name="place" size="20px" />
							<span>{{ previewAddress }}</span>
						</a>
						<div v-else class="text-body2 page-preview__empty">{{ t('pages.noAddress') }}</div>
					</div>

					<div v-if="previewSocials.length" class="page-preview__detail-card">
						<div class="page-preview__section-title">{{ t('pages.sections.socials') }}</div>
						<div class="page-preview__social-list">
							<a v-for="item in previewSocials"
								:key="item.platform"
								class="page-preview__social-link"
								:href="item.href"
								target="_blank"
								rel="noopener noreferrer"
							>
								<svg class="page-social-icon" :class="`page-social-icon--${item.platform}`" viewBox="0 0 24 24" aria-hidden="true">
									<template v-if="item.platform === 'facebook'">
										<circle cx="12" cy="12" r="10" />
										<path d="M13.3 20v-7h2.3l.4-2.7h-2.7V8.6c0-.8.2-1.3 1.3-1.3H16V5c-.2 0-1.1-.1-2-.1-2.1 0-3.6 1.3-3.6 3.6v1.9H8V13h2.4v7h2.9Z" />
									</template>
									<template v-else-if="item.platform === 'instagram'">
										<rect x="3" y="3" width="18" height="18" rx="5" />
										<circle cx="12" cy="12" r="4" />
										<circle cx="17" cy="7" r="1.2" />
									</template>
									<template v-else-if="item.platform === 'tiktok'">
										<path class="page-social-icon__shadow-a" d="M15.1 3.5c.4 2.5 1.8 4 4.2 4.3v3.1c-1.5 0-2.9-.5-4.2-1.3v5.4a5.4 5.4 0 1 1-5.4-5.4c.3 0 .7 0 1 .1v3.3a2.2 2.2 0 1 0 1.4 2V3.5h3Z" />
										<path class="page-social-icon__shadow-b" d="M14.1 2.6c.4 2.5 1.8 4 4.2 4.3V10c-1.5 0-2.9-.5-4.2-1.3v5.4a5.4 5.4 0 1 1-5.4-5.4c.3 0 .7 0 1 .1v3.3a2.2 2.2 0 1 0 1.4 2V2.6h3Z" />
										<path d="M14.6 3c.4 2.5 1.8 4 4.2 4.3v3.1c-1.5 0-2.9-.5-4.2-1.3v5.4a5.4 5.4 0 1 1-5.4-5.4c.3 0 .7 0 1 .1v3.3a2.2 2.2 0 1 0 1.4 2V3h3Z" />
									</template>
									<template v-else>
										<circle cx="12" cy="12" r="10" />
										<path d="m6.2 11.7 10.6-4.1c.5-.2.9.1.7.8l-1.8 8.7c-.1.6-.5.7-1 .4l-2.8-2-1.3 1.3c-.2.2-.3.3-.6.3l.2-2.9 5.3-4.8c.2-.2 0-.4-.3-.2l-6.5 4.1-2.8-.9c-.6-.2-.6-.6.3-.7Z" />
									</template>
								</svg>
								<span>{{ item.label }}</span>
							</a>
						</div>
					</div>
				</div>

				<div class="page-preview__column">
					<div class="page-preview__detail-card">
						<div class="page-preview__section-title">{{ t('pages.sections.openingHours') }}</div>
						<div v-if="previewOpeningHours.length > 0" class="page-preview__hours-list">
							<div v-for="item in previewOpeningHours" :key="item.weekday" class="page-preview__detail-row">
								<span class="page-preview__detail-label">{{ t(`pages.weekdays.${item.weekday}`) }}</span>
								<span>{{ item.is_open ? `${item.opens_at} - ${item.closes_at}` : t('pages.closed') }}</span>
							</div>
						</div>
						<div v-else class="text-body2 page-preview__empty">{{ t('pages.noOpeningHours') }}</div>
					</div>

					<div class="page-preview__detail-card">
						<div class="page-preview__section-title">{{ t('ratings.title') }}</div>
						<div class="page-preview__rating-row">
							<div class="page-preview__rating-score">
								<RatingStars readonly :value="ratingAverage" />
								<div class="text-body2 page-preview__empty">{{ ratingText }}</div>
							</div>
							<div class="page-preview__rating-actions">
								<q-btn rounded
									unelevated
									color="primary"
									class="page-preview__ratings-btn"
									icon="reviews"
									:disable="!page?.id"
									:label="t('ratings.allRatings')"
									@click="emit('show-ratings')"
								/>
								<q-btn v-if="canRate"
									rounded
									unelevated
									color="primary"
									icon="star"
									:label="t('ratings.rate')"
									@click="emit('rate')"
								/>
							</div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</article>
</template>

<style scoped lang="scss">
.page-preview {
  display: grid;
  gap: 26px;
  padding: 24px;
  border: 1px solid var(--presence-border);
  border-radius: 36px;
  background: var(--presence-surface);
  color: var(--presence-ink);
}

.page-preview__hero {
  position: relative;
  overflow: hidden;
  min-height: 320px;
  border-radius: 36px;
  background: var(--presence-hero);
}

.page-preview__banner,
.page-preview__overlay {
  position: absolute;
  inset: 0;
}

.page-preview__banner {
  display: block;
  background: var(--presence-hero);
  --responsive-image-fit: cover;
  --responsive-image-position: center;
}

.page-preview__overlay {
  background: var(--presence-overlay);
}

.page-preview__intro {
  position: relative;
  z-index: 1;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 26px;
  align-items: end;
  min-height: 320px;
  padding: 36px;
}

.page-preview__hero-actions {
  position: absolute;
  right: 24px;
  bottom: 24px;
  z-index: 2;
  display: flex;
  gap: 10px;
  align-items: center;
}

:global([dir="rtl"]) .page-preview__hero-actions {
  right: auto;
  left: 24px;
}

.page-preview--rtl .page-preview__hero-actions {
  right: auto;
  left: 24px;
}

.page-preview__share-button.q-btn.bg-primary,
.page-preview__chat-button.q-btn.bg-primary {
  background: var(--soz-action-gradient) !important;
  box-shadow: 0 16px 34px rgba(245, 66, 145, 0.28) !important;
}

.page-share-icon {
  display: block;
  width: 28px;
  height: 28px;
}

.page-chat-icon {
  display: block;
  width: 27px;
  height: 27px;
  fill: none;
  stroke: #ffffff;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.9;
}

.page-share-icon--share {
  color: #fff;
  overflow: visible;
}

.page-share-icon--share circle,
.page-share-icon--share path {
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 2;
}

.page-share-icon--whatsapp .page-share-icon__brand-bg {
  fill: #25d366;
}

.page-share-icon--whatsapp .page-share-icon__brand-inner {
  transform: translate(-0.15px, -0.1px) scale(0.84);
  transform-box: view-box;
  transform-origin: 12px 12px;
}

.page-share-icon--whatsapp .page-share-icon__brand-line {
  fill: none;
  stroke: #fff;
  stroke-width: 1.15;
}

.page-share-icon--whatsapp .page-share-icon__brand-phone {
  fill: #fff;
}

.page-share-icon--facebook circle {
  fill: #1877f2;
}

.page-share-icon--facebook path {
  fill: #fff;
}

.page-share-icon--instagram rect {
  fill: url("#instagramGradient");
}

.page-share-icon--instagram circle {
  fill: none;
  stroke: #fff;
  stroke-width: 1.7;
}

.page-share-icon--tiktok .page-share-icon__shadow-a {
  fill: #25f4ee;
}

.page-share-icon--tiktok .page-share-icon__shadow-b {
  fill: #fe2c55;
}

.page-share-icon--tiktok > path:last-child {
  fill: #111;
}

.page-share-icon--telegram circle {
  fill: #229ed9;
}

.page-share-icon--telegram path {
  fill: #fff;
}

.page-share-icon--copy rect,
.page-share-icon--copy path {
  fill: none;
  stroke: #6366f1;
  stroke-linejoin: round;
  stroke-width: 1.9;
}

.page-share-icon--qr path {
  fill: #151f2d;
}

:global(.page-share-menu) {
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 18px;
  background: #fffaf5;
  box-shadow: 0 22px 48px rgba(17, 34, 45, 0.16);
}

:global(.page-share-menu__content) {
  display: grid;
  grid-template-columns: repeat(4, 54px);
  gap: 10px;
  padding: 14px;
}

:global(.page-share-menu__button) {
  display: grid;
  place-items: center;
  width: 54px;
  height: 54px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.86);
  cursor: pointer;
  transition:
    background 0.18s ease,
    border-color 0.18s ease,
    transform 0.18s ease;
}

:global(.page-share-menu__button:hover) {
  border-color: rgba(245, 66, 145, 0.24);
  background: #fff;
  transform: translateY(-1px);
}

:global(.page-share-menu__qr) {
  display: grid;
  grid-column: 1 / -1;
  gap: 8px;
  justify-items: center;
  padding: 10px 8px 8px;
  border-top: 1px solid rgba(17, 34, 45, 0.08);
}

:global(.page-share-menu__qr-code) {
  width: 156px;
  height: 156px;
  padding: 8px;
  border-radius: 12px;
  background: #fff;
}

:global(.page-share-menu__qr-code svg) {
  display: block;
  width: 100%;
  height: 100%;
}

:global(.page-share-menu__url) {
  max-width: 180px;
  color: rgba(17, 34, 45, 0.58);
  font-size: 11px;
  line-height: 1.35;
  overflow-wrap: anywhere;
  text-align: center;
}

.page-preview__logo {
  border-radius: 28px;
  background: var(--presence-logo-bg);
  color: var(--presence-accent);
  font-size: 38px;
  font-weight: 700;
  box-shadow: 0 18px 44px var(--presence-shadow);
}

.page-preview__logo :deep(img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.page-preview__logo-image {
  width: 100%;
  height: 100%;
}

.page-preview__copy {
  max-width: 760px;
  min-width: 0;
}

.page-preview__title {
  margin: 0;
  font-size: clamp(2.2rem, 4vw, 3.6rem);
  line-height: 1;
  overflow-wrap: anywhere;
}

.page-preview--has-banner .page-preview__title,
.page-preview--has-banner .page-preview__description {
  display: table;
  width: fit-content;
  max-width: 100%;
  border: 1px solid color-mix(in srgb, var(--presence-accent) 22%, var(--presence-banner-border));
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.54);
  background:
    linear-gradient(
      135deg,
      color-mix(in srgb, var(--presence-card) 62%, transparent),
      color-mix(in srgb, var(--presence-surface) 46%, transparent)
    );
  backdrop-filter: blur(14px);
  box-shadow: 0 16px 36px rgba(17, 34, 45, 0.16);
}

.page-preview--has-banner .page-preview__title {
  padding: 10px 16px 12px;
}

.page-preview--has-banner .page-preview__description {
  padding: 10px 14px;
}

.page-preview--dark.page-preview--has-banner .page-preview__title,
.page-preview--dark.page-preview--has-banner .page-preview__description {
  background: rgba(9, 14, 24, 0.52);
  background:
    linear-gradient(
      135deg,
      color-mix(in srgb, var(--presence-card) 54%, transparent),
      rgba(9, 14, 24, 0.48)
    );
  box-shadow: 0 16px 40px rgba(0, 0, 0, 0.28);
}

.page-preview__description {
  max-width: 620px;
  margin: 14px 0 0;
  color: var(--presence-muted);
  font-size: 1.02rem;
  line-height: 1.7;
}

.page-preview__body {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(360px, 0.8fr);
  gap: 18px;
}

.page-preview__info {
  display: contents;
}

.page-preview__content {
  min-width: 0;
  order: 1;
}

.page-preview__body--with-content {
  grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
  align-items: start;
}

.page-preview__body--with-content .page-preview__info {
  display: grid;
  gap: 14px;
  min-width: 0;
  order: 2;
}

.page-preview__body--with-content .page-preview__content {
  order: 1;
}

.page-preview__column {
  display: grid;
  align-content: start;
  gap: 14px;
  min-width: 0;
}

:global([dir="rtl"]) .page-preview__body--with-content .page-preview__info {
  order: 1;
}

:global([dir="rtl"]) .page-preview__body--with-content {
  grid-template-columns: minmax(280px, 1fr) minmax(0, 2fr);
}

:global([dir="rtl"]) .page-preview__body--with-content .page-preview__content {
  order: 2;
}

.page-preview__section-title {
  color: var(--presence-muted);
  font-size: 13px;
  font-weight: 700;
  letter-spacing: 0.16em;
  text-transform: uppercase;
}

.page-preview__detail-card {
  min-width: 0;
  padding: 20px;
  border: 1px solid var(--presence-border);
  border-radius: 24px;
  background: var(--presence-card);
}

.page-preview__detail-list,
.page-preview__hours-list {
  display: grid;
  gap: 10px;
  margin-top: 14px;
}

.page-preview__detail-row {
  display: grid;
  grid-template-columns: 120px 1fr;
  gap: 12px;
  color: var(--presence-ink);
}

.page-preview__detail-row span {
  min-width: 0;
  overflow-wrap: anywhere;
}

.page-preview__detail-link,
.page-preview__address-link {
  min-width: 0;
  color: var(--presence-accent);
  font-weight: 800;
  text-decoration: none;
  overflow-wrap: anywhere;
}

.page-preview__detail-link:hover,
.page-preview__address-link:hover {
  color: color-mix(in srgb, var(--presence-accent) 72%, var(--presence-ink));
}

.page-preview__detail-label {
  color: var(--presence-muted);
}

.page-preview__address-link {
  display: flex;
  gap: 8px;
  align-items: flex-start;
  margin-top: 14px;
}

.page-preview__address-link .q-icon {
  flex: 0 0 auto;
  margin-top: 1px;
}

.page-preview__social-list {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 14px;
}

.page-preview__social-link {
  display: inline-flex;
  min-height: 42px;
  gap: 8px;
  align-items: center;
  padding: 8px 12px;
  border: 1px solid color-mix(in srgb, var(--presence-accent) 18%, var(--presence-border));
  border-radius: 999px;
  background: color-mix(in srgb, var(--presence-card) 84%, var(--presence-accent) 16%);
  color: var(--presence-ink);
  font-weight: 820;
  text-decoration: none;
  transition:
    border-color 0.18s ease,
    transform 0.18s ease;
}

.page-preview__social-link:hover {
  border-color: color-mix(in srgb, var(--presence-accent) 40%, var(--presence-border));
  transform: translateY(-1px);
}

.page-social-icon {
  display: block;
  flex: 0 0 auto;
  width: 24px;
  height: 24px;
}

.page-social-icon--facebook circle {
  fill: #1877f2;
}

.page-social-icon--facebook path {
  fill: #fff;
}

.page-social-icon--instagram rect {
  fill: #e1306c;
}

.page-social-icon--instagram circle {
  fill: none;
  stroke: #fff;
  stroke-width: 1.7;
}

.page-social-icon--tiktok .page-social-icon__shadow-a {
  fill: #25f4ee;
}

.page-social-icon--tiktok .page-social-icon__shadow-b {
  fill: #fe2c55;
}

.page-social-icon--tiktok > path:last-child {
  fill: #111;
}

.page-social-icon--telegram circle {
  fill: #229ed9;
}

.page-social-icon--telegram path {
  fill: #fff;
}

.page-preview__empty {
  color: var(--presence-muted);
}

.page-preview__rating-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 14px;
  align-items: center;
  margin-top: 14px;
}

.page-preview__rating-score,
.page-preview__rating-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
}

.page-preview__rating-actions {
  justify-content: flex-end;
}

.page-preview__ratings-btn.q-btn.bg-primary {
  background: var(--soz-action-gradient) !important;
  box-shadow: 0 14px 28px rgba(245, 66, 145, 0.22) !important;
}

@media (max-width: 900px) {
  .page-preview__intro,
  .page-preview__body {
    grid-template-columns: 1fr;
  }

  .page-preview__body--with-content .page-preview__content {
    order: 1;
  }

  .page-preview__body--with-content .page-preview__info {
    order: 2;
  }

  .page-preview__intro {
    align-items: flex-start;
  }

  .page-preview__hero,
  .page-preview__intro {
    min-height: 280px;
  }
}

@media (max-width: 640px) {
  .page-preview {
    gap: 16px;
    padding: 14px;
    border-radius: 24px;
  }

  .page-preview__hero {
    min-height: 260px;
    border-radius: 24px;
  }

  .page-preview__intro {
    gap: 16px;
    min-height: 260px;
    padding: 20px;
  }

  .page-preview__hero-actions {
    right: 16px;
    bottom: 16px;
  }

  :global([dir="rtl"]) .page-preview__hero-actions {
    right: auto;
    left: 16px;
  }

  .page-preview--rtl .page-preview__hero-actions {
    right: auto;
    left: 16px;
  }

  .page-preview__logo {
    width: 72px !important;
    height: 72px !important;
    border-radius: 20px;
    font-size: 30px;
  }

  .page-preview__title {
    font-size: clamp(1.75rem, 9vw, 2.4rem);
    line-height: 1.08;
  }

  .page-preview__description {
    font-size: 0.98rem;
    line-height: 1.58;
  }

  .page-preview__detail-card {
    padding: 16px;
    border-radius: 20px;
  }

  .page-preview__rating-row,
  .page-preview__detail-row {
    grid-template-columns: 1fr;
  }

  .page-preview__rating-actions {
    justify-content: flex-start;
  }

  .page-preview__rating-actions .q-btn {
    width: 100%;
  }
}
</style>
