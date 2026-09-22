<script setup>
	import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { fetchService } from '@/services/api/services'
	import { useSeo } from '@/composables/useSeo'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { catalogLabel, catalogPath, catalogTopicByKey, pageRoute } from '@/constants/catalogTopics'
	import { locationLabel } from '@/utils/locationLabels'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import CommunityDiscussion from '@/components/community/CommunityDiscussion.vue'

	const route = useRoute()
	const { t, locale } = useI18n()
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const service = ref(null)
	const loading = ref(false)
	const failed = ref(false)
	const unavailable = ref(false)
	let request = null
	const page = computed(() => service.value?.page || null)
	const address = computed(() => page.value?.address_details || {})
	const topic = computed(() => catalogTopicByKey(catalogGroups.value, service.value?.category_key))
	const topicLabel = computed(() => catalogLabel(topic.value?.labels, locale.value))
	const location = computed(() => [
		address.value.city ? locationLabel(address.value.city, 'city', locale.value) : '',
		address.value.neighborhood ? locationLabel(address.value.neighborhood, 'neighborhood', locale.value) : ''
	].filter(Boolean).join(' / '))
	const serviceLink = computed(() => {
		const link = String(service.value?.link || '').trim()
		return /^(https?:\/\/|mailto:|tel:)/i.test(link) ? link : ''
	})
	useSeo(computed(() => ({
		title: service.value?.name || t('businessServices.title'),
		description: service.value?.description || '',
		image: service.value?.image_url,
		canonical: route.path,
		robots: 'noindex,follow'
	})))

	async function load() {
		request?.abort()
		const current = new AbortController()
		request = current
		loading.value = true
		failed.value = false
		unavailable.value = false
		service.value = null
		try {
			const { data } = await fetchService(route.params.id, { signal: current.signal })
			if (request !== current || current.signal.aborted) return
			service.value = data.data
		} catch (error) {
			if (request !== current || current.signal.aborted) return
			failed.value = true
			unavailable.value = [401, 403, 404, 410].includes(error.response?.status)
		} finally {
			if (request === current && !current.signal.aborted) loading.value = false
		}
	}

	watch(() => route.params.id, load, { immediate: true })
	watch(() => [service.value?.id, loading.value, route.hash], async() => {
		if (loading.value || !service.value || route.hash !== '#discussion') return
		await nextTick()
		document.getElementById('discussion')?.scrollIntoView()
	})
	onMounted(() => loadCatalogTopics().catch(() => {}))
	onBeforeUnmount(() => request?.abort())
</script>

<template>
	<q-page padding class="public-item-page service-detail-page">
		<div class="public-item-shell">
			<div v-if="loading" class="text-center q-pa-xl"><q-spinner color="primary" size="32px" /></div>
			<div v-else-if="failed" class="public-item-state" role="alert">
				<p>{{ t(unavailable ? 'community.itemUnavailable' : 'community.loadFailed') }}</p>
				<q-btn v-if="!unavailable" outline color="primary" :label="t('community.retry')" @click="load" />
			</div>
			<template v-else-if="service">
				<article class="public-item-detail" :class="{ 'public-item-detail--no-image': !service.image_url }">
					<div v-if="service.image_url" class="public-item-media">
						<ResponsiveImage class="public-item-image"
							:src="service.image_url"
							:alt="service.image_alt || service.name"
							:webp-srcset="service.image_webp_srcset || ''"
							:avif-srcset="service.image_avif_srcset || ''"
							sizes="(max-width: 860px) calc(100vw - 52px), 700px"
							:width="service.image_width || 768"
							:height="service.image_height || 576"
							loading="eager"
							fetchpriority="high"
						/>
					</div>
					<div class="public-item-body">
						<div v-if="topic || location" class="public-item-meta">
							<router-link v-if="topic" class="public-item-chip" :to="catalogPath(topic, address.city, address.neighborhood)"><q-icon name="category" size="18px" />{{ topicLabel }}</router-link>
							<span v-if="location" class="public-item-chip"><q-icon name="place" size="18px" />{{ location }}</span>
						</div>
						<h1>{{ service.name }}</h1>
						<router-link v-if="page" class="public-item-owner" :to="pageRoute(page)"><q-icon name="storefront" size="20px" />{{ page.name }}</router-link>
						<div v-if="serviceLink" class="public-item-actions">
							<q-btn rounded
								unelevated
								color="primary"
								icon="open_in_new"
								:href="serviceLink"
								target="_blank"
								rel="noopener noreferrer"
								:label="t('businessServices.visit')"
							/>
						</div>
					</div>
					<p v-if="service.description" class="public-item-description">{{ service.description }}</p>
				</article>
				<CommunityDiscussion :key="service.id"
					class="public-item-discussion"
					questions
					target-type="service"
					:target-id="service.id"
					:initial-count="Number(service.social?.comments_count || 0)"
				/>
			</template>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
@use '@/styles/public-item-detail';
</style>
