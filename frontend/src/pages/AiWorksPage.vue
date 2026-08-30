<script setup>
	import { computed, onMounted, reactive, ref, watch } from 'vue'
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
	import { CATALOG_SCOPES, catalogGroupsForScope, catalogLabel, catalogTopicByKey } from '@/constants/catalogTopics'
	import { presencePalettes } from '@/constants/presencePalettes'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import UnclaimedPageIcon from '@/components/icons/UnclaimedPageIcon.vue'

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
	const pageSaveError = ref('')
	const pageValidationErrors = ref({})
	const deleting = ref(false)
	const taskDialogOpen = ref(false)
	const deleteDialogOpen = ref(false)
	const deleteTarget = ref(null)
	const taskFormRef = ref(null)
	const pageFormRef = ref(null)
	const taskForm = reactive({ id: null, title: '', text: '' })
	const pageForm = reactive(emptyPageForm())
	const pageCity = computed(() => pageForm.address.city)
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		hasOptionValue
	} = useLocationOptions(pageCity)
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const selectedPage = computed(() => pages.value.find((page) => page.id === selectedPageId.value) || null)
	const pageCategoryScope = computed(() => pageForm.type === 'community' ? CATALOG_SCOPES.COMMUNITY_PAGES : CATALOG_SCOPES.BUSINESS_PAGES)
	const pageCategoryGroups = computed(() => catalogGroupsForScope(catalogGroups.value, pageCategoryScope.value))
	const pageTypeOptions = computed(() => [
		{ label: t('pages.kinds.business'), value: 'business', icon: 'storefront' },
		{ label: t('pages.kinds.community'), value: 'community', icon: 'diversity_3' }
	])
	const taskDialogTitle = computed(() => taskForm.id ? t('aiWorks.tasks.edit') : t('aiWorks.tasks.create'))
	const pageValidationItems = computed(() => Object.entries(pageValidationErrors.value)
		.flatMap(([field, messages]) => (Array.isArray(messages) ? messages : [messages])
			.filter(Boolean)
			.map((message) => ({ field, message }))))

	function emptyPageForm() {
		return {
			type: 'business',
			name: '',
			public_description: '',
			contact_email: '',
			phone: '',
			whatsapp: '',
			website: '',
			category_key: '',
			palette_key: presencePalettes[0].key,
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
			type: value?.type === 'community' ? 'community' : 'business',
			name: value?.name || '',
			public_description: value?.public_description || '',
			contact_email: contact.email || value?.contact_email || '',
			phone: contact.tel || value?.phone || '',
			whatsapp: contact.whatsapp || '',
			website: value?.website || '',
			category_key: value?.category_key || '',
			palette_key: value?.palette_key || presencePalettes[0].key,
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
			type: pageForm.type,
			name: pageForm.name.trim(),
			public_description: pageForm.public_description.trim() || null,
			contact_email: pageForm.contact_email.trim() || null,
			phone: pageForm.phone.trim() || null,
			whatsapp: pageForm.whatsapp.trim() || null,
			website: pageForm.website.trim() || null,
			category_key: pageForm.category_key,
			palette_key: pageForm.palette_key,
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

	function clearPageSaveError() {
		pageSaveError.value = ''
		pageValidationErrors.value = {}
	}

	function pageFieldError(field) {
		const messages = pageValidationErrors.value[field]

		return Array.isArray(messages) ? String(messages[0] || '') : String(messages || '')
	}

	function pageFieldLabel(field) {
		const labels = {
			type: t('pages.type'),
			name: t('pages.name'),
			public_description: t('pages.description'),
			contact_email: t('pages.email'),
			phone: t('pages.tel'),
			whatsapp: t('pages.whatsapp'),
			website: t('pages.website'),
			category_key: t('catalog.category'),
			palette_key: t('pages.palette'),
			address: t('pages.sections.address'),
			'address.street': t('pages.street'),
			'address.number': t('pages.number'),
			'address.city': t('pages.city'),
			'address.neighborhood': t('auth.neighborhood'),
			'socials.facebook': 'Facebook',
			'socials.instagram': 'Instagram',
			'socials.tiktok': 'TikTok',
			'socials.telegram': 'Telegram',
			opening_hours: t('pages.sections.openingHours')
		}

		if (labels[field]) {
			return labels[field]
		}

		const openingHoursMatch = field.match(/^opening_hours\.(\d+)\.(.+)$/)

		if (openingHoursMatch) {
			const item = pageForm.opening_hours[Number(openingHoursMatch[1])]
			const property = openingHoursMatch[2].replaceAll('_', ' ')

			return `${item ? dayLabel(item.weekday) : t('pages.sections.openingHours')} - ${property}`
		}

		return field.replaceAll('.', ' - ').replaceAll('_', ' ')
	}

	function capturePageValidationErrors(error) {
		const errors = error.response?.status === 422 ? error.response?.data?.errors : null

		if (!errors || typeof errors !== 'object' || Array.isArray(errors)) {
			pageValidationErrors.value = {}
			return false
		}

		pageValidationErrors.value = Object.fromEntries(
			Object.entries(errors).filter(([field]) => field !== 'recaptcha')
		)

		return Object.keys(pageValidationErrors.value).length > 0
	}

	function upsertSavedPage(saved) {
		pages.value = [saved, ...pages.value.filter((page) => page.id !== saved.id)]
		selectedPageId.value = saved.id
		replacePageForm(saved)
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
		clearPageSaveError()

		if (!(await validateRequiredForm(pageFormRef))) {
			workspaceTab.value = 'settings'
			pageSaveError.value = t('validation.requiredFields')
			return
		}

		pageSaving.value = true
		try {
			const { data } = selectedPageId.value ? await updateAiWorkPage(selectedPageId.value, pagePayload()) : await createAiWorkPage(pagePayload())
			const saved = data.data
			upsertSavedPage(saved)
			rememberLocation(pageForm.address.city, pageForm.address.neighborhood)
			$q.notify({ type: 'positive', message: t('pages.saved') })

			try {
				await loadPages()
			} catch {
				// The successful response already contains the complete saved page.
			}
		} catch (error) {
			const hasValidationErrors = capturePageValidationErrors(error)
			pageSaveError.value = hasValidationErrors ? `${t('pages.saveFailed')} ${t('validation.requiredFields')}` : apiErrorMessage(error, t('pages.saveFailed'))
			$q.notify({ type: 'negative', message: pageSaveError.value })
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

	function changePageType(type) {
		if (type === pageForm.type) {
			return
		}

		pageForm.type = type
		pageForm.category_key = ''
	}

	function categoryLabel(categoryKey) {
		const topic = catalogTopicByKey(catalogGroups.value, categoryKey)

		return topic ? catalogLabel(topic.labels, locale.value) : categoryKey || '—'
	}

	watch(() => pageForm.address.city, () => {
		if (!pageForm.address.city) {
			pageForm.address.neighborhood = ''
			return
		}

		if (pageForm.address.neighborhood && !hasOptionValue(neighborhoodOptions.value, pageForm.address.neighborhood)) {
			pageForm.address.neighborhood = ''
		}
	})

	watch(pageForm, () => {
		if (pageSaveError.value || pageValidationItems.value.length) {
			clearPageSaveError()
		}
	}, { deep: true })

	onMounted(async() => {
		await Promise.all([loadTasks(), loadPages(), loadCatalogTopics(), loadLocationOptions()])
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
										<q-btn-toggle
											:model-value="pageForm.type"
											spread
											no-caps
											rounded
											unelevated
											toggle-color="primary"
											color="white"
											text-color="dark"
											:options="pageTypeOptions"
											class="page-type-toggle"
											@update:model-value="changePageType"
										/>

										<q-input v-model="pageForm.name"
											outlined
											name="name"
											:label="requiredLabel('pages.name')"
											:rules="[requiredRule]"
											:error="Boolean(pageFieldError('name'))"
											:error-message="pageFieldError('name')"
										/>
										<q-input v-model="pageForm.public_description"
											outlined
											name="public_description"
											type="textarea"
											autogrow
											:input-style="{ minHeight: '150px' }"
											:label="t('pages.description')"
											:error="Boolean(pageFieldError('public_description'))"
											:error-message="pageFieldError('public_description')"
										/>
										<q-field
											:model-value="pageForm.category_key"
											outlined
											stack-label
											:label="requiredLabel('catalog.category')"
											:rules="[requiredRule]"
											:error="Boolean(pageFieldError('category_key'))"
											:error-message="pageFieldError('category_key')"
										>
											<template #control="{ id }">
												<select
													:id="id"
													v-model="pageForm.category_key"
													name="category_key"
													class="q-field__input ai-native-select"
													data-testid="ai-page-category"
													:aria-label="requiredLabel('catalog.category')"
												>
													<option value="" />
													<optgroup
														v-for="group in pageCategoryGroups"
														:key="group.key"
														:label="catalogLabel(group.labels, locale)"
													>
														<option v-for="topic in group.topics" :key="topic.key" :value="topic.key">
															{{ catalogLabel(topic.labels, locale) }}
														</option>
													</optgroup>
												</select>
											</template>
										</q-field>

										<section class="form-segment">
											<h3>{{ t('pages.sections.contact') }}</h3>
											<div class="form-grid form-grid--three">
												<q-input v-model="pageForm.phone"
													outlined
													name="phone"
													:label="t('pages.tel')"
													:error="Boolean(pageFieldError('phone'))"
													:error-message="pageFieldError('phone')"
												/>
												<q-input v-model="pageForm.contact_email"
													outlined
													name="contact_email"
													type="email"
													:label="t('pages.email')"
													:error="Boolean(pageFieldError('contact_email'))"
													:error-message="pageFieldError('contact_email')"
												/>
												<q-input v-model="pageForm.whatsapp"
													outlined
													name="whatsapp"
													:label="t('pages.whatsapp')"
													:error="Boolean(pageFieldError('whatsapp'))"
													:error-message="pageFieldError('whatsapp')"
												/>
											</div>
											<q-input v-model="pageForm.website"
												outlined
												name="website"
												clearable
												inputmode="url"
												:label="t('pages.website')"
												:error="Boolean(pageFieldError('website'))"
												:error-message="pageFieldError('website')"
											/>
										</section>

										<section class="form-segment">
											<h3>{{ t('pages.sections.address') }}</h3>
											<div class="form-grid form-grid--address">
												<q-input v-model="pageForm.address.street"
													outlined
													name="address.street"
													:label="t('pages.street')"
													:error="Boolean(pageFieldError('address.street'))"
													:error-message="pageFieldError('address.street')"
												/>
												<q-input v-model="pageForm.address.number"
													outlined
													name="address.number"
													:label="t('pages.number')"
													:error="Boolean(pageFieldError('address.number'))"
													:error-message="pageFieldError('address.number')"
												/>
												<q-field
													:model-value="pageForm.address.city"
													outlined
													stack-label
													:label="requiredLabel('pages.city')"
													:rules="[requiredRule]"
													:error="Boolean(pageFieldError('address.city'))"
													:error-message="pageFieldError('address.city')"
												>
													<template #control="{ id }">
														<select
															:id="id"
															v-model="pageForm.address.city"
															name="address.city"
															class="q-field__input ai-native-select"
															data-testid="ai-page-city"
															:aria-label="requiredLabel('pages.city')"
														>
															<option value="" />
															<option v-for="option in cityOptions" :key="option.value" :value="option.value">
																{{ option.label }}
															</option>
														</select>
													</template>
												</q-field>
												<q-field
													:model-value="pageForm.address.neighborhood"
													outlined
													stack-label
													:label="t('auth.neighborhood')"
													:error="Boolean(pageFieldError('address.neighborhood'))"
													:error-message="pageFieldError('address.neighborhood')"
												>
													<template #control="{ id }">
														<select
															:id="id"
															v-model="pageForm.address.neighborhood"
															name="address.neighborhood"
															class="q-field__input ai-native-select"
															data-testid="ai-page-neighborhood"
															:aria-label="t('auth.neighborhood')"
															:disabled="!pageForm.address.city"
														>
															<option value="" />
															<option v-for="option in neighborhoodOptions" :key="option.value" :value="option.value">
																{{ option.label }}
															</option>
														</select>
													</template>
												</q-field>
											</div>
										</section>

										<section class="form-segment">
											<h3>{{ t('pages.sections.socials') }}</h3>
											<div class="form-grid form-grid--four">
												<q-input v-model="pageForm.socials.facebook"
													outlined
													name="socials.facebook"
													label="Facebook"
													:error="Boolean(pageFieldError('socials.facebook'))"
													:error-message="pageFieldError('socials.facebook')"
												/>
												<q-input v-model="pageForm.socials.instagram"
													outlined
													name="socials.instagram"
													label="Instagram"
													:error="Boolean(pageFieldError('socials.instagram'))"
													:error-message="pageFieldError('socials.instagram')"
												/>
												<q-input v-model="pageForm.socials.tiktok"
													outlined
													name="socials.tiktok"
													label="TikTok"
													:error="Boolean(pageFieldError('socials.tiktok'))"
													:error-message="pageFieldError('socials.tiktok')"
												/>
												<q-input v-model="pageForm.socials.telegram"
													outlined
													name="socials.telegram"
													label="Telegram"
													:error="Boolean(pageFieldError('socials.telegram'))"
													:error-message="pageFieldError('socials.telegram')"
												/>
											</div>
										</section>

										<section class="form-segment">
											<h3>{{ t('pages.sections.openingHours') }}</h3>
											<div class="hours-grid">
												<div v-for="(item, index) in pageForm.opening_hours" :key="item.weekday" class="hours-row">
													<strong>{{ dayLabel(item.weekday) }}</strong>
													<q-toggle v-model="item.is_open" :label="item.is_open ? t('pages.open') : t('pages.closed')" color="primary" />
													<q-input v-model="item.opens_at"
														outlined
														:name="`opening_hours.${index}.opens_at`"
														type="time"
														:disable="!item.is_open"
														:label="t('pages.opensAt')"
														:error="Boolean(pageFieldError(`opening_hours.${index}.opens_at`))"
														:error-message="pageFieldError(`opening_hours.${index}.opens_at`)"
													/>
													<q-input v-model="item.closes_at"
														outlined
														:name="`opening_hours.${index}.closes_at`"
														type="time"
														:disable="!item.is_open"
														:label="t('pages.closesAt')"
														:error="Boolean(pageFieldError(`opening_hours.${index}.closes_at`))"
														:error-message="pageFieldError(`opening_hours.${index}.closes_at`)"
													/>
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

										<div v-if="pageSaveError" class="page-save-error" role="alert" aria-live="assertive">
											<strong>{{ pageSaveError }}</strong>
											<ul v-if="pageValidationItems.length">
												<li v-for="item in pageValidationItems" :key="`${item.field}-${item.message}`">
													<b>{{ pageFieldLabel(item.field) }}:</b> {{ item.message }}
												</li>
											</ul>
										</div>

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

								<q-tab-panel name="unclaimed-pages">
									<section class="page-table-card">
										<q-inner-loading :showing="pagesLoading" color="primary" />
										<div v-if="pages.length" class="page-table-scroll">
											<table class="page-table">
												<thead>
													<tr>
														<th>{{ t('pages.name') }}</th>
														<th>{{ t('pages.type') }}</th>
														<th>{{ t('auth.city') }}</th>
														<th>{{ t('catalog.category') }}</th>
														<th class="page-table__actions-heading">{{ t('admin.actions') }}</th>
													</tr>
												</thead>
												<tbody>
													<tr v-for="item in pages" :key="item.id">
														<td><strong>{{ item.name }}</strong></td>
														<td>
															<q-badge rounded class="page-type-badge" :class="`page-type-badge--${item.type}`">
																<q-icon :name="item.type === 'community' ? 'diversity_3' : 'storefront'" />
																{{ t(`pages.kinds.${item.type}`) }}
															</q-badge>
														</td>
														<td>{{ item.address_details?.city || '—' }}</td>
														<td>{{ categoryLabel(item.category_key) }}</td>
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

.page-type-toggle {
  border: 1px solid rgba(123, 63, 242, 0.16);
  border-radius: 18px;
  overflow: hidden;
}

.page-type-toggle :deep(.q-btn) {
  min-height: 50px;
}

.page-type-badge {
  display: inline-flex;
  gap: 6px;
  align-items: center;
  padding: 6px 10px;
  color: #fff;
  font-weight: 800;
}

.page-type-badge--business {
  background: #f06a2f;
}

.page-type-badge--community {
  background: #7b3ff2;
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

.page-save-error {
  padding: 14px 16px;
  border: 1px solid rgba(194, 38, 77, 0.3);
  border-radius: 16px;
  background: rgba(194, 38, 77, 0.08);
  color: #8f1738;
}

.page-save-error ul {
  margin: 8px 0 0;
  padding-inline-start: 20px;
}

.page-save-error li + li {
  margin-top: 4px;
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

.ai-native-select {
  width: 100%;
  min-width: 0;
  height: 100%;
  border: 0;
  outline: 0;
  background: transparent;
  color: var(--soz-ink);
  font: inherit;
  cursor: pointer;
}

.ai-native-select:disabled {
  color: var(--soz-muted);
  cursor: not-allowed;
}

.ai-native-select option,
.ai-native-select optgroup {
  background: #fff;
  color: #11222d;
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
