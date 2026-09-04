<script setup>
	import { computed } from 'vue'

	const props = defineProps({
		type: {
			type: String,
			default: ''
		},
		size: {
			type: [Number, String],
			default: 21
		}
	})

	const paths = {
		page_claim_approved: ['M5 12.5 9.2 17 19 7', 'M12 2.8 4 6v5.2c0 5 3.4 8.4 8 10 4.6-1.6 8-5 8-10V6l-8-3.2Z'],
		page_claim_rejected: ['m7 7 10 10', 'm17 7-10 10', 'M12 2.8 4 6v5.2c0 5 3.4 8.4 8 10 4.6-1.6 8-5 8-10V6l-8-3.2Z'],
		page_assigned: ['M15 19.5v-1.2a4.3 4.3 0 0 0-4.3-4.3H6.3A4.3 4.3 0 0 0 2 18.3v1.2', 'M8.5 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M16 11h6', 'M19 8v6'],
		page_detached: ['M14 19.5v-1.2a4.3 4.3 0 0 0-4.3-4.3H5.3A4.3 4.3 0 0 0 1 18.3v1.2', 'M7.5 10a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z', 'M16 11h7'],
		page_deleted: ['M4 7h16', 'M9 7V4h6v3', 'm7 7 1 13h10l1-13', 'M10 11v5', 'M14 11v5'],
		page_rating_received: ['m12 2.5 2.9 5.9 6.5 1-4.7 4.6 1.1 6.5-5.8-3.1-5.8 3.1 1.1-6.5-4.7-4.6 6.5-1L12 2.5Z'],
		page_claim_submitted: ['M4 4h16v16H4z', 'M8 9h8', 'M8 13h5', 'm15.5 15.5 1.5 1.5 3-3'],
		lead_page_created: ['M4 3h12l4 4v14H4z', 'M16 3v5h4', 'M8 13h8', 'M8 17h5']
	}
	const iconPaths = computed(() => paths[props.type] || ['M4 4h16v16H4z', 'M12 8v5', 'M12 17h.01'])
</script>

<template>
	<svg
		viewBox="0 0 24 24"
		:width="size"
		:height="size"
		fill="none"
		stroke="currentColor"
		stroke-width="1.8"
		stroke-linecap="round"
		stroke-linejoin="round"
		aria-hidden="true"
	>
		<path v-for="path in iconPaths" :key="path" :d="path" />
	</svg>
</template>
