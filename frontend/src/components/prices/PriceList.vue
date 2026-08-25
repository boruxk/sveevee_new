<script setup>
	import { useI18n } from 'vue-i18n'

	defineProps({
		items: {
			type: Array,
			default: () => []
		},
		editable: {
			type: Boolean,
			default: false
		},
		disabled: {
			type: Boolean,
			default: false
		}
	})

	const emit = defineEmits(['edit', 'delete'])
	const { t } = useI18n()
</script>

<template>
	<div v-if="items.length" class="price-list" :aria-disabled="disabled || undefined">
		<div v-for="item in items" :key="item.id" class="price-list__row">
			<span class="price-list__name">{{ item.name }}</span>
			<span class="price-list__dots" aria-hidden="true" />
			<strong class="price-list__price">{{ item.price_label }}</strong>
			<div v-if="editable" class="price-list__actions">
				<q-btn round
					flat
					icon="edit"
					:disable="disabled"
					:aria-label="t('actions.edit')"
					@click="emit('edit', item)"
				>
					<q-tooltip>{{ t('actions.edit') }}</q-tooltip>
				</q-btn>
				<q-btn round
					flat
					color="negative"
					icon="delete"
					:disable="disabled"
					:aria-label="t('actions.delete')"
					@click="emit('delete', item)"
				>
					<q-tooltip>{{ t('actions.delete') }}</q-tooltip>
				</q-btn>
			</div>
		</div>
	</div>
	<div v-else class="price-list__empty">{{ t('priceList.empty') }}</div>
</template>

<style scoped lang="scss">
.price-list {
  display: grid;
  gap: 10px;
}

.price-list__row {
  display: flex;
  min-height: 58px;
  gap: 12px;
  align-items: center;
  padding: 12px 16px;
  border: 1px solid color-mix(in srgb, var(--presence-accent, var(--soz-primary)) 18%, rgba(17, 34, 45, 0.08));
  border-radius: 18px;
  background: color-mix(in srgb, var(--presence-card, #fff) 90%, var(--presence-accent, var(--soz-primary)) 10%);
  color: var(--presence-ink, var(--soz-ink));
}

.price-list__name {
  min-width: 0;
  font-size: 1.02rem;
  font-weight: 760;
  overflow-wrap: anywhere;
}

.price-list__dots {
  min-width: 22px;
  flex: 1;
  align-self: center;
  border-bottom: 2px dotted color-mix(in srgb, var(--presence-muted, #68727d) 34%, transparent);
}

.price-list__price {
  flex: 0 0 auto;
  font-size: 1.08rem;
}

.price-list__actions {
  display: flex;
  flex: 0 0 auto;
  gap: 2px;
}

.price-list__empty {
  padding: 22px;
  border: 1px dashed rgba(17, 34, 45, 0.16);
  border-radius: 18px;
  color: var(--presence-muted, rgba(17, 34, 45, 0.62));
  text-align: center;
}

@media (max-width: 520px) {
  .price-list__row {
    gap: 8px;
    padding: 10px 12px;
  }

  .price-list__actions {
    margin-inline-end: -6px;
  }
}
</style>
