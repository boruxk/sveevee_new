<script setup>
	import { reactive, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { searchEverything } from '@/services/api/search'
	import AdCard from '@/components/AdCard.vue'

	const { t } = useI18n()
	const loading = ref(false)
	const q = ref('')
	const results = reactive({ users: [], pages: [], ads: [] })

	async function submit() {
		loading.value = true
		try {
			const { data } = await searchEverything(q.value)
			results.users = data.data?.users || []
			results.pages = data.data?.pages || []
			results.ads = data.data?.ads || []
		} finally {
			loading.value = false
		}
	}
</script>

<template>
	<q-page padding class="search-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<div>
					<h1 class="soz-page-title">{{ t('search.title') }}</h1>
				</div>
				<q-form class="search-form" @submit.prevent="submit">
					<q-input v-model="q" outlined clearable :label="t('search.placeholder')" />
					<q-btn color="primary"
						unelevated
						rounded
						type="submit"
						icon="search"
						:loading="loading"
						:label="t('actions.search')"
					/>
				</q-form>
			</section>

			<section class="result-section">
				<h2>{{ t('search.users') }}</h2>
				<div v-if="results.users.length === 0" class="empty-state">{{ t('search.noUsers') }}</div>
				<div v-else class="result-grid">
					<router-link v-for="user in results.users" :key="user.id" :to="{ name: 'user-page', params: { id: user.id } }" class="result-card">
						<q-avatar size="54px" color="primary" text-color="white">
							<img v-if="user.profile?.photo_url" :src="user.profile.photo_url" alt="" />
							<span v-else>{{ user.display_name.slice(0, 1) }}</span>
						</q-avatar>
						<div>
							<strong>{{ user.display_name }}</strong>
							<p>{{ user.profile?.neighborhood || user.profile?.city || '-' }}</p>
						</div>
					</router-link>
				</div>
			</section>

			<section class="result-section">
				<h2>{{ t('search.pages') }}</h2>
				<div v-if="results.pages.length === 0" class="empty-state">{{ t('search.noPages') }}</div>
				<div v-else class="result-grid">
					<router-link v-for="page in results.pages" :key="page.id" :to="{ name: 'page-detail', params: { id: page.id } }" class="result-card">
						<q-icon :name="page.type === 'business' ? 'storefront' : 'diversity_3'" size="32px" color="primary" />
						<div>
							<strong>{{ page.name }}</strong>
							<p>{{ page.public_description || '-' }}</p>
						</div>
					</router-link>
				</div>
			</section>

			<section class="result-section">
				<h2>{{ t('search.ads') }}</h2>
				<div v-if="results.ads.length === 0" class="empty-state">{{ t('ads.empty') }}</div>
				<div v-else class="ad-grid">
					<AdCard v-for="ad in results.ads" :key="ad.id" :ad="ad" />
				</div>
			</section>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.search-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.page-head {
  display: grid;
  grid-template-columns: 0.8fr 1.2fr;
  gap: 18px;
  align-items: end;
  padding: 28px;
}

.search-form {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
}

.result-section {
  margin-top: 22px;
}

.result-grid,
.ad-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}

.result-card {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 14px;
  align-items: center;
  padding: 18px;
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.76);
}

.result-card p {
  overflow: hidden;
  margin: 4px 0 0;
  color: rgba(17, 34, 45, 0.62);
  text-overflow: ellipsis;
  white-space: nowrap;
}

.empty-state {
  color: rgba(17, 34, 45, 0.58);
}

@media (max-width: 900px) {
  .page-head,
  .search-form,
  .result-grid,
  .ad-grid {
    grid-template-columns: 1fr;
  }
}
</style>
