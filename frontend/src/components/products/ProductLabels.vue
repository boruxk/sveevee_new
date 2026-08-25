<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'

	const props = defineProps({
		labels: {
			type: Array,
			default: () => []
		}
	})

	const { t } = useI18n()
	const labelMeta = {
		new: { icon: 'new_releases', tone: 'new' },
		price_dropped: { icon: 'trending_down', tone: 'drop' },
		popular: { icon: 'local_fire_department', tone: 'popular' },
		highly_rated: { icon: 'star', tone: 'rated' }
	}
	const visibleLabels = computed(() => props.labels
		.filter((label) => labelMeta[label])
		.map((label) => ({ key: label, ...labelMeta[label] })))
</script>

<template>
	<div v-if="visibleLabels.length" class="product-labels">
		<span
			v-for="label in visibleLabels"
			:key="label.key"
			class="product-label"
			:class="`product-label--${label.tone}`"
		>
			<q-icon :name="label.icon" size="15px" />
			<span>{{ t(`products.labels.${label.key}`) }}</span>
		</span>
	</div>
</template>

<style scoped lang="scss">
.product-labels {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.product-label {
  display: inline-flex;
  min-height: 27px;
  gap: 5px;
  align-items: center;
  padding: 4px 9px;
  border-radius: 999px;
  font-size: 0.75rem;
  font-weight: 780;
  line-height: 1;
}

.product-label--new { background: #e8f8ff; color: #08779b; }
.product-label--drop { background: #ecf9ef; color: #16733a; }
.product-label--popular { background: #fff0e7; color: #b54a0a; }
.product-label--rated { background: #fff7d8; color: #8f6900; }
</style>
