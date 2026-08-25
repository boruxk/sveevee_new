<script setup>
	defineProps({
		src: {
			type: String,
			default: ''
		},
		alt: {
			type: String,
			default: ''
		},
		avifSrcset: {
			type: String,
			default: ''
		},
		webpSrcset: {
			type: String,
			default: ''
		},
		sizes: {
			type: String,
			default: ''
		},
		width: {
			type: [Number, String],
			default: null
		},
		height: {
			type: [Number, String],
			default: null
		},
		loading: {
			type: String,
			default: 'lazy'
		},
		fetchpriority: {
			type: String,
			default: ''
		},
		decoding: {
			type: String,
			default: 'async'
		}
	})
</script>

<template>
	<picture v-if="src" class="responsive-image">
		<source v-if="avifSrcset" :srcset="avifSrcset" :sizes="sizes || undefined" type="image/avif" />
		<source v-if="webpSrcset" :srcset="webpSrcset" :sizes="sizes || undefined" type="image/webp" />
		<img
			class="responsive-image__img"
			:src="src"
			:alt="alt"
			:width="width || undefined"
			:height="height || undefined"
			:loading="loading"
			:fetchpriority="fetchpriority || undefined"
			:decoding="decoding"
		/>
	</picture>
</template>

<style scoped lang="scss">
.responsive-image {
  display: block;
  overflow: hidden;
}

.responsive-image__img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: var(--responsive-image-fit, cover);
  object-position: var(--responsive-image-position, center);
}
</style>
