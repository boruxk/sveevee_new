export function publicImageSrcset(basePath, version, widths, extension) {
	return widths
		.map((width) => `${basePath}-${width}.${version}.${extension} ${width}w`)
		.join(', ')
}

export function versionedPublicImage(basePath, options = {}) {
	const {
		version = 'v1',
		widths = [],
		fallbackWidth = null,
		fallbackSrc = '',
		width = fallbackWidth,
		height = null,
		sizes = '',
		alt = ''
	} = options
	const imageUrl = fallbackSrc || (
		fallbackWidth ? `${basePath}-${fallbackWidth}.${version}.webp` : `${basePath}.${version}.webp`
	)

	return {
		image_url: imageUrl,
		image_alt: alt,
		image_width: width,
		image_height: height,
		image_sizes: sizes,
		image_avif_srcset: widths.length ? publicImageSrcset(basePath, version, widths, 'avif') : '',
		image_webp_srcset: widths.length ? publicImageSrcset(basePath, version, widths, 'webp') : ''
	}
}

export function imageObjectForJsonLd(image, absoluteUrl) {
	if (!image?.image_url || typeof absoluteUrl !== 'function') {
		return null
	}

	const object = {
		'@type': 'ImageObject',
		url: absoluteUrl(image.image_url)
	}

	if (image.image_width) {
		object.width = image.image_width
	}

	if (image.image_height) {
		object.height = image.image_height
	}

	if (image.image_alt) {
		object.caption = image.image_alt
	}

	return object
}
