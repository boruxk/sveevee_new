import { ref } from 'vue'
import { fetchCatalog } from '@/services/api/catalog'

const catalogGroups = ref([])
const catalogPopularTopics = ref([])
const catalogTopicsLoading = ref(false)
let catalogPromise = null

export function useCatalogTopics() {
	async function loadCatalogTopics() {
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
		catalogTopicsLoading,
		loadCatalogTopics
	}
}
