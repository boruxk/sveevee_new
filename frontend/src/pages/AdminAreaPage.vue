<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { banAdminUser, fetchAdminUsers, messageAdminUser, restoreAdminUser } from '@/services/api/admin'
	import { pageRoute } from '@/constants/catalogTopics'
	import { CHAT_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'

	const { t } = useI18n()
	const $q = useQuasar()
	const loading = ref(false)
	const users = ref([])
	const selectedUserId = ref(null)
	const reason = ref('')
	const message = ref('')
	const selectedUser = computed(() => users.value.find((user) => user.id === selectedUserId.value) || users.value[0] || null)

	async function load() {
		loading.value = true
		try {
			const { data } = await fetchAdminUsers()
			users.value = data.data || []
			selectedUserId.value ||= users.value[0]?.id || null
		} finally {
			loading.value = false
		}
	}

	async function ban(user) {
		await banAdminUser(user.id, { reason: reason.value })
		reason.value = ''
		await load()
	}

	async function restore(user) {
		await restoreAdminUser(user.id)
		await load()
	}

	async function sendMessage(user) {
		try {
			await messageAdminUser(user.id, message.value)
			message.value = ''
			$q.notify({ type: 'positive', message: t('admin.messageSent') })
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('admin.messageFailed') })
		}
	}

	onMounted(load)
</script>

<template>
	<q-page padding class="admin-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<div>
					<h1 class="soz-page-title">{{ t('admin.users') }}</h1>
				</div>
			</section>

			<div class="admin-grid q-mt-lg">
				<section class="soz-section-card user-list">
					<button
						v-for="user in users"
						:key="user.id"
						type="button"
						class="user-row"
						:class="{ 'user-row--active': selectedUser?.id === user.id }"
						@click="selectedUserId = user.id"
					>
						<span>
							<strong>{{ user.display_name }}</strong>
							<small>{{ user.email }}</small>
						</span>
						<q-chip dense :color="user.banned_at ? 'negative' : 'positive'" text-color="white">
							{{ user.banned_at ? t('admin.banned') : t('admin.active') }}
						</q-chip>
					</button>
				</section>

				<section v-if="selectedUser" class="soz-section-card detail-panel">
					<div class="row items-start justify-between q-gutter-md">
						<div>
							<h2>{{ selectedUser.display_name }}</h2>
							<p>{{ selectedUser.email }}</p>
							<p>{{ selectedUser.profile?.city || '-' }} / {{ selectedUser.profile?.neighborhood || '-' }}</p>
						</div>
						<q-chip :color="selectedUser.role === 'admin' ? 'dark' : 'primary'" text-color="white">{{ selectedUser.role }}</q-chip>
					</div>

					<div class="page-links q-mt-md">
						<router-link v-if="selectedUser.business_page" :to="pageRoute(selectedUser.business_page)">
							{{ selectedUser.business_page.name }}
						</router-link>
						<router-link v-if="selectedUser.community_page" :to="pageRoute(selectedUser.community_page)">
							{{ selectedUser.community_page.name }}
						</router-link>
					</div>

					<div class="q-mt-lg">
						<q-input
							v-model="message"
							outlined
							type="textarea"
							autogrow
							:label="t('admin.message')"
							:maxlength="CHAT_MAX_LENGTH"
							:hint="characterLimitHint(message, CHAT_MAX_LENGTH, t)"
							counter
							persistent-hint
						/>
						<q-btn class="q-mt-sm"
							color="primary"
							unelevated
							rounded
							icon="send"
							:label="t('actions.send')"
							:disable="!message.trim()"
							@click="sendMessage(selectedUser)"
						/>
					</div>

					<div class="q-mt-lg">
						<div class="text-overline text-negative">{{ t('actions.ban') }}</div>
						<q-input v-model="reason" outlined :label="t('admin.reason')" />
						<div class="row q-gutter-sm q-mt-sm">
							<q-btn v-if="!selectedUser.banned_at"
								color="negative"
								unelevated
								rounded
								icon="block"
								:label="t('actions.ban')"
								:disable="selectedUser.role === 'admin'"
								@click="ban(selectedUser)"
							/>
							<q-btn v-else
								color="positive"
								unelevated
								rounded
								icon="restart_alt"
								:label="t('actions.restore')"
								@click="restore(selectedUser)"
							/>
						</div>
					</div>
				</section>
			</div>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.admin-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.page-head,
.user-list,
.detail-panel {
  padding: 28px;
}

.admin-grid {
  display: grid;
  grid-template-columns: 360px minmax(0, 1fr);
  gap: 18px;
}

.user-list {
  display: grid;
  gap: 8px;
}

.user-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 14px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.64);
  text-align: start;
  cursor: pointer;
}

.user-row--active,
.user-row:hover {
  border-color: rgba(123, 63, 242, 0.38);
  background: rgba(123, 63, 242, 0.08);
}

.user-row span {
  display: grid;
  min-width: 0;
}

.user-row small {
  color: rgba(17, 34, 45, 0.56);
  overflow-wrap: anywhere;
}

.page-links {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
}

.page-links a {
  padding: 10px 12px;
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.74);
}

@media (max-width: 900px) {
  .admin-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 700px) {
  .admin-page {
    padding-inline: 10px;
  }

  .page-head,
  .user-list,
  .detail-panel {
    padding: 20px;
  }

  .user-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .detail-panel p {
    overflow-wrap: anywhere;
  }

  .detail-panel .q-btn {
    width: 100%;
  }
}
</style>
