<script setup>
	import { computed, onMounted, reactive, ref, watch } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { deleteAd } from '@/services/api/ads'
	import { fetchMyPage, saveMyPage } from '@/services/api/pages'
	import { findPresencePalette, presencePalettes } from '@/constants/presencePalettes'
	import AdCard from '@/components/AdCard.vue'
	import AdComposer from '@/components/AdComposer.vue'
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
	const { t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const loading = ref(false)
	const saving = ref(false)
	const logoUploading = ref(false)
	const bannerUploading = ref(false)
	const setupDialogOpen = ref(false)
	const ratingsDialogOpen = ref(false)
	const page = ref(null)
	const localLogoPreviewUrl = ref(null)
	const localBannerPreviewUrl = ref(null)
	const form = reactive({
		name: '',
		public_description: '',
		contact_email: '',
		phone: '',
		whatsapp: '',
		address: {
			street: '',
			number: '',
			city: ''
		},
		opening_hours: [],
		palette_key: presencePalettes[0].key,
		logo: null,
		banner: null
	})

	const type = computed(() => route.meta.pageType || 'business')
	const title = computed(() => (type.value === 'business' ? t('pages.businessTitle') : t('pages.communityTitle')))
	const selectedPalette = computed(() => findPresencePalette(form.palette_key))
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
			city: form.address.city
		},
		opening_hours: form.opening_hours.map((item) => ({ ...item })),
		palette_key: form.palette_key,
		logo_url: localLogoPreviewUrl.value || page.value?.logo_url || null,
		banner_url: localBannerPreviewUrl.value || page.value?.banner_url || null,
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
		return [address.street, address.number, address.city].filter(Boolean).join(', ')
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
		form.opening_hours = normalizedOpeningHours(value?.opening_hours || setup.opening_hours)
		form.palette_key = findPresencePalette(value?.palette_key || setup.palette_key).key
		form.logo = null
		form.banner = null
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
					city: form.address.city.trim() || null
				},
				opening_hours: form.opening_hours.map((item) => ({
					weekday: item.weekday,
					is_open: item.is_open,
					opens_at: item.is_open ? item.opens_at || null : null,
					closes_at: item.is_open ? item.closes_at || null : null
				}))
			},
			logo: form.logo,
			banner: form.banner
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
		saving.value = true
		try {
			const { data } = await saveMyPage(type.value, pagePayload())
			hydrate(data.data)
			await authStore.refreshUser()
			if (notify) {
				$q.notify({ type: 'positive', message: t('pages.saved') })
			}
			return true
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('pages.saveFailed') })
			return false
		} finally {
			saving.value = false
		}
	}

	async function uploadLogo() {
		if (!form.logo) {
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
		if (!form.banner) {
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
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('ads.deleteFailed') })
		}
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
	attachObjectPreview(() => form.logo, localLogoPreviewUrl)
	attachObjectPreview(() => form.banner, localBannerPreviewUrl)

	onMounted(load)
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
					<q-btn rounded
						unelevated
						color="primary"
						class="page-setup-btn"
						icon="settings"
						:label="t('pages.setup')"
						@click="setupDialogOpen = true"
					/>
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

			<section class="soz-section-card panel q-mt-lg">
				<h2 class="create-ad-title">{{ t('actions.createAd') }}</h2>
				<AdComposer :page-id="page?.id" :disabled="!page" @saved="load" />
				<div v-if="!page" class="empty-state">{{ t('pages.saveFirst') }}</div>
			</section>

			<section class="soz-section-card panel q-mt-lg">
				<h2>{{ t('ads.listTitle') }}</h2>
				<div v-if="!page?.ads?.length" class="empty-state">{{ t('ads.empty') }}</div>
				<div v-else class="ad-grid">
					<AdCard v-for="ad in page.ads" :key="ad.id" :ad="ad" editable @delete="removeAd" />
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
							<q-form v-if="!loading" class="column q-gutter-md" @submit.prevent="save">
								<q-input v-model="form.name" outlined :label="t('pages.name')" />
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
											<q-input v-model="form.phone" outlined :label="t('pages.tel')" />
										</div>
										<div class="col-12 col-md-4">
											<q-input v-model="form.contact_email" outlined type="email" :label="t('pages.email')" />
										</div>
										<div class="col-12 col-md-4">
											<q-input v-model="form.whatsapp" outlined :label="t('pages.whatsapp')" />
										</div>
									</div>
								</section>

								<section class="presence-segment">
									<div class="presence-segment__title">{{ t('pages.sections.address') }}</div>
									<div class="row q-col-gutter-md">
										<div class="col-12 col-md-5">
											<q-input v-model="form.address.street" outlined :label="t('pages.street')" />
										</div>
										<div class="col-12 col-md-3">
											<q-input v-model="form.address.number" outlined :label="t('pages.number')" />
										</div>
										<div class="col-12 col-md-4">
											<q-input v-model="form.address.city" outlined :label="t('pages.city')" />
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

								<div class="upload-group q-mt-md">
									<div class="upload-row">
										<q-file v-model="form.logo" outlined clearable accept=".jpg,.jpeg,.png,.webp,image/png,image/jpeg,image/webp" :label="t('pages.logo')" />
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
										<q-file v-model="form.banner" outlined clearable accept=".jpg,.jpeg,.png,.webp,image/png,image/jpeg,image/webp" :label="t('pages.banner')" />
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

								<div class="row items-center q-col-gutter-sm q-mt-md">
									<div class="col-auto">
										<q-btn rounded
											unelevated
											color="primary"
											type="submit"
											:loading="saving"
											:label="t('pages.saveSettings')"
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

.create-ad-title {
  margin-bottom: 28px !important;
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

.page-setup-btn.q-btn.bg-primary {
  background: var(--soz-action-gradient) !important;
  box-shadow: 0 14px 28px rgba(245, 66, 145, 0.22) !important;
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
  grid-template-columns: repeat(2, minmax(0, 1fr));
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

.ad-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
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
  .ad-grid {
    grid-template-columns: 1fr;
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
}
</style>
