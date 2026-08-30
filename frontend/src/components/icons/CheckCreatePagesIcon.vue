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
		class="check-create-pages-icon"
		viewBox="0 0 24 24"
		:style="{ width: `${size}px`, height: `${size}px` }"
		aria-hidden="true"
	>
		<path d="M12.2 20H6a2 2 0 0 1-2-2V5.8a2 2 0 0 1 2-2h8.2l3.8 3.8v3.1" />
		<path d="M14 3.8v4h4" />
		<path d="M8 9.5h3.5M8 13h3" />
		<circle cx="17" cy="17" r="4" />
		<path d="m15.2 17 1.2 1.2 2.4-2.5" />
	</svg>
</template>

<style scoped>
.check-create-pages-icon {
  display: block;
  flex: 0 0 auto;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}
</style>
