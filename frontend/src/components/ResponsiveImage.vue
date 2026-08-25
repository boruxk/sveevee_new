<script setup>
	import { ref, watch } from 'vue'

	const props = defineProps({
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

	const loaded = ref(false)

	watch(() => props.src, () => {
		loaded.value = false
	})
</script>

<template>
	<picture v-if="src" class="responsive-image" :class="{ 'responsive-image--loaded': loaded }">
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
			@load="loaded = true"
		/>
	</picture>
</template>

<style scoped lang="scss">
.responsive-image {
  position: relative;
  display: block;
  overflow: hidden;
  background: rgba(123, 63, 242, 0.055);
}

.responsive-image::before {
  position: absolute;
  inset: 0;
  background: linear-gradient(110deg, transparent 24%, rgba(255, 255, 255, 0.52) 45%, transparent 66%);
  opacity: 0.7;
  content: "";
  transform: translateX(-100%);
  animation: responsiveImageLoading 1.25s ease-in-out infinite;
  pointer-events: none;
}

.responsive-image--loaded::before {
  opacity: 0;
  animation: none;
}

.responsive-image__img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: var(--responsive-image-fit, cover);
  object-position: var(--responsive-image-position, center);
  color: transparent;
  font-size: 0;
  opacity: 0;
  transition: opacity 0.16s ease;
}

.responsive-image--loaded .responsive-image__img {
  opacity: 1;
}

@keyframes responsiveImageLoading {
  to {
    transform: translateX(100%);
  }
}
</style>
