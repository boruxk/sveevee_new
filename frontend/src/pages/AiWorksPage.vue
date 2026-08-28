<script setup>
	import { computed, onMounted, reactive, ref, toRef } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import {
		createAiWorkPage,
		createAiWorkTask,
		deleteAiWorkPage,
		deleteAiWorkTask,
		fetchAiWorkPages,
		fetchAiWorkTasks,
		updateAiWorkPage,
		updateAiWorkTask
	} from '@/services/api/aiWorks'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { absoluteUrl } from '@/composables/useSeo'
	import { CATALOG_SCOPES, catalogLabel, catalogTopicByKey, publicPagePath } from '@/constants/catalogTopics'
	import { findPresencePalette, presencePalettes } from '@/constants/presencePalettes'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'
	import UnclaimedPageIcon from '@/components/icons/UnclaimedPageIcon.vue'
	import PagePreview from '@/components/pages/PagePreview.vue'

	const DEFAULT_OPENING_HOURS = [
		{ weekday: 'sunday', is_open: false, opens_at: null, closes_at: null },
		{ weekday: 'monday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'tuesday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'wednesday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'thursday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'friday', is_open: true, opens_at: '09:00', closes_at: '13:00' },
		{ weekday: 'saturday', is_open: false, opens_at: null, closes_at: null }
	]

	const { t, locale } = useI18n()
	const $q = useQuasar()
	const activeTab = ref('tasks')
	const workspaceTab = ref('settings')
	const tasks = ref([])
	const pages = ref([])
	const selectedPageId = ref(null)
	const tasksLoading = ref(false)
	const pagesLoading = ref(false)
	const taskSaving = ref(false)
	const pageSaving = ref(false)
	const deleting = ref(false)
	const taskDialogOpen = ref(false)
	const deleteDialogOpen = ref(false)
	const deleteTarget = ref(null)
	const taskFormRef = ref(null)
	const pageFormRef = ref(null)
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
	const taskForm = reactive({ id: null, title: '', text: '' })
	const pageForm = reactive(emptyPageForm())
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		addOption,
		filterOptions
	} = useLocationOptions(toRef(pageForm.address, 'city'))
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const selectedPage = computed(() => pages.value.find((page) => page.id === selectedPageId.value) || null)
	const selectedPalette = computed(() => findPresencePalette(pageForm.palette_key))
	const previewPage = computed(() => ({
		id: selectedPage.value?.id,
		type: 'business',
		name: pageForm.name,
		public_description: pageForm.public_description,
		contact_email: pageForm.contact_email,
		phone: pageForm.phone,
		contact: {
			tel: pageForm.phone,
			email: pageForm.contact_email,
			whatsapp: pageForm.whatsapp
		},
		address_details: { ...pageForm.address },
		socials: { ...pageForm.socials },
		opening_hours: pageForm.opening_hours.map((item) => ({ ...item })),
		website: pageForm.website,
		category_key: pageForm.category_key || null,
		palette_key: pageForm.palette_key,
		is_unclaimed: true,
		features: { store: false, services: false, events: false, price_list: false },
		logo_url: null,
		banner_url: null,
		rating_summary: selectedPage.value?.rating_summary || { average: 0, count: 0 }
	}))
	const previewShareUrl = computed(() => selectedPage.value ? absoluteUrl(publicPagePath(selectedPage.value, locale.value)) : '')
	const taskDialogTitle = computed(() => taskForm.id ? t('aiWorks.tasks.edit') : t('aiWorks.tasks.create'))

	function today() {
		return new Date().toISOString().slice(0, 10)
	}

	function emptyPageForm() {
		return {
			name: '',
			public_description: '',
			contact_email: '',
			phone: '',
			whatsapp: '',
			website: '',
			category_key: '',
			palette_key: presencePalettes[0].key,
			source_url: '',
			source_checked_at: today(),
			address: { street: '', number: '', city: '', neighborhood: '' },
			socials: { facebook: '', instagram: '', tiktok: '', telegram: '' },
			opening_hours: DEFAULT_OPENING_HOURS.map((item) => ({ ...item }))
		}
	}

	function replacePageForm(value = null) {
		const empty = emptyPageForm()
		const contact = value?.contact || {}
		const address = value?.address_details || {}
		const socials = value?.socials || {}

		Object.assign(pageForm, empty, {
			name: value?.name || '',
			public_description: value?.public_description || '',
			contact_email: contact.email || value?.contact_email || '',
			phone: contact.tel || value?.phone || '',
			whatsapp: contact.whatsapp || '',
			website: value?.website || '',
			category_key: value?.category_key || '',
			palette_key: value?.palette_key || presencePalettes[0].key,
			source_url: value?.source_url || '',
			source_checked_at: value?.source_checked_at || today(),
			address: { ...empty.address, ...address },
			socials: { ...empty.socials, ...socials },
			opening_hours: normalizedOpeningHours(value?.opening_hours)
		})
	}

	function normalizedOpeningHours(value) {
		const byDay = new Map((Array.isArray(value) ? value : []).map((item) => [item.weekday, item]))

		return DEFAULT_OPENING_HOURS.map((fallback) => ({
			...fallback,
			...(byDay.get(fallback.weekday) || {})
		}))
	}

	function selectPage(page) {
		selectedPageId.value = page?.id || null
		replacePageForm(page)
		workspaceTab.value = 'settings'
	}

	function createPageDraft() {
		selectedPageId.value = null
		replacePageForm()
		workspaceTab.value = 'settings'
	}

	function pagePayload() {
		return {
			name: pageForm.name.trim(),
			public_description: pageForm.public_description.trim() || null,
			contact_email: pageForm.contact_email.trim() || null,
			phone: pageForm.phone.trim() || null,
			whatsapp: pageForm.whatsapp.trim() || null,
			website: pageForm.website.trim() || null,
			category_key: pageForm.category_key,
			palette_key: pageForm.palette_key,
			source_url: pageForm.source_url.trim(),
			source_checked_at: pageForm.source_checked_at,
			address: Object.fromEntries(Object.entries(pageForm.address).map(([key, value]) => [key, String(value || '').trim() || null])),
			socials: Object.fromEntries(Object.entries(pageForm.socials).map(([key, value]) => [key, String(value || '').trim() || null])),
			opening_hours: pageForm.opening_hours.map((item) => ({
				weekday: item.weekday,
				is_open: Boolean(item.is_open),
				opens_at: item.is_open ? item.opens_at || null : null,
				closes_at: item.is_open ? item.closes_at || null : null
			}))
		}
	}

	async function loadTasks() {
		tasksLoading.value = true
		try {
			const { data } = await fetchAiWorkTasks()
			tasks.value = data.data?.tasks || []
		} finally {
			tasksLoading.value = false
		}
	}

	async function loadPages({ preserveSelection = true, selectFirst = false } = {}) {
		pagesLoading.value = true
		try {
			const currentId = preserveSelection ? selectedPageId.value : null
			const { data } = await fetchAiWorkPages()
			pages.value = data.data?.pages || []
			const next = pages.value.find((page) => page.id === currentId) || (selectFirst ? pages.value[0] : null)

			if (next) {
				selectPage(next)
			} else {
				createPageDraft()
			}
		} finally {
			pagesLoading.value = false
		}
	}

	function openTask(task = null) {
		taskForm.id = task?.id || null
		taskForm.title = task?.title || ''
		taskForm.text = task?.text || ''
		taskDialogOpen.value = true
	}

	async function saveTask() {
		if (!(await validateRequiredForm(taskFormRef))) {
			return
		}

		taskSaving.value = true
		try {
			const payload = { title: taskForm.title.trim(), text: taskForm.text.trim() }
			if (taskForm.id) {
				await updateAiWorkTask(taskForm.id, payload)
			} else {
				await createAiWorkTask(payload)
			}
			taskDialogOpen.value = false
			await loadTasks()
			$q.notify({ type: 'positive', message: t('aiWorks.tasks.saved') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('aiWorks.tasks.saveFailed')) })
		} finally {
			taskSaving.value = false
		}
	}

	async function savePage() {
		if (!(await validateRequiredForm(pageFormRef))) {
			workspaceTab.value = 'settings'
			return
		}

		pageSaving.value = true
		try {
			const { data } = selectedPageId.value ? await updateAiWorkPage(selectedPageId.value, pagePayload()) : await createAiWorkPage(pagePayload())
			const saved = data.data
			selectedPageId.value = saved.id
			rememberLocation(pageForm.address.city, pageForm.address.neighborhood)
			await loadPages()
			$q.notify({ type: 'positive', message: t('pages.saved') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('pages.saveFailed')) })
		} finally {
			pageSaving.value = false
		}
	}

	function requestDelete(type, item) {
		deleteTarget.value = { type, item }
		deleteDialogOpen.value = true
	}

	async function confirmDelete() {
		if (!deleteTarget.value || deleting.value) {
			return
		}

		const returnToPagesTable = deleteTarget.value.type === 'page' && workspaceTab.value === 'unclaimed-pages'
		deleting.value = true
		try {
			if (deleteTarget.value.type === 'task') {
				await deleteAiWorkTask(deleteTarget.value.item.id)
				await loadTasks()
			} else {
				await deleteAiWorkPage(deleteTarget.value.item.id)
				selectedPageId.value = null
				await loadPages({ preserveSelection: false })
				if (returnToPagesTable) {
					workspaceTab.value = 'unclaimed-pages'
				}
			}
			deleteDialogOpen.value = false
			deleteTarget.value = null
			$q.notify({ type: 'positive', message: t('aiWorks.deleted') })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('aiWorks.deleteFailed')) })
		} finally {
			deleting.value = false
		}
	}

	function dayLabel(weekday) {
		return t(`pages.weekdays.${weekday}`)
	}

	function categoryLabel(categoryKey) {
		const topic = catalogTopicByKey(catalogGroups.value, categoryKey)

		return topic ? catalogLabel(topic.labels, locale.value) : categoryKey || '—'
	}

	function sourceLabel(value) {
		try {
			return new URL(value).hostname.replace(/^www\./, '')
		} catch {
			return value || '—'
		}
	}

	function checkedDate(value) {
		return String(value || '').slice(0, 10) || '—'
	}

	function filterCityOptions(value, update) {
		filterOptions(cityOptions.value, value, update, (options) => { citySelectOptions.value = options })
	}

	function filterNeighborhoodOptions(value, update) {
		filterOptions(neighborhoodOptions.value, value, update, (options) => { neighborhoodSelectOptions.value = options })
	}

	onMounted(async() => {
		await Promise.all([loadTasks(), loadPages(), loadCatalogTopics(), loadLocationOptions()])
		citySelectOptions.value = cityOptions.value
		neighborhoodSelectOptions.value = neighborhoodOptions.value
	})
</script>

<template>
	<q-page padding class="ai-works-page">
		<div class="ai-works-shell">
			<header class="ai-works-head">
				<div>
					<h1>{{ t('aiWorks.title') }}</h1>
					<p>{{ t('aiWorks.intro') }}</p>
				</div>
			</header>

			<q-tabs v-model="activeTab"
				inline-label
				no-caps
				class="ai-main-tabs"
				active-color="primary"
				indicator-color="transparent"
			>
				<q-tab name="tasks" icon="task_alt" :label="t('aiWorks.tabs.tasks')" />
				<q-tab name="pages" icon="storefront" :label="t('aiWorks.tabs.pages')" />
			</q-tabs>

			<q-tab-panels v-model="activeTab" animated class="ai-main-panels">
				<q-tab-panel name="tasks">
					<section class="soz-section-card ai-panel">
						<header class="ai-panel-head">
							<div>
								<h2>{{ t('aiWorks.tasks.title') }}</h2>
								<p>{{ t('aiWorks.tasks.intro') }}</p>
							</div>
							<q-btn rounded
								unelevated
								color="primary"
								icon="add"
								:label="t('aiWorks.tasks.create')"
								@click="openTask()"
							/>
						</header>

						<q-inner-loading :showing="tasksLoading" color="primary" />
						<div v-if="!tasksLoading && tasks.length" class="task-list">
							<article v-for="task in tasks" :key="task.id" class="task-row">
								<div>
									<h3>{{ task.title }}</h3>
									<p>{{ task.text }}</p>
								</div>
								<div class="task-row__actions">
									<q-btn flat round icon="edit" :aria-label="t('actions.update')" @click="openTask(task)" />
									<q-btn flat
										round
										color="negative"
										icon="delete"
										:aria-label="t('actions.delete')"
										@click="requestDelete('task', task)"
									/>
								</div>
							</article>
						</div>
						<div v-else-if="!tasksLoading" class="ai-empty">{{ t('aiWorks.tasks.empty') }}</div>
					</section>
				</q-tab-panel>

				<q-tab-panel name="pages">
					<div class="page-workspace">
						<section class="page-editor">
							<div class="page-editor-toolbar">
								<q-tabs v-model="workspaceTab"
									inline-label
									no-caps
									class="workspace-tabs"
									active-color="primary"
									indicator-color="transparent"
								>
									<q-tab name="settings" icon="settings" :label="t('pages.tabs.settings')" />
									<q-tab name="preview" icon="visibility" :label="t('pages.tabs.preview')" />
									<q-tab name="unclaimed-pages">
										<span class="workspace-tab-content">
											<UnclaimedPageIcon :size="20" />
											<span>{{ t('aiWorks.pages.list') }}</span>
										</span>
									</q-tab>
								</q-tabs>
								<div class="page-editor-actions">
									<q-btn v-if="selectedPage"
										flat
										round
										icon="open_in_new"
										:to="selectedPage.public_path"
										target="_blank"
										:aria-label="t('admin.openClaimPage')"
									/>
									<q-btn v-if="selectedPage"
										flat
										round
										color="negative"
										icon="delete"
										:aria-label="t('actions.deletePage')"
										@click="requestDelete('page', selectedPage)"
									/>
								</div>
							</div>

							<q-tab-panels v-model="workspaceTab" animated class="workspace-panels">
								<q-tab-panel name="settings">
									<q-form ref="pageFormRef" greedy class="page-form" @submit.prevent="savePage">
										<section class="ai-info-banner">
											<UnclaimedPageIcon />
											<span>{{ t('aiWorks.pages.infoOnly') }}</span>
										</section>

										<q-input v-model="pageForm.name" outlined :label="requiredLabel('pages.name')" :rules="[requiredRule]" />
										<q-input v-model="pageForm.public_description"
											outlined
											type="textarea"
											autogrow
											:input-style="{ minHeight: '150px' }"
											:label="t('pages.description')"
										/>
										<CatalogCategorySelect v-model="pageForm.category_key" :groups="catalogGroups" :scope="CATALOG_SCOPES.BUSINESS_PAGES" required :label="requiredLabel('catalog.category')" />

										<section class="form-segment">
											<h3>{{ t('aiWorks.pages.source') }}</h3>
											<div class="form-grid form-grid--two">
												<q-input v-model="pageForm.source_url" outlined inputmode="url" :label="requiredLabel('aiWorks.pages.sourceUrl')" :rules="[requiredRule]" />
												<q-input v-model="pageForm.source_checked_at" outlined type="date" :label="requiredLabel('aiWorks.pages.checkedAt')" :rules="[requiredRule]" />
											</div>
										</section>

										<section class="form-segment">
											<h3>{{ t('pages.sections.contact') }}</h3>
											<div class="form-grid form-grid--three">
												<q-input v-model="pageForm.phone" outlined :label="t('pages.tel')" />
												<q-input v-model="pageForm.contact_email" outlined type="email" :label="t('pages.email')" />
												<q-input v-model="pageForm.whatsapp" outlined :label="t('pages.whatsapp')" />
											</div>
											<q-input v-model="pageForm.website" outlined clearable inputmode="url" :label="t('pages.website')" />
										</section>

										<section class="form-segment">
											<h3>{{ t('pages.sections.address') }}</h3>
											<div class="form-grid form-grid--address">
												<q-input v-model="pageForm.address.street" outlined :label="t('pages.street')" />
												<q-input v-model="pageForm.address.number" outlined :label="t('pages.number')" />
												<q-select v-model="pageForm.address.city"
													outlined
													clearable
													emit-value
													map-options
													use-input
													hide-selected
													fill-input
													input-debounce="0"
													new-value-mode="add-unique"
													:options="citySelectOptions"
													:label="requiredLabel('pages.city')"
													:rules="[requiredRule]"
													@filter="filterCityOptions"
													@new-value="addOption"
												/>
												<q-select v-model="pageForm.address.neighborhood"
													outlined
													clearable
													emit-value
													map-options
													use-input
													hide-selected
													fill-input
													input-debounce="0"
													new-value-mode="add-unique"
													:options="neighborhoodSelectOptions"
													:label="t('auth.neighborhood')"
													:disable="!pageForm.address.city"
													@filter="filterNeighborhoodOptions"
													@new-value="addOption"
												/>
											</div>
										</section>

										<section class="form-segment">
											<h3>{{ t('pages.sections.socials') }}</h3>
											<div class="form-grid form-grid--four">
												<q-input v-model="pageForm.socials.facebook" outlined label="Facebook" />
												<q-input v-model="pageForm.socials.instagram" outlined label="Instagram" />
												<q-input v-model="pageForm.socials.tiktok" outlined label="TikTok" />
												<q-input v-model="pageForm.socials.telegram" outlined label="Telegram" />
											</div>
										</section>

										<section class="form-segment">
											<h3>{{ t('pages.sections.openingHours') }}</h3>
											<div class="hours-grid">
												<div v-for="item in pageForm.opening_hours" :key="item.weekday" class="hours-row">
													<strong>{{ dayLabel(item.weekday) }}</strong>
													<q-toggle v-model="item.is_open" :label="item.is_open ? t('pages.open') : t('pages.closed')" color="primary" />
													<q-input v-model="item.opens_at" outlined type="time" :disable="!item.is_open" :label="t('pages.opensAt')" />
													<q-input v-model="item.closes_at" outlined type="time" :disable="!item.is_open" :label="t('pages.closesAt')" />
												</div>
											</div>
										</section>

										<section class="form-segment">
											<h3>{{ t('pages.palette') }}</h3>
											<div class="palette-grid">
												<button v-for="palette in presencePalettes"
													:key="palette.key"
													type="button"
													class="palette-option"
													:class="{ 'palette-option--active': palette.key === pageForm.palette_key }"
													:aria-label="t(palette.nameKey)"
													@click="pageForm.palette_key = palette.key"
												>
													<span :style="{ background: palette.hero }" />
												</button>
											</div>
										</section>

										<div class="save-row">
											<q-btn rounded
												unelevated
												color="primary"
												type="submit"
												icon="save"
												:loading="pageSaving"
												:label="t('pages.saveSettings')"
											/>
										</div>
									</q-form>
								</q-tab-panel>

								<q-tab-panel name="preview">
									<PagePreview
										:page="previewPage"
										:palette="selectedPalette"
										:can-rate="false"
										:can-chat="false"
										:show-ratings="false"
										title-tag="h2"
										:share-url="previewShareUrl"
									/>
								</q-tab-panel>

								<q-tab-panel name="unclaimed-pages">
									<section class="page-table-card">
										<q-inner-loading :showing="pagesLoading" color="primary" />
										<div v-if="pages.length" class="page-table-scroll">
											<table class="page-table">
												<thead>
													<tr>
														<th>{{ t('pages.name') }}</th>
														<th>{{ t('auth.city') }}</th>
														<th>{{ t('catalog.category') }}</th>
														<th>{{ t('aiWorks.pages.source') }}</th>
														<th>{{ t('aiWorks.pages.checkedAt') }}</th>
														<th class="page-table__actions-heading">{{ t('admin.actions') }}</th>
													</tr>
												</thead>
												<tbody>
													<tr v-for="item in pages" :key="item.id">
														<td><strong>{{ item.name }}</strong></td>
														<td>{{ item.address_details?.city || '—' }}</td>
														<td>{{ categoryLabel(item.category_key) }}</td>
														<td>
															<a
																v-if="item.source_url"
																class="page-table__source"
																:href="item.source_url"
																target="_blank"
																rel="noopener noreferrer"
															>
																{{ sourceLabel(item.source_url) }}
															</a>
															<span v-else>—</span>
														</td>
														<td>{{ checkedDate(item.source_checked_at) }}</td>
														<td>
															<div class="page-table__actions">
																<q-btn
																	flat
																	round
																	color="primary"
																	icon="edit"
																	:aria-label="t('actions.update')"
																	@click="selectPage(item)"
																/>
																<q-btn
																	flat
																	round
																	color="negative"
																	icon="delete"
																	:aria-label="t('actions.deletePage')"
																	@click="requestDelete('page', item)"
																/>
															</div>
														</td>
													</tr>
												</tbody>
											</table>
										</div>
										<div v-else-if="!pagesLoading" class="ai-empty">{{ t('aiWorks.pages.empty') }}</div>
									</section>
								</q-tab-panel>
							</q-tab-panels>
						</section>
					</div>
				</q-tab-panel>
			</q-tab-panels>
		</div>

		<q-dialog v-model="taskDialogOpen">
			<q-card class="ai-dialog">
				<q-card-section><h2>{{ taskDialogTitle }}</h2></q-card-section>
				<q-card-section>
					<q-form ref="taskFormRef" greedy class="task-form" @submit.prevent="saveTask">
						<q-input v-model="taskForm.title" outlined :label="requiredLabel('aiWorks.tasks.fields.title')" :rules="[requiredRule]" />
						<q-input v-model="taskForm.text"
							outlined
							type="textarea"
							autogrow
							:input-style="{ minHeight: '180px' }"
							:label="requiredLabel('aiWorks.tasks.fields.text')"
							:rules="[requiredRule]"
						/>
					</q-form>
				</q-card-section>
				<q-card-actions align="right">
					<q-btn flat rounded :label="t('actions.cancel')" v-close-popup />
					<q-btn rounded
						unelevated
						color="primary"
						:loading="taskSaving"
						:label="t('actions.save')"
						@click="saveTask"
					/>
				</q-card-actions>
			</q-card>
		</q-dialog>

		<q-dialog v-model="deleteDialogOpen">
			<q-card class="ai-dialog ai-dialog--confirm">
				<q-card-section>
					<h2>{{ t('aiWorks.deleteTitle') }}</h2>
					<p>{{ t('aiWorks.deleteBody') }}</p>
				</q-card-section>
				<q-card-actions align="right">
					<q-btn flat rounded :label="t('actions.cancel')" v-close-popup />
					<q-btn rounded
						unelevated
						color="negative"
						:loading="deleting"
						:label="t('actions.delete')"
						@click="confirmDelete"
					/>
				</q-card-actions>
			</q-card>
		</q-dialog>
	</q-page>
</template>

<style scoped lang="scss">
.ai-works-page {
  padding: 0 20px 40px;
}

.ai-works-shell {
  width: min(1440px, 100%);
  margin: 0 auto;
}

.ai-works-head {
  padding: 10px 6px 18px;
}

.ai-works-head h1,
.ai-panel-head h2,
.ai-dialog h2 {
  margin: 0;
  color: var(--soz-ink);
}

.ai-works-head h1 {
  font-size: clamp(2rem, 4vw, 3.2rem);
}

.ai-works-head p,
.ai-panel-head p,
.ai-dialog p {
  margin: 6px 0 0;
  color: var(--soz-muted);
}

.ai-main-tabs,
.workspace-tabs {
  width: max-content;
  max-width: 100%;
  padding: 6px;
  border: 1px solid rgba(123, 63, 242, 0.14);
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.72);
}

.ai-main-tabs :deep(.q-tab),
.workspace-tabs :deep(.q-tab) {
  min-height: 46px;
  border-radius: 13px;
}

.ai-main-tabs :deep(.q-tab--active),
.workspace-tabs :deep(.q-tab--active) {
  background: var(--soz-action-gradient);
  color: #fff !important;
}

.workspace-tab-content {
  display: inline-flex;
  gap: 8px;
  align-items: center;
}

.ai-main-panels,
.workspace-panels {
  margin-top: 16px;
  overflow: visible;
  background: transparent;
}

.ai-main-panels :deep(> .q-panel > .q-tab-panel),
.workspace-panels :deep(> .q-panel > .q-tab-panel) {
  padding: 0;
}

.ai-panel {
  position: relative;
  min-height: 260px;
  padding: 24px;
}

.ai-panel-head,
.page-editor-toolbar {
  display: flex;
  gap: 16px;
  align-items: center;
  justify-content: space-between;
}

.task-list {
  display: grid;
  gap: 12px;
  margin-top: 22px;
}

.task-row {
  display: flex;
  gap: 16px;
  align-items: start;
  justify-content: space-between;
  padding: 18px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 16px;
  background: rgba(17, 34, 45, 0.035);
}

.task-row h3,
.form-segment h3 {
  margin: 0;
  color: var(--soz-ink);
}

.task-row p {
  margin: 6px 0 0;
  color: var(--soz-muted);
  white-space: pre-line;
}

.task-row__actions {
  display: flex;
  flex: 0 0 auto;
}

.ai-empty {
  padding: 50px 20px;
  color: var(--soz-muted);
  text-align: center;
}

.page-workspace {
  width: 100%;
}

.page-editor {
  min-width: 0;
}

.page-editor-toolbar {
  margin-bottom: 16px;
}

.page-editor-actions {
  display: flex;
}

.page-table-card {
  position: relative;
  min-height: 220px;
  padding: 18px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.78);
  box-shadow: 0 18px 42px rgba(40, 22, 93, 0.08);
}

.page-table-scroll {
  overflow-x: auto;
}

.page-table {
  width: 100%;
  min-width: 800px;
  border-collapse: collapse;
  color: var(--soz-ink);
}

.page-table th,
.page-table td {
  padding: 14px 12px;
  border-bottom: 1px solid rgba(17, 34, 45, 0.08);
  text-align: start;
  vertical-align: middle;
}

.page-table th {
  color: var(--soz-muted);
  font-size: 0.78rem;
  font-weight: 750;
  text-transform: uppercase;
}

.page-table tbody tr:last-child td {
  border-bottom: 0;
}

.page-table tbody tr:hover {
  background: rgba(123, 63, 242, 0.045);
}

.page-table__source {
  color: var(--soz-primary-deep);
  font-weight: 700;
  text-decoration: none;
}

.page-table__source:hover {
  text-decoration: underline;
}

.page-table__actions-heading {
  width: 108px;
}

.page-table__actions {
  display: flex;
  justify-content: flex-end;
}

.page-form {
  display: grid;
  gap: 18px;
  padding: 24px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.78);
  box-shadow: 0 18px 42px rgba(40, 22, 93, 0.08);
}

.ai-info-banner {
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 14px 16px;
  border: 1px solid rgba(123, 63, 242, 0.18);
  border-radius: 16px;
  background: rgba(123, 63, 242, 0.08);
  color: var(--soz-primary-deep);
  font-weight: 750;
}

.form-segment {
  display: grid;
  gap: 12px;
  padding-top: 4px;
}

.form-grid {
  display: grid;
  gap: 12px;
}

.form-grid--two {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.form-grid--three {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}

.form-grid--four,
.form-grid--address {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}

.hours-grid {
  display: grid;
  gap: 10px;
}

.hours-row {
  display: grid;
  grid-template-columns: minmax(110px, 0.8fr) minmax(130px, 0.8fr) 1fr 1fr;
  gap: 12px;
  align-items: center;
  padding: 10px 12px;
  border-radius: 14px;
  background: rgba(17, 34, 45, 0.035);
}

.palette-grid {
  display: grid;
  grid-template-columns: repeat(8, minmax(42px, 1fr));
  gap: 9px;
}

.palette-option {
  aspect-ratio: 1;
  padding: 4px;
  border: 2px solid transparent;
  border-radius: 12px;
  background: transparent;
  cursor: pointer;
}

.palette-option span {
  display: block;
  width: 100%;
  height: 100%;
  border-radius: 8px;
}

.palette-option--active {
  border-color: var(--soz-primary);
}

.save-row {
  display: flex;
  justify-content: flex-end;
}

.ai-dialog {
  width: min(620px, calc(100vw - 28px));
  border-radius: 22px;
}

.ai-dialog--confirm {
  width: min(440px, calc(100vw - 28px));
}

.task-form {
  display: grid;
  gap: 12px;
}

@media (max-width: 900px) {
  .form-grid--three,
  .form-grid--four,
  .form-grid--address {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 700px) {
  .ai-works-page {
    padding-inline: 10px;
  }

  .ai-panel,
  .page-form {
    padding: 16px;
  }

  .ai-panel-head,
  .page-editor-toolbar,
  .task-row {
    align-items: stretch;
  }

  .ai-panel-head,
  .task-row {
    flex-direction: column;
  }

  .form-grid--two,
  .form-grid--three,
  .form-grid--four,
  .form-grid--address {
    grid-template-columns: 1fr;
  }

  .hours-row {
    grid-template-columns: 1fr 1fr;
  }

  .palette-grid {
    grid-template-columns: repeat(4, minmax(42px, 1fr));
  }

  .ai-main-tabs,
  .workspace-tabs {
    width: 100%;
  }
}
</style>
