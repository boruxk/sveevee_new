<script setup>
	import { computed, defineAsyncComponent, onBeforeUnmount, onMounted, watch } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useChatsStore } from '@/stores/chats'
	import { accountNotificationEventName, useNotificationsStore } from '@/stores/notifications'
	import { sendPresenceHeartbeat } from '@/services/api/auth'
	import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
	import NotificationCenter from '@/components/NotificationCenter.vue'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import AiWorksIcon from '@/components/icons/AiWorksIcon.vue'
	import { getLegalDocument } from '@/constants/legalDocuments'
	import { marketPath, normalizeCatalogLocale } from '@/constants/catalogTopics'
	import {
		accountRefreshNotificationTypes,
		notificationActionPath,
		notificationParameters,
		notificationTranslationKeys
	} from '@/utils/accountNotifications'

	const props = defineProps({
		tone: {
			type: String,
			default: 'public'
		}
	})

	const route = useRoute()
	const router = useRouter()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const chatsStore = useChatsStore()
	const notificationsStore = useNotificationsStore()
	const { t, locale } = useI18n()
	const logoSrc = '/assets/landing/sveevee-logo-320.v1.webp'
	const SupportChatWidget = defineAsyncComponent(() => import('@/components/SupportChatWidget.vue'))
	let presenceTimer = null

	const toneClass = computed(() => `shell--${props.tone}`)
	const currentYear = new Date().getFullYear()
	const homeRouteName = computed(() => (
		authStore.isAiWorker ? 'ai-works' : (authStore.isAuthenticated ? 'home' : 'landing')
	))
	const unreadCount = computed(() => chatsStore.unreadCount || authStore.unreadMessagesCount)
	const hasBusinessPage = computed(() => Boolean(authStore.user?.business_page))
	const hasCommunityPage = computed(() => Boolean(authStore.user?.community_page))
	const isAdmin = computed(() => authStore.isAdmin || authStore.canAccess(['admin']))
	const isAiWorker = computed(() => authStore.isAiWorker || authStore.canAccess(['ai_worker']))
	const isPrivilegedAccount = computed(() => isAdmin.value || isAiWorker.value)
	const isCampaignPage = computed(() => Boolean(route.meta.campaign))
	const profileAvatar = computed(() => authStore.user?.profile || null)
	const profileAvatarUrl = computed(() => authStore.user?.profile?.photo_url || null)
	const profileAvatarAlt = computed(() => authStore.user?.display_name || t('nav.profile'))
	const profileInitials = computed(() => {
		const givenName = String(authStore.user?.given_name || '').trim()
		const familyName = String(authStore.user?.family_name || '').trim()
		return `${givenName.slice(0, 1)}${familyName.slice(0, 1)}`.trim().toUpperCase() || 'S'
	})
	const navLinks = computed(() => [
		{ label: t('nav.home'), name: homeRouteName.value, icon: 'home', visible: !isAiWorker.value },
		{ label: t('nav.search'), name: 'search', icon: 'search', visible: true },
		{ label: t('nav.me'), name: 'me', icon: 'forum', visible: authStore.isAuthenticated && !isPrivilegedAccount.value, badge: unreadCount.value },
		{ label: t('nav.business'), name: 'business', icon: 'storefront', visible: authStore.isAuthenticated && !isPrivilegedAccount.value && hasBusinessPage.value },
		{ label: t('nav.community'), name: 'community', icon: 'diversity_3', visible: authStore.isAuthenticated && !isPrivilegedAccount.value && hasCommunityPage.value }
	])
	const visibleNavLinks = computed(() => navLinks.value.filter((link) => link.visible))
	const switchCampaignLocale = (nextLocale) => router.replace({
		name: 'leads-page-001',
		params: { locale: normalizeCatalogLocale(nextLocale) },
		query: route.query
	})
	const footerColumns = computed(() => {
		const columns = [
			{
				title: t('footer.explore'),
				links: [
					{ label: t('footer.forBusinesses'), name: 'businesses-landing' },
					{ label: t('footer.forCommunities'), name: 'communities-landing' },
					{ label: t('footer.businesses'), name: 'catalog-businesses' },
					{ label: t('footer.communities'), name: 'catalog-communities' },
					{ label: t('footer.people'), name: 'catalog-people' }
				]
			},
			{
				title: t('footer.marketplace'),
				links: [
					{ label: t('footer.products'), to: `/${normalizeCatalogLocale(locale.value)}${marketPath('Jerusalem')}` },
					{ label: t('footer.services'), name: 'catalog-services' },
					{ label: t('footer.ads'), name: 'catalog-ads' }
				]
			},
			{
				title: t('footer.community'),
				links: [
					{ label: t('footer.events'), name: 'catalog-events' }
				]
			},
			{
				title: t('footer.contact'),
				links: [
					{ label: 'info@sveevee.co.il', href: 'mailto:info@sveevee.co.il' }
				]
			}
		]

		return columns
	})
	const legalLinks = computed(() => [
		{ label: getLegalDocument('privacy', locale.value).title, name: 'privacy' },
		{ label: getLegalDocument('terms', locale.value).title, name: 'terms' },
		{ label: getLegalDocument('disclaimer', locale.value).title, name: 'disclaimer' }
	])

	function isActive(name) {
		return route.name === name
	}

	function footerLinkTo(link) {
		return link.to || { name: link.name }
	}

	async function signOut() {
		try {
			await authStore.logout()
		} finally {
			chatsStore.clearActive()
			notificationsStore.reset()
			router.replace({ name: 'landing' })
		}
	}

	function openProfile() {
		router.push({ name: 'profile' })
	}

	async function refreshShellUser() {
		if (authStore.token) {
			try {
				await authStore.refreshUser()
			} catch {
				await authStore.clearSession()
			}
		}
	}

	async function loadChatBadge() {
		if (authStore.isAuthenticated && !isPrivilegedAccount.value) {
			await chatsStore.loadConversations()
		}
	}

	async function loadShellState() {
		await refreshShellUser()

		if (authStore.isAuthenticated) {
			await Promise.all([
				loadChatBadge(),
				notificationsStore.initialize(authStore.user.id)
			])
		} else {
			notificationsStore.reset()
		}
	}

	async function heartbeat() {
		if (!authStore.isAuthenticated || (typeof document !== 'undefined' && document.visibilityState !== 'visible')) {
			return
		}

		try {
			const { data } = await sendPresenceHeartbeat()
			await notificationsStore.reconcileSummary(data.data?.notifications)
		} catch {
			// Presence is best-effort and should never interrupt normal app use.
		}
	}

	function onVisibilityChange() {
		if (document.visibilityState === 'visible') {
			heartbeat()
		}
	}

	function startPresenceTracking() {
		heartbeat()
		presenceTimer = window.setInterval(heartbeat, 60_000)
		window.addEventListener('focus', heartbeat)
		document.addEventListener('visibilitychange', onVisibilityChange)
	}

	function stopPresenceTracking() {
		if (presenceTimer) {
			window.clearInterval(presenceTimer)
			presenceTimer = null
		}

		window.removeEventListener('focus', heartbeat)
		document.removeEventListener('visibilitychange', onVisibilityChange)
	}

	async function openToastNotification(notification) {
		try {
			await notificationsStore.markRead(notification)
		} catch {
			// Opening the destination remains useful if the read marker cannot be saved.
		}

		await router.push(notificationActionPath(notification))
	}

	async function handleAccountNotification(event) {
		const notification = event.detail

		if (!notification?.id) {
			return
		}

		if (accountRefreshNotificationTypes.includes(notification.type)) {
			try {
				await authStore.refreshUser()
			} catch {
				// The heartbeat will retry the account refresh without interrupting the toast.
			}
		}

		const keys = notificationTranslationKeys(notification)
		const parameters = notificationParameters(notification)
		$q.notify({
			color: 'primary',
			textColor: 'white',
			position: 'bottom-left',
			timeout: 7000,
			message: t(keys.title, parameters),
			caption: t(keys.body, parameters),
			actions: [{
				label: t('notifications.open'),
				color: 'white',
				handler: () => openToastNotification(notification)
			}]
		})
	}

	onMounted(async() => {
		window.addEventListener(accountNotificationEventName, handleAccountNotification)
		await loadShellState()
		startPresenceTracking()
	})
	onBeforeUnmount(() => {
		stopPresenceTracking()
		window.removeEventListener(accountNotificationEventName, handleAccountNotification)
		notificationsStore.reset()
	})
	watch(() => authStore.token, async() => {
		await loadShellState()
		await heartbeat()
	})
</script>

<template>
	<q-layout view="hHh lpR fFf" class="shell" :class="toneClass">
		<q-header class="bg-transparent text-dark shell-header">
			<q-toolbar class="shell-toolbar">
				<router-link :to="{ name: homeRouteName }" class="brand-lockup">
					<picture class="brand-logo">
						<source
							srcset="/assets/landing/sveevee-logo-320.v1.avif 320w, /assets/landing/sveevee-logo-640.v1.avif 640w"
							sizes="214px"
							type="image/avif"
						/>
						<source
							srcset="/assets/landing/sveevee-logo-320.v1.webp 320w, /assets/landing/sveevee-logo-640.v1.webp 640w"
							sizes="214px"
							type="image/webp"
						/>
						<img
							:src="logoSrc"
							alt="sveevee"
							width="320"
							height="63"
							decoding="async"
						/>
					</picture>
				</router-link>

				<q-space />

				<div v-if="!isCampaignPage" class="row items-center no-wrap shell-nav shell-nav--desktop">
					<q-btn
						v-for="link in visibleNavLinks"
						:key="link.name"
						:flat="!isActive(link.name)"
						rounded
						:unelevated="isActive(link.name)"
						:color="isActive(link.name) ? 'primary' : 'dark'"
						:text-color="isActive(link.name) ? 'white' : undefined"
						class="shell-link"
						:class="{ 'shell-link--active': isActive(link.name) }"
						:icon="link.icon"
						:label="link.label"
						:to="{ name: link.name }"
					>
						<q-badge v-if="link.badge" floating rounded class="chat-unread-badge shell-link__badge">{{ link.badge }}</q-badge>
					</q-btn>

					<q-btn
						v-if="!authStore.isAuthenticated"
						:flat="!isActive('login')"
						rounded
						:unelevated="isActive('login')"
						:color="isActive('login') ? 'primary' : 'dark'"
						:text-color="isActive('login') ? 'white' : undefined"
						class="shell-link"
						:class="{ 'shell-link--active': isActive('login') }"
						icon="login"
						:label="t('nav.login')"
						:to="{ name: 'login' }"
					/>
					<q-btn
						v-if="!authStore.isAuthenticated"
						:flat="!isActive('register')"
						rounded
						:unelevated="isActive('register')"
						:color="isActive('register') ? 'primary' : 'dark'"
						:text-color="isActive('register') ? 'white' : undefined"
						class="shell-link"
						:class="{ 'shell-link--active': isActive('register') }"
						icon="person_add"
						:label="t('nav.register')"
						:to="{ name: 'register' }"
					/>
					<LocaleSwitcher
						v-if="!authStore.isAuthenticated"
						guest-storage
						compact
						class="shell-guest-locale-switcher"
					/>
					<q-btn
						v-if="isAdmin"
						:flat="!isActive('admin-area')"
						round
						:unelevated="isActive('admin-area')"
						:color="isActive('admin-area') ? 'primary' : 'dark'"
						:text-color="isActive('admin-area') ? 'white' : undefined"
						class="shell-link privileged-nav-button"
						:class="{ 'shell-link--active': isActive('admin-area') }"
						icon="admin_panel_settings"
						:to="{ name: 'admin-area' }"
						:aria-label="t('nav.admin')"
					>
						<q-tooltip>{{ t('nav.admin') }}</q-tooltip>
					</q-btn>
					<q-btn
						v-if="isAiWorker"
						:flat="!isActive('ai-works')"
						round
						:unelevated="isActive('ai-works')"
						:color="isActive('ai-works') ? 'primary' : 'dark'"
						:text-color="isActive('ai-works') ? 'white' : undefined"
						class="shell-link privileged-nav-button"
						:class="{ 'shell-link--active': isActive('ai-works') }"
						:to="{ name: 'ai-works' }"
						:aria-label="t('nav.aiWorks')"
					>
						<AiWorksIcon :size="24" />
						<q-tooltip>{{ t('nav.aiWorks') }}</q-tooltip>
					</q-btn>
					<NotificationCenter v-if="authStore.isAuthenticated" />

					<q-btn v-if="authStore.isAuthenticated"
						flat
						round
						dense
						color="dark"
						class="profile-menu-trigger"
					>
						<q-avatar size="52px" color="primary" text-color="white">
							<ResponsiveImage
								v-if="profileAvatarUrl"
								class="profile-avatar-image"
								:src="profileAvatarUrl"
								:alt="profileAvatarAlt"
								:avif-srcset="profileAvatar?.photo_avif_srcset || ''"
								:webp-srcset="profileAvatar?.photo_webp_srcset || ''"
								sizes="52px"
								:width="profileAvatar?.photo_width || 96"
								:height="profileAvatar?.photo_height || 96"
							/>
							<span v-else>{{ profileInitials }}</span>
						</q-avatar>
						<q-menu anchor="bottom end" self="top end" class="profile-menu">
							<q-list padding style="min-width: 180px">
								<q-item v-if="!isAiWorker" clickable v-close-popup @click="openProfile">
									<q-item-section avatar><q-icon name="badge" /></q-item-section>
									<q-item-section>{{ t('nav.profile') }}</q-item-section>
								</q-item>
								<q-item clickable v-close-popup @click="signOut">
									<q-item-section avatar><q-icon name="logout" /></q-item-section>
									<q-item-section>{{ t('nav.logout') }}</q-item-section>
								</q-item>
							</q-list>
						</q-menu>
					</q-btn>
				</div>
				<LocaleSwitcher
					v-else-if="!authStore.isAuthenticated"
					guest-storage
					compact
					class="shell-campaign-locale"
					@update:model-value="switchCampaignLocale"
				/>

				<div v-if="!isCampaignPage" class="mobile-shell-actions">
					<q-btn
						v-if="isAdmin"
						:flat="!isActive('admin-area')"
						round
						:unelevated="isActive('admin-area')"
						:color="isActive('admin-area') ? 'primary' : 'dark'"
						:text-color="isActive('admin-area') ? 'white' : undefined"
						class="shell-link privileged-nav-button privileged-nav-button--mobile"
						:class="{ 'shell-link--active': isActive('admin-area') }"
						icon="admin_panel_settings"
						:to="{ name: 'admin-area' }"
						:aria-label="t('nav.admin')"
					>
						<q-tooltip>{{ t('nav.admin') }}</q-tooltip>
					</q-btn>
					<q-btn
						v-if="isAiWorker"
						:flat="!isActive('ai-works')"
						round
						:unelevated="isActive('ai-works')"
						:color="isActive('ai-works') ? 'primary' : 'dark'"
						:text-color="isActive('ai-works') ? 'white' : undefined"
						class="shell-link privileged-nav-button privileged-nav-button--mobile"
						:class="{ 'shell-link--active': isActive('ai-works') }"
						:to="{ name: 'ai-works' }"
						:aria-label="t('nav.aiWorks')"
					>
						<AiWorksIcon :size="22" />
						<q-tooltip>{{ t('nav.aiWorks') }}</q-tooltip>
					</q-btn>
					<NotificationCenter v-if="authStore.isAuthenticated" mobile />
					<q-btn
						flat
						round
						color="dark"
						icon="menu"
						class="mobile-menu-trigger"
						:aria-label="t('nav.menu')"
					>
						<q-menu anchor="bottom end" self="top end" class="mobile-menu">
							<q-list padding class="mobile-menu__list">
								<q-item
									v-for="link in visibleNavLinks"
									:key="link.name"
									clickable
									v-close-popup
									:active="isActive(link.name)"
									active-class="mobile-menu__item--active"
									:to="{ name: link.name }"
								>
									<q-item-section avatar>
										<q-icon :name="link.icon" />
									</q-item-section>
									<q-item-section>{{ link.label }}</q-item-section>
									<q-item-section v-if="link.badge" side>
										<q-badge rounded class="chat-unread-badge">{{ link.badge }}</q-badge>
									</q-item-section>
								</q-item>

								<q-separator spaced />

								<q-item
									v-if="!authStore.isAuthenticated"
									clickable
									v-close-popup
									:active="isActive('login')"
									active-class="mobile-menu__item--active"
									:to="{ name: 'login' }"
								>
									<q-item-section avatar><q-icon name="login" /></q-item-section>
									<q-item-section>{{ t('nav.login') }}</q-item-section>
								</q-item>
								<q-item
									v-if="!authStore.isAuthenticated"
									clickable
									v-close-popup
									:active="isActive('register')"
									active-class="mobile-menu__item--active"
									:to="{ name: 'register' }"
								>
									<q-item-section avatar><q-icon name="person_add" /></q-item-section>
									<q-item-section>{{ t('nav.register') }}</q-item-section>
								</q-item>
								<q-item v-if="!authStore.isAuthenticated" class="mobile-menu__locale">
									<q-item-section avatar><q-icon name="language" /></q-item-section>
									<q-item-section>
										<LocaleSwitcher guest-storage compact class="mobile-menu__locale-select" />
									</q-item-section>
								</q-item>

								<q-item v-if="authStore.isAuthenticated && !isAiWorker" clickable v-close-popup @click="openProfile">
									<q-item-section avatar><q-icon name="badge" /></q-item-section>
									<q-item-section>{{ t('nav.profile') }}</q-item-section>
								</q-item>
								<q-item v-if="authStore.isAuthenticated" clickable v-close-popup @click="signOut">
									<q-item-section avatar><q-icon name="logout" /></q-item-section>
									<q-item-section>{{ t('nav.logout') }}</q-item-section>
								</q-item>
							</q-list>
						</q-menu>
					</q-btn>
				</div>
			</q-toolbar>
		</q-header>

		<q-page-container>
			<slot />
		</q-page-container>

		<footer class="shell-footer" :class="{ 'shell-footer--campaign': isCampaignPage }">
			<div class="shell-footer__inner">
				<div class="shell-footer__brand">© {{ currentYear }} sveevee</div>
				<nav class="shell-footer__legal" :aria-label="t('footer.legalLabel')">
					<router-link v-for="link in legalLinks" :key="link.name" :to="{ name: link.name }">
						{{ link.label }}
					</router-link>
				</nav>
				<p class="shell-footer__recaptcha">{{ t('footer.recaptchaProtected') }}</p>
				<nav v-if="!isCampaignPage" class="shell-footer__nav" :aria-label="t('footer.label')">
					<div v-for="column in footerColumns" :key="column.title" class="shell-footer__column">
						<h2>{{ column.title }}</h2>
						<template v-for="link in column.links" :key="link.name || link.to || link.href">
							<a v-if="link.href" :href="link.href">{{ link.label }}</a>
							<router-link v-else :to="footerLinkTo(link)">
								{{ link.label }}
							</router-link>
						</template>
					</div>
				</nav>
			</div>
		</footer>
		<SupportChatWidget v-if="!isPrivilegedAccount && !isCampaignPage" />
	</q-layout>
</template>

<style scoped lang="scss">
.shell {
  min-height: 100vh;
}

.shell-header {
  backdrop-filter: blur(20px);
}

.shell-toolbar {
  display: flex;
  align-items: center;
  gap: 12px;
  max-width: 1280px;
  margin: 0 auto;
  width: 100%;
  padding: 18px 20px;
}

.brand-lockup {
  display: flex;
  flex: 0 0 auto;
  align-items: center;
}

.brand-logo {
  display: block;
  width: 214px;
  height: 42px;
  overflow: hidden;
  border-radius: 10px;
}

.brand-logo img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.profile-avatar-image {
  width: 100%;
  height: 100%;
  --responsive-image-fit: cover;
}

.shell-nav {
  min-width: 0;
  overscroll-behavior-inline: contain;
  gap: 10px;
  padding: 8px 4px 12px;
  margin: -8px -4px -12px;
  scrollbar-width: none;
}

.shell-nav::-webkit-scrollbar {
  display: none;
}

.shell-link {
  color: rgba(21, 31, 59, 0.75);
  overflow: visible;
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;
}

.shell-link__badge {
  top: 3px;
  inset-inline-end: 3px;
  right: auto;
}

.chat-unread-badge {
  border: 1px solid rgba(255, 255, 255, 0.82);
  background: var(--soz-action-gradient) !important;
  color: #fff !important;
  font-weight: 800;
  box-shadow: 0 8px 16px rgba(245, 66, 145, 0.24);
}

.shell-link--active {
  box-shadow: 0 12px 24px rgba(123, 63, 242, 0.24);
}

.shell-link:hover {
  transform: translateY(-1px);
}

.profile-menu-trigger {
  flex: 0 0 auto;
  padding: 0;
  min-width: 52px;
  min-height: 52px;
}

.privileged-nav-button {
  flex: 0 0 auto;
  width: 48px;
  height: 48px;
  padding: 0;
}

.privileged-nav-button--mobile {
  width: 44px;
  height: 44px;
}

.shell-guest-locale-switcher {
  flex: 0 0 auto;
  width: 76px;
}

.mobile-shell-actions {
  display: none;
  align-items: center;
  gap: 8px;
  padding: 8px;
  margin: -8px;
  overflow: visible;
}

.mobile-menu-trigger {
  flex: 0 0 auto;
  width: 48px;
  height: 48px;
  background: rgba(255, 255, 255, 0.86);
  box-shadow: 0 12px 26px rgba(40, 22, 93, 0.12);
  overflow: visible;
}

.mobile-menu {
  width: min(320px, calc(100vw - 20px));
  padding-bottom: 12px;
}

.mobile-menu__list {
  display: grid;
  gap: 6px;
  padding-block: 4px 8px;
}

.mobile-menu__list :deep(.q-item) {
  min-height: 52px;
  overflow: visible;
  padding-block: 8px;
  line-height: 1.25;
}

.mobile-menu__item--active {
  background: rgba(123, 63, 242, 0.12);
  color: var(--soz-primary-deep);
}

.mobile-menu__locale {
  align-items: center;
}

.mobile-menu__locale-select {
  width: 76px;
}

.profile-menu-trigger :deep(.q-avatar__content img) {
  width: 100%;
  height: 100%;
  object-fit: cover;
  object-position: center;
}

.shell--public {
  background: transparent;
}

.shell--user {
  background:
    radial-gradient(circle at top left, rgba(255, 116, 38, 0.08), transparent 26%),
    linear-gradient(180deg, rgba(123, 63, 242, 0.08), transparent 30%);
}

.shell--admin {
  background:
    radial-gradient(circle at top right, rgba(245, 66, 145, 0.08), transparent 26%),
    linear-gradient(180deg, rgba(21, 31, 59, 0.08), transparent 32%);
}

.shell-footer {
  padding: 18px 20px 26px;
}

.shell-footer__inner {
  display: grid;
  grid-template-columns: minmax(180px, 0.7fr) minmax(0, 1.3fr);
  gap: 16px;
  align-items: start;
  justify-content: space-between;
  max-width: 1280px;
  margin: 0 auto;
  padding-top: 18px;
  border-top: 1px solid rgba(17, 34, 45, 0.1);
  color: rgba(17, 34, 45, 0.56);
  font-size: 0.95rem;
  font-weight: 700;
}

.shell-footer__recaptcha {
  grid-column: 1;
  margin: 4px 0 0;
  color: rgba(17, 34, 45, 0.38);
  font-size: 0.78rem;
  font-weight: 500;
  line-height: 1.35;
}

.shell-footer__legal {
  display: grid;
  grid-column: 1;
  gap: 6px;
  margin-top: 10px;
}

.shell-footer__nav {
  display: grid;
  grid-column: 2;
  grid-row: 1 / span 3;
  grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
  gap: 22px;
  justify-content: flex-end;
}

.shell-footer__column {
  display: grid;
  align-content: start;
  gap: 8px;
}

.shell-footer__column h2 {
  margin: 0 0 2px;
  color: rgba(17, 34, 45, 0.72);
  font-size: 0.88rem;
  font-weight: 850;
}

.shell-footer__nav a,
.shell-footer__legal a {
  color: var(--soz-primary-deep);
  text-decoration: none;
}

.shell-footer__nav a:hover,
.shell-footer__legal a:hover {
  text-decoration: underline;
}

@media (max-width: 900px) {
  .shell-toolbar {
    padding: 14px 12px;
  }

  .brand-logo {
    height: 34px;
  }

  .shell-link :deep(.q-btn__content .block) {
    display: none;
  }
}

@media (max-width: 700px) {
  .shell-toolbar {
    gap: 8px;
    padding: 10px 8px;
  }

  .brand-logo {
    height: 30px;
    max-width: 116px;
    object-fit: contain;
  }

  .shell-nav {
    gap: 6px;
  }

  .shell-nav--desktop {
    display: none;
  }

  .mobile-shell-actions {
    display: flex;
  }

  .shell-link {
    min-width: 44px !important;
    min-height: 44px !important;
    padding: 0 12px !important;
  }

  .shell-guest-locale-switcher {
    display: none;
  }

  .profile-menu-trigger {
    min-width: 44px;
    min-height: 44px;
  }

  .profile-menu-trigger :deep(.q-avatar) {
    width: 44px !important;
    height: 44px !important;
  }

  .shell-footer {
    padding-inline: 12px;
  }

  .shell-footer__inner {
    grid-template-columns: 1fr;
    gap: 10px;
    justify-content: center;
    text-align: center;
  }

  .shell-footer__legal,
  .shell-footer__recaptcha,
  .shell-footer__nav {
    grid-column: auto;
    grid-row: auto;
  }

  .shell-footer__nav {
    grid-template-columns: 1fr;
    gap: 16px;
  }

  .shell-footer__legal,
  .shell-footer__column {
    justify-items: center;
  }
}
</style>
