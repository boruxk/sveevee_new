export const IMAGE_ACCEPT = '.jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp'

export function selectedFileName(value) {
	const file = Array.isArray(value) ? value[0] : value

	if (file instanceof File && file.name) {
		return file.name
	}

	return ''
}

export function fileNameFromUrl(value) {
	const url = String(value || '').trim()

	if (!url) {
		return ''
	}

	try {
		const path = url.split('#')[0].split('?')[0]
		return decodeURIComponent(path.split('/').filter(Boolean).pop() || '')
	} catch {
		return ''
	}
}

export function imageUploadDisplayName(fileValue, imageUrl = '', storedName = '') {
	return selectedFileName(fileValue) || storedName || fileNameFromUrl(imageUrl)
}
