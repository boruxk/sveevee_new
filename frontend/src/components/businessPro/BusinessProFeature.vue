<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { matLock } from '@quasar/extras/material-icons'
	import { businessProFeatureAvailable, localizedProText } from '@/utils/businessPro'

	const props = defineProps({ feature: { type: Object, required: true } })
	const { locale, t } = useI18n()
	const available = computed(() => businessProFeatureAvailable(props.feature))
	const title = computed(() => localizedProText(props.feature.labels, locale.value) || props.feature.key)
	const description = computed(() => localizedProText(props.feature.descriptions, locale.value))
	const reason = computed(() => props.feature.locked_reason === 'subscription_required' ? (props.feature.key === 'featured_ads' ? 'businessPro.featuredAdRequiresPlan' : 'businessPro.subscriptionRequired') : 'businessPro.notAvailable')
</script>

<template>
	<article class="business-pro-feature" :class="{ 'business-pro-feature--locked': !available }" :aria-disabled="!available" :tabindex="!available ? 0 : undefined" :title="!available ? t(reason) : undefined">
		<header><h3>{{ title }}</h3><q-badge :color="available ? 'positive' : 'grey-7'">{{ t(available ? 'businessPro.available' : 'businessPro.locked') }}</q-badge></header>
		<p v-if="description">{{ description }}</p>
		<div v-if="$slots.default" :inert="!available || undefined"><slot /></div>
		<p v-if="!available" class="business-pro-feature__reason"><q-icon :name="matLock" size="18px" />{{ t(reason) }}</p>
		<q-tooltip v-if="!available">{{ t(reason) }}</q-tooltip>
	</article>
</template>

<style scoped>
.business-pro-feature { padding: 20px; border: 1px solid var(--soz-line); border-radius: 20px; min-width: 0; background: rgba(255,255,255,.8); overflow-wrap: anywhere; }
.business-pro-feature header { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; }
.business-pro-feature h3 { margin: 0; font-size: 19px; font-weight: 750; }
.business-pro-feature p { margin: 14px 0 0; }
.business-pro-feature__reason { display: flex; align-items: center; gap: 8px; color: var(--soz-muted); }
.business-pro-feature--locked { background: rgba(245,245,250,.85); }
</style>
