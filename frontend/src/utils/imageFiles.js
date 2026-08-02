const MAX_LEGACY_UPLOAD_BYTES = 1800000
const EDGE_STEPS = [1800, 1600, 1400, 1200]
const QUALITY_STEPS = [0.9, 0.82, 0.74, 0.66]

function fileFromValue(value) {
	return Array.isArray(value) ? value[0] : value
}

function webpFileName(name) {
	const cleanName = String(name || 'image').replace(/\.[^.]+$/, '')

	return `${cleanName || 'image'}.webp`
}

function imageSize(image) {
	return {
		width: image.width || image.naturalWidth,
		height: image.height || image.naturalHeight
	}
}

function scaledSize(image, maxEdge) {
	const { width, height } = imageSize(image)
	const scale = Math.min(1, maxEdge / Math.max(width, height))

	return {
		width: Math.max(1, Math.round(width * scale)),
		height: Math.max(1, Math.round(height * scale))
	}
}

async function loadImage(file) {
	if (typeof createImageBitmap === 'function') {
		return createImageBitmap(file)
	}

	return new Promise((resolve, reject) => {
		const url = URL.createObjectURL(file)
		const image = new Image()

		image.onload = () => {
			URL.revokeObjectURL(url)
			resolve(image)
		}

		image.onerror = () => {
			URL.revokeObjectURL(url)
			reject(new Error('Image could not be loaded.'))
		}

		image.src = url
	})
}

function imageBlob(image, maxEdge, quality) {
	const { width, height } = scaledSize(image, maxEdge)
	const canvas = document.createElement('canvas')

	canvas.width = width
	canvas.height = height
	canvas.getContext('2d').drawImage(image, 0, 0, width, height)

	return new Promise((resolve) => {
		canvas.toBlob(resolve, 'image/webp', quality)
	})
}

export async function imageFileForUpload(value) {
	const file = fileFromValue(value)

	if (!(file instanceof File) || !file.type.startsWith('image/') || file.size <= MAX_LEGACY_UPLOAD_BYTES) {
		return file
	}

	try {
		const image = await loadImage(file)
		let fallbackBlob = null

		for (const edge of EDGE_STEPS) {
			for (const quality of QUALITY_STEPS) {
				const blob = await imageBlob(image, edge, quality)

				if (!blob) {
					continue
				}

				if (!fallbackBlob || blob.size < fallbackBlob.size) {
					fallbackBlob = blob
				}

				if (blob.size <= MAX_LEGACY_UPLOAD_BYTES) {
					image.close?.()
					return new File([blob], webpFileName(file.name), {
						type: 'image/webp',
						lastModified: file.lastModified
					})
				}
			}
		}

		image.close?.()

		if (fallbackBlob && fallbackBlob.size < file.size) {
			return new File([fallbackBlob], webpFileName(file.name), {
				type: 'image/webp',
				lastModified: file.lastModified
			})
		}
	} catch {
		return file
	}

	return file
}

export async function appendImageFile(formData, field, value) {
	const originalFile = fileFromValue(value)
	const file = await imageFileForUpload(value)

	if (file) {
		if (originalFile instanceof File && originalFile.name) {
			formData.append(`${field}_original_name`, originalFile.name)
		}

		formData.append(field, file)
	}
}
