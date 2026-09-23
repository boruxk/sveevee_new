<script setup>
	import { useI18n } from 'vue-i18n'
	import { matStars } from '@quasar/extras/material-icons'
	const { t } = useI18n()
</script>

<template>
	<span class="featured-ad-badge"><q-icon :name="matStars" size="14px" />{{ t('businessPro.featuredAdBadge') }}</span>
</template>

<style scoped>
.featured-ad-badge { display: inline-flex; flex-shrink: 0; align-items: center; gap: 4px; width: fit-content; max-width: 100%; min-height: 24px; padding: 3px 9px; border-radius: 999px; background: #ffe4ba; color: #82430b; font-size: 11px; line-height: 16px; font-weight: 750; white-space: nowrap; }
</style>
