import path from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const landingDir = path.join(root, 'public', 'assets', 'landing')
const examplesDir = path.join(root, 'public', 'assets', 'examples')
const iconsDir = path.join(landingDir, 'icons')

const variants = [
	{ input: 'hero-mobile.v1.webp', output: 'hero-mobile-480.v1', width: 480, quality: 60 },
	{ input: 'hero-mobile.v1.webp', output: 'hero-mobile-640.v1', width: 640, quality: 60 },
	{ input: 'hero-mobile.v1.webp', output: 'hero-mobile-720.v1', width: 720, quality: 60 },
	{ input: 'hero-mobile.v1.webp', output: 'hero-mobile-800.v1', width: 800, quality: 60 },
	{ input: 'hero-mobile.v1.webp', output: 'hero-mobile-960.v1', width: 960, quality: 60 },
	{ input: 'hero-main.v1.webp', output: 'hero-main-960.v1', width: 960, quality: 74 },
	{ input: 'hero-main.v1.webp', output: 'hero-main-1360.v1', width: 1360, quality: 76 },
	{ input: 'sveevee-logo.v1.webp', output: 'sveevee-logo-320.v1', width: 320, quality: 82 },
	{ input: 'sveevee-logo.v1.webp', output: 'sveevee-logo-640.v1', width: 640, quality: 82 },
	{ input: 'workflow-house.v1.webp', output: 'workflow-house-420.v1', width: 420, quality: 72 },
	{ input: 'workflow-house.v1.webp', output: 'workflow-house-720.v1', width: 720, quality: 74 },
	{ input: 'pricing-business.v1.webp', output: 'pricing-business-280.v1', width: 280, quality: 74 },
	{ input: 'pricing-business.v1.webp', output: 'pricing-business-520.v1', width: 520, quality: 76 },
	{ input: 'pricing-private.v1.webp', output: 'pricing-private-220.v1', width: 220, quality: 74 },
	{ input: 'pricing-private.v1.webp', output: 'pricing-private-360.v1', width: 360, quality: 76 }
]

const extraVariants = [
	...['business', 'community'].flatMap((type) => [
		{ dir: landingDir, input: `example-${type}-banner-1440.v1.webp`, output: `example-${type}-banner-480.v1`, width: 480, quality: 72 },
		{ dir: landingDir, input: `example-${type}-banner-1440.v1.webp`, output: `example-${type}-banner-768.v1`, width: 768, quality: 74 },
		{ dir: landingDir, input: `example-${type}-banner-1440.v1.webp`, output: `example-${type}-banner-960.v1`, width: 960, quality: 75 },
		{ dir: landingDir, input: `example-${type}-banner-1440.v1.webp`, output: `example-${type}-banner-1440.v1`, width: 1440, quality: 76 },
		{ dir: landingDir, input: `example-${type}-logo-512.v1.webp`, output: `example-${type}-logo-128.v1`, width: 128, quality: 78 },
		{ dir: landingDir, input: `example-${type}-logo-512.v1.webp`, output: `example-${type}-logo-256.v1`, width: 256, quality: 80 },
		{ dir: landingDir, input: `example-${type}-logo-512.v1.webp`, output: `example-${type}-logo-512.v1`, width: 512, quality: 82 }
	]),
	...[
		'example-ad-display-table',
		'example-ad-opening-offer',
		'example-ad-volunteers',
		'example-event-makers-meetup',
		'example-event-repair-workshop',
		'example-product-event-kit',
		'example-product-gift-basket',
		'example-product-office-shelf',
		'example-service-delivery',
		'example-service-event-planning',
		'example-service-page-setup'
	].flatMap((name) => [
		{ dir: examplesDir, input: `${name}.v1.webp`, output: `${name}-384.v1`, width: 384, quality: 72 },
		{ dir: examplesDir, input: `${name}.v1.webp`, output: `${name}-576.v1`, width: 576, quality: 74 },
		{ dir: examplesDir, input: `${name}.v1.webp`, output: `${name}-768.v1`, width: 768, quality: 76 }
	]),
	...[
		'feature-ads',
		'feature-chat',
		'feature-community',
		'feature-controls',
		'feature-events',
		'feature-hours',
		'feature-location',
		'feature-palette',
		'feature-products',
		'feature-profile',
		'feature-ratings',
		'feature-search',
		'feature-services',
		'feature-share',
		'feature-storefront'
	].flatMap((name) => [
		{ dir: iconsDir, input: `${name}.v2.webp`, output: `${name}-160.v2`, width: 160, quality: 76 },
		{ dir: iconsDir, input: `${name}.v2.webp`, output: `${name}.v2`, width: 320, quality: 78 }
	])
]

async function writeVariant({ dir = landingDir, input, output, width, quality }) {
	const source = path.join(dir, input)
	const image = sharp(source).resize({ width, withoutEnlargement: true })
	const webpTarget = path.join(dir, `${output}.webp`)
	const avifTarget = path.join(dir, `${output}.avif`)

	if (path.resolve(source) !== path.resolve(webpTarget)) {
		await image.clone()
			.webp({ quality, effort: 6 })
			.toFile(webpTarget)
	}

	await image.clone()
		.avif({ quality: Math.max(42, quality - 20), effort: 6 })
		.toFile(avifTarget)
}

await Promise.all([...variants, ...extraVariants].map(writeVariant))
