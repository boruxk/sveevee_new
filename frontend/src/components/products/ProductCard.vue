<script setup>
	import { useI18n } from 'vue-i18n'

	defineProps({
		product: {
			type: Object,
			required: true
		}
	})

	const { t } = useI18n()
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
				<q-btn
					rounded
					unelevated
					color="primary"
					icon="open_in_new"
					:href="product.link"
					target="_blank"
					rel="noopener noreferrer"
					:label="t('products.open')"
				/>
			</div>
		</div>
	</article>
</template>

<style scoped lang="scss">
.product-card {
  overflow: hidden;
  border: 1px solid rgba(17, 34, 45, 0.1);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.78);
}

.product-card__image {
  min-height: 180px;
  background-position: center;
  background-size: cover;
}

.product-card__body {
  display: flex;
  flex-direction: column;
  gap: 18px;
  min-height: 220px;
  padding: 18px;
}

.product-card__title {
  margin: 0 0 8px;
  font-size: 21px;
  line-height: 1.25;
}

.product-card__description {
  margin: 0;
  color: rgba(17, 34, 45, 0.72);
  line-height: 1.55;
  white-space: pre-line;
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
</style>
