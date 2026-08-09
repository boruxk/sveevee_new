<script setup>
	import { computed, onMounted, watch } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useAuthStore } from '@/stores/auth'
	import { useChatsStore } from '@/stores/chats'
	import LocaleSwitcher from '@/components/LocaleSwitcher.vue'
	import logoSrc from '@/assets/sveevee-logo.png'

	const props = defineProps({
		tone: {
			type: String,
			default: 'public'
		}
	})

	const route = useRoute()
	const router = useRouter()
	const authStore = useAuthStore()
	const chatsStore = useChatsStore()
	const { t } = useI18n()

	const toneClass = computed(() => `shell--${props.tone}`)
	const homeRouteName = computed(() => (authStore.isAuthenticated ? 'home' : 'landing'))
	const unreadCount = computed(() => chatsStore.unreadCount || authStore.unreadMessagesCount)
	const hasBusinessPage = computed(() => Boolean(authStore.user?.business_page))
	const hasCommunityPage = computed(() => Boolean(authStore.user?.community_page))
	const isAdmin = computed(() => authStore.isAdmin || authStore.canAccess(['admin']))
	const profileAvatarUrl = computed(() => authStore.user?.profile?.photo_url || null)
	const profileInitials = computed(() => {
		const givenName = String(authStore.user?.given_name || '').trim()
		const familyName = String(authStore.user?.family_name || '').trim()
		return `${givenName.slice(0, 1)}${familyName.slice(0, 1)}`.trim().toUpperCase() || 'S'
	})
	const navLinks = computed(() => [
		{ label: t('nav.home'), name: homeRouteName.value, icon: 'home', visible: true },
		{ label: t('nav.search'), name: 'search', icon: 'search', visible: true },
		{ label: t('nav.me'), name: 'me', icon: 'forum', visible: authStore.isAuthenticated, badge: unreadCount.value },
		{ label: t('nav.business'), name: 'business', icon: 'storefront', visible: authStore.isAuthenticated && hasBusinessPage.value },
		{ label: t('nav.community'), name: 'community', icon: 'diversity_3', visible: authStore.isAuthenticated && hasCommunityPage.value }
	])
	const visibleNavLinks = computed(() => navLinks.value.filter((link) => link.visible))

	function isActive(name) {
		return route.name === name
	}

	async function signOut() {
		await authStore.logout()
		chatsStore.clearActive()
		router.push({ name: 'landing' })
	}

	function openProfile() {
		router.push({ name: 'profile' })
	}

	function openAdmin() {
		router.push({ name: 'admin-area' })
	}

	async function refreshShellUser() {
		if (authStore.token) {
			try {
				await authStore.refreshUser()
			} catch {
				authStore.clearSession()
			}
		}
	}

	async function loadChatBadge() {
		if (authStore.isAuthenticated) {
			await chatsStore.loadConversations()
		}
	}

	async function loadShellState() {
		await refreshShellUser()
		await loadChatBadge()
	}

	onMounted(loadShellState)
	watch(() => authStore.token, loadShellState)
</script>

<template>
	<q-layout view="hHh lpR fFf" class="shell" :class="toneClass">
		<q-header class="bg-transparent text-dark shell-header">
			<q-toolbar class="shell-toolbar">
				<router-link :to="{ name: homeRouteName }" class="brand-lockup">
					<img :src="logoSrc" alt="SVEEVEE" class="brand-logo" />
				</router-link>

				<q-space />

				<div class="row items-center no-wrap shell-nav shell-nav--desktop">
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
						<q-badge v-if="link.badge" color="negative" floating rounded>{{ link.badge }}</q-badge>
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

					<q-btn v-if="authStore.isAuthenticated"
						flat
						round
						dense
						color="dark"
						class="profile-menu-trigger"
					>
						<q-avatar size="52px" color="primary" text-color="white">
							<img v-if="profileAvatarUrl" :src="profileAvatarUrl" alt="Profile" />
							<span v-else>{{ profileInitials }}</span>
						</q-avatar>
						<q-menu anchor="bottom end" self="top end" class="profile-menu">
							<q-list padding style="min-width: 180px">
								<q-item v-if="isAdmin" clickable v-close-popup @click="openAdmin">
									<q-item-section avatar><q-icon name="admin_panel_settings" /></q-item-section>
									<q-item-section>{{ t('nav.admin') }}</q-item-section>
								</q-item>
								<q-item clickable v-close-popup @click="openProfile">
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

					<LocaleSwitcher class="shell-locale-switcher" />
				</div>

				<div class="mobile-shell-actions">
					<LocaleSwitcher class="shell-locale-switcher" />
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
										<q-badge color="negative" rounded>{{ link.badge }}</q-badge>
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

								<q-item v-if="authStore.isAuthenticated && isAdmin" clickable v-close-popup @click="openAdmin">
									<q-item-section avatar><q-icon name="admin_panel_settings" /></q-item-section>
									<q-item-section>{{ t('nav.admin') }}</q-item-section>
								</q-item>
								<q-item v-if="authStore.isAuthenticated" clickable v-close-popup @click="openProfile">
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
  height: 42px;
  width: auto;
  display: block;
  border-radius: 10px;
}

.shell-nav {
  min-width: 0;
  overflow-x: auto;
  overscroll-behavior-inline: contain;
  gap: 10px;
  padding-bottom: 2px;
  scrollbar-width: none;
}

.shell-nav::-webkit-scrollbar {
  display: none;
}

.shell-link {
  color: rgba(21, 31, 59, 0.75);
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;
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

.shell-locale-switcher {
  flex: 0 0 auto;
}

.mobile-shell-actions {
  display: none;
  align-items: center;
  gap: 8px;
}

.mobile-menu-trigger {
  flex: 0 0 auto;
  width: 48px;
  height: 48px;
  background: rgba(255, 255, 255, 0.86);
  box-shadow: 0 12px 26px rgba(40, 22, 93, 0.12);
}

.mobile-menu {
  width: min(320px, calc(100vw - 20px));
}

.mobile-menu__list {
  display: grid;
  gap: 2px;
}

.mobile-menu__item--active {
  background: rgba(123, 63, 242, 0.12);
  color: var(--soz-primary-deep);
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

  .profile-menu-trigger {
    min-width: 44px;
    min-height: 44px;
  }

  .profile-menu-trigger :deep(.q-avatar) {
    width: 44px !important;
    height: 44px !important;
  }
}
</style>
