<script setup>
	import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { deleteAd } from '@/services/api/ads'
	import { deletePage, fetchMyPage, saveMyPage } from '@/services/api/pages'
	import { findPresencePalette, presencePalettes } from '@/constants/presencePalettes'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import { IMAGE_ACCEPT, imageUploadDisplayName } from '@/utils/imageUploads'
	import AdCard from '@/components/AdCard.vue'
	import AdComposer from '@/components/AdComposer.vue'
	import EventCard from '@/components/events/EventCard.vue'
	import EventComposer from '@/components/events/EventComposer.vue'
	import ProductCard from '@/components/products/ProductCard.vue'
	import ProductComposer from '@/components/products/ProductComposer.vue'
	import PagePreview from '@/components/pages/PagePreview.vue'
	import PageRatingsDialog from '@/components/ratings/PageRatingsDialog.vue'

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
	const { t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const loading = ref(false)
	const saving = ref(false)
	const deleting = ref(false)
	const logoUploading = ref(false)
	const bannerUploading = ref(false)
	const formRef = ref(null)
	const setupDialogOpen = ref(false)
	const adDialogOpen = ref(false)
	const productDialogOpen = ref(false)
	const eventDialogOpen = ref(false)
	const ratingsDialogOpen = ref(false)
	const editingAd = ref(null)
	const editingProduct = ref(null)
	const page = ref(null)
	const localLogoPreviewUrl = ref(null)
	const localBannerPreviewUrl = ref(null)
	const logoRemoved = ref(false)
	const bannerRemoved = ref(false)
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
	const form = reactive({
		name: '',
		public_description: '',
		contact_email: '',
		phone: '',
		whatsapp: '',
		address: {
			street: '',
			number: '',
			city: '',
			neighborhood: ''
		},
		opening_hours: [],
		palette_key: presencePalettes[0].key,
		logo: null,
		banner: null
	})

	const type = computed(() => route.meta.pageType || 'business')
	const isBusinessPage = computed(() => type.value === 'business')
	const isCommunityPage = computed(() => type.value === 'community')
	const title = computed(() => (type.value === 'business' ? t('pages.businessTitle') : t('pages.communityTitle')))
	const selectedPalette = computed(() => findPresencePalette(form.palette_key))
	const adDialogTitle = computed(() => (editingAd.value ? t('actions.update') : t('actions.createAd')))
	const productDialogTitle = computed(() => (editingProduct.value ? t('actions.update') : t('actions.addProduct')))
	const visibleAds = computed(() => (Array.isArray(page.value?.ads) ? page.value.ads.filter((ad) => ad?.id) : []))
	const visibleProducts = computed(() => (Array.isArray(page.value?.products) ? page.value.products.filter((product) => product?.id) : []))
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
		filterOptions
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
		address_details: {
			street: form.address.street,
			number: form.address.number,
			city: form.address.city,
			neighborhood: form.address.neighborhood
		},
		opening_hours: form.opening_hours.map((item) => ({ ...item })),
		palette_key: form.palette_key,
		logo_url: logoRemoved.value ? null : localLogoPreviewUrl.value || page.value?.logo_url || null,
		banner_url: bannerRemoved.value ? null : localBannerPreviewUrl.value || page.value?.banner_url || null,
		rating_summary: page.value?.rating_summary || { average: 0, count: 0 }
	}))

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

	function hydrate(value) {
		const setup = value?.setup || {}
		const contact = value?.contact || setup.contact || {}
		const address = value?.address_details || setup.address || {}

		page.value = value
		form.name = cleanPageText(value?.name)
		form.public_description = cleanPageText(value?.public_description)
		form.contact_email = contact.email || value?.contact_email || ''
		form.phone = contact.tel || value?.phone || ''
		form.whatsapp = contact.whatsapp || ''
		form.address.street = address.street || ''
		form.address.number = address.number || ''
		form.address.city = address.city || ''
		form.address.neighborhood = address.neighborhood || ''
		form.opening_hours = normalizedOpeningHours(value?.opening_hours || setup.opening_hours)
		form.palette_key = findPresencePalette(value?.palette_key || setup.palette_key).key
		form.logo = null
		form.banner = null
		logoRemoved.value = false
		bannerRemoved.value = false
	}

	function pagePayload() {
		return {
			name: form.name.trim(),
			public_description: form.public_description.trim(),
			contact_email: form.contact_email.trim(),
			phone: form.phone.trim(),
			address: addressLine(form.address),
			palette_key: form.palette_key,
			setup: {
				contact: {
					tel: form.phone.trim() || null,
					email: form.contact_email.trim() || null,
					whatsapp: form.whatsapp.trim() || null
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
				}))
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
				setupDialogOpen.value = false
			}
			return true
		} catch (error) {
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('pages.saveFailed')) })
			return false
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

	async function handleEventSaved() {
		eventDialogOpen.value = false
		await load()
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

		if (form.address.neighborhood && !neighborhoodOptions.value.includes(form.address.neighborhood)) {
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
		await Promise.all([load(), loadLocationOptions()])
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
					<p>{{ t('pages.setupSubtitle') }}</p>
				</div>
			</section>

			<section class="soz-section-card panel q-mt-lg">
				<div class="preview-head">
					<div>
						<h2>{{ t('pages.previewDialogTitle') }}</h2>
						<p>{{ t('pages.helper') }}</p>
					</div>
					<div class="page-toolbar-actions">
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
						<q-btn rounded
							unelevated
							color="primary"
							class="page-setup-btn"
							icon="settings"
							:label="t('pages.setup')"
							@click="setupDialogOpen = true"
						/>
					</div>
				</div>

				<div v-if="loading" class="row justify-center q-pa-lg">
					<q-spinner color="primary" />
				</div>
				<PagePreview v-else
					class="q-mt-lg"
					:page="previewPage"
					:palette="selectedPalette"
					@show-ratings="ratingsDialogOpen = true"
				/>
			</section>

			<section v-if="isBusinessPage" class="soz-section-card panel q-mt-lg">
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
					<ProductCard v-for="product in visibleProducts" :key="product.id" :product="product" editable @edit="openEditProduct" />
				</div>
			</section>

			<section v-if="isCommunityPage" class="soz-section-card panel q-mt-lg">
				<div class="panel-head">
					<h2>{{ t('events.eventsTitle') }}</h2>
					<q-btn rounded
						unelevated
						color="primary"
						icon="event"
						:disable="!page"
						:label="t('actions.addEvent')"
						@click="eventDialogOpen = true"
					/>
				</div>
				<div v-if="!page" class="empty-state">{{ t('pages.saveFirst') }}</div>
				<div v-else-if="!page.events?.length" class="empty-state">{{ t('events.empty') }}</div>
				<div v-else class="event-grid">
					<EventCard v-for="event in page.events" :key="event.id" :event="event" />
				</div>
			</section>

			<section class="soz-section-card panel q-mt-lg">
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
		</div>

		<q-dialog v-model="setupDialogOpen">
			<q-card class="setup-dialog">
				<q-card-section class="dialog-head">
					<div>
						<div class="text-h6">{{ t('pages.setup') }}</div>
						<div class="text-body2 text-grey-7">{{ t('pages.helper') }}</div>
					</div>
					<q-btn flat round icon="close" color="dark" v-close-popup />
				</q-card-section>

				<q-card-section class="setup-dialog__body">
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
												use-input
												hide-selected
												fill-input
												input-debounce="0"
												new-value-mode="add-unique"
												:options="neighborhoodSelectOptions"
												:label="requiredLabel('auth.neighborhood')"
												:rules="[requiredRule]"
												:disable="!form.address.city"
												@filter="filterNeighborhoodOptions"
												@new-value="addOption"
											/>
										</div>
									</div>
								</section>

								<section class="presence-segment">
									<div class="presence-segment__title">{{ t('pages.sections.openingHours') }}</div>
									<div class="hours-grid">
										<div v-for="item in form.opening_hours" :key="item.weekday" class="hours-row">
											<div class="text-body2 text-weight-medium">{{ dayLabel(item.weekday) }}</div>
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
										@click="form.palette_key = palette.key"
									>
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
									<div class="col text-caption text-grey-7">
										{{ t('pages.saveHint') }}
									</div>
								</div>
							</q-form>
							<div v-else class="row justify-center q-pa-lg">
								<q-spinner color="primary" />
							</div>
						</div>
					</div>
				</q-card-section>
			</q-card>
		</q-dialog>
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
		<q-dialog v-model="productDialogOpen">
			<q-card class="product-dialog">
				<q-card-section class="dialog-head">
					<div class="text-h6">{{ productDialogTitle }}</div>
					<q-btn flat round icon="close" color="dark" v-close-popup />
				</q-card-section>
				<q-card-section>
					<ProductComposer v-if="page?.id" :page-id="page.id" :product="editingProduct" @saved="handleProductSaved" />
				</q-card-section>
			</q-card>
		</q-dialog>
		<q-dialog v-model="eventDialogOpen">
			<q-card class="event-dialog">
				<q-card-section class="dialog-head">
					<div class="text-h6">{{ t('actions.addEvent') }}</div>
					<q-btn flat round icon="close" color="dark" v-close-popup />
				</q-card-section>
				<q-card-section>
					<EventComposer v-if="page?.id" :page-id="page.id" @saved="handleEventSaved" />
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

.page-head p,
.preview-head p {
  max-width: 720px;
  margin: 8px 0 0;
  color: rgba(17, 34, 45, 0.66);
  line-height: 1.6;
}

.preview-head {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 18px;
  align-items: start;
}

.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  margin-bottom: 18px;
}

.page-setup-btn.q-btn.bg-primary {
  background: var(--soz-action-gradient) !important;
  box-shadow: 0 14px 28px rgba(245, 66, 145, 0.22) !important;
}

.page-toolbar-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
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
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px;
}

.palette-card {
  display: grid;
  gap: 10px;
  padding: 12px;
  border: 1px solid rgba(17, 34, 45, 0.08);
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
  border-color: rgba(245, 66, 145, 0.5);
  box-shadow: 0 16px 30px rgba(17, 34, 45, 0.08);
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

.product-grid,
.event-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
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

.setup-dialog {
  display: flex;
  flex-direction: column;
  width: min(1080px, calc(100vw - 24px));
  max-width: 1080px;
  max-height: calc(100vh - 32px);
  border-radius: 30px;
  background: #f9f2eb;
}

.listing-dialog,
.product-dialog,
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

.setup-dialog__body {
  overflow-y: auto;
  padding-top: 0;
}

@media (max-width: 1100px) {
  .preview-head,
  .listing-grid,
  .product-grid,
  .event-grid {
    grid-template-columns: 1fr;
  }

  .palette-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 800px) {
  .palette-grid,
  .hours-row,
  .upload-row {
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

  .panel-head {
    align-items: flex-start;
    flex-direction: column;
  }
}
</style>
