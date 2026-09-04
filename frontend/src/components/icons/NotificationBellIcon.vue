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
		:viewBox="'0 0 24 24'"
		:width="size"
		:height="size"
		fill="none"
		stroke="currentColor"
		stroke-width="1.9"
		stroke-linecap="round"
		stroke-linejoin="round"
		aria-hidden="true"
	>
		<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z" />
		<path d="M13.7 21a2 2 0 0 1-3.4 0" />
	</svg>
</template>
