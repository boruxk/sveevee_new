import { reactive, ref } from 'vue'
import { fetchCatalog } from '@/services/api/catalog'

const catalogGroups = ref([])
const catalogPopularTopics = ref([])
const catalogTopicsLoading = ref(false)
let catalogPromise = null
const scopedCatalogGroups = reactive(new Map())
const scopedCatalogPopularTopics = reactive(new Map())
const scopedCatalogPromises = new Map()

export function useCatalogTopics() {
	async function loadCatalogTopics(scope = '') {
		const scopeKey = Array.isArray(scope) ? scope.filter(Boolean).join(',') : String(scope || '')

		if (scopeKey) {
			if (scopedCatalogGroups.has(scopeKey)) {
				return scopedCatalogGroups.get(scopeKey)
			}

			if (!scopedCatalogPromises.has(scopeKey)) {
				const promise = fetchCatalog({ scope: scopeKey })
					.then(({ data }) => {
						const groups = data.data?.groups || []
						scopedCatalogGroups.set(scopeKey, groups)
						scopedCatalogPopularTopics.set(scopeKey, data.data?.popular_topics || [])

						return groups
					})
					.finally(() => {
						scopedCatalogPromises.delete(scopeKey)
					})

				scopedCatalogPromises.set(scopeKey, promise)
			}

			return scopedCatalogPromises.get(scopeKey)
		}

		if (catalogGroups.value.length > 0) {
			return catalogGroups.value
		}

		if (!catalogPromise) {
			catalogTopicsLoading.value = true
			catalogPromise = fetchCatalog()
				.then(({ data }) => {
					catalogGroups.value = data.data?.groups || []
					catalogPopularTopics.value = data.data?.popular_topics || []

					return catalogGroups.value
				})
				.finally(() => {
					catalogTopicsLoading.value = false
					catalogPromise = null
				})
		}

		return catalogPromise
	}

	return {
		catalogGroups,
		catalogPopularTopics,
		scopedCatalogGroups,
		scopedCatalogPopularTopics,
		catalogTopicsLoading,
		loadCatalogTopics
	}
}
