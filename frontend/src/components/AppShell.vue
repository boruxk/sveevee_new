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
		{ label: t('nav.community'), name: 'community', icon: 'diversity_3', visible: authStore.isAuthenticated && hasCommunityPage.value },
		{ label: t('nav.admin'), name: 'admin-area', icon: 'admin_panel_settings', visible: authStore.canAccess(['admin']) }
	])

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

	async function loadChatBadge() {
		if (authStore.isAuthenticated) {
			await chatsStore.loadConversations()
		}
	}

	onMounted(loadChatBadge)
	watch(() => authStore.isAuthenticated, loadChatBadge)
</script>

<template>
	<q-layout view="hHh lpR fFf" class="shell" :class="toneClass">
		<q-header class="bg-transparent text-dark shell-header">
			<q-toolbar class="shell-toolbar">
				<router-link :to="{ name: homeRouteName }" class="brand-lockup">
					<img :src="logoSrc" alt="SVEEVEE" class="brand-logo" />
				</router-link>

				<q-space />

				<div class="row items-center no-wrap shell-nav">
					<q-btn
						v-for="link in navLinks"
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
						v-show="link.visible"
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
						<q-avatar size="48px" color="primary" text-color="white">
							<img v-if="profileAvatarUrl" :src="profileAvatarUrl" alt="Profile" />
							<span v-else>{{ profileInitials }}</span>
						</q-avatar>
						<q-menu anchor="bottom right" self="top right" class="profile-menu">
							<q-list padding style="min-width: 180px">
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
  max-width: 1280px;
  margin: 0 auto;
  width: 100%;
  padding: 18px 20px;
}

.brand-lockup {
  display: flex;
  align-items: center;
}

.brand-logo {
  height: 42px;
  width: auto;
  display: block;
  border-radius: 10px;
}

.shell-nav {
  gap: 10px;
}

.shell-link {
  color: rgba(17, 34, 45, 0.75);
  transition:
    transform 0.16s ease,
    box-shadow 0.16s ease;
}

.shell-link--active {
  box-shadow: 0 12px 24px rgba(140, 91, 61, 0.2);
}

.shell-link:hover {
  transform: translateY(-1px);
}

.profile-menu-trigger {
  padding: 0;
  min-width: 48px;
  min-height: 48px;
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
  background: linear-gradient(180deg, rgba(15, 118, 110, 0.07), transparent 30%);
}

.shell--admin {
  background: linear-gradient(180deg, rgba(17, 34, 45, 0.08), transparent 32%);
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
</style>
