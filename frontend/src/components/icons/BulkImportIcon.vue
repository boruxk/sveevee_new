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
		class="bulk-import-icon"
		viewBox="0 0 24 24"
		:style="{ width: `${size}px`, height: `${size}px` }"
		aria-hidden="true"
	>
		<path d="M6.5 3.5h7l4 4v4.2" />
		<path d="M13.5 3.5v4h4" />
		<path d="M9.4 19.5H6.5a2 2 0 0 1-2-2v-12a2 2 0 0 1 2-2" />
		<path d="M15.5 11.7v7.1" />
		<path d="m12.7 16 2.8 2.8 2.8-2.8" />
		<path d="M11.8 21h7.4" />
	</svg>
</template>

<style scoped>
.bulk-import-icon {
  display: block;
  flex: 0 0 auto;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}
</style>
