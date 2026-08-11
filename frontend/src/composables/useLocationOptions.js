import { computed, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { fetchLocations } from '@/services/api/locations'
import { hasOptionValue, locationOption, optionLabel, optionValue } from '@/utils/locationLabels'

const state = reactive({
	cities: [],
	neighborhoods: [],
	loaded: false,
	loading: false
})

function clean(value) {
	return String(value || '').trim()
}

function sortValues(values) {
	return [...new Set(values.map(clean).filter(Boolean))]
		.sort((left, right) => left.localeCompare(right))
}

function localeSortLabel(locale) {
	return locale === 'he' ? 'he' : undefined
}

function optionList(values, type, locale) {
	return sortValues(values)
		.map((value) => locationOption(value, type, locale))
		.sort((left, right) => optionLabel(left).localeCompare(optionLabel(right), localeSortLabel(locale)))
}

export function useLocationOptions(cityRef = null) {
	const { locale } = useI18n({ useScope: 'global' })
	const cityOptions = computed(() => optionList(state.cities, 'city', locale.value))
	const neighborhoodOptions = computed(() => {
		const city = clean(cityRef?.value)

		if (cityRef && !city) {
			return []
		}

		const values = state.neighborhoods
			.filter((location) => !city || !location.city || location.city === city)
			.map((location) => location.name)

		return optionList(values, 'neighborhood', locale.value)
	})

	async function loadLocationOptions() {
		if (state.loaded || state.loading) {
			return
		}

		state.loading = true

		try {
			const { data } = await fetchLocations()
			state.cities = sortValues(data.data?.cities || [])
			state.neighborhoods = (data.data?.neighborhoods || [])
				.map((location) => ({
					city: clean(location.city),
					name: clean(location.name)
				}))
				.filter((location) => location.name)
			state.loaded = true
		} catch {
			state.loaded = false
		} finally {
			state.loading = false
		}
	}

	function rememberLocation(city, neighborhood) {
		const cleanCity = clean(city)
		const cleanNeighborhood = clean(neighborhood)

		if (cleanCity) {
			state.cities = sortValues([...state.cities, cleanCity])
		}

		if (cleanNeighborhood) {
			const key = `${cleanCity}|${cleanNeighborhood}`.toLocaleLowerCase()
			const exists = state.neighborhoods.some((location) => `${location.city}|${location.name}`.toLocaleLowerCase() === key)

			if (!exists) {
				state.neighborhoods = [
					...state.neighborhoods,
					{ city: cleanCity, name: cleanNeighborhood }
				]
			}
		}
	}

	function addOption(value, done) {
		const option = clean(value)

		if (option) {
			done(option, 'add-unique')
		}
	}

	function filterOptions(options, needle) {
		const normalizedNeedle = clean(needle).toLocaleLowerCase()

		if (!normalizedNeedle) {
			return options
		}

		return options.filter((option) => {
			const label = optionLabel(option).toLocaleLowerCase()
			const value = optionValue(option).toLocaleLowerCase()

			return label.includes(normalizedNeedle) || value.includes(normalizedNeedle)
		})
	}

	return {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		addOption,
		filterOptions,
		hasOptionValue
	}
}
