<script setup>
	defineOptions({ inheritAttrs: false })

	defineProps({
		modelValue: {
			type: String,
			default: ''
		},
		placeholder: {
			type: String,
			default: ''
		}
	})

	const emit = defineEmits(['update:modelValue'])
</script>

<template>
	<q-input
		:model-value="modelValue"
		class="search-query-input"
		outlined
		clearable
		:placeholder="placeholder"
		:aria-label="placeholder"
		v-bind="$attrs"
		@update:model-value="emit('update:modelValue', $event || '')"
	/>
</template>

<style scoped lang="scss">
.search-query-input :deep(.q-field__control) {
  height: 76px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.84);
  box-shadow: 0 12px 28px rgba(66, 20, 143, 0.14);
  transition: box-shadow 0.18s ease;
}

.search-query-input :deep(.q-field__marginal) {
  height: 76px;
}

.search-query-input :deep(.q-field__native),
.search-query-input :deep(.q-field__input) {
  min-height: 76px;
  padding-block: 0;
  padding-inline: 6px;
  font-size: 1.28rem;
  font-weight: 500;
  line-height: 1.45;
}

.search-query-input.q-field--focused :deep(.q-field__control) {
  box-shadow: 0 15px 34px rgba(123, 63, 242, 0.2);
}
</style>
