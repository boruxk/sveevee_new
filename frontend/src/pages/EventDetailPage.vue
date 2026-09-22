<script setup>
	import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { fetchNearbyEvent } from '@/services/api/community'
	import { useSeo } from '@/composables/useSeo'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { catalogLabel, catalogPath, catalogTopicByKey, pageRoute, userRoute } from '@/constants/catalogTopics'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import CommunityActions from '@/components/community/CommunityActions.vue'
	import CommunityDiscussion from '@/components/community/CommunityDiscussion.vue'

	const route = useRoute()
	const { t, locale } = useI18n()
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const event = ref(null)
	const loading = ref(false)
	const failed = ref(false)
	const unavailable = ref(false)
	let request = null
	const topic = computed(() => catalogTopicByKey(catalogGroups.value, event.value?.category_key))
	const topicLabel = computed(() => catalogLabel(topic.value?.labels, locale.value))
	const dateLabel = computed(() => {
		if (!event.value?.date) return ''
		const date = new Date(`${event.value.date}T00:00:00`)
		return Number.isNaN(date.getTime()) ? event.value.date : new Intl.DateTimeFormat(locale.value, { dateStyle: 'long' }).format(date)
	})
	const timeLabel = computed(() => [event.value?.time, event.value?.end_time].filter(Boolean).map(value => String(value).slice(0, 5)).join(' – '))
	const mapsUrl = computed(() => event.value?.address ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(event.value.address)}` : '')
	useSeo(computed(() => ({ title: event.value?.name || t('events.eventsTitle'), description: event.value?.description || '', image: event.value?.image_url, canonical: route.path, robots: 'noindex,follow' })))

	async function load() {
		request?.abort()
		const current = new AbortController()
		request = current
		loading.value = true
		failed.value = false
		unavailable.value = false
		event.value = null
		try {
			const { data } = await fetchNearbyEvent(route.params.id, { signal: current.signal })
			if (request !== current || current.signal.aborted) return
			event.value = data.data
		} catch (error) {
			if (request !== current || current.signal.aborted) return
			failed.value = true
			unavailable.value = [401, 403, 404, 410].includes(error.response?.status)
		} finally {
			if (request === current && !current.signal.aborted) loading.value = false
		}
	}

	watch(() => route.params.id, load, { immediate: true })
	watch(() => [event.value?.id, loading.value, route.hash], async() => {
		if (loading.value || !event.value || route.hash !== '#discussion') return
		await nextTick()
		document.getElementById('discussion')?.scrollIntoView()
	})
	onMounted(() => loadCatalogTopics().catch(() => {}))
	onBeforeUnmount(() => request?.abort())
</script>

<template>
	<q-page padding class="public-item-page event-detail-page">
		<div class="public-item-shell">
			<q-btn class="q-mb-md" flat no-caps :to="{ name: 'nearby' }" :label="t('community.nearby')" />
			<div v-if="loading" class="text-center q-pa-xl"><q-spinner color="primary" size="32px" /></div>
			<div v-else-if="failed" class="public-item-state" role="alert">
				<p>{{ t(unavailable ? 'community.itemUnavailable' : 'community.loadFailed') }}</p>
				<q-btn v-if="!unavailable" outline color="primary" :label="t('community.retry')" @click="load" />
			</div>
			<template v-else-if="event">
				<article class="public-item-detail" :class="{ 'public-item-detail--no-image': !event.image_url }">
					<div v-if="event.image_url" class="public-item-media">
						<ResponsiveImage class="public-item-image"
							:src="event.image_url"
							:alt="event.image_alt || event.name"
							:webp-srcset="event.image_webp_srcset || ''"
							:avif-srcset="event.image_avif_srcset || ''"
							sizes="(max-width: 860px) calc(100vw - 52px), 700px"
							:width="event.image_width || 768"
							:height="event.image_height || 576"
							loading="eager"
							fetchpriority="high"
						/>
					</div>
					<div class="public-item-body">
						<router-link v-if="topic" class="public-item-chip" :to="catalogPath(topic)"><q-icon name="category" size="18px" />{{ topicLabel }}</router-link>
						<h1>{{ event.name }}</h1>
						<router-link v-if="event.page" class="public-item-owner" :to="pageRoute(event.page)"><q-icon name="storefront" size="20px" />{{ event.page.name }}</router-link>
						<router-link v-else-if="event.user" class="public-item-owner" :to="userRoute(event.user)"><q-icon name="person" size="20px" />{{ event.user.display_name || event.user.name }}</router-link>
						<dl class="public-item-facts">
							<div v-if="dateLabel"><dt>{{ t('events.date') }}</dt><dd><time :datetime="event.date">{{ dateLabel }}</time></dd></div>
							<div v-if="timeLabel"><dt>{{ t('events.time') }}</dt><dd dir="auto">{{ timeLabel }}</dd></div>
							<div v-if="event.address"><dt>{{ t('events.address') }}</dt><dd><a :href="mapsUrl" target="_blank" rel="noopener noreferrer">{{ event.address }}</a></dd></div>
						</dl>
						<CommunityActions target-type="event" :target-id="event.id" :social="event.social || null" to="#discussion" />
					</div>
					<p v-if="event.description" class="public-item-description">{{ event.description }}</p>
				</article>
				<CommunityDiscussion :key="event.id"
					class="public-item-discussion"
					questions
					target-type="event"
					:target-id="event.id"
					:initial-count="Number(event.social?.comments_count || 0)"
				/>
			</template>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
@use '@/styles/public-item-detail';
</style>
