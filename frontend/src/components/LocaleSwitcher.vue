<script setup>
	import { computed } from 'vue'
	import { useAppStore } from '@/stores/app'
	import { setLocale } from '@/i18n'

	const appStore = useAppStore()
	const flag = (...points) => String.fromCodePoint(...points)

	const localeOptions = computed(() => [
		{ label: flag(0x1f1ee, 0x1f1f1), value: 'he' },
		{ label: flag(0x1f1fa, 0x1f1f8), value: 'en' },
		{ label: flag(0x1f1f7, 0x1f1fa), value: 'ru' },
		{ label: flag(0x1f1eb, 0x1f1f7), value: 'fr' }
	])
</script>

<template>
	<q-select
		dense
		borderless
		rounded
		dropdown-icon="expand_more"
		popup-content-class="locale-switcher-menu"
		standout="bg-white text-primary"
		:model-value="appStore.locale"
		:options="localeOptions"
		emit-value
		map-options
		class="locale-switcher"
		style="min-width: 68px"
		@update:model-value="setLocale"
	/>
</template>

<style scoped lang="scss">
.locale-switcher {
  :deep(.q-field__control) {
    border-radius: 999px;
    min-height: 42px;
    padding-inline: 6px;
  }

  :deep(.q-field__native),
  :deep(.q-field__input) {
    min-height: 42px;
    padding-top: 0;
    padding-bottom: 0;
    font-size: 20px;
    line-height: 1;
  }
}
</style>
