<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useAuthStore } from '@/stores/auth'
	import { useChatsStore } from '@/stores/chats'
	import { fetchUser } from '@/services/api/users'
	import { absoluteUrl, truncateText, useSeo } from '@/composables/useSeo'
	import AdCard from '@/components/AdCard.vue'
	import ChatBlock from '@/components/ChatBlock.vue'

	const route = useRoute()
	const { t } = useI18n()
	const authStore = useAuthStore()
	const chatsStore = useChatsStore()
	const loading = ref(false)
	const user = ref(null)
	const chatOpen = ref(false)

	const pages = computed(() => [user.value?.business_page, user.value?.community_page].filter(Boolean))
	const profileLocation = computed(() => [user.value?.profile?.neighborhood, user.value?.profile?.city].filter(Boolean).join(', '))
	const seoDescription = computed(() => {
		if (!user.value) {
			return t('seo.userFallbackDescription')
		}

		return truncateText([t('seo.userDescription', { name: user.value.display_name }), profileLocation.value].filter(Boolean).join(' '))
	})
	const existingConversation = computed(() =>
		chatsStore.conversations.find((conversation) => String(conversation.other_user?.id) === String(route.params.id) && conversation.latest_message)
	)

	useSeo(computed(() => ({
		title: user.value?.display_name || t('seo.userFallbackTitle'),
		description: seoDescription.value,
		image: user.value?.profile?.photo_url,
		canonical: route.path,
		type: 'profile',
		robots: user.value ? 'index,follow' : 'noindex,follow',
		jsonLd: user.value ? {
			'@context': 'https://schema.org',
			'@type': 'Person',
			name: user.value.display_name,
			url: absoluteUrl(route.path),
			image: user.value.profile?.photo_url || undefined,
			address: user.value.profile?.city ? {
				'@type': 'PostalAddress',
				addressLocality: user.value.profile.city,
				addressRegion: user.value.profile.neighborhood || undefined
			} : undefined
		} : null
	})))

	async function load() {
		loading.value = true
		try {
			const { data } = await fetchUser(route.params.id)
			user.value = data.data
			if (authStore.isAuthenticated) {
				await chatsStore.loadConversations()
				chatOpen.value = Boolean(existingConversation.value)
			}
		} finally {
			loading.value = false
		}
	}

	function openChat() {
		chatOpen.value = true
	}

	onMounted(load)
</script>

<template>
	<q-page padding class="user-page">
		<div class="page-shell">
			<section v-if="user" class="soz-section-card person-head">
				<q-avatar size="96px" color="primary" text-color="white">
					<img v-if="user.profile?.photo_url" :src="user.profile.photo_url" alt="" />
					<span v-else>{{ user.display_name.slice(0, 1) }}</span>
				</q-avatar>
				<div>
					<h1 class="soz-page-title">{{ user.display_name }}</h1>
					<p>{{ user.profile?.neighborhood || user.profile?.city || '' }}</p>
				</div>
				<q-btn v-if="authStore.isAuthenticated && !chatOpen && authStore.user?.id !== user.id"
					color="primary"
					unelevated
					rounded
					icon="chat"
					:label="t('actions.chat')"
					@click="openChat"
				/>
			</section>

			<section v-if="pages.length" class="page-row q-mt-lg">
				<router-link v-for="page in pages" :key="page.id" :to="{ name: 'page-detail', params: { id: page.id } }" class="public-page-card">
					<q-icon :name="page.type === 'business' ? 'storefront' : 'diversity_3'" size="34px" color="primary" />
					<div>
						<h2>{{ page.name }}</h2>
						<p>{{ page.public_description }}</p>
					</div>
				</router-link>
			</section>

			<section v-if="chatOpen && user" class="soz-section-card chat-panel q-mt-lg">
				<ChatBlock compact :target-user-id="user.id" />
			</section>

			<section v-if="user?.private_ads?.length" class="q-mt-lg">
				<h2>{{ t('ads.private') }}</h2>
				<div class="listing-grid">
					<AdCard v-for="ad in user.private_ads" :key="ad.id" :ad="ad" />
				</div>
			</section>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.user-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.person-head {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) auto;
  gap: 20px;
  align-items: center;
  padding: 28px;
}

.page-row {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

.listing-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

.public-page-card,
.chat-panel {
  padding: 24px;
  border: 1px solid var(--soz-line);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.78);
}

.public-page-card {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 14px;
}

.public-page-card h2 {
  margin: 0 0 8px;
}

@media (max-width: 900px) {
  .person-head {
    grid-template-columns: auto minmax(0, 1fr);
  }

  .person-head .q-btn {
    grid-column: 1 / -1;
    justify-self: start;
  }
}

@media (max-width: 760px) {
  .user-page {
    padding-inline: 10px;
  }

  .person-head,
  .page-row,
  .listing-grid {
    grid-template-columns: 1fr;
  }

  .person-head,
  .public-page-card,
  .chat-panel {
    padding: 20px;
  }

  .person-head .q-btn {
    width: 100%;
  }

  .public-page-card p,
  .person-head p {
    overflow-wrap: anywhere;
  }
}
</style>
