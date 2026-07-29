<script setup>
	import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { fetchProfile, updateProfile, uploadProfilePhoto } from '@/services/api/profile'

	const { t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const supportedLanguages = ['he', 'en', 'ru', 'fr']
	const loading = ref(false)
	const saving = ref(false)
	const photo = ref(null)
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
	const form = reactive({
		email: '',
		given_name: '',
		family_name: '',
		phone: '',
		city: '',
		neighborhood: '',
		languages: ['he']
	})
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		addOption,
		filterOptions
	} = useLocationOptions(toRef(form, 'city'))
	const languageOptions = computed(() => [
		{ label: t('languages.he'), value: 'he' },
		{ label: t('languages.en'), value: 'en' },
		{ label: t('languages.ru'), value: 'ru' },
		{ label: t('languages.fr'), value: 'fr' }
	])

	function hydrate(profile) {
		form.email = profile?.email || authStore.user?.email || ''
		form.given_name = authStore.user?.given_name || ''
		form.family_name = authStore.user?.family_name || ''
		form.phone = profile?.phone || ''
		form.city = profile?.city || ''
		form.neighborhood = profile?.neighborhood || ''
		const languages = profile?.languages?.filter((item) => supportedLanguages.includes(item)) || []
		form.languages = languages.length ? [...languages] : ['he']
	}

	async function load() {
		loading.value = true
		try {
			await authStore.refreshUser()
			const { data } = await fetchProfile()
			hydrate(data.data)
		} finally {
			loading.value = false
		}
	}

	async function save() {
		saving.value = true
		try {
			await updateProfile(form)
			rememberLocation(form.city, form.neighborhood)
			if (photo.value) {
				await uploadProfilePhoto(photo.value)
				photo.value = null
			}
			await authStore.refreshUser()
			hydrate(authStore.user.profile)
			$q.notify({ type: 'positive', message: t('profile.saved') })
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('profile.saveFailed') })
		} finally {
			saving.value = false
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

	watch(cityOptions, (options) => {
		citySelectOptions.value = options
	}, { immediate: true })

	watch(neighborhoodOptions, (options) => {
		neighborhoodSelectOptions.value = options
	}, { immediate: true })

	watch(() => form.city, () => {
		if (!form.city) {
			form.neighborhood = ''
			return
		}

		if (form.neighborhood && !neighborhoodOptions.value.includes(form.neighborhood)) {
			form.neighborhood = ''
		}
	})

	onMounted(async() => {
		await Promise.all([load(), loadLocationOptions()])
		citySelectOptions.value = cityOptions.value
		neighborhoodSelectOptions.value = neighborhoodOptions.value
	})
</script>

<template>
	<q-page padding class="profile-page">
		<div class="page-shell">
			<section class="soz-section-card page-head">
				<div>
					<h1 class="soz-page-title">{{ t('profile.title') }}</h1>
					<p>{{ t('profile.subtitle') }}</p>
				</div>
			</section>

			<section class="soz-section-card profile-panel q-mt-lg">
				<q-form v-if="!loading" class="column q-gutter-md q-pl-md q-pt-lg" @submit.prevent="save">
					<div class="row q-col-gutter-md q-pb-md">
						<q-input class="col-12 col-md-4" v-model="form.email" outlined type="email" :label="t('auth.email')" />
						<q-input class="col-12 col-md-4" v-model="form.given_name" outlined :label="t('auth.givenName')" />
						<q-input class="col-12 col-md-4" v-model="form.family_name" outlined :label="t('auth.familyName')" />
					</div>
					<div class="row q-col-gutter-md q-pb-md">
						<q-input class="col-12 col-md-4" v-model="form.phone" outlined :label="t('auth.phone')" />
						<q-select class="col-12 col-md-4"
							v-model="form.city"
							outlined
							clearable
							use-input
							hide-selected
							fill-input
							input-debounce="0"
							new-value-mode="add-unique"
							:options="citySelectOptions"
							:label="t('auth.city')"
							@filter="filterCityOptions"
							@new-value="addOption"
						/>
						<q-select class="col-12 col-md-4"
							v-model="form.neighborhood"
							outlined
							clearable
							use-input
							hide-selected
							fill-input
							input-debounce="0"
							new-value-mode="add-unique"
							:options="neighborhoodSelectOptions"
							:label="t('auth.neighborhood')"
							:disable="!form.city"
							@filter="filterNeighborhoodOptions"
							@new-value="addOption"
						/>
					</div>
					<div class="row q-col-gutter-md q-pb-md">
						<q-select class="col-12 col-md-6"
							v-model="form.languages"
							outlined
							multiple
							emit-value
							map-options
							:options="languageOptions"
							:label="t('profile.languages')"
						/>
						<q-file class="col-12 col-md-6"
							v-model="photo"
							outlined
							clearable
							accept="image/*"
							:label="t('profile.photo')"
						/>
					</div>
					<q-btn class="form-submit"
						color="primary"
						unelevated
						rounded
						type="submit"
						icon="save"
						:loading="saving"
						:label="t('actions.save')"
					/>
				</q-form>
				<q-spinner v-else color="primary" />
			</section>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.profile-page {
  padding: 0 20px 36px;
}

.page-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.page-head,
.profile-panel {
  padding: 28px;
}

.form-submit {
  margin-inline-start: 0 !important;
}
</style>
