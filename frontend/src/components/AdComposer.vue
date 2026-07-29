<script setup>
	import { computed, onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { createAd } from '@/services/api/ads'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { useAuthStore } from '@/stores/auth'

	const props = defineProps({
		pageId: {
			type: [Number, String],
			default: null
		},
		disabled: {
			type: Boolean,
			default: false
		}
	})

	const emit = defineEmits(['saved'])
	const { t } = useI18n()
	const $q = useQuasar()
	const authStore = useAuthStore()
	const loading = ref(false)
	const formRef = ref(null)
	const form = reactive({
		title: '',
		text: '',
		city: '',
		neighborhood: '',
		image: null
	})
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
	const defaultCity = computed(() => authStore.user?.profile?.city || '')
	const defaultNeighborhood = computed(() => authStore.user?.profile?.neighborhood || '')
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		addOption,
		filterOptions
	} = useLocationOptions(toRef(form, 'city'))

	async function submit() {
		const valid = await formRef.value?.validate()

		if (!valid) {
			return
		}

		loading.value = true

		try {
			const { data } = await createAd({
				...form,
				page_id: props.pageId
			})
			rememberLocation(data.data?.city || form.city, data.data?.neighborhood || form.neighborhood)
			form.title = ''
			form.text = ''
			form.image = null
			emit('saved', data.data)
			$q.notify({ type: 'positive', message: t('actions.createAd') })
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('ads.saveFailed') })
		} finally {
			loading.value = false
		}
	}

	function applyDefaultLocation() {
		if (!form.city && defaultCity.value) {
			form.city = defaultCity.value
		}

		if (!form.neighborhood && defaultNeighborhood.value) {
			form.neighborhood = defaultNeighborhood.value
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

	watch(() => authStore.user?.profile, applyDefaultLocation, { immediate: true })

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
		await loadLocationOptions()
		citySelectOptions.value = cityOptions.value
		neighborhoodSelectOptions.value = neighborhoodOptions.value
		applyDefaultLocation()
	})
</script>

<template>
	<q-form ref="formRef" class="ad-composer" @submit.prevent="submit">
		<q-input v-model="form.title" outlined :label="t('ads.title')" :disable="disabled" :rules="[(value) => !!String(value || '').trim()]" />
		<q-input v-model="form.text"
			outlined
			type="textarea"
			autogrow
			:label="t('ads.text')"
			:disable="disabled"
			:rules="[(value) => !!String(value || '').trim()]"
		/>
		<div class="ad-composer__row ad-composer__row--location">
			<q-select v-model="form.city"
				outlined
				clearable
				use-input
				hide-selected
				fill-input
				input-debounce="0"
				new-value-mode="add-unique"
				:options="citySelectOptions"
				:label="t('auth.city')"
				:disable="disabled"
				@filter="filterCityOptions"
				@new-value="addOption"
			/>
			<q-select v-model="form.neighborhood"
				outlined
				clearable
				use-input
				hide-selected
				fill-input
				input-debounce="0"
				new-value-mode="add-unique"
				:options="neighborhoodSelectOptions"
				:label="t('auth.neighborhood')"
				:disable="disabled || !form.city"
				@filter="filterNeighborhoodOptions"
				@new-value="addOption"
			/>
		</div>
		<div class="ad-composer__row">
			<q-file v-model="form.image"
				outlined
				clearable
				accept="image/*"
				:label="t('ads.image')"
				:disable="disabled"
			/>
			<q-btn color="primary"
				unelevated
				rounded
				type="submit"
				icon="add"
				:loading="loading"
				:disable="disabled"
				:label="t('actions.createAd')"
			/>
		</div>
	</q-form>
</template>

<style scoped lang="scss">
.ad-composer {
  display: grid;
  gap: 14px;
}

.ad-composer__row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
}

.ad-composer__row--location {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

@media (max-width: 700px) {
  .ad-composer__row {
    grid-template-columns: 1fr;
  }
}
</style>
