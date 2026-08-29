<script setup>
	import { computed, nextTick, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { deleteAd } from '@/services/api/ads'
	import { deleteEvent } from '@/services/api/events'
	import { deletePage, fetchMyPage, saveMyPage, updatePageFeatures } from '@/services/api/pages'
	import { deleteProduct } from '@/services/api/products'
	import { deletePrice } from '@/services/api/prices'
	import { deleteService } from '@/services/api/services'
	import { findPresencePalette, presencePalettes } from '@/constants/presencePalettes'
	import { CATALOG_SCOPES, publicPagePath } from '@/constants/catalogTopics'
	import { absoluteUrl } from '@/composables/useSeo'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { IMAGE_ACCEPT, imageUploadDisplayName } from '@/utils/imageUploads'
	import AdCard from '@/components/AdCard.vue'
	import AdComposer from '@/components/AdComposer.vue'
	import CatalogCategorySelect from '@/components/CatalogCategorySelect.vue'
	import EventCard from '@/components/events/EventCard.vue'
	import EventComposer from '@/components/events/EventComposer.vue'
	import ProductCard from '@/components/products/ProductCard.vue'
	import ProductComposer from '@/components/products/ProductComposer.vue'
	import PriceComposer from '@/components/prices/PriceComposer.vue'
	import PriceList from '@/components/prices/PriceList.vue'
	import ServiceCard from '@/components/services/ServiceCard.vue'
	import ServiceComposer from '@/components/services/ServiceComposer.vue'
	import PagePreview from '@/components/pages/PagePreview.vue'
	import PageRatingsDialog from '@/components/ratings/PageRatingsDialog.vue'
	import ChatBlock from '@/components/ChatBlock.vue'
	import PriceListIcon from '@/components/icons/PriceListIcon.vue'

	const DEFAULT_OPENING_HOURS = [
		{ weekday: 'sunday', is_open: false, opens_at: null, closes_at: null },
		{ weekday: 'monday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'tuesday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'wednesday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'thursday', is_open: true, opens_at: '09:00', closes_at: '17:00' },
		{ weekday: 'friday', is_open: true, opens_at: '09:00', closes_at: '13:00' },
		{ weekday: 'saturday', is_open: false, opens_at: null, closes_at: null }
	]

	const route = useRoute()
	const router = useRouter()
	const { t, locale } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const loading = ref(false)
	const saving = ref(false)
	const deleting = ref(false)
	const logoUploading = ref(false)
	const bannerUploading = ref(false)
	const formRef = ref(null)
	const adDialogOpen = ref(false)
	const productDialogOpen = ref(false)
	const priceDialogOpen = ref(false)
	const serviceDialogOpen = ref(false)
	const eventDialogOpen = ref(false)
	const ratingsDialogOpen = ref(false)
	const activeTab = ref('preview')
	const editingAd = ref(null)
	const editingProduct = ref(null)
	const editingPrice = ref(null)
	const editingService = ref(null)
	const editingEvent = ref(null)
	const page = ref(null)
	const localLogoPreviewUrl = ref(null)
	const localBannerPreviewUrl = ref(null)
	const logoRemoved = ref(false)
	const bannerRemoved = ref(false)
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const form = reactive({
		name: '',
		public_description: '',
		contact_email: '',
		phone: '',
		whatsapp: '',
		socials: {
			facebook: '',
			instagram: '',
			tiktok: '',
			telegram: ''
		},
		address: {
			street: '',
			number: '',
			city: '',
			neighborhood: ''
		},
		opening_hours: [],
		features: {
			store: false,
			services: false,
			events: false,
			price_list: false
		},
		category_key: '',
		website: '',
		palette_key: presencePalettes[0].key,
		logo: null,
		banner: null
	})

	const type = computed(() => route.meta.pageType || 'business')
	const isBusinessPage = computed(() => type.value === 'business')
	const isCommunityPage = computed(() => type.value === 'community')
	const pageCatalogScope = computed(() => (
		isCommunityPage.value ? CATALOG_SCOPES.COMMUNITY_PAGES : CATALOG_SCOPES.BUSINESS_PAGES
	))
	const title = computed(() => (type.value === 'business' ? t('pages.businessTitle') : t('pages.communityTitle')))
	const selectedPalette = computed(() => findPresencePalette(form.palette_key))
	const adDialogTitle = computed(() => (editingAd.value ? t('actions.update') : t('actions.createAd')))
	const productDialogTitle = computed(() => (editingProduct.value ? t('actions.update') : t('actions.addProduct')))
	const priceDialogTitle = computed(() => (editingPrice.value ? t('actions.update') : t('priceList.add')))
	const serviceDialogTitle = computed(() => (editingService.value ? t('actions.update') : t('businessServices.addService')))
	const eventDialogTitle = computed(() => (editingEvent.value ? t('actions.update') : t('actions.addEvent')))
	const visibleAds = computed(() => (Array.isArray(page.value?.ads) ? page.value.ads.filter((ad) => ad?.id) : []))
	const visibleProducts = computed(() => (Array.isArray(page.value?.products) ? page.value.products.filter((product) => product?.id) : []))
	const visiblePrices = computed(() => (Array.isArray(page.value?.prices) ? page.value.prices.filter((price) => price?.id) : []))
	const visibleServices = computed(() => (Array.isArray(page.value?.services) ? page.value.services.filter((service) => service?.id) : []))
	const visibleEvents = computed(() => (Array.isArray(page.value?.events) ? page.value.events.filter((event) => event?.id) : []))
	const isStoreEnabled = computed(() => Boolean(form.features.store))
	const isServicesEnabled = computed(() => Boolean(form.features.services))
	const isEventsEnabled = computed(() => Boolean(form.features.events))
	const isPriceListEnabled = computed(() => Boolean(form.features.price_list))
	const pageFeatureKeys = computed(() => {
		if (isBusinessPage.value) {
			return ['price_list', 'store', 'services']
		}

		if (isCommunityPage.value) {
			return ['events']
		}

		return []
	})
	const pageTabs = computed(() => [
		{ name: 'preview', label: t('pages.tabs.preview'), icon: 'visibility' },
		{ name: 'settings', label: t('pages.tabs.settings'), icon: 'settings' },
		isBusinessPage.value && isPriceListEnabled.value ? {
			name: 'price-list',
			label: t('priceList.title'),
			icon: null
		} : null,
		isBusinessPage.value && isStoreEnabled.value ? {
			name: 'store',
			label: t('businessFeatures.store'),
			icon: 'inventory_2'
		} : null,
		isBusinessPage.value && isServicesEnabled.value ? {
			name: 'services',
			label: t('businessFeatures.services'),
			icon: 'design_services'
		} : null,
		isCommunityPage.value && isEventsEnabled.value ? {
			name: 'events',
			label: t('businessFeatures.events'),
			icon: 'event'
		} : null,
		{ name: 'chat', label: t('chat.title'), icon: 'chat' },
		{ name: 'ads', label: t('ads.listTitle'), icon: 'campaign' }
	].filter(Boolean))
	const hasStoredLogo = computed(() => Boolean(page.value?.logo_url) && !form.logo && !logoRemoved.value)
	const hasStoredBanner = computed(() => Boolean(page.value?.banner_url) && !form.banner && !bannerRemoved.value)
	const logoDisplayName = computed(() => imageUploadDisplayName(
		form.logo,
		logoRemoved.value ? '' : page.value?.logo_url,
		logoRemoved.value ? '' : page.value?.logo_name
	))
	const bannerDisplayName = computed(() => imageUploadDisplayName(
		form.banner,
		bannerRemoved.value ? '' : page.value?.banner_url,
		bannerRemoved.value ? '' : page.value?.banner_name
	))
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		addOption,
		filterOptions,
		hasOptionValue
	} = useLocationOptions(toRef(form.address, 'city'))
	const previewPage = computed(() => ({
		id: page.value?.id,
		type: type.value,
		name: form.name,
		public_description: form.public_description,
		contact_email: form.contact_email,
		phone: form.phone,
		contact: {
			tel: form.phone,
			email: form.contact_email,
			whatsapp: form.whatsapp
		},
		socials: {
			facebook: form.socials.facebook,
			instagram: form.socials.instagram,
			tiktok: form.socials.tiktok,
			telegram: form.socials.telegram
		},
		address_details: {
			street: form.address.street,
			number: form.address.number,
			city: form.address.city,
			neighborhood: form.address.neighborhood
		},
		website: normalizedWebsite(form.website),
		opening_hours: form.opening_hours.map((item) => ({ ...item })),
		features: {
			store: form.features.store,
			services: form.features.services,
			events: form.features.events,
			price_list: form.features.price_list
		},
		category_key: form.category_key || null,
		palette_key: form.palette_key,
		logo_url: logoRemoved.value ? null : localLogoPreviewUrl.value || page.value?.logo_url || null,
		banner_url: bannerRemoved.value ? null : localBannerPreviewUrl.value || page.value?.banner_url || null,
		rating_summary: page.value?.rating_summary || { average: 0, count: 0 }
	}))
	const previewShareUrl = computed(() => {
		if (!page.value) {
			return ''
		}

		return absoluteUrl(publicPagePath(page.value, locale.value))
	})
	const previewContentPlaceholders = computed(() => {
		if (isBusinessPage.value) {
			return [
				isPriceListEnabled.value ? {
					key: 'price-list',
					icon: null,
					label: t('priceList.title'),
					targetId: 'page-prices-section',
					tabName: 'price-list',
					cardCount: 1
				} : null,
				isStoreEnabled.value ? {
					key: 'store',
					icon: 'inventory_2',
					label: t('products.storeTitle'),
					targetId: 'page-products-section',
					tabName: 'store',
					cardCount: 2
				} : null,
				isServicesEnabled.value ? {
					key: 'services',
					icon: 'design_services',
					label: t('businessServices.title'),
					targetId: 'page-services-section',
					tabName: 'services',
					cardCount: 1
				} : null
			].filter(Boolean)
		}

		if (isCommunityPage.value && isEventsEnabled.value) {
			return [{
				key: 'events',
				icon: 'event',
				label: t('events.eventsTitle'),
				targetId: 'page-events-section',
				tabName: 'events',
				cardCount: 1
			}]
		}

		return []
	})
	const hasPreviewPlaceholders = computed(() => previewContentPlaceholders.value.length > 0)

	async function openSetupTab(tabName, targetId) {
		activeTab.value = tabName
		await nextTick()
		document.getElementById(targetId)?.scrollIntoView({
			behavior: 'smooth',
			block: 'start'
		})
	}

	function openPageChatTab() {
		if (page.value) {
			activeTab.value = 'chat'
		}
	}

	function normalizedOpeningHours(value) {
		const byWeekday = new Map((Array.isArray(value) ? value : []).map((item) => [item.weekday, item]))

		return DEFAULT_OPENING_HOURS.map((fallback) => {
			const item = byWeekday.get(fallback.weekday) || {}
			return {
				weekday: fallback.weekday,
				is_open: Boolean(item.is_open ?? fallback.is_open),
				opens_at: item.opens_at || fallback.opens_at,
				closes_at: item.closes_at || fallback.closes_at
			}
		})
	}

	function addressLine(address) {
		return [address.street, address.number, address.neighborhood, address.city].filter(Boolean).join(', ')
	}

	function normalizedWebsite(value) {
		const raw = String(value || '').trim()

		if (!raw) {
			return ''
		}

		const candidate = /^https?:\/\//i.test(raw) ? raw : `https://${raw}`

		try {
			const url = new URL(candidate)
			return ['http:', 'https:'].includes(url.protocol) && url.hostname ? url.toString() : ''
		} catch {
			return ''
		}
	}

	function websiteRule(value) {
		return !String(value || '').trim() || Boolean(normalizedWebsite(value)) || t('pages.websiteInvalid')
	}

	function cleanPageText(value) {
		const text = String(value || '').trim()

		if (!text) {
			return ''
		}

		const questionMarks = text.match(/\?/g)?.length || 0
		const gereshMarks = text.match(/\u05f3/g)?.length || 0

		if ((questionMarks >= 3 && questionMarks / text.length > 0.35) || gereshMarks >= 3) {
			return ''
		}

		return text
	}

	function featureFlag(value, key, fallback) {
		const features = value?.features || value?.setup?.features || {}
		const flag = features[key] ?? fallback

		return flag === true || flag === 'true' || flag === 1 || flag === '1'
	}

	function hydrate(value) {
		const setup = value?.setup || {}
		const contact = value?.contact || setup.contact || {}
		const address = value?.address_details || setup.address || {}
		const socials = value?.socials || setup.socials || {}

		page.value = value
		form.name = cleanPageText(value?.name)
		form.public_description = cleanPageText(value?.public_description)
		form.contact_email = contact.email || value?.contact_email || ''
		form.phone = contact.tel || value?.phone || ''
		form.whatsapp = contact.whatsapp || ''
		form.socials.facebook = socials.facebook || ''
		form.socials.instagram = socials.instagram || ''
		form.socials.tiktok = socials.tiktok || ''
		form.socials.telegram = socials.telegram || ''
		form.address.street = address.street || ''
		form.address.number = address.number || ''
		form.address.city = address.city || ''
		form.address.neighborhood = address.neighborhood || ''
		form.opening_hours = normalizedOpeningHours(value?.opening_hours || setup.opening_hours)
		form.features.store = featureFlag(value, 'store', false)
		form.features.services = featureFlag(value, 'services', false)
		form.features.events = featureFlag(value, 'events', false)
		form.features.price_list = featureFlag(value, 'price_list', false)
		form.category_key = value?.category_key || ''
		form.website = value?.website || setup.website || ''
		form.palette_key = findPresencePalette(value?.palette_key || setup.palette_key).key
		form.logo = null
		form.banner = null
		logoRemoved.value = false
		bannerRemoved.value = false
	}

	function pagePayload() {
		const website = normalizedWebsite(form.website)

		return {
			name: form.name.trim(),
			public_description: form.public_description.trim(),
			contact_email: form.contact_email.trim(),
			phone: form.phone.trim(),
			address: addressLine(form.address),
			website,
			category_key: form.category_key || null,
			palette_key: form.palette_key,
			setup: {
				website,
				contact: {
					tel: form.phone.trim() || null,
					email: form.contact_email.trim() || null,
					whatsapp: form.whatsapp.trim() || null
				},
				socials: {
					facebook: form.socials.facebook.trim() || null,
					instagram: form.socials.instagram.trim() || null,
					tiktok: form.socials.tiktok.trim() || null,
					telegram: form.socials.telegram.trim() || null
				},
				address: {
					street: form.address.street.trim() || null,
					number: form.address.number.trim() || null,
					city: form.address.city.trim() || null,
					neighborhood: form.address.neighborhood.trim() || null
				},
				opening_hours: form.opening_hours.map((item) => ({
					weekday: item.weekday,
					is_open: item.is_open,
					opens_at: item.is_open ? item.opens_at || null : null,
					closes_at: item.is_open ? item.closes_at || null : null
				})),
				features: {
					store: form.features.store,
					services: form.features.services,
					events: form.features.events,
					price_list: form.features.price_list
				}
			},
			logo: form.logo,
			logo_remove: logoRemoved.value,
			banner: form.banner,
			banner_remove: bannerRemoved.value
		}
	}

	async function load() {
		loading.value = true
		try {
			const { data } = await fetchMyPage(type.value)
			hydrate(data.data)
		} finally {
			loading.value = false
		}
	}

	async function save(options = {}) {
		const notify = options.notify !== false

		if (options.validate !== false && formRef.value && !(await validateRequiredForm(formRef))) {
			return false
		}

		saving.value = true
		try {
			const { data } = await saveMyPage(type.value, pagePayload())
			hydrate(data.data)
			rememberLocation(form.address.city, form.address.neighborhood)
			await authStore.refreshUser()
			if (notify) {
				$q.notify({ type: 'positive', message: t('pages.saved') })
			}
			return true
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('pages.saveFailed')) })
			return false
		} finally {
			saving.value = false
		}
	}

	async function togglePageFeature(key) {
		if (!pageFeatureKeys.value.includes(key) || saving.value) {
			return
		}

		const previousFeatures = { ...form.features }
		form.features[key] = !previousFeatures[key]

		if (!page.value) {
			return
		}

		saving.value = true
		try {
			const { data } = await updatePageFeatures(type.value, { features: { ...form.features } })
			const savedPage = data.data
			const savedFeatures = savedPage?.features || savedPage?.setup?.features || form.features

			page.value = {
				...page.value,
				...savedPage,
				setup: {
					...(page.value?.setup || {}),
					...(savedPage?.setup || {}),
					features: savedFeatures
				},
				features: savedFeatures
			}
			form.features.store = featureFlag(page.value, 'store', false)
			form.features.services = featureFlag(page.value, 'services', false)
			form.features.events = featureFlag(page.value, 'events', false)
			form.features.price_list = featureFlag(page.value, 'price_list', false)
		} catch (error) {
			form.features.store = previousFeatures.store
			form.features.services = previousFeatures.services
			form.features.events = previousFeatures.events
			form.features.price_list = previousFeatures.price_list
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('pages.saveFailed')) })
		} finally {
			saving.value = false
		}
	}

	async function uploadLogo() {
		if (!form.logo && !logoRemoved.value) {
			$q.notify({ type: 'warning', message: t('pages.logoMissing') })
			return
		}

		logoUploading.value = true
		try {
			if (await save({ notify: false })) {
				$q.notify({ type: 'positive', message: t('pages.logoUploaded') })
			}
		} finally {
			logoUploading.value = false
		}
	}

	async function uploadBanner() {
		if (!form.banner && !bannerRemoved.value) {
			$q.notify({ type: 'warning', message: t('pages.bannerMissing') })
			return
		}

		bannerUploading.value = true
		try {
			if (await save({ notify: false })) {
				$q.notify({ type: 'positive', message: t('pages.bannerUploaded') })
			}
		} finally {
			bannerUploading.value = false
		}
	}

	async function removeAd(ad) {
		try {
			await deleteAd(ad.id)
			await load()
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('ads.deleteFailed')) })
		}
	}

	function removeStoredLogo() {
		form.logo = null
		localLogoPreviewUrl.value = null
		logoRemoved.value = true
	}

	function removeStoredBanner() {
		form.banner = null
		localBannerPreviewUrl.value = null
		bannerRemoved.value = true
	}

	function openCreateAd() {
		editingAd.value = null
		adDialogOpen.value = true
	}

	function openEditAd(ad) {
		editingAd.value = ad
		adDialogOpen.value = true
	}

	function mergeSavedAd(savedAd) {
		if (!page.value?.id || !savedAd?.id || savedAd.page_id !== page.value.id) {
			return
		}

		const ads = Array.isArray(page.value.ads) ? page.value.ads : []
		const existingIndex = ads.findIndex((ad) => ad.id === savedAd.id)
		let nextAds = [savedAd, ...ads]

		if (existingIndex !== -1) {
			nextAds = ads.map((ad) => (ad.id === savedAd.id ? savedAd : ad))
		}

		page.value = {
			...page.value,
			ads: nextAds
		}
	}

	function openCreateProduct() {
		editingProduct.value = null
		productDialogOpen.value = true
	}

	function openEditProduct(product) {
		editingProduct.value = product
		productDialogOpen.value = true
	}

	function openCreatePrice() {
		editingPrice.value = null
		priceDialogOpen.value = true
	}

	function openEditPrice(price) {
		editingPrice.value = price
		priceDialogOpen.value = true
	}

	function openCreateService() {
		editingService.value = null
		serviceDialogOpen.value = true
	}

	function openEditService(service) {
		editingService.value = service
		serviceDialogOpen.value = true
	}

	async function removeProduct(product) {
		try {
			await deleteProduct(product.id)
			await load()
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('products.deleteFailed')) })
		}
	}

	async function removePrice(price) {
		try {
			await deletePrice(price.id)
			await load()
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('priceList.deleteFailed')) })
		}
	}

	async function removeService(service) {
		try {
			await deleteService(service.id)
			await load()
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('businessServices.deleteFailed')) })
		}
	}

	function openCreateEvent() {
		editingEvent.value = null
		eventDialogOpen.value = true
	}

	function openEditEvent(event) {
		editingEvent.value = event
		eventDialogOpen.value = true
	}

	async function removeEvent(event) {
		try {
			await deleteEvent(event.id)
			await load()
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('events.deleteFailed')) })
		}
	}

	function mergeSavedProduct(savedProduct) {
		if (!page.value?.id || !savedProduct?.id || savedProduct.page_id !== page.value.id) {
			return
		}

		const products = Array.isArray(page.value.products) ? page.value.products : []
		const existingIndex = products.findIndex((product) => product.id === savedProduct.id)
		let nextProducts = [savedProduct, ...products]

		if (existingIndex !== -1) {
			nextProducts = products.map((product) => (product.id === savedProduct.id ? savedProduct : product))
		}

		page.value = {
			...page.value,
			products: nextProducts
		}
	}

	function mergeSavedPrice(savedPrice) {
		if (!page.value?.id || !savedPrice?.id || savedPrice.page_id !== page.value.id) {
			return
		}

		const prices = Array.isArray(page.value.prices) ? page.value.prices : []
		const existingIndex = prices.findIndex((price) => price.id === savedPrice.id)
		const nextPrices = existingIndex === -1 ? [savedPrice, ...prices] : prices.map((price) => (price.id === savedPrice.id ? savedPrice : price))

		page.value = { ...page.value, prices: nextPrices }
	}

	function mergeSavedService(savedService) {
		if (!page.value?.id || !savedService?.id || savedService.page_id !== page.value.id) {
			return
		}

		const services = Array.isArray(page.value.services) ? page.value.services : []
		const existingIndex = services.findIndex((service) => service.id === savedService.id)
		let nextServices = [savedService, ...services]

		if (existingIndex !== -1) {
			nextServices = services.map((service) => (service.id === savedService.id ? savedService : service))
		}

		page.value = {
			...page.value,
			services: nextServices
		}
	}

	function mergeSavedEvent(savedEvent) {
		if (!page.value?.id || !savedEvent?.id || savedEvent.page_id !== page.value.id) {
			return
		}

		const events = Array.isArray(page.value.events) ? page.value.events : []
		const existingIndex = events.findIndex((event) => event.id === savedEvent.id)
		let nextEvents = [savedEvent, ...events]

		if (existingIndex !== -1) {
			nextEvents = events.map((event) => (event.id === savedEvent.id ? savedEvent : event))
		}

		page.value = {
			...page.value,
			events: nextEvents
		}
	}

	function confirmDeletePage() {
		if (!page.value) {
			return
		}

		$q.dialog({
			title: t('pages.deleteTitle'),
			message: t('pages.deleteMessage'),
			cancel: true,
			persistent: true,
			ok: {
				label: t('actions.deletePage'),
				color: 'negative',
				unelevated: true,
				rounded: true
			}
		}).onOk(deleteCurrentPage)
	}

	async function deleteCurrentPage() {
		if (!page.value) {
			return
		}

		deleting.value = true
		try {
			await deletePage(page.value.id)
			await authStore.refreshUser()
			$q.notify({ type: 'positive', message: t('pages.deleted') })
			router.push({ name: 'me' })
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('pages.deleteFailed')) })
		} finally {
			deleting.value = false
		}
	}

	async function handleAdSaved(savedAd) {
		adDialogOpen.value = false
		editingAd.value = null
		mergeSavedAd(savedAd)
		await load()
		mergeSavedAd(savedAd)
	}

	async function handleProductSaved(savedProduct) {
		productDialogOpen.value = false
		editingProduct.value = null
		mergeSavedProduct(savedProduct)
		await load()
		mergeSavedProduct(savedProduct)
	}

	async function handlePriceSaved(savedPrice) {
		priceDialogOpen.value = false
		editingPrice.value = null
		mergeSavedPrice(savedPrice)
		await load()
		mergeSavedPrice(savedPrice)
	}

	async function handleServiceSaved(savedService) {
		serviceDialogOpen.value = false
		editingService.value = null
		mergeSavedService(savedService)
		await load()
		mergeSavedService(savedService)
	}

	async function handleEventSaved(savedEvent) {
		eventDialogOpen.value = false
		editingEvent.value = null
		mergeSavedEvent(savedEvent)
		await load()
		mergeSavedEvent(savedEvent)
	}

	function dayLabel(weekday) {
		return t(`pages.weekdays.${weekday}`)
	}

	function syncRatingSummary(summary) {
		if (!page.value || !summary) {
			return
		}

		page.value = {
			...page.value,
			rating_summary: summary
		}
	}

	function filterCityOptions(value, update) {
		update(() => {
			citySelectOptions.value = filterOptions(cityOptions.value, value)
		})
	}

	function filterNeighborhoodOptions(value, update) {
		update(() => {
			neighborhoodSelectOptions.value = filterOptions(neighborhoodOptions.value, value)
		})
	}

	function attachObjectPreview(sourceRef, targetRef) {
		watch(sourceRef, (value, _, onCleanup) => {
			const resolvedFile = Array.isArray(value) ? value[0] : value

			if (!(resolvedFile instanceof File)) {
				targetRef.value = null
				return
			}

			const objectUrl = URL.createObjectURL(resolvedFile)
			targetRef.value = objectUrl

			onCleanup(() => {
				URL.revokeObjectURL(objectUrl)
			})
		})
	}

	watch(type, load)
	watch(pageTabs, (tabs) => {
		if (!tabs.some((tab) => tab.name === activeTab.value)) {
			activeTab.value = 'settings'
		}
	})
	watch(() => route.query.tab, (requestedTab) => {
		if (requestedTab && pageTabs.value.some((tab) => tab.name === requestedTab)) {
			activeTab.value = requestedTab
		}
	}, { immediate: true })
	watch(cityOptions, (options) => {
		citySelectOptions.value = options
	}, { immediate: true })

	watch(neighborhoodOptions, (options) => {
		neighborhoodSelectOptions.value = options
	}, { immediate: true })

	watch(() => form.address.city, () => {
		if (!form.address.city) {
			form.address.neighborhood = ''
			return
		}

		if (form.address.neighborhood && !hasOptionValue(neighborhoodOptions.value, form.address.neighborhood)) {
			form.address.neighborhood = ''
		}
	})

	watch(() => form.logo, (value) => {
		if (value) {
			logoRemoved.value = false
		}
	})

	watch(() => form.banner, (value) => {
		if (value) {
			bannerRemoved.value = false
		}
	})

	attachObjectPreview(() => form.logo, localLogoPreviewUrl)
	attachObjectPreview(() => form.banner, localBannerPreviewUrl)

	onMounted(async() => {
		await Promise.all([load(), loadLocationOptions(), loadCatalogTopics()])
		citySelectOptions.value = cityOptions.value
		neighborhoodSelectOptions.value = neighborhoodOptions.value
	})
</script>

<template>
	<q-page padding class="setup-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<div>
					<h1 class="soz-page-title">{{ title }}</h1>
				</div>
			</section>

			<q-tabs
				v-model="activeTab"
				class="setup-tabs q-mt-lg"
				active-color="primary"
				indicator-color="primary"
				align="left"
				no-caps
				inline-label
				mobile-arrows
				outside-arrows
			>
				<q-tab v-for="tab in pageTabs"
					:key="tab.name"
					:name="tab.name"
				>
					<span class="setup-tab-content">
						<PriceListIcon v-if="tab.name === 'price-list'" :size="21" />
						<q-icon v-else :name="tab.icon" />
						<span>{{ tab.label }}</span>
					</span>
				</q-tab>
			</q-tabs>

			<q-tab-panels v-model="activeTab" animated class="setup-panels">
				<q-tab-panel name="preview" class="setup-panel">
					<section class="soz-section-card panel">
						<div v-if="loading" class="row justify-center q-pa-lg">
							<q-spinner color="primary" />
						</div>
						<PagePreview v-else
							:page="previewPage"
							:palette="selectedPalette"
							:has-after-info="hasPreviewPlaceholders"
							:share-url="previewShareUrl"
							:can-chat="Boolean(page)"
							@show-ratings="ratingsDialogOpen = true"
							@chat="openPageChatTab"
						>
							<template #afterInfo>
								<div class="preview-placeholder-list">
									<div v-for="placeholder in previewContentPlaceholders"
										:key="placeholder.key"
										class="preview-placeholder-segment"
									>
										<a
											class="preview-placeholder-heading"
											:href="`#${placeholder.targetId}`"
											@click.prevent="openSetupTab(placeholder.tabName, placeholder.targetId)"
										>
											<span class="preview-placeholder-heading__icon">
												<PriceListIcon v-if="placeholder.key === 'price-list'" :size="22" />
												<q-icon v-else :name="placeholder.icon" size="22px" />
											</span>
											<span>{{ placeholder.label }}</span>
											<q-icon name="south" class="preview-placeholder-heading__arrow" />
										</a>
										<div class="preview-placeholder-card-grid" :class="{ 'preview-placeholder-card-grid--two': placeholder.cardCount === 2 }">
											<div v-for="index in placeholder.cardCount"
												:key="`${placeholder.key}-${index}`"
												class="preview-placeholder-card"
											>
												<span class="preview-placeholder-card__media" />
												<span class="preview-placeholder-card__line preview-placeholder-card__line--strong" />
												<span class="preview-placeholder-card__line" />
												<span class="preview-placeholder-card__line preview-placeholder-card__line--short" />
											</div>
										</div>
									</div>
								</div>
							</template>
						</PagePreview>
					</section>
				</q-tab-panel>

				<q-tab-panel name="settings" class="setup-panel">
					<div class="settings-grid">
						<section class="soz-section-card panel">
							<div class="panel-head panel-head--compact">
								<h2>{{ t('pages.modules') }}</h2>
							</div>

							<div v-if="isBusinessPage || isCommunityPage" class="feature-toggle-row">
								<button
									v-if="isBusinessPage"
									type="button"
									class="feature-toggle"
									:class="{ 'feature-toggle--active': isPriceListEnabled }"
									:aria-label="t('businessFeatures.togglePriceList')"
									:aria-pressed="isPriceListEnabled"
									:title="t('businessFeatures.togglePriceList')"
									:disabled="saving"
									@click="togglePageFeature('price_list')"
								>
									<span class="feature-toggle__dot" aria-hidden="true" />
									<span>{{ t('businessFeatures.priceList') }}</span>
								</button>
								<button
									v-if="isBusinessPage"
									type="button"
									class="feature-toggle"
									:class="{ 'feature-toggle--active': isStoreEnabled }"
									:aria-label="t('businessFeatures.toggleStore')"
									:aria-pressed="isStoreEnabled"
									:title="t('businessFeatures.toggleStore')"
									:disabled="saving"
									@click="togglePageFeature('store')"
								>
									<span class="feature-toggle__dot" aria-hidden="true" />
									<span>{{ t('businessFeatures.store') }}</span>
								</button>
								<button
									v-if="isBusinessPage"
									type="button"
									class="feature-toggle"
									:class="{ 'feature-toggle--active': isServicesEnabled }"
									:aria-label="t('businessFeatures.toggleServices')"
									:aria-pressed="isServicesEnabled"
									:title="t('businessFeatures.toggleServices')"
									:disabled="saving"
									@click="togglePageFeature('services')"
								>
									<span class="feature-toggle__dot" aria-hidden="true" />
									<span>{{ t('businessFeatures.services') }}</span>
								</button>
								<button
									v-if="isCommunityPage"
									type="button"
									class="feature-toggle"
									:class="{ 'feature-toggle--active': isEventsEnabled }"
									:aria-label="t('businessFeatures.toggleEvents')"
									:aria-pressed="isEventsEnabled"
									:title="t('businessFeatures.toggleEvents')"
									:disabled="saving"
									@click="togglePageFeature('events')"
								>
									<span class="feature-toggle__dot" aria-hidden="true" />
									<span>{{ t('businessFeatures.events') }}</span>
								</button>
							</div>
						</section>

						<section class="soz-section-card panel">
							<div class="panel-head">
								<h2>{{ t('pages.tabs.settings') }}</h2>
								<q-btn rounded
									unelevated
									color="negative"
									class="page-delete-btn"
									icon="delete"
									:disable="!page"
									:loading="deleting"
									:label="t('actions.deletePage')"
									@click="confirmDeletePage"
								/>
							</div>

							<div class="presence-grid">
								<div class="presence-editor">
									<q-form v-if="!loading" ref="formRef" greedy class="column q-gutter-md" @submit.prevent="save()">
										<q-input v-model="form.name" outlined :label="requiredLabel('pages.name')" :rules="[requiredRule]" />
										<q-input
											v-model="form.public_description"
											outlined
											type="textarea"
											autogrow
											:input-style="{ minHeight: '150px' }"
											:label="t('pages.description')"
										/>
										<CatalogCategorySelect
											v-model="form.category_key"
											:groups="catalogGroups"
											:scope="pageCatalogScope"
											required
											:label="requiredLabel('catalog.category')"
										/>
										<q-input
											v-model="form.website"
											outlined
											clearable
											inputmode="url"
											:label="t('pages.website')"
											:rules="[websiteRule]"
										/>

										<section class="presence-segment">
											<div class="presence-segment__title">{{ t('pages.sections.contact') }}</div>
											<div class="row q-col-gutter-md">
												<div class="col-12 col-md-4">
													<q-input v-model="form.phone" outlined :label="requiredLabel('pages.tel')" :rules="[requiredRule]" />
												</div>
												<div class="col-12 col-md-4">
													<q-input v-model="form.contact_email" outlined type="email" :label="requiredLabel('pages.email')" :rules="[requiredRule]" />
												</div>
												<div class="col-12 col-md-4">
													<q-input v-model="form.whatsapp" outlined :label="t('pages.whatsapp')" />
												</div>
											</div>
										</section>

										<section class="presence-segment">
											<div class="presence-segment__title">{{ t('pages.sections.address') }}</div>
											<div class="row q-col-gutter-md">
												<div class="col-12 col-md-4">
													<q-input
														v-model="form.address.street"
														outlined
														:label="requiredLabel('pages.street')"
														:rules="[requiredRule]"
													/>
												</div>
												<div class="col-12 col-md-2">
													<q-input
														v-model="form.address.number"
														outlined
														:label="requiredLabel('pages.number')"
														:rules="[requiredRule]"
													/>
												</div>
												<div class="col-12 col-md-3">
													<q-select v-model="form.address.city"
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
												</div>
												<div class="col-12 col-md-3">
													<q-select v-model="form.address.neighborhood"
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
														:disable="!form.address.city"
														@filter="filterNeighborhoodOptions"
														@new-value="addOption"
													/>
												</div>
											</div>
										</section>

										<section class="presence-segment">
											<div class="presence-segment__title">{{ t('pages.sections.socials') }}</div>
											<div class="row q-col-gutter-md">
												<div class="col-12 col-md-3">
													<q-input v-model="form.socials.facebook" outlined label="Facebook" />
												</div>
												<div class="col-12 col-md-3">
													<q-input v-model="form.socials.instagram" outlined label="Instagram" />
												</div>
												<div class="col-12 col-md-3">
													<q-input v-model="form.socials.tiktok" outlined label="TikTok" />
												</div>
												<div class="col-12 col-md-3">
													<q-input v-model="form.socials.telegram" outlined label="Telegram" />
												</div>
											</div>
										</section>

										<section class="presence-segment">
											<div class="presence-segment__title">{{ t('pages.sections.openingHours') }}</div>
											<div class="hours-grid">
												<div v-for="item in form.opening_hours" :key="item.weekday" class="hours-row">
													<div class="hours-row__day text-body2 text-weight-medium">{{ dayLabel(item.weekday) }}</div>
													<q-toggle v-model="item.is_open" :label="item.is_open ? t('pages.open') : t('pages.closed')" color="primary" />
													<q-input v-model="item.opens_at" outlined type="time" :disable="!item.is_open" :label="t('pages.opensAt')" />
													<q-input v-model="item.closes_at" outlined type="time" :disable="!item.is_open" :label="t('pages.closesAt')" />
												</div>
											</div>
										</section>

										<div class="upload-group q-mt-md">
											<div class="upload-row">
												<q-file v-model="form.logo"
													outlined
													clearable
													:accept="IMAGE_ACCEPT"
													:display-value="logoDisplayName"
													:label="t('pages.logo')"
												>
													<template #append>
														<q-btn
															v-if="hasStoredLogo"
															flat
															round
															dense
															color="negative"
															icon="delete"
															:aria-label="t('actions.delete')"
															@click.stop.prevent="removeStoredLogo"
														>
															<q-tooltip>{{ t('actions.delete') }}</q-tooltip>
														</q-btn>
													</template>
												</q-file>
												<q-btn type="button"
													rounded
													outline
													color="dark"
													:loading="logoUploading"
													:label="t('pages.uploadLogo')"
													@click="uploadLogo"
												/>
											</div>

											<div class="upload-row">
												<q-file v-model="form.banner"
													outlined
													clearable
													:accept="IMAGE_ACCEPT"
													:display-value="bannerDisplayName"
													:label="t('pages.banner')"
												>
													<template #append>
														<q-btn
															v-if="hasStoredBanner"
															flat
															round
															dense
															color="negative"
															icon="delete"
															:aria-label="t('actions.delete')"
															@click.stop.prevent="removeStoredBanner"
														>
															<q-tooltip>{{ t('actions.delete') }}</q-tooltip>
														</q-btn>
													</template>
												</q-file>
												<q-btn type="button"
													rounded
													outline
													color="dark"
													:loading="bannerUploading"
													:label="t('pages.uploadBanner')"
													@click="uploadBanner"
												/>
											</div>
										</div>

										<div class="section-label">{{ t('pages.palette') }}</div>
										<div class="palette-grid">
											<button
												v-for="palette in presencePalettes"
												:key="palette.key"
												type="button"
												class="palette-card"
												:class="{ 'palette-card--active': palette.key === form.palette_key }"
												:aria-pressed="palette.key === form.palette_key"
												@click="form.palette_key = palette.key"
											>
												<q-icon v-if="palette.key === form.palette_key" name="check_circle" class="palette-card__check" />
												<span class="palette-card__swatch" :style="{ background: palette.hero }" />
												<span class="palette-card__name">{{ t(palette.nameKey) }}</span>
											</button>
										</div>

										<div class="row items-center q-col-gutter-sm q-mt-md">
											<div class="col-auto">
												<q-btn rounded
													unelevated
													color="primary"
													type="button"
													:loading="saving"
													:label="t('pages.saveSettings')"
													@click="save"
												/>
											</div>
										</div>
									</q-form>
									<div v-else class="row justify-center q-pa-lg">
										<q-spinner color="primary" />
									</div>
								</div>
							</div>
						</section>
					</div>
				</q-tab-panel>

				<q-tab-panel v-if="isBusinessPage && isPriceListEnabled" name="price-list" class="setup-panel">
					<section id="page-prices-section" class="soz-section-card panel">
						<div class="panel-head">
							<h2>{{ t('priceList.title') }}</h2>
							<q-btn
								rounded
								unelevated
								color="primary"
								icon="add"
								:disable="!page"
								:label="t('priceList.add')"
								@click="openCreatePrice"
							/>
						</div>
						<div v-if="!page" class="empty-state">{{ t('pages.saveFirst') }}</div>
						<PriceList
							v-else
							:items="visiblePrices"
							editable
							@edit="openEditPrice"
							@delete="removePrice"
						/>
					</section>
				</q-tab-panel>

				<q-tab-panel v-if="isBusinessPage && isStoreEnabled" name="store" class="setup-panel">
					<section id="page-products-section" class="soz-section-card panel">
						<div class="panel-head">
							<h2>{{ t('products.storeTitle') }}</h2>
							<q-btn rounded
								unelevated
								color="primary"
								icon="add_shopping_cart"
								:disable="!page"
								:label="t('actions.addProduct')"
								@click="openCreateProduct"
							/>
						</div>
						<div v-if="!page" class="empty-state">{{ t('pages.saveFirst') }}</div>
						<div v-else-if="visibleProducts.length === 0" class="empty-state">{{ t('products.empty') }}</div>
						<div v-else class="product-grid">
							<ProductCard v-for="product in visibleProducts"
								:key="product.id"
								:product="product"
								editable
								@edit="openEditProduct"
								@delete="removeProduct"
							/>
						</div>
					</section>
				</q-tab-panel>

				<q-tab-panel v-if="isBusinessPage && isServicesEnabled" name="services" class="setup-panel">
					<section id="page-services-section" class="soz-section-card panel">
						<div class="panel-head">
							<h2>{{ t('businessServices.title') }}</h2>
							<q-btn rounded
								unelevated
								color="primary"
								icon="design_services"
								:disable="!page"
								:label="t('businessServices.addService')"
								@click="openCreateService"
							/>
						</div>
						<div v-if="!page" class="empty-state">{{ t('pages.saveFirst') }}</div>
						<div v-else-if="visibleServices.length === 0" class="empty-state">{{ t('businessServices.empty') }}</div>
						<div v-else class="service-list">
							<ServiceCard v-for="service in visibleServices"
								:key="service.id"
								:service="service"
								editable
								@edit="openEditService"
								@delete="removeService"
							/>
						</div>
					</section>
				</q-tab-panel>

				<q-tab-panel v-if="isCommunityPage && isEventsEnabled" name="events" class="setup-panel">
					<section id="page-events-section" class="soz-section-card panel">
						<div class="panel-head">
							<h2>{{ t('events.eventsTitle') }}</h2>
							<q-btn rounded
								unelevated
								color="primary"
								icon="event"
								:disable="!page"
								:label="t('actions.addEvent')"
								@click="openCreateEvent"
							/>
						</div>
						<div v-if="!page" class="empty-state">{{ t('pages.saveFirst') }}</div>
						<div v-else-if="visibleEvents.length === 0" class="empty-state">{{ t('events.empty') }}</div>
						<div v-else class="event-grid">
							<EventCard v-for="event in visibleEvents"
								:key="event.id"
								:event="event"
								editable
								@edit="openEditEvent"
								@delete="removeEvent"
							/>
						</div>
					</section>
				</q-tab-panel>

				<q-tab-panel name="chat" class="setup-panel">
					<section class="soz-section-card panel page-chat-panel">
						<div class="panel-head">
							<h2>{{ t('chat.title') }}</h2>
						</div>
						<ChatBlock v-if="page" :page-id="page.id" page-owner />
						<div v-else class="empty-state">{{ t('pages.saveFirst') }}</div>
					</section>
				</q-tab-panel>

				<q-tab-panel name="ads" class="setup-panel">
					<section class="soz-section-card panel">
						<div class="panel-head">
							<h2>{{ t('ads.listTitle') }}</h2>
							<q-btn rounded
								unelevated
								color="primary"
								icon="add"
								:disable="!page"
								:label="t('actions.createAd')"
								@click="openCreateAd"
							/>
						</div>
						<div v-if="!page" class="empty-state">{{ t('pages.saveFirst') }}</div>
						<div v-else-if="visibleAds.length === 0" class="empty-state">{{ t('ads.empty') }}</div>
						<div v-else class="listing-grid">
							<AdCard v-for="ad in visibleAds"
								:key="ad.id"
								:ad="ad"
								editable
								@edit="openEditAd"
								@delete="removeAd"
							/>
						</div>
					</section>
				</q-tab-panel>
			</q-tab-panels>
		</div>
		<q-dialog v-model="adDialogOpen">
			<q-card class="listing-dialog">
				<q-card-section class="dialog-head">
					<div class="text-h6">{{ adDialogTitle }}</div>
					<q-btn flat round icon="close" color="dark" v-close-popup />
				</q-card-section>
				<q-card-section>
					<AdComposer :page-id="page?.id" :ad="editingAd" :disabled="!page" @saved="handleAdSaved" />
				</q-card-section>
			</q-card>
		</q-dialog>
		<q-dialog v-model="priceDialogOpen">
			<q-card class="price-dialog">
				<q-card-section class="dialog-head">
					<div class="text-h6">{{ priceDialogTitle }}</div>
					<q-btn flat round icon="close" color="dark" v-close-popup />
				</q-card-section>
				<q-card-section>
					<PriceComposer
						v-if="page?.id"
						:page-id="page.id"
						:price="editingPrice"
						@saved="handlePriceSaved"
					/>
				</q-card-section>
			</q-card>
		</q-dialog>
		<q-dialog v-model="productDialogOpen">
			<q-card class="product-dialog">
				<q-card-section class="dialog-head">
					<div class="text-h6">{{ productDialogTitle }}</div>
					<q-btn flat round icon="close" color="dark" v-close-popup />
				</q-card-section>
				<q-card-section>
					<ProductComposer
						v-if="page?.id"
						:page-id="page.id"
						:product="editingProduct"
						:page-category-key="form.category_key"
						:catalog-groups="catalogGroups"
						@saved="handleProductSaved"
					/>
				</q-card-section>
			</q-card>
		</q-dialog>
		<q-dialog v-model="serviceDialogOpen">
			<q-card class="service-dialog">
				<q-card-section class="dialog-head">
					<div class="text-h6">{{ serviceDialogTitle }}</div>
					<q-btn flat round icon="close" color="dark" v-close-popup />
				</q-card-section>
				<q-card-section>
					<ServiceComposer
						v-if="page?.id"
						:page-id="page.id"
						:service="editingService"
						:page-category-key="form.category_key"
						:catalog-groups="catalogGroups"
						@saved="handleServiceSaved"
					/>
				</q-card-section>
			</q-card>
		</q-dialog>
		<q-dialog v-model="eventDialogOpen">
			<q-card class="event-dialog">
				<q-card-section class="dialog-head">
					<div class="text-h6">{{ eventDialogTitle }}</div>
					<q-btn flat round icon="close" color="dark" v-close-popup />
				</q-card-section>
				<q-card-section>
					<EventComposer
						v-if="page?.id"
						:page-id="page.id"
						:event="editingEvent"
						:page-category-key="form.category_key"
						:catalog-groups="catalogGroups"
						@saved="handleEventSaved"
					/>
				</q-card-section>
			</q-card>
		</q-dialog>
		<PageRatingsDialog
			v-model="ratingsDialogOpen"
			:page-id="page?.id"
			@loaded="syncRatingSummary"
		/>
	</q-page>
</template>

<style scoped lang="scss">
.setup-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.page-head,
.panel {
  padding: 28px;
}

.page-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
}

.page-head h1,
.panel h2 {
  margin: 0;
}

.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  margin-bottom: 18px;
}

.panel-head h2 {
  font-size: clamp(1.42rem, 1.75vw, 1.88rem);
  line-height: 1.18;
}

.panel-head--compact {
  margin-bottom: 0;
}

.setup-tabs {
  padding: 8px 14px;
  border: 1px solid var(--soz-line);
  border-radius: 30px;
  background: var(--soz-soft-white);
  backdrop-filter: blur(18px);
  box-shadow:
    0 18px 40px rgba(33, 18, 8, 0.04),
    inset 0 1px 0 rgba(255, 255, 255, 0.8);
}

.setup-tabs :deep(.q-tabs__content) {
  gap: 18px;
}

.setup-tabs :deep(.q-tabs__indicator),
.setup-tabs :deep(.q-tab__indicator) {
  display: none;
}

.setup-tabs :deep(.q-tab) {
  min-height: 54px;
  padding: 0 20px;
  border-radius: 999px;
  color: var(--soz-muted);
  transition:
    background-color 0.18s ease,
    box-shadow 0.18s ease,
    color 0.18s ease;
}

.setup-tabs :deep(.q-tab:hover) {
  background: var(--soz-primary-tint);
}

.setup-tabs :deep(.q-tab--active),
.setup-tabs :deep(.q-tab--active:hover) {
  background: var(--soz-menu-gradient);
  color: #ffffff !important;
}

.setup-tabs :deep(.q-tab--active .q-focus-helper) {
  opacity: 0 !important;
}

.setup-tabs :deep(.q-tab--active .q-icon),
.setup-tabs :deep(.q-tab--active .q-tab__label) {
  color: #ffffff !important;
}

.setup-tabs :deep(.q-tab__content) {
  gap: 8px;
}

.setup-tab-content {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  font-size: 1.2rem;
  line-height: 1;
}

.setup-tabs :deep(.q-tab__label) {
  font-size: 1.2rem;
}

.setup-panels {
  margin: 18px -34px 0;
  padding: 4px 34px 58px;
  background: transparent;
  overflow: hidden;
}

.setup-panel {
  padding: 0 0 52px;
  overflow: hidden;
}

.setup-panels :deep(.q-panel) {
  width: calc(100% + 68px);
  margin-inline: -34px;
  padding-inline: 34px;
  overflow: hidden;
}

.settings-grid {
  display: grid;
  gap: 18px;
}

.feature-toggle-row {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 20px;
}

.feature-toggle {
  display: inline-flex;
  gap: 12px;
  align-items: center;
  min-height: 46px;
  padding: 8px 18px 8px 10px;
  border: 0;
  border-radius: 999px;
  background: #e8ebf0;
  color: rgba(17, 34, 45, 0.72);
  box-shadow: inset 0 0 0 1px rgba(17, 34, 45, 0.06);
  cursor: pointer;
  font: inherit;
  font-size: 0.95rem;
  font-weight: 800;
  transition:
    background 0.18s ease,
    box-shadow 0.18s ease,
    color 0.18s ease,
    transform 0.18s ease;
}

.feature-toggle:hover:not(:disabled) {
  transform: translateY(-1px);
}

.feature-toggle:disabled {
  cursor: wait;
  opacity: 0.72;
}

.feature-toggle--active {
  background: var(--soz-action-gradient);
  color: #fff;
  box-shadow: 0 14px 28px rgba(245, 66, 145, 0.22);
}

.feature-toggle__dot {
  width: 28px;
  height: 28px;
  border-radius: 999px;
  background: #aeb5c1;
  box-shadow: inset 0 0 0 1px rgba(17, 34, 45, 0.08);
}

.feature-toggle--active .feature-toggle__dot {
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 6px 14px rgba(17, 34, 45, 0.14);
}

.preview-placeholder-list {
  display: grid;
  gap: 18px;
  min-width: 0;
}

.preview-placeholder-segment {
  display: grid;
  gap: 16px;
  min-height: 270px;
  padding: 20px;
  border: 1px dashed rgba(245, 66, 145, 0.45);
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.54);
}

.preview-placeholder-heading {
  display: inline-flex;
  align-items: center;
  gap: 12px;
  width: max-content;
  max-width: 100%;
  color: #151f2d;
  font-size: 1rem;
  font-weight: 800;
  text-decoration: none;
}

.preview-placeholder-heading__icon {
  display: grid;
  place-items: center;
  width: 44px;
  height: 44px;
  flex: 0 0 44px;
  border-radius: 999px;
  background: var(--soz-action-gradient);
  color: #fff;
  box-shadow: 0 12px 24px rgba(245, 66, 145, 0.22);
}

.preview-placeholder-heading__icon :deep(.q-icon) {
  font-size: 22px !important;
  line-height: 1 !important;
}

.preview-placeholder-heading__arrow {
  color: rgba(17, 34, 45, 0.48);
  font-size: 20px;
}

.preview-placeholder-card-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 14px;
}

.preview-placeholder-card-grid--two {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.preview-placeholder-card {
  display: grid;
  gap: 12px;
  min-height: 178px;
  padding: 14px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 16px;
  background: rgba(255, 255, 255, 0.76);
  box-shadow: 0 14px 28px rgba(17, 34, 45, 0.06);
}

.preview-placeholder-card__media,
.preview-placeholder-card__line {
  display: block;
  border-radius: 999px;
  background: linear-gradient(90deg, rgba(255, 124, 44, 0.18), rgba(245, 66, 145, 0.16), rgba(129, 69, 255, 0.16));
}

.preview-placeholder-card__media {
  height: 86px;
  border-radius: 12px;
}

.preview-placeholder-card__line {
  width: 100%;
  height: 10px;
}

.preview-placeholder-card__line--strong {
  width: 72%;
  height: 14px;
}

.preview-placeholder-card__line--short {
  width: 48%;
}

.page-delete-btn.q-btn.bg-negative {
  background: #e23f57 !important;
  box-shadow: 0 12px 24px rgba(226, 63, 87, 0.22) !important;
}

.presence-grid {
  display: block;
}

.presence-editor {
  display: grid;
  gap: 18px;
}

.presence-segment {
  display: grid;
  gap: 14px;
  padding: 18px;
  border: 1px solid rgba(17, 34, 45, 0.08);
  border-radius: 20px;
  background: rgba(255, 255, 255, 0.78);
}

.presence-segment__title {
  color: #151f2d;
  font-size: 15px;
  font-weight: 700;
}

.section-label {
  color: rgba(17, 34, 45, 0.52);
  font-size: 14px;
  font-weight: 700;
  letter-spacing: 0.14em;
  text-transform: uppercase;
}

.palette-grid {
  display: grid;
  grid-template-columns: repeat(8, minmax(0, 1fr));
  gap: 12px;
}

.palette-card {
  position: relative;
  display: grid;
  gap: 10px;
  padding: 12px;
  border: 2px solid rgba(17, 34, 45, 0.08);
  border-radius: 18px;
  background: rgba(255, 255, 255, 0.85);
  cursor: pointer;
  text-align: start;
  transition:
    transform 0.18s ease,
    border-color 0.18s ease,
    box-shadow 0.18s ease;
}

.palette-card:hover,
.palette-card--active {
  transform: translateY(-1px);
  box-shadow: 0 16px 30px rgba(17, 34, 45, 0.08);
}

.palette-card:hover {
  border-color: rgba(245, 66, 145, 0.46);
}

.palette-card--active {
  transform: translateY(-3px);
  border-color: #f54291;
  background: #fff;
  box-shadow:
    0 0 0 4px rgba(245, 66, 145, 0.18),
    0 20px 42px rgba(245, 66, 145, 0.22);
}

.palette-card__check {
  position: absolute;
  top: 8px;
  inset-inline-end: 8px;
  z-index: 1;
  display: grid;
  place-items: center;
  width: 28px;
  height: 28px;
  border-radius: 999px;
  background: #fff;
  color: #f54291;
  font-size: 24px;
  box-shadow: 0 8px 18px rgba(245, 66, 145, 0.28);
}

.palette-card__swatch {
  display: block;
  width: 100%;
  height: 44px;
  border-radius: 12px;
}

.palette-card__name {
  color: #151f2d;
  font-size: 0.94rem;
  font-weight: 600;
}

.upload-group,
.hours-grid {
  display: grid;
  gap: 12px;
}

.hours-row {
  display: grid;
  grid-template-columns: 150px 110px minmax(0, 1fr) minmax(0, 1fr);
  gap: 12px;
  align-items: center;
}

.upload-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
}

.product-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
}

.event-grid,
.service-list {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

.listing-grid {
  display: grid;
  grid-template-columns: 1fr;
  gap: 16px;
}

.empty-state {
  margin-top: 14px;
  color: rgba(17, 34, 45, 0.62);
}

.listing-dialog,
.price-dialog,
.product-dialog,
.service-dialog,
.event-dialog {
  width: min(680px, calc(100vw - 24px));
  max-width: 680px;
  border-radius: 24px;
  background: #f9f2eb;
}

.dialog-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}

@media (max-width: 1100px) {
  .listing-grid {
    grid-template-columns: 1fr;
  }

  .product-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .palette-grid {
    grid-template-columns: repeat(4, minmax(0, 1fr));
  }
}

@media (max-width: 800px) {
  .palette-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .upload-row {
    grid-template-columns: 1fr;
  }

  .hours-row {
    grid-template-columns: minmax(0, 1fr) auto;
  }

  .hours-row .q-field {
    grid-column: 1 / -1;
  }
}

@media (max-width: 760px) {
  .product-grid,
  .preview-placeholder-card-grid--two {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 700px) {
  .setup-page {
    padding-inline: 10px;
  }

  .page-head,
  .panel {
    padding: 20px;
  }

  .setup-tabs {
    border-radius: 22px;
    padding: 6px 38px;
  }

  .setup-tabs :deep(.q-tabs__content) {
    gap: 4px;
  }

  .setup-tabs :deep(.q-tab) {
    min-height: 40px;
    padding: 0 8px;
  }

  .setup-tabs :deep(.q-icon) {
    font-size: 18px;
  }

  .setup-tabs :deep(.q-tab__label) {
    font-size: 0.82rem;
    font-weight: 700;
  }

  .setup-tab-content {
    gap: 5px;
    font-size: 0.82rem;
    font-weight: 700;
  }

  .setup-tabs :deep(.q-tabs__arrow) {
    z-index: 2;
    min-width: 30px;
    color: var(--soz-ink);
    text-shadow: none;
  }

  .setup-tabs :deep(.q-tabs__arrow--left) {
    inset-inline-start: 4px;
  }

  .setup-tabs :deep(.q-tabs__arrow--right) {
    inset-inline-end: 4px;
  }

  .panel-head {
    align-items: flex-start;
    flex-direction: column;
  }

  .panel-head .q-btn,
  .feature-toggle {
    width: 100%;
  }

  .panel-head {
    align-items: stretch;
  }

  .listing-dialog,
  .price-dialog,
  .product-dialog,
  .service-dialog,
  .event-dialog {
    width: calc(100vw - 20px);
    max-height: calc(100dvh - 20px);
    border-radius: 20px;
  }

  .dialog-head {
    align-items: flex-start;
  }

  .presence-segment {
    gap: 10px;
    padding: 0;
    border: 0;
    border-radius: 0;
    background: transparent;
  }

  .palette-card {
    padding: 10px;
  }
}
</style>
