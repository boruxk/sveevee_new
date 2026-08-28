<script setup>
	defineProps({
		size: {
			type: [Number, String],
			default: 26
		}
	})
</script>

<template>
	<svg
		class="unclaimed-page-icon"
		viewBox="0 0 24 24"
		:style="{ width: `${size}px`, height: `${size}px` }"
		aria-hidden="true"
	>
		<path d="M12 2.8 19 5.7v5.1c0 4.5-2.8 8.5-7 10.4-4.2-1.9-7-5.9-7-10.4V5.7L12 2.8Z" />
		<path d="M12 10.6v5" />
		<circle cx="12" cy="7.8" r=".8" class="unclaimed-page-icon__dot" />
	</svg>
</template>

<style scoped>
.unclaimed-page-icon {
  display: block;
  flex: 0 0 auto;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}

.unclaimed-page-icon__dot {
  fill: currentColor;
  stroke: none;
}
</style>
