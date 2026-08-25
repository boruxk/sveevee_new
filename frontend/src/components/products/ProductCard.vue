<script setup>
	import { computed, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import ResponsiveImage from '@/components/ResponsiveImage.vue'
	import ProductLabels from '@/components/products/ProductLabels.vue'
	import { recordProductContact } from '@/services/api/products'

	const props = defineProps({
		product: {
			type: Object,
			required: true
		},
		editable: {
			type: Boolean,
			default: false
		},
		palette: {
			type: Object,
			default: null
		}
	})

	const emit = defineEmits(['delete', 'edit'])
	const { t } = useI18n()
	const $q = useQuasar()
	const detailOpen = ref(false)
	const compactActionButtons = computed(() => $q.screen.width <= 700)
	const productDetailPath = computed(() => props.product?.public_path || '')
	const productImageAlt = computed(() => props.product?.image_alt || props.product?.name || '')
	const productImageSizes = computed(() => props.product?.image_sizes || '(max-width: 700px) calc(100vw - 36px), 340px')
	const productIdentity = computed(() => [props.product?.brand, props.product?.model].filter(Boolean).join(' '))
	const themeStyle = computed(() => {
		if (!props.palette) {
			return null
		}

		return {
			'--presence-accent': props.palette.accent,
			'--presence-surface': props.palette.surface,
			'--presence-card': props.palette.card || 'rgba(255, 255, 255, 0.82)',
			'--presence-border': props.palette.border || 'rgba(17, 34, 45, 0.1)',
			'--presence-ink': props.palette.ink || '#151f2d',
			'--presence-muted': props.palette.muted || 'rgba(17, 34, 45, 0.72)'
		}
	})

	function openProduct() {
		if (!productDetailPath.value) {
			detailOpen.value = true
		}
	}

	function trackContact() {
		if (props.product?.id) {
			recordProductContact(props.product.id).catch(() => {})
		}
	}
</script>

<template>
	<article class="product-card" :style="themeStyle">
		<ResponsiveImage
			v-if="product.image_url"
			class="product-card__image"
			:src="product.image_url"
			:alt="productImageAlt"
			:avif-srcset="product.image_avif_srcset || ''"
			:webp-srcset="product.image_webp_srcset || ''"
			:sizes="productImageSizes"
			:width="product.image_width || 768"
			:height="product.image_height || 576"
		/>
		<div class="product-card__body">
			<div class="product-card__copy">
				<ProductLabels :labels="product.labels" />
				<h3 class="product-card__title">{{ product.name }}</h3>
				<div v-if="productIdentity" class="product-card__identity">{{ productIdentity }}</div>
				<p class="product-card__description">{{ product.description }}</p>
			</div>
			<div class="product-card__footer">
				<div class="product-card__prices" :class="{ 'product-card__prices--offer': product.offer_active }">
					<del v-if="product.offer_active" class="product-card__normal-price">{{ product.normal_price_label }}</del>
					<div class="product-card__price" :class="{ 'product-card__price--offer': product.offer_active }">{{ product.price_label }}</div>
				</div>
				<div class="product-card__actions">
					<q-btn
						class="product-card__view-btn"
						:round="compactActionButtons"
						:rounded="!compactActionButtons"
						unelevated
						color="primary"
						icon="visibility"
						:to="productDetailPath || undefined"
						:aria-label="t('products.open')"
						:label="compactActionButtons ? undefined : t('products.open')"
						@click="openProduct"
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
		<q-card class="product-detail-dialog" :style="themeStyle">
			<ResponsiveImage
				v-if="product.image_url"
				class="product-detail-dialog__image"
				:src="product.image_url"
				:alt="productImageAlt"
				:avif-srcset="product.image_avif_srcset || ''"
				:webp-srcset="product.image_webp_srcset || ''"
				:sizes="productImageSizes"
				:width="product.image_width || 768"
				:height="product.image_height || 576"
			/>
			<q-card-section class="product-detail-dialog__body">
				<div class="product-detail-dialog__head">
					<div>
						<ProductLabels :labels="product.labels" />
						<h3>{{ product.name }}</h3>
						<div v-if="productIdentity" class="product-detail-dialog__identity">{{ productIdentity }}</div>
						<div class="product-detail-dialog__prices" :class="{ 'product-detail-dialog__prices--offer': product.offer_active }">
							<del v-if="product.offer_active">{{ product.normal_price_label }}</del>
							<div class="product-detail-dialog__price" :class="{ 'product-detail-dialog__price--offer': product.offer_active }">{{ product.price_label }}</div>
						</div>
					</div>
					<q-btn flat round icon="close" class="product-detail-dialog__close" v-close-popup />
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
						@click="trackContact"
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
  height: 100%;
  overflow: hidden;
  border: 1px solid var(--presence-border, rgba(17, 34, 45, 0.1));
  border-radius: 8px;
  background: var(--presence-card, rgba(255, 255, 255, 0.78));
  color: var(--presence-ink, #151f2d);
}

.product-card__image {
  flex: 0 0 180px;
  min-height: 180px;
  --responsive-image-fit: cover;
  --responsive-image-position: center;
}

.product-card__body {
  display: flex;
  flex-direction: column;
  gap: 18px;
  flex: 1;
  min-height: 220px;
  min-width: 0;
  overflow: hidden;
  padding: 18px;
}

.product-card__copy {
  min-height: 0;
  overflow: hidden;
}

.product-card__title {
  margin: 0 0 8px;
  font-size: 21px;
  line-height: 1.25;
  overflow-wrap: anywhere;
}

.product-card__copy :deep(.product-labels) {
  margin-bottom: 9px;
}

.product-card__identity,
.product-detail-dialog__identity {
  margin: -3px 0 8px;
  color: var(--presence-muted, rgba(17, 34, 45, 0.65));
  font-size: 0.88rem;
  font-weight: 700;
}

.product-card__description {
  display: -webkit-box;
  overflow: hidden;
  max-height: calc(1.55em * 4);
  margin: 0;
  color: var(--presence-muted, rgba(17, 34, 45, 0.72));
  line-height: 1.55;
  overflow-wrap: anywhere;
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
  flex: 0 0 auto;
  margin-top: auto;
}

.product-card__price {
  color: var(--presence-ink, #151f2d);
  font-size: 22px;
  font-weight: 800;
}

.product-card__prices,
.product-detail-dialog__prices {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: baseline;
}

.product-card__prices--offer,
.product-detail-dialog__prices--offer {
  display: inline-grid;
  justify-items: start;
  gap: 1px;
  width: fit-content;
  padding: 7px 11px;
  border: 1px solid rgba(235, 52, 130, 0.2);
  border-radius: 12px;
  background: rgba(255, 240, 247, 0.78);
  box-shadow: 0 6px 16px rgba(174, 35, 100, 0.08);
}

.product-card__price--offer {
  color: #b51f60;
  font-size: 27px;
  font-weight: 900;
  line-height: 1.05;
}

.product-detail-dialog__price--offer {
  color: #b51f60;
  font-size: 30px;
  font-weight: 900;
  line-height: 1.08;
}

.product-card__normal-price,
.product-detail-dialog__prices del {
  color: rgba(128, 34, 77, 0.68);
  font-size: 0.9rem;
  text-decoration-color: #b51f60;
  text-decoration-thickness: 2px;
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
  border: 1px solid color-mix(in srgb, var(--presence-accent, #f97316) 28%, var(--presence-border, rgba(17, 34, 45, 0.1)));
  border-radius: 24px;
  background:
    radial-gradient(circle at top left, color-mix(in srgb, var(--presence-accent, #f97316) 16%, transparent), transparent 42%),
    color-mix(in srgb, var(--presence-surface, #fffaf6) 88%, var(--presence-accent, #f97316) 12%);
  color: var(--presence-ink, #151f2d);
  box-shadow: 0 24px 58px color-mix(in srgb, var(--presence-accent, #f97316) 18%, transparent);
}

.product-detail-dialog__image {
  min-height: 260px;
  --responsive-image-fit: cover;
  --responsive-image-position: center;
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
  color: var(--presence-ink, #151f2d);
  font-size: 28px;
  line-height: 1.2;
}

.product-detail-dialog__head :deep(.product-labels) {
  margin-bottom: 9px;
}

.product-detail-dialog__price {
  color: var(--presence-ink, #151f2d);
  font-size: 24px;
  font-weight: 800;
}

.product-detail-dialog__description {
  overflow-y: auto;
  max-height: 34vh;
  margin: 0;
  color: var(--presence-muted, rgba(17, 34, 45, 0.76));
  line-height: 1.65;
  white-space: pre-line;
}

.product-detail-dialog__actions {
  display: flex;
  justify-content: flex-end;
}

.product-detail-dialog__close {
  color: var(--presence-ink, #151f2d) !important;
}

.product-detail-dialog__actions .q-btn.bg-primary {
  background: var(--presence-accent, var(--soz-action-gradient)) !important;
  box-shadow: 0 14px 28px color-mix(in srgb, var(--presence-accent, #f97316) 22%, transparent) !important;
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
