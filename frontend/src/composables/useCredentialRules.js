export function useCredentialRules(t) {
	const emailRule = (value) => /^[^\s@]+@[^\s@]+$/.test(String(value || '').trim()) || t('auth.emailInvalid')
	const passwordRule = (value) => {
		const password = String(value || '')

		return (password.length >= 8 && /\p{L}/u.test(password) && /\d/.test(password)) || t('auth.passwordRequirements')
	}
	const matchingPasswordRule = (passwordValue) => (value) => (
		String(value || '') === String(passwordValue() || '') || t('auth.passwordMismatch')
	)

	return {
		emailRule,
		passwordRule,
		matchingPasswordRule
	}
}
