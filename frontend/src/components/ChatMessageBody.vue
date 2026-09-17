<script setup>
	import { computed } from 'vue'
	import { chatMessageSegments } from '@/utils/chatMessageLinks'

	const props = defineProps({
		body: { type: String, default: '' }
	})
	const segments = computed(() => chatMessageSegments(props.body))
</script>

<template>
	<div class="chat-message-body" dir="auto">
		<template v-for="(segment, index) in segments" :key="index">
			<a
				v-if="segment.type === 'link'"
				:href="segment.href"
				target="_blank"
				rel="noopener noreferrer"
				dir="auto"
			>{{ segment.text }}</a>
			<template v-else>{{ segment.text }}</template>
		</template>
	</div>
</template>

<style scoped>
.chat-message-body {
  min-width: 0;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  unicode-bidi: plaintext;
}

.chat-message-body a {
  color: inherit;
  text-decoration: underline;
  text-underline-offset: 0.15em;
  unicode-bidi: isolate;
}
</style>
