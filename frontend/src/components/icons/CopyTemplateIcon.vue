<script setup>
	defineProps({
		size: {
			type: [Number, String],
			default: 24
		}
	})
</script>

<template>
	<svg
		class="copy-template-icon"
		viewBox="0 0 24 24"
		:style="{ width: `${size}px`, height: `${size}px` }"
		aria-hidden="true"
	>
		<rect x="7" y="7" width="12.5" height="13" rx="2" />
		<path d="M16.5 7V5.5a2 2 0 0 0-2-2h-10a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2H7" />
		<path d="m11.2 11-1.8 2 1.8 2" />
		<path d="m15.3 11 1.8 2-1.8 2" />
	</svg>
</template>

<style scoped>
.copy-template-icon {
  display: block;
  flex: 0 0 auto;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}
</style>
