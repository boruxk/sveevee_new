<script setup>
	import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import {
		createAiWorkPage,
		createAiWorkTask,
		createAiPageImport,
		checkAiWorkPageDuplicate,
		deleteAiWorkPage,
		deleteAiWorkTask,
		fetchAiWorkBulkEditPages,
		fetchAiWorkPage,
		fetchAiWorkPages,
		fetchAiWorkPreferences,
		fetchAiWorkTasks,
		fetchAiPageImports,
		saveAiWorkBulkEditPages,
		updateAiWorkPage,
		updateAiWorkPreferences,
		updateAiWorkTask
	} from '@/services/api/aiWorks'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { aiWorkBulkEditTask } from '@/constants/aiWorkTaskTemplates'
	import { CATALOG_SCOPES, catalogGroupsForScope, catalogLabel, catalogTopicByKey } from '@/constants/catalogTopics'
	import { presencePalettes } from '@/constants/presencePalettes'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import BusinessDetailsFields from '@/components/pages/BusinessDetailsFields.vue'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'
	import BulkEditIcon from '@/components/icons/BulkEditIcon.vue'
	import BulkImportIcon from '@/components/icons/BulkImportIcon.vue'
	import CheckCreatePagesIcon from '@/components/icons/CheckCreatePagesIcon.vue'
	import CopyTemplateIcon from '@/components/icons/CopyTemplateIcon.vue'
	import JsonLoadIcon from '@/components/icons/JsonLoadIcon.vue'
	import JsonSaveIcon from '@/components/icons/JsonSaveIcon.vue'
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
	const DEFAULT_PAGE_DEFAULTS = {
		type: 'business',
		city: '',
		neighborhood: '',
		category_key: '',
		palette_key: presencePalettes[0].key
	}
	const AI_WORK_BATCH_LIMIT = 1000

	const { t, locale } = useI18n()
	const $q = useQuasar()
	const activeTab = ref('tasks')
	const workspaceTab = ref('settings')
	const tasks = ref([])
	const pages = ref([])
	const pagePagination = reactive({ current_page: 1, last_page: 1, per_page: 25, total: 0 })
	const pageSearch = ref('')
	const selectedPageId = ref(null)
	const tasksLoading = ref(false)
	const pagesLoading = ref(false)
	const taskSaving = ref(false)
	const pageSaving = ref(false)
	const pageLoading = ref(false)
	const pageNameInput = ref(null)
	const pageSaveError = ref('')
	const pageValidationErrors = ref({})
	const pageDuplicate = ref(null)
	const duplicateChecking = ref(false)
	let duplicateTimer = null
	const pageDefaults = reactive({ ...DEFAULT_PAGE_DEFAULTS })
	const preferencesSaving = ref(false)
	const bulkMode = ref('json')
	const bulkInput = ref('')
	const bulkSaving = ref(false)
	const bulkError = ref('')
	const bulkResult = ref(null)
	const recentImports = ref([])
	const bulkEditFilters = reactive({ city: '', neighborhood: '', category_key: '', id_from: null, id_to: null })
	const bulkEditJson = ref('')
	const bulkEditLoading = ref(false)
	const bulkEditSaving = ref(false)
	const bulkEditError = ref('')
	const bulkEditValidationErrors = ref([])
	const bulkEditResult = ref(null)
	const bulkEditMeta = reactive({ matched_count: 0, returned_count: 0, limit: AI_WORK_BATCH_LIMIT, truncated: false, next_id_from: null })
	const deleting = ref(false)
	const taskDialogOpen = ref(false)
	const deleteDialogOpen = ref(false)
	const deleteTarget = ref(null)
	const taskFormRef = ref(null)
	const pageFormRef = ref(null)
	const taskForm = reactive({ id: null, title: '', text: '' })
	const pageForm = reactive(emptyPageForm())
	const pageCity = computed(() => pageForm.address.city)
	const bulkEditCity = computed(() => bulkEditFilters.city)
	const bulkEditTaskText = computed(() => aiWorkBulkEditTask(bulkEditFilters))
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		hasOptionValue
	} = useLocationOptions(pageCity)
	const { neighborhoodOptions: bulkEditNeighborhoodOptions } = useLocationOptions(bulkEditCity)
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const selectedPage = computed(() => pages.value.find((page) => page.id === selectedPageId.value) || null)
	const pageCategoryScope = computed(() => pageForm.type === 'community' ? CATALOG_SCOPES.COMMUNITY_PAGES : CATALOG_SCOPES.BUSINESS_PAGES)
	const pageCategoryGroups = computed(() => catalogGroupsForScope(catalogGroups.value, pageCategoryScope.value))
	const pageTypeOptions = computed(() => [
		{ label: t('pages.kinds.business'), value: 'business', icon: 'storefront' },
		{ label: t('pages.kinds.community'), value: 'community', icon: 'diversity_3' }
	])
	const bulkEditCategoryScope = [CATALOG_SCOPES.BUSINESS_PAGES, CATALOG_SCOPES.COMMUNITY_PAGES]
	const taskDialogTitle = computed(() => taskForm.id ? t('aiWorks.tasks.edit') : t('aiWorks.tasks.create'))
	const pageValidationItems = computed(() => Object.entries(pageValidationErrors.value)
		.flatMap(([field, messages]) => (Array.isArray(messages) ? messages : [messages])
			.filter(Boolean)
			.map((message) => ({ field, message }))))

	function emptyPageForm(withDefaults = true) {
		const defaults = withDefaults ? pageDefaults : DEFAULT_PAGE_DEFAULTS
		return {
			type: defaults.type,
			name: '',
			public_description: '',
			contact_email: '',
			phone: '',
			whatsapp: '',
			website: '',
			category_key: defaults.category_key,
			palette_key: defaults.palette_key,
			address: { street: '', number: '', city: defaults.city, neighborhood: defaults.neighborhood },
			socials: { facebook: '', instagram: '', tiktok: '', x: '', telegram: '' },
			opening_hours: DEFAULT_OPENING_HOURS.map((item) => ({ ...item })),
			service_areas: [],
			specialties: []
		}
	}

	function replacePageForm(value = null) {
		const empty = emptyPageForm(!value)
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
			opening_hours: normalizedOpeningHours(value?.opening_hours),
			service_areas: Array.isArray(value?.service_areas) ? [...value.service_areas] : [],
			specialties: Array.isArray(value?.specialties) ? [...value.specialties] : []
		})
	}

	function normalizedOpeningHours(value) {
		const byDay = new Map((Array.isArray(value) ? value : []).map((item) => [item.weekday, item]))

		return DEFAULT_OPENING_HOURS.map((fallback) => ({
			...fallback,
			...(byDay.get(fallback.weekday) || {})
		}))
	}

	async function selectPage(page) {
		if (!page?.id || pageLoading.value) {
			return
		}

		pageLoading.value = true
		try {
			const { data } = await fetchAiWorkPage(page.id)
			selectedPageId.value = page.id
			replacePageForm(data.data)
			workspaceTab.value = 'settings'
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('aiWorks.pages.loadFailed')) })
		} finally {
			pageLoading.value = false
		}
	}

	function createPageDraft() {
		selectedPageId.value = null
		replacePageForm()
		clearPageSaveError()
		pageDuplicate.value = null
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
			service_areas: pageForm.type === 'business' ? [...pageForm.service_areas] : [],
			specialties: pageForm.type === 'business' ? [...pageForm.specialties] : [],
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
			'socials.x': t('pages.socials.x'),
			'socials.telegram': 'Telegram',
			opening_hours: t('pages.sections.openingHours'),
			service_areas: t('pages.sections.serviceAreas'),
			specialties: t('pages.sections.specialties')
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
		pagePagination.total = Math.max(pagePagination.total, pages.value.length)
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

	async function loadPages({ page = pagePagination.current_page } = {}) {
		pagesLoading.value = true
		try {
			const { data } = await fetchAiWorkPages({
				page,
				per_page: pagePagination.per_page,
				search: pageSearch.value.trim() || undefined
			})
			pages.value = data.data?.pages || []
			Object.assign(pagePagination, data.data?.pagination || {})
		} finally {
			pagesLoading.value = false
		}
	}

	function bulkEditParams() {
		return Object.fromEntries(Object.entries(bulkEditFilters)
			.map(([key, value]) => [key, typeof value === 'string' ? value.trim() : value])
			.filter(([, value]) => value !== '' && value !== null && value !== undefined))
	}

	function clearBulkEditFeedback() {
		bulkEditError.value = ''
		bulkEditValidationErrors.value = []
		bulkEditResult.value = null
	}

	function captureBulkEditErrors(error) {
		const errors = error.response?.data?.errors
		bulkEditError.value = apiErrorMessage(error, t('aiWorks.bulkEdit.failed'))
		if (errors && typeof errors === 'object' && !Array.isArray(errors)) {
			bulkEditValidationErrors.value = Object.entries(errors).flatMap(([field, messages]) => (Array.isArray(messages) ? messages : [messages])
				.filter(Boolean)
				.map((message) => ({ field, message })))
			return
		}

		bulkEditValidationErrors.value = []
	}

	async function loadBulkEditJson() {
		clearBulkEditFeedback()
		bulkEditLoading.value = true

		try {
			const { data } = await fetchAiWorkBulkEditPages(bulkEditParams())
			const result = data.data || {}
			bulkEditJson.value = JSON.stringify(result.pages || [], null, 2)
			Object.assign(bulkEditMeta, {
				matched_count: result.matched_count || 0,
				returned_count: result.returned_count || 0,
				limit: result.limit || AI_WORK_BATCH_LIMIT,
				truncated: Boolean(result.truncated),
				next_id_from: result.next_id_from || null
			})
			$q.notify({ type: 'positive', message: t('aiWorks.bulkEdit.loaded', { count: bulkEditMeta.returned_count }) })
		} catch (error) {
			captureBulkEditErrors(error)
		} finally {
			bulkEditLoading.value = false
		}
	}

	function parseBulkEditJson() {
		const value = bulkEditJson.value.trim()
		if (!value) {
			throw new Error(t('aiWorks.bulkEdit.emptyJson'))
		}

		const parsed = JSON.parse(value)
		if (!Array.isArray(parsed)) {
			throw new Error(t('aiWorks.bulkEdit.arrayRequired'))
		}
		if (parsed.length < 1 || parsed.length > bulkEditMeta.limit) {
			throw new Error(t('aiWorks.bulkEdit.rowLimit', { count: bulkEditMeta.limit }))
		}
		if (parsed.some((page) => !page || typeof page !== 'object' || Array.isArray(page) || !Number.isInteger(Number(page.id)))) {
			throw new Error(t('aiWorks.bulkEdit.idRequired'))
		}

		return parsed
	}

	async function saveBulkEditJson() {
		clearBulkEditFeedback()
		let rows

		try {
			rows = parseBulkEditJson()
		} catch (error) {
			bulkEditError.value = error instanceof SyntaxError ? t('aiWorks.bulkEdit.invalidJson') : error.message
			return
		}

		bulkEditSaving.value = true
		try {
			const { data } = await saveAiWorkBulkEditPages(rows)
			const result = data.data || {}
			bulkEditJson.value = JSON.stringify(result.pages || rows, null, 2)
			bulkEditResult.value = result

			const updatedIds = rows.map((page) => Number(page.id))
			if (selectedPageId.value && updatedIds.includes(selectedPageId.value)) {
				createPageDraft()
			}

			await loadPages({ page: pagePagination.current_page })
			$q.notify({ type: 'positive', message: t('aiWorks.bulkEdit.updated', { count: result.updated_count || rows.length }) })
		} catch (error) {
			captureBulkEditErrors(error)
		} finally {
			bulkEditSaving.value = false
		}
	}

	async function copyBulkEditTask() {
		try {
			await navigator.clipboard.writeText(bulkEditTaskText.value)
			$q.notify({ type: 'positive', message: t('aiWorks.bulkEdit.taskCopied') })
		} catch {
			openTask()
			taskForm.title = t('aiWorks.bulkEdit.taskTitle')
			taskForm.text = bulkEditTaskText.value
		}
	}

	async function loadNextBulkEditBatch() {
		if (!bulkEditMeta.next_id_from) return

		bulkEditFilters.id_from = bulkEditMeta.next_id_from
		await loadBulkEditJson()
	}

	async function loadPreferences() {
		try {
			const { data } = await fetchAiWorkPreferences()
			Object.assign(pageDefaults, DEFAULT_PAGE_DEFAULTS, data.data?.page_defaults || {})
		} catch {
			Object.assign(pageDefaults, DEFAULT_PAGE_DEFAULTS)
		}
	}

	async function loadImports() {
		try {
			const { data } = await fetchAiPageImports()
			recentImports.value = data.data?.imports || []
		} catch {
			recentImports.value = []
		}
	}

	async function savePreferences(defaults = pageDefaults) {
		preferencesSaving.value = true
		try {
			const { data } = await updateAiWorkPreferences({ ...defaults })
			Object.assign(pageDefaults, DEFAULT_PAGE_DEFAULTS, data.data?.page_defaults || {})
			return true
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('aiWorks.bulk.defaultsFailed')) })
			return false
		} finally {
			preferencesSaving.value = false
		}
	}

	async function rememberCurrentPageDefaults() {
		const defaults = {
			type: pageForm.type,
			city: pageForm.address.city,
			neighborhood: pageForm.address.neighborhood,
			category_key: pageForm.category_key,
			palette_key: pageForm.palette_key
		}
		Object.assign(pageDefaults, defaults)
		rememberLocation(defaults.city, defaults.neighborhood)
		await savePreferences(defaults)
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

	async function savePage(createNext = false) {
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
			await rememberCurrentPageDefaults()
			$q.notify({ type: 'positive', message: t('pages.saved') })

			if (createNext) {
				createPageDraft()
				await nextTick()
				pageNameInput.value?.focus()
			}
		} catch (error) {
			if (error.response?.status === 409) {
				pageDuplicate.value = error.response?.data?.data || { matches: [] }
			}
			const hasValidationErrors = capturePageValidationErrors(error)
			pageSaveError.value = hasValidationErrors ? `${t('pages.saveFailed')} ${t('validation.requiredFields')}` : apiErrorMessage(error, t('pages.saveFailed'))
			$q.notify({ type: 'negative', message: pageSaveError.value })
		} finally {
			pageSaving.value = false
		}
	}

	function savePageAndNext() {
		return savePage(true)
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
				await loadPages()
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

	function bulkTemplate() {
		const sample = {
			type: 'business',
			name: 'Example business',
			public_description: 'Short public description.',
			category_key: 'professionals.electricians',
			city: 'Tel Aviv',
			neighborhood: 'Ramat Aviv',
			street: 'Example Street',
			number: '10',
			phone: '',
			contact_email: '',
			website: '',
			facebook: '',
			instagram: '',
			tiktok: '',
			x: '',
			telegram: '',
			service_areas: ['Tel Aviv', 'Jerusalem'],
			specialties: ['Electrical repairs', 'Lighting installation']
		}
		const openingHours = DEFAULT_OPENING_HOURS.map((item) => ({ ...item }))

		if (bulkMode.value === 'json') {
			const { facebook, instagram, tiktok, x, telegram, ...page } = sample
			return JSON.stringify([{
				...page,
				socials: { facebook, instagram, tiktok, x, telegram },
				opening_hours: openingHours
			}], null, 2)
		}

		const tableSample = {
			...sample,
			service_areas: JSON.stringify(sample.service_areas),
			specialties: JSON.stringify(sample.specialties),
			opening_hours: JSON.stringify(openingHours)
		}
		const headers = Object.keys(tableSample)
		return headers.join('\t') + '\n' + headers.map((key) => tableSample[key]).join('\t')
	}

	async function copyBulkTemplate() {
		try {
			await navigator.clipboard.writeText(bulkTemplate())
			$q.notify({ type: 'positive', message: t('aiWorks.bulk.templateCopied') })
		} catch {
			bulkInput.value = bulkTemplate()
		}
	}

	function parseBulkRows() {
		const value = bulkInput.value.trim()
		if (!value) {
			throw new Error(t('aiWorks.bulk.emptyInput'))
		}

		if (bulkMode.value === 'json') {
			const parsed = JSON.parse(value)
			const rows = Array.isArray(parsed) ? parsed : parsed?.rows
			if (!Array.isArray(rows)) {
				throw new Error(t('aiWorks.bulk.jsonArrayRequired'))
			}
			return rows
		}

		const lines = value.split(/\r?\n/).filter((line) => line.trim())
		if (lines.length < 2 || !lines[0].includes('\t')) {
			throw new Error(t('aiWorks.bulk.tableRequired'))
		}
		const headers = lines.shift().split('\t').map((header) => header.trim())

		return lines.map((line) => {
			const values = line.split('\t')
			return Object.fromEntries(headers.map((header, index) => [header, values[index]?.trim() || null]))
		})
	}

	async function runBulkImport() {
		bulkError.value = ''
		bulkResult.value = null
		let rows

		try {
			rows = parseBulkRows()
			if (rows.length < 1 || rows.length > AI_WORK_BATCH_LIMIT) {
				throw new Error(t('aiWorks.bulk.rowLimit'))
			}
		} catch (error) {
			bulkError.value = error instanceof SyntaxError ? t('aiWorks.bulk.invalidJson') : error.message
			return
		}

		bulkSaving.value = true
		try {
			const clientImportId = globalThis.crypto?.randomUUID?.() || `${Date.now()}-0000-4000-8000-${Math.random().toString(16).slice(2, 14).padEnd(12, '0')}`
			const { data } = await createAiPageImport({ client_import_id: clientImportId, rows })
			bulkResult.value = data.data
			recentImports.value = [data.data, ...recentImports.value.filter((item) => item.id !== data.data?.id)].slice(0, 20)
			bulkInput.value = ''
			const createdPages = data.data?.created_pages || []
			pages.value = [...createdPages, ...pages.value.filter((page) => !createdPages.some((created) => created.id === page.id))]
			pagePagination.total += createdPages.length
		} catch (error) {
			bulkError.value = apiErrorMessage(error, t('aiWorks.bulk.failed'))
		} finally {
			bulkSaving.value = false
		}
	}

	function scheduleDuplicateCheck() {
		clearTimeout(duplicateTimer)
		pageDuplicate.value = null
		if (!pageForm.name.trim() || !pageForm.category_key || !pageForm.address.city) {
			return
		}

		duplicateTimer = setTimeout(async() => {
			duplicateChecking.value = true
			try {
				const { data } = await checkAiWorkPageDuplicate({
					...pagePayload(),
					exclude_page_id: selectedPageId.value || undefined
				})
				pageDuplicate.value = data.data?.exact_duplicate ? data.data : null
			} catch {
				pageDuplicate.value = null
			} finally {
				duplicateChecking.value = false
			}
		}, 450)
	}

	function changePageListPage(page) {
		loadPages({ page })
	}

	function formatImportDate(value) {
		if (!value) {
			return ''
		}

		return new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
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

	watch([
		() => pageForm.name,
		() => pageForm.type,
		() => pageForm.category_key,
		() => pageForm.phone,
		() => pageForm.website,
		() => pageForm.address.street,
		() => pageForm.address.number,
		() => pageForm.address.city,
		() => pageForm.address.neighborhood
	], scheduleDuplicateCheck)

	watch(bulkEditCity, () => {
		if (!bulkEditFilters.city) {
			bulkEditFilters.neighborhood = ''
			return
		}

		if (bulkEditFilters.neighborhood && !hasOptionValue(bulkEditNeighborhoodOptions.value, bulkEditFilters.neighborhood)) {
			bulkEditFilters.neighborhood = ''
		}
	})

	watch(pageForm, () => {
		if (pageSaveError.value || pageValidationItems.value.length) {
			clearPageSaveError()
		}
	}, { deep: true })

	onMounted(async() => {
		await Promise.all([loadTasks(), loadCatalogTopics(), loadLocationOptions(), loadPreferences(), loadImports()])
		createPageDraft()
		await loadPages()
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
									<q-tab name="bulk-import">
										<span class="workspace-tab-content">
											<BulkImportIcon :size="20" />
											<span>{{ t('aiWorks.bulk.title') }}</span>
										</span>
									</q-tab>
									<q-tab name="bulk-edit">
										<span class="workspace-tab-content">
											<BulkEditIcon :size="20" />
											<span>{{ t('aiWorks.bulkEdit.title') }}</span>
										</span>
									</q-tab>
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
									<q-form ref="pageFormRef" greedy class="page-form" @submit.prevent="savePage(false)">
										<q-inner-loading :showing="pageLoading" color="primary" />
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

										<q-input ref="pageNameInput"
											v-model="pageForm.name"
											outlined
											name="name"
											:label="requiredLabel('pages.name')"
											:rules="[requiredRule]"
											:error="Boolean(pageFieldError('name'))"
											:error-message="pageFieldError('name')"
										/>
										<q-linear-progress v-if="duplicateChecking" indeterminate rounded color="primary" />
										<section v-if="pageDuplicate" class="duplicate-warning" role="alert">
											<q-icon name="content_copy" size="22px" />
											<div>
												<strong>{{ t('aiWorks.duplicates.exact') }}</strong>
												<a v-for="match in pageDuplicate.matches" :key="match.id" :href="match.public_path" target="_blank" rel="noopener">
													{{ match.name }}
												</a>
											</div>
										</section>
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
											<div class="form-grid form-grid--socials">
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
												<q-input v-model="pageForm.socials.x"
													outlined
													name="socials.x"
													:label="t('pages.socials.x')"
													:error="Boolean(pageFieldError('socials.x'))"
													:error-message="pageFieldError('socials.x')"
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

										<BusinessDetailsFields
											v-if="pageForm.type === 'business'"
											v-model:service-areas="pageForm.service_areas"
											v-model:specialties="pageForm.specialties"
											:city-options="cityOptions"
										/>

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
											<q-btn v-if="!selectedPageId"
												outline
												rounded
												color="primary"
												icon="add"
												:loading="pageSaving"
												:disable="Boolean(pageDuplicate)"
												:label="t('aiWorks.pages.saveNext')"
												@click="savePageAndNext"
											/>
											<q-btn rounded
												unelevated
												color="primary"
												type="submit"
												icon="save"
												:loading="pageSaving"
												:disable="Boolean(pageDuplicate)"
												:label="t('pages.saveSettings')"
											/>
										</div>
									</q-form>
								</q-tab-panel>

								<q-tab-panel name="bulk-import">
									<section class="bulk-card">
										<header class="bulk-head">
											<div>
												<h2>{{ t('aiWorks.bulk.title') }}</h2>
												<p>{{ t('aiWorks.bulk.intro') }}</p>
											</div>
											<q-btn outline
												rounded
												color="primary"
												@click="copyBulkTemplate"
											>
												<span class="svg-button-content">
													<CopyTemplateIcon :size="20" />
													<span>{{ t('aiWorks.bulk.copyTemplate') }}</span>
												</span>
											</q-btn>
										</header>

										<div class="bulk-mode-row">
											<q-btn-toggle v-model="bulkMode"
												no-caps
												rounded
												unelevated
												toggle-color="primary"
												color="white"
												text-color="dark"
												:options="[{ label: 'JSON', value: 'json' }, { label: t('aiWorks.bulk.table'), value: 'table' }]"
											/>
											<span>{{ t('aiWorks.bulk.limit') }}</span>
										</div>
										<q-input v-model="bulkInput"
											outlined
											type="textarea"
											class="bulk-input"
											input-class="bulk-input__control"
											:placeholder="bulkMode === 'json' ? t('aiWorks.bulk.jsonPlaceholder') : t('aiWorks.bulk.tablePlaceholder')"
										/>
										<div v-if="bulkError" class="page-save-error" role="alert">{{ bulkError }}</div>
										<section v-if="bulkResult" class="bulk-result" aria-live="polite">
											<h3>{{ t('aiWorks.bulk.completed') }}</h3>
											<div class="bulk-result__counts">
												<strong>{{ t('aiWorks.bulk.created', { count: bulkResult.created_count }) }}</strong>
												<span>{{ t('aiWorks.bulk.duplicates', { count: bulkResult.duplicate_count }) }}</span>
												<span>{{ t('aiWorks.bulk.invalid', { count: bulkResult.invalid_count }) }}</span>
											</div>
											<ul v-if="bulkResult.skipped?.length">
												<li v-for="item in bulkResult.skipped" :key="`${item.row}-${item.reason}`">
													{{ t('aiWorks.bulk.skippedRow', { row: item.row, name: item.name || '-', reason: item.reason }) }}
												</li>
											</ul>
										</section>
										<section v-if="recentImports.length" class="import-history">
											<h3>{{ t('aiWorks.bulk.recent') }}</h3>
											<article v-for="item in recentImports" :key="item.id" class="import-history__row">
												<div>
													<strong>{{ formatImportDate(item.created_at) }}</strong>
													<span>{{ t('aiWorks.bulk.historySummary', { created: item.created_count, duplicates: item.duplicate_count, invalid: item.invalid_count }) }}</span>
												</div>
												<div class="import-history__links">
													<a v-for="page in item.created_pages" :key="page.id" :href="page.public_path" target="_blank" rel="noopener">{{ page.name }}</a>
												</div>
											</article>
										</section>
										<div class="save-row">
											<q-btn rounded
												unelevated
												color="primary"
												:loading="bulkSaving"
												@click="runBulkImport"
											>
												<span class="svg-button-content">
													<CheckCreatePagesIcon :size="21" />
													<span>{{ t('aiWorks.bulk.create') }}</span>
												</span>
											</q-btn>
										</div>
									</section>
								</q-tab-panel>

								<q-tab-panel name="bulk-edit">
									<section class="bulk-card bulk-edit-card">
										<header class="bulk-head">
											<div>
												<h2>{{ t('aiWorks.bulkEdit.title') }}</h2>
												<p>{{ t('aiWorks.bulkEdit.intro') }}</p>
											</div>
											<q-btn outline rounded color="primary" @click="copyBulkEditTask">
												<span class="svg-button-content">
													<CopyTemplateIcon :size="20" />
													<span>{{ t('aiWorks.bulkEdit.copyTask') }}</span>
												</span>
											</q-btn>
										</header>

										<section class="bulk-edit-task-note">
											<BulkEditIcon :size="26" />
											<div>
												<strong>{{ t('aiWorks.bulkEdit.taskTitle') }}</strong>
												<p>{{ t('aiWorks.bulkEdit.taskHint') }}</p>
											</div>
										</section>

										<div class="bulk-edit-filter-grid">
											<q-select
												v-model="bulkEditFilters.city"
												outlined
												clearable
												emit-value
												map-options
												options-dense
												:options="cityOptions"
												:label="t('pages.city')"
											/>
											<q-select
												v-model="bulkEditFilters.neighborhood"
												outlined
												clearable
												emit-value
												map-options
												options-dense
												:options="bulkEditNeighborhoodOptions"
												:label="t('auth.neighborhood')"
												:disable="!bulkEditFilters.city"
											/>
											<CatalogCategorySelect
												v-model="bulkEditFilters.category_key"
												:groups="catalogGroups"
												:scope="bulkEditCategoryScope"
												:label="t('aiWorks.bulkEdit.categoryFilter')"
											/>
											<q-input
												v-model.number="bulkEditFilters.id_from"
												outlined
												clearable
												type="number"
												min="1"
												:label="t('aiWorks.bulkEdit.idFrom')"
											/>
											<q-input
												v-model.number="bulkEditFilters.id_to"
												outlined
												clearable
												type="number"
												min="1"
												:label="t('aiWorks.bulkEdit.idTo')"
											/>
										</div>

										<div class="bulk-edit-load-row">
											<span>{{ t('aiWorks.bulkEdit.filterHint') }}</span>
											<q-btn
												rounded
												unelevated
												color="primary"
												:loading="bulkEditLoading"
												@click="loadBulkEditJson"
											>
												<span class="svg-button-content">
													<JsonLoadIcon :size="21" />
													<span>{{ t('aiWorks.bulkEdit.loadJson') }}</span>
												</span>
											</q-btn>
										</div>

										<section v-if="bulkEditJson" class="bulk-edit-meta" aria-live="polite">
											<div>
												<strong>{{ t('aiWorks.bulkEdit.matches', { count: bulkEditMeta.matched_count }) }}</strong>
												<span>{{ t('aiWorks.bulkEdit.loadedCount', { count: bulkEditMeta.returned_count }) }}</span>
											</div>
											<div v-if="bulkEditMeta.truncated" class="bulk-edit-next">
												<span>{{ t('aiWorks.bulkEdit.truncated', { count: bulkEditMeta.limit, id: bulkEditMeta.next_id_from }) }}</span>
												<q-btn flat rounded color="primary" :label="t('aiWorks.bulkEdit.loadNext')" @click="loadNextBulkEditBatch" />
											</div>
										</section>

										<q-input
											v-model="bulkEditJson"
											outlined
											type="textarea"
											class="bulk-input bulk-edit-json"
											input-class="bulk-input__control"
											:placeholder="t('aiWorks.bulkEdit.jsonPlaceholder')"
											@update:model-value="clearBulkEditFeedback"
										/>

										<div v-if="bulkEditError" class="page-save-error" role="alert">
											<strong>{{ bulkEditError }}</strong>
											<ul v-if="bulkEditValidationErrors.length">
												<li v-for="item in bulkEditValidationErrors" :key="`${item.field}-${item.message}`">
													{{ item.field }}: {{ item.message }}
												</li>
											</ul>
										</div>
										<section v-if="bulkEditResult" class="bulk-result" aria-live="polite">
											<h3>{{ t('aiWorks.bulkEdit.completed') }}</h3>
											<strong>{{ t('aiWorks.bulkEdit.updated', { count: bulkEditResult.updated_count }) }}</strong>
										</section>

										<div class="save-row bulk-edit-save-row">
											<span>{{ t('aiWorks.bulkEdit.atomicNote') }}</span>
											<q-btn
												rounded
												unelevated
												color="primary"
												:loading="bulkEditSaving"
												:disable="!bulkEditJson.trim()"
												@click="saveBulkEditJson"
											>
												<span class="svg-button-content">
													<JsonSaveIcon :size="21" />
													<span>{{ t('aiWorks.bulkEdit.saveJson') }}</span>
												</span>
											</q-btn>
										</div>
										<q-inner-loading :showing="bulkEditLoading" color="primary" />
									</section>
								</q-tab-panel>

								<q-tab-panel name="unclaimed-pages">
									<section class="page-table-card">
										<div class="page-list-toolbar">
											<q-input v-model="pageSearch"
												outlined
												dense
												clearable
												debounce="300"
												:placeholder="t('aiWorks.pages.search')"
												@update:model-value="loadPages({ page: 1 })"
											>
												<template #prepend><q-icon name="search" /></template>
											</q-input>
											<strong>{{ t('aiWorks.pages.total', { count: pagePagination.total }) }}</strong>
										</div>
										<q-inner-loading :showing="pagesLoading || pageLoading" color="primary" />
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
										<q-pagination v-if="pagePagination.last_page > 1"
											:model-value="pagePagination.current_page"
											:max="pagePagination.last_page"
											direction-links
											class="page-pagination"
											@update:model-value="changePageListPage"
										/>
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

.svg-button-content {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  justify-content: center;
  white-space: nowrap;
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
  position: relative;
  display: grid;
  gap: 18px;
  padding: 24px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.78);
  box-shadow: 0 18px 42px rgba(40, 22, 93, 0.08);
}

.bulk-card {
  position: relative;
  display: grid;
  gap: 20px;
  padding: 24px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 24px;
  background: rgba(255, 255, 255, 0.78);
  box-shadow: 0 18px 42px rgba(40, 22, 93, 0.08);
}

.bulk-edit-task-note {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-inline-start: 3px solid var(--q-primary);
  background: rgba(124, 58, 237, 0.045);
}

.bulk-edit-task-note strong,
.bulk-edit-task-note p {
  margin: 0;
}

.bulk-edit-task-note p {
  margin-top: 2px;
  color: var(--soz-muted);
  font-size: 0.9rem;
}

.bulk-edit-filter-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  align-items: start;
}

.bulk-edit-load-row,
.bulk-edit-meta,
.bulk-edit-next {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
}

.bulk-edit-load-row > span,
.bulk-edit-save-row > span {
  color: var(--soz-muted);
  font-size: 0.9rem;
}

.bulk-edit-meta {
  padding: 12px 14px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 12px;
  background: rgba(255, 255, 255, 0.46);
}

.bulk-edit-meta > div:first-child {
  display: grid;
  gap: 2px;
}

.bulk-edit-meta span {
  color: var(--soz-muted);
  font-size: 0.86rem;
}

.bulk-edit-next {
  justify-content: flex-end;
}

.bulk-edit-json :deep(textarea) {
  min-height: 520px !important;
}

.bulk-edit-save-row {
  align-items: center;
  justify-content: space-between;
}

.bulk-head,
.bulk-mode-row,
.page-list-toolbar {
  display: flex;
  gap: 16px;
  align-items: center;
  justify-content: space-between;
}

.bulk-head h2,
.bulk-result h3 {
  margin: 0;
  color: var(--soz-ink);
}

.bulk-head p {
  margin: 5px 0 0;
  color: var(--soz-muted);
}

.bulk-mode-row {
  justify-content: flex-start;
}

.bulk-mode-row span {
  color: var(--soz-muted);
}

.bulk-input :deep(textarea) {
  min-height: 280px !important;
  font-family: Consolas, "Courier New", monospace;
  line-height: 1.55;
}

.bulk-result {
  padding: 18px;
  border: 1px solid rgba(35, 145, 105, 0.22);
  border-radius: 18px;
  background: rgba(35, 145, 105, 0.08);
}

.bulk-result__counts {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-top: 10px;
}

.import-history {
  display: grid;
  gap: 10px;
}

.import-history h3 {
  margin: 0;
  color: var(--soz-ink);
}

.import-history__row {
  display: flex;
  gap: 16px;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  border-radius: 14px;
  background: rgba(17, 34, 45, 0.04);
}

.import-history__row > div,
.import-history__links {
  display: flex;
  flex-wrap: wrap;
  gap: 6px 12px;
}

.import-history__row > div:first-child {
  flex-direction: column;
}

.import-history__links a {
  color: var(--soz-primary);
  font-weight: 700;
}

.duplicate-warning {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  padding: 14px 16px;
  border: 1px solid rgba(194, 38, 77, 0.3);
  border-radius: 16px;
  background: rgba(194, 38, 77, 0.08);
  color: #8f1738;
}

.duplicate-warning div {
  display: grid;
  gap: 5px;
}

.duplicate-warning a {
  color: inherit;
  font-weight: 700;
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

.form-grid--socials {
  grid-template-columns: repeat(5, minmax(0, 1fr));
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
  gap: 10px;
  flex-wrap: wrap;
  justify-content: flex-end;
}

.page-list-toolbar {
  margin-bottom: 14px;
}

.page-list-toolbar .q-field {
  width: min(460px, 100%);
}

.page-pagination {
  justify-content: center;
  margin-top: 18px;
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
  .form-grid--socials,
  .form-grid--address {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 700px) {
  .ai-works-page {
    padding-inline: 10px;
  }

  .ai-panel,
  .page-form,
  .bulk-card {
    padding: 16px;
  }

  .bulk-head,
  .page-list-toolbar,
  .import-history__row {
    align-items: stretch;
    flex-direction: column;
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
  .form-grid--socials,
  .form-grid--address {
    grid-template-columns: 1fr;
  }

  .bulk-edit-filter-grid {
    grid-template-columns: 1fr;
  }

  .bulk-edit-load-row,
  .bulk-edit-meta,
  .bulk-edit-next,
  .bulk-edit-save-row {
    align-items: stretch;
    flex-direction: column;
  }

  .bulk-edit-load-row .q-btn,
  .bulk-edit-save-row .q-btn {
    width: 100%;
  }

  .bulk-edit-json :deep(textarea) {
    min-height: 420px !important;
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
