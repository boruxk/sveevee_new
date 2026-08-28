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
		class="ai-works-icon"
		viewBox="0 0 24 24"
		:style="{ width: `${size}px`, height: `${size}px` }"
		aria-hidden="true"
	>
		<path d="M10.5 5V3.6M8.9 3.6h3.2" />
		<rect x="3.5" y="5.5" width="14" height="13.5" rx="3" />
		<circle cx="8" cy="11" r="1" />
		<circle cx="13" cy="11" r="1" />
		<path d="M7.5 15h6M3.5 10H2M19 10h-1.5" />
		<path d="m20 3 .55 1.45L22 5l-1.45.55L20 7l-.55-1.45L18 5l1.45-.55L20 3Z" />
	</svg>
</template>

<style scoped>
.ai-works-icon {
  display: block;
  flex: 0 0 auto;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.7;
}
</style>
