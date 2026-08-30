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
		class="delete-icon"
		viewBox="0 0 24 24"
		:style="{ width: size + 'px', height: size + 'px' }"
		aria-hidden="true"
	>
		<path d="M4.5 7h15" />
		<path d="M9 7V4.8h6V7" />
		<path d="m6.5 7 .8 12h9.4l.8-12" />
		<path d="M10 10.5v5.2M14 10.5v5.2" />
	</svg>
</template>

<style scoped>
.delete-icon {
  display: block;
  flex: 0 0 auto;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.8;
}
</style>
