<script setup>
	defineProps({
		size: {
			type: [Number, String],
			default: 20
		}
	})
</script>

<template>
	<svg
		class="chevron-forward-icon"
		viewBox="0 0 24 24"
		:width="size"
		:height="size"
		fill="none"
		stroke="currentColor"
		stroke-width="2"
		stroke-linecap="round"
		stroke-linejoin="round"
		aria-hidden="true"
	>
		<path d="m9 18 6-6-6-6" />
	</svg>
</template>

<style scoped>
.chevron-forward-icon {
  display: block;
  flex: 0 0 auto;
}

:global([dir='rtl']) .chevron-forward-icon {
  transform: scaleX(-1);
}
</style>
