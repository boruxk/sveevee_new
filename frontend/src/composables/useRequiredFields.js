export function useRequiredFields(t, $q) {
	const requiredRule = (value) => {
		if (Array.isArray(value)) {
			return value.length > 0 || t('validation.required')
		}

		return String(value ?? '').trim().length > 0 || t('validation.required')
	}

	const requiredLabel = (key) => `${t(key)} *`

	async function validateRequiredForm(formRef) {
		const valid = await formRef.value?.validate()

		if (!valid) {
			$q.notify({ type: 'warning', message: t('validation.requiredFields') })
			return false
		}

		return true
	}

	return {
		requiredLabel,
		requiredRule,
		validateRequiredForm
	}
}
