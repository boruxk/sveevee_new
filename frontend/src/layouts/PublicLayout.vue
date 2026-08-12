<template>
	<AppShell :tone="shellTone">
		<router-view />
	</AppShell>
</template>

<script setup>
	import { computed } from 'vue'
	import { useRoute } from 'vue-router'
	import AppShell from '@/components/AppShell.vue'

	const route = useRoute()
	const shellTone = computed(() => (route.name === 'search' ? 'user' : 'public'))
</script>
