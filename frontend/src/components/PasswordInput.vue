<script setup>
	import { ref } from 'vue'

	defineOptions({
		inheritAttrs: false
	})

	defineProps({
		modelValue: {
			type: String,
			default: ''
		},
		label: {
			type: String,
			default: ''
		},
		rules: {
			type: Array,
			default: () => []
		},
		autocomplete: {
			type: String,
			default: 'current-password'
		}
	})

	defineEmits(['update:modelValue'])

	const visible = ref(false)
</script>

<template>
	<q-input
		v-bind="$attrs"
		:model-value="modelValue"
		outlined
		:type="visible ? 'text' : 'password'"
		:label="label"
		:rules="rules"
		:autocomplete="autocomplete"
		@update:model-value="$emit('update:modelValue', $event)"
	>
		<template #append>
			<q-btn
				flat
				round
				dense
				type="button"
				:icon="visible ? 'visibility_off' : 'visibility'"
				:aria-label="visible ? 'Hide password' : 'Show password'"
				@click.prevent="visible = !visible"
			/>
		</template>
	</q-input>
</template>
