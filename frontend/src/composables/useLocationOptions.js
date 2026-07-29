import { computed, reactive } from 'vue'
import { fetchLocations } from '@/services/api/locations'

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

export function useLocationOptions(cityRef = null) {
	const cityOptions = computed(() => state.cities)
	const neighborhoodOptions = computed(() => {
		const city = clean(cityRef?.value)

		if (cityRef && !city) {
			return []
		}

		const values = state.neighborhoods
			.filter((location) => !city || !location.city || location.city === city)
			.map((location) => location.name)

		return sortValues(values)
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

		return options.filter((option) => option.toLocaleLowerCase().includes(normalizedNeedle))
	}

	return {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		addOption,
		filterOptions
	}
}
