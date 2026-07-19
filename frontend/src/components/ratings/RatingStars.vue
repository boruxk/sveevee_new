<script setup>
	import { computed } from 'vue'

	const props = defineProps({
		modelValue: {
			type: Number,
			default: null
		},
		value: {
			type: Number,
			default: 0
		},
		readonly: {
			type: Boolean,
			default: false
		},
		size: {
			type: String,
			default: '22px'
		}
	})

	const emit = defineEmits(['update:modelValue'])
	const activeValue = computed(() => Number(props.modelValue ?? props.value ?? 0))

	function setRating(value) {
		if (props.readonly) {
			return
		}

		emit('update:modelValue', value)
	}
</script>

<template>
	<div class="rating-stars" :class="{ 'rating-stars--interactive': !readonly }">
		<button
			v-for="star in 5"
			:key="star"
			type="button"
			class="rating-stars__button"
			:disabled="readonly"
			@click="setRating(star)"
		>
			<q-icon
				name="star"
				:size="size"
				:class="star <= Math.round(activeValue) ? 'rating-stars__icon--active' : 'rating-stars__icon--muted'"
			/>
		</button>
	</div>
</template>

<style scoped lang="scss">
.rating-stars {
  display: inline-flex;
  align-items: center;
  gap: 2px;
}

.rating-stars__button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: default;
}

.rating-stars--interactive .rating-stars__button {
  cursor: pointer;
}

.rating-stars--interactive .rating-stars__button:hover {
  transform: translateY(-1px);
}

.rating-stars__icon--active {
  color: #f59e0b;
}

.rating-stars__icon--muted {
  color: rgba(17, 34, 45, 0.2);
}
</style>
