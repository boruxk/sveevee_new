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
		class="json-save-icon"
		viewBox="0 0 24 24"
		:style="{ width: `${size}px`, height: `${size}px` }"
		aria-hidden="true"
	>
		<path d="M7.5 4.5H5.8A1.8 1.8 0 0 0 4 6.3v2.2c0 1.1-.6 1.9-1.5 2.2.9.3 1.5 1.1 1.5 2.2v2.8a1.8 1.8 0 0 0 1.8 1.8h1.7" />
		<path d="M16.5 4.5h1.7A1.8 1.8 0 0 1 20 6.3v2.2c0 1.1.6 1.9 1.5 2.2-.9.3-1.5 1.1-1.5 2.2v2.8a1.8 1.8 0 0 1-1.8 1.8h-1.7" />
		<path d="m8.5 11.3 2.2 2.2 4.8-5" />
		<path d="M8.5 19.5h7" />
	</svg>
</template>

<style scoped>
.json-save-icon {
  display: block;
  flex: 0 0 auto;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}
</style>
