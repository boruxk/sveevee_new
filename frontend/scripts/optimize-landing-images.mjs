import path from 'node:path'
import { fileURLToPath } from 'node:url'
import sharp from 'sharp'

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..')
const landingDir = path.join(root, 'public', 'assets', 'landing')

const variants = [
	{ input: 'hero-mobile.v1.webp', output: 'hero-mobile-480.v1', width: 480, quality: 70 },
	{ input: 'hero-mobile.v1.webp', output: 'hero-mobile-640.v1', width: 640, quality: 70 },
	{ input: 'hero-mobile.v1.webp', output: 'hero-mobile-720.v1', width: 720, quality: 70 },
	{ input: 'hero-mobile.v1.webp', output: 'hero-mobile-800.v1', width: 800, quality: 70 },
	{ input: 'hero-mobile.v1.webp', output: 'hero-mobile-960.v1', width: 960, quality: 70 },
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

async function writeVariant({ input, output, width, quality }) {
	const source = path.join(landingDir, input)
	const image = sharp(source).resize({ width, withoutEnlargement: true })

	await image.clone()
		.webp({ quality, effort: 6 })
		.toFile(path.join(landingDir, `${output}.webp`))

	await image.clone()
		.avif({ quality: Math.max(42, quality - 20), effort: 6 })
		.toFile(path.join(landingDir, `${output}.avif`))
}

await Promise.all(variants.map(writeVariant))
