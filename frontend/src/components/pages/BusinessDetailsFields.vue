<script setup>
	import { computed, nextTick, ref, watch } from 'vue'
	import { useI18n } from 'vue-i18n'

	const props = defineProps({
		serviceAreas: {
			type: Array,
			default: () => []
		},
		specialties: {
			type: Array,
			default: () => []
		},
		cityOptions: {
			type: Array,
			default: () => []
		},
		disabled: {
			type: Boolean,
			default: false
		}
	})

	const emit = defineEmits(['update:serviceAreas', 'update:specialties'])
	const { t } = useI18n()
	const specialtySelect = ref(null)
	const specialtyInput = ref('')
	const filteredCityOptions = ref([])
	const MAX_SERVICE_AREAS = 10
	const MAX_SPECIALTIES = 50

	function cleanList(values, limit) {
		const seen = new Set()

		return (Array.isArray(values) ? values : [])
			.map((value) => String(value || '').trim())
			.filter((value) => {
				const key = value.toLocaleLowerCase()

				if (!value || seen.has(key)) {
					return false
				}

				seen.add(key)
				return true
			})
			.slice(0, limit)
	}

	function optionText(option) {
		if (option && typeof option === 'object') {
			return String(option.label || option.value || '')
		}

		return String(option || '')
	}

	const serviceAreasModel = computed({
		get: () => props.serviceAreas,
		set: (value) => emit('update:serviceAreas', cleanList(value, MAX_SERVICE_AREAS))
	})
	const specialtiesModel = computed({
		get: () => props.specialties,
		set: (value) => emit('update:specialties', cleanList(value, MAX_SPECIALTIES))
	})
	const serviceAreasLabel = computed(() => t('pages.serviceAreasCount', {
		count: props.serviceAreas.length,
		max: MAX_SERVICE_AREAS
	}))

	function filterServiceAreas(value, update) {
		const needle = String(value || '').trim().toLocaleLowerCase()

		update(() => {
			if (!needle) {
				filteredCityOptions.value = props.cityOptions
				return
			}

			filteredCityOptions.value = props.cityOptions
				.filter((option) => optionText(option).toLocaleLowerCase().includes(needle))
		})
	}

	function appendSpecialties(values) {
		emit('update:specialties', cleanList([
			...props.specialties,
			...(Array.isArray(values) ? values : [])
		], MAX_SPECIALTIES))
	}

	function setSpecialtyInput(value) {
		const input = String(value || '')
		specialtyInput.value = input

		if (!/[,،]/u.test(input)) {
			return
		}

		const pieces = input.split(/[,،]/u)
		const remainder = pieces.pop() || ''
		appendSpecialties(pieces)
		specialtyInput.value = remainder
		nextTick(() => specialtySelect.value?.updateInputValue(remainder, true))
	}

	function createSpecialty(value, done) {
		appendSpecialties(String(value || '').split(/[,،]/u))
		specialtyInput.value = ''
		done()
	}

	function commitPending() {
		if (!specialtyInput.value.trim()) {
			return
		}

		appendSpecialties([specialtyInput.value])
		specialtyInput.value = ''
		specialtySelect.value?.updateInputValue('', true)
	}

	watch(() => props.cityOptions, (options) => {
		filteredCityOptions.value = options
	}, { immediate: true })

	defineExpose({ commitPending })
</script>

<template>
	<div class="business-details-fields">
		<section class="business-detail-segment">
			<div class="business-detail-segment__title">{{ t('pages.sections.serviceAreas') }}</div>
			<q-select
				v-model="serviceAreasModel"
				outlined
				multiple
				use-input
				use-chips
				emit-value
				map-options
				input-debounce="0"
				:max-values="MAX_SERVICE_AREAS"
				:options="filteredCityOptions"
				:label="serviceAreasLabel"
				:disable="disabled"
				@filter="filterServiceAreas"
			/>
		</section>

		<section class="business-detail-segment">
			<div class="business-detail-segment__title">{{ t('pages.sections.specialties') }}</div>
			<q-select
				ref="specialtySelect"
				v-model="specialtiesModel"
				outlined
				multiple
				use-input
				use-chips
				hide-dropdown-icon
				input-debounce="0"
				new-value-mode="add-unique"
				maxlength="120"
				:max-values="MAX_SPECIALTIES"
				:label="t('pages.specialties')"
				:placeholder="t('pages.specialtiesPlaceholder')"
				:disable="disabled"
				@input-value="setSpecialtyInput"
				@new-value="createSpecialty"
				@blur="commitPending"
			/>
		</section>
	</div>
</template>

<style scoped>
.business-details-fields {
	display: grid;
	gap: 14px;
}

.business-detail-segment {
	display: grid;
	gap: 14px;
	padding: 18px;
	border: 1px solid rgba(17, 34, 45, 0.08);
	border-radius: 20px;
	background: rgba(255, 255, 255, 0.78);
}

.business-detail-segment__title {
	color: #151f2d;
	font-size: 15px;
	font-weight: 700;
}

@media (max-width: 700px) {
	.business-detail-segment {
		gap: 10px;
		padding: 0;
		border: 0;
		border-radius: 0;
		background: transparent;
	}
}
</style>
