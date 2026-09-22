<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'
	import SubscriptionsPanel from './SubscriptionsPanel.vue'

	const props = defineProps({ modelValue: { type: Boolean, default: false } })
	const emit = defineEmits(['update:modelValue', 'changed'])
	const { t } = useI18n()
	const open = computed({ get: () => props.modelValue, set: value => emit('update:modelValue', value) })
</script>

<template>
	<q-dialog v-model="open">
		<q-card class="subscriptions-dialog">
			<SubscriptionsPanel v-if="open" @changed="emit('changed')">
				<template #headerActions><q-btn flat round icon="close" :aria-label="t('community.close')" @click="open = false" /></template>
			</SubscriptionsPanel>
		</q-card>
	</q-dialog>
</template>

<style scoped>
.subscriptions-dialog { width: min(660px, 95vw); max-width: 95vw; border-radius: 24px; padding: 22px; }
@media (max-width: 600px) { .subscriptions-dialog { padding: 16px; } }
</style>
