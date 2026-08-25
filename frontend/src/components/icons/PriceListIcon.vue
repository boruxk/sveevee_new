<script setup>
	defineProps({
		size: {
			type: [Number, String],
			default: 22
		}
	})
</script>

<template>
	<svg
		class="price-list-icon"
		viewBox="0 0 24 24"
		:style="{ width: `${size}px`, height: `${size}px` }"
		aria-hidden="true"
	>
		<path d="M6.5 3.5h9.2l2.8 2.8v14.2h-12z" />
		<path d="M15.5 3.8v3h2.8M9 10h6M9 13.5h6M9 17h3.3" />
		<path d="M15.2 15.6c1.5-.4 2.4.1 2.4 1.1 0 .9-.7 1.3-1.8 1.3h-1.2m1.2-3.3v4.2" />
	</svg>
</template>

<style scoped>
.price-list-icon {
  display: block;
  flex: 0 0 auto;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 1.7;
}
</style>
