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
		class="bulk-edit-icon"
		viewBox="0 0 24 24"
		:style="{ width: `${size}px`, height: `${size}px` }"
		aria-hidden="true"
	>
		<path d="M4 6.5h9M4 11.5h7M4 16.5h5" />
		<path d="m13.2 17.8.7-3.2 5.7-5.7a1.5 1.5 0 0 1 2.1 2.1L16 16.7l-2.8 1.1Z" />
		<path d="m18.6 9.9 2.1 2.1" />
	</svg>
</template>

<style scoped>
.bulk-edit-icon {
  display: block;
  flex: 0 0 auto;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}
</style>
