<script setup>
	import { computed, onMounted, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { banAdminUser, fetchAdminUserTable, fetchAdminUsers, messageAdminUser, restoreAdminUser } from '@/services/api/admin'
	import { pageRoute } from '@/constants/catalogTopics'
	import { CHAT_MAX_LENGTH, characterLimitHint } from '@/constants/textLimits'

	const { t } = useI18n()
	const $q = useQuasar()
	const loading = ref(false)
	const tableLoading = ref(false)
	const activeTab = ref('communication')
	const users = ref([])
	const userRows = ref([])
	const selectedUserId = ref(null)
	const message = ref('')
	const tablePagination = ref({
		page: 1,
		rowsPerPage: 50,
		rowsNumber: 0
	})
	const selectedUser = computed(() => users.value.find((user) => user.id === selectedUserId.value) || users.value[0] || null)
	const userColumns = computed(() => [
		{
			name: 'name',
			label: t('admin.name'),
			align: 'left',
			field: (user) => user.display_name || user.name || '-',
			sortable: false
		},
		{
			name: 'email',
			label: t('auth.email'),
			align: 'left',
			field: (user) => user.email || '-',
			sortable: false
		},
		{
			name: 'city',
			label: t('auth.city'),
			align: 'left',
			field: (user) => user.profile?.city || '-',
			sortable: false
		},
		{
			name: 'status',
			label: t('admin.status'),
			align: 'left',
			field: (user) => user.banned_at ? t('admin.banned') : t('admin.active'),
			sortable: false
		},
		{
			name: 'actions',
			label: t('admin.actions'),
			align: 'right',
			field: 'actions',
			sortable: false
		}
	])

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

	async function loadUserTable(page = tablePagination.value.page) {
		tableLoading.value = true
		try {
			const { data } = await fetchAdminUserTable({ page })
			const payload = data.data || {}
			const pagination = payload.pagination || {}

			userRows.value = payload.items || []
			tablePagination.value = {
				page: pagination.current_page || page,
				rowsPerPage: pagination.per_page || 50,
				rowsNumber: pagination.total || userRows.value.length
			}
		} finally {
			tableLoading.value = false
		}
	}

	async function reloadAfterModeration() {
		await Promise.all([
			load(),
			loadUserTable(tablePagination.value.page)
		])
	}

	async function ban(user) {
		await banAdminUser(user.id, {})
		await reloadAfterModeration()
	}

	async function restore(user) {
		await restoreAdminUser(user.id)
		await reloadAfterModeration()
	}

	function onTableRequest({ pagination }) {
		loadUserTable(pagination.page || 1)
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

	onMounted(() => {
		load()
		loadUserTable()
	})
</script>

<template>
	<q-page padding class="admin-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<div>
					<h1 class="soz-page-title">{{ t('admin.users') }}</h1>
				</div>
			</section>

			<q-tabs
				v-model="activeTab"
				class="admin-tabs q-mt-lg"
				active-color="primary"
				indicator-color="primary"
				align="left"
				dense
			>
				<q-tab name="communication" icon="forum" :label="t('admin.communication')" />
				<q-tab name="users" icon="manage_accounts" :label="t('admin.userTable')" />
			</q-tabs>

			<q-tab-panels v-model="activeTab" animated class="admin-panels">
				<q-tab-panel name="communication" class="admin-panel">
					<div class="admin-grid">
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
						</section>
					</div>
				</q-tab-panel>

				<q-tab-panel name="users" class="admin-panel">
					<section class="soz-section-card table-panel">
						<q-table
							v-model:pagination="tablePagination"
							flat
							:rows="userRows"
							:columns="userColumns"
							row-key="id"
							:loading="tableLoading"
							:rows-per-page-options="[50]"
							binary-state-sort
							@request="onTableRequest"
						>
							<template #body-cell-name="props">
								<q-td :props="props">
									<div class="table-name">
										<strong>{{ props.row.display_name || props.row.name || '-' }}</strong>
										<small>{{ props.row.role }}</small>
									</div>
								</q-td>
							</template>

							<template #body-cell-email="props">
								<q-td :props="props" class="table-email">
									{{ props.row.email || '-' }}
								</q-td>
							</template>

							<template #body-cell-status="props">
								<q-td :props="props">
									<q-chip dense :color="props.row.banned_at ? 'negative' : 'positive'" text-color="white">
										{{ props.row.banned_at ? t('admin.banned') : t('admin.active') }}
									</q-chip>
								</q-td>
							</template>

							<template #body-cell-actions="props">
								<q-td :props="props">
									<q-btn v-if="!props.row.banned_at"
										color="negative"
										unelevated
										rounded
										icon="block"
										:label="t('actions.ban')"
										:disable="props.row.role === 'admin'"
										@click="ban(props.row)"
									/>
									<q-btn v-else
										color="positive"
										unelevated
										rounded
										icon="restart_alt"
										:label="t('admin.unban')"
										@click="restore(props.row)"
									/>
								</q-td>
							</template>
						</q-table>
					</section>
				</q-tab-panel>
			</q-tab-panels>
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

.admin-tabs {
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.7);
  box-shadow: 0 14px 34px rgba(123, 63, 242, 0.08);
}

.admin-panels {
  background: transparent;
}

.admin-panel {
  padding: 18px 0 0;
}

.table-panel {
  padding: 8px;
  overflow: hidden;
}

.table-name {
  display: grid;
  gap: 2px;
  min-width: 0;
}

.table-name small {
  color: rgba(17, 34, 45, 0.54);
}

.table-email {
  overflow-wrap: anywhere;
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
