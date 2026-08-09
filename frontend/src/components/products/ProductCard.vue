<script setup>
	import { computed, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'

	defineProps({
		product: {
			type: Object,
			required: true
		},
		editable: {
			type: Boolean,
			default: false
		}
	})

	const emit = defineEmits(['delete', 'edit'])
	const { t } = useI18n()
	const $q = useQuasar()
	const detailOpen = ref(false)
	const compactActionButtons = computed(() => $q.screen.width <= 700)
</script>

<template>
	<article class="product-card">
		<div v-if="product.image_url" class="product-card__image" :style="{ backgroundImage: `url(${product.image_url})` }" />
		<div class="product-card__body">
			<div>
				<h3 class="product-card__title">{{ product.name }}</h3>
				<p class="product-card__description">{{ product.description }}</p>
			</div>
			<div class="product-card__footer">
				<div class="product-card__price">{{ product.price_label }}</div>
				<div class="product-card__actions">
					<q-btn
						class="product-card__view-btn"
						:round="compactActionButtons"
						:rounded="!compactActionButtons"
						unelevated
						color="primary"
						icon="visibility"
						:aria-label="t('products.open')"
						:label="compactActionButtons ? undefined : t('products.open')"
						@click="detailOpen = true"
					/>
					<q-btn v-if="editable"
						class="product-card__icon-btn"
						round
						unelevated
						color="secondary"
						icon="edit"
						:aria-label="t('actions.edit')"
						@click="emit('edit', product)"
					>
						<q-tooltip>{{ t('actions.edit') }}</q-tooltip>
					</q-btn>
					<q-btn v-if="editable"
						class="product-card__icon-btn"
						round
						unelevated
						color="negative"
						icon="delete"
						:aria-label="t('actions.delete')"
						@click="emit('delete', product)"
					>
						<q-tooltip>{{ t('actions.delete') }}</q-tooltip>
					</q-btn>
				</div>
			</div>
		</div>
	</article>
	<q-dialog v-model="detailOpen">
		<q-card class="product-detail-dialog">
			<div v-if="product.image_url" class="product-detail-dialog__image" :style="{ backgroundImage: `url(${product.image_url})` }" />
			<q-card-section class="product-detail-dialog__body">
				<div class="product-detail-dialog__head">
					<div>
						<h3>{{ product.name }}</h3>
						<div class="product-detail-dialog__price">{{ product.price_label }}</div>
					</div>
					<q-btn flat round icon="close" color="dark" v-close-popup />
				</div>
				<p class="product-detail-dialog__description">{{ product.description }}</p>
				<div class="product-detail-dialog__actions">
					<q-btn
						rounded
						unelevated
						color="primary"
						icon="shopping_cart"
						:href="product.link"
						target="_blank"
						rel="noopener noreferrer"
						:label="t('products.buy')"
					/>
				</div>
			</q-card-section>
		</q-card>
	</q-dialog>
</template>

<style scoped lang="scss">
.product-card {
  display: flex;
  flex-direction: column;
  max-height: 450px;
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.78);
}

.product-card__image {
  flex: 0 0 180px;
  min-height: 180px;
  background-position: center;
  background-size: cover;
}

.product-card__body {
  display: flex;
  flex-direction: column;
  gap: 18px;
  flex: 1;
  min-height: 220px;
  min-width: 0;
  padding: 18px;
}

.product-card__title {
  margin: 0 0 8px;
  font-size: 21px;
  line-height: 1.25;
}

.product-card__description {
  display: -webkit-box;
  overflow: hidden;
  margin: 0;
  color: rgba(17, 34, 45, 0.72);
  line-height: 1.55;
  white-space: pre-line;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 4;
}

.product-card__footer {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  justify-content: space-between;
  margin-top: auto;
}

.product-card__price {
  color: #151f2d;
  font-size: 22px;
  font-weight: 800;
}

.product-card__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
}

.product-card__icon-btn {
  aspect-ratio: 1;
  width: 53px;
  min-width: 53px;
  height: 53px;
  min-height: 53px;
  padding: 0;
}

.product-detail-dialog {
  overflow: hidden;
  width: min(720px, calc(100vw - 24px));
  max-width: 720px;
  max-height: calc(100vh - 32px);
  border-radius: 24px;
  background: #fffaf6;
}

.product-detail-dialog__image {
  min-height: 260px;
  background-position: center;
  background-size: cover;
}

.product-detail-dialog__body {
  display: grid;
  gap: 18px;
}

.product-detail-dialog__head {
  display: flex;
  gap: 16px;
  align-items: flex-start;
  justify-content: space-between;
}

.product-detail-dialog__head h3 {
  margin: 0 0 8px;
  color: #151f2d;
  font-size: 28px;
  line-height: 1.2;
}

.product-detail-dialog__price {
  color: #151f2d;
  font-size: 24px;
  font-weight: 800;
}

.product-detail-dialog__description {
  overflow-y: auto;
  max-height: 34vh;
  margin: 0;
  color: rgba(17, 34, 45, 0.76);
  line-height: 1.65;
  white-space: pre-line;
}

.product-detail-dialog__actions {
  display: flex;
  justify-content: flex-end;
}

@media (max-width: 700px) {
  .product-card {
    max-height: none;
  }

  .product-card__image {
    flex-basis: 160px;
    min-height: 160px;
  }

  .product-card__body {
    min-height: auto;
    padding: 16px;
  }

  .product-card__footer {
    align-items: stretch;
    flex-direction: column;
  }

  .product-card__actions {
    justify-content: flex-start;
  }

  .product-card__view-btn {
    aspect-ratio: 1;
    width: 53px;
    min-width: 53px;
    height: 53px;
    min-height: 53px;
    padding: 0;
  }

  .product-detail-dialog {
    width: calc(100vw - 20px);
    max-height: calc(100dvh - 20px);
    border-radius: 20px;
  }

  .product-detail-dialog__image {
    min-height: 190px;
  }

  .product-detail-dialog__body {
    padding: 18px;
  }

  .product-detail-dialog__head h3 {
    font-size: 24px;
    overflow-wrap: anywhere;
  }

  .product-detail-dialog__actions .q-btn {
    width: 100%;
  }
}
</style>
