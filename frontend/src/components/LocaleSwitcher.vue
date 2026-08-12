<script setup>
	import { computed, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAppStore } from '@/stores/app'
	import { useAuthStore } from '@/stores/auth'
	import { setLocale } from '@/i18n'
	import { updateProfileLocale } from '@/services/api/profile'
	import { apiErrorMessage } from '@/utils/apiErrors'

	const props = defineProps({
		persist: {
			type: Boolean,
			default: false
		},
		guestStorage: {
			type: Boolean,
			default: false
		},
		compact: {
			type: Boolean,
			default: false
		}
	})
	const emit = defineEmits(['update:modelValue'])

	const appStore = useAppStore()
	const authStore = useAuthStore()
	const $q = useQuasar()
	const { t } = useI18n()
	const changing = ref(false)
	const flag = (...points) => String.fromCodePoint(...points)

	const localeOptions = computed(() => {
		const options = [
			{ flag: flag(0x1f1ee, 0x1f1f1), title: t('languages.he'), value: 'he' },
			{ flag: flag(0x1f1fa, 0x1f1f8), title: t('languages.en'), value: 'en' },
			{ flag: flag(0x1f1f7, 0x1f1fa), title: t('languages.ru'), value: 'ru' },
			{ flag: flag(0x1f1eb, 0x1f1f7), title: t('languages.fr'), value: 'fr' }
		]

		return options.map((option) => ({
			label: props.compact ? option.flag : `${option.flag} ${option.title}`,
			value: option.value
		}))
	})

	async function selectLocale(locale) {
		const previousLocale = appStore.locale

		setLocale(locale)
		emit('update:modelValue', locale)

		if (props.guestStorage && !authStore.isAuthenticated) {
			appStore.setGuestLocale(locale)
		}

		if (!props.persist || !authStore.isAuthenticated) {
			return
		}

		changing.value = true

		try {
			const { data } = await updateProfileLocale(locale)

			if (data.data?.user) {
				authStore.user = data.data.user
			}
		} catch (error) {
			setLocale(previousLocale)
			emit('update:modelValue', previousLocale)
			$q.notify({ type: 'negative', message: apiErrorMessage(error, t('profile.saveFailed')) })
		} finally {
			changing.value = false
		}
	}
</script>

<template>
	<q-select
		dense
		borderless
		rounded
		dropdown-icon="expand_more"
		popup-content-class="locale-switcher-menu"
		standout="bg-white text-primary"
		:model-value="appStore.locale"
		:options="localeOptions"
		:loading="changing"
		:disable="changing"
		emit-value
		map-options
		class="locale-switcher"
		:class="{ 'locale-switcher--compact': compact }"
		@update:model-value="selectLocale"
	/>
</template>

<style scoped lang="scss">
.locale-switcher {
  min-width: 180px;

  :deep(.q-field__control) {
    border-radius: 999px;
    min-height: 42px;
    padding-inline: 6px;
  }

  :deep(.q-field__native),
  :deep(.q-field__input) {
    min-height: 42px;
    padding-top: 0;
    padding-bottom: 0;
    font-size: 16px;
    line-height: 1.2;
  }

  &.locale-switcher--compact {
    min-width: 76px;
    width: 76px;

    :deep(.q-field__control) {
      padding-inline: 8px 4px;
    }

    :deep(.q-field__native) {
      justify-content: center;
      font-size: 20px;
      line-height: 1;
    }
  }
}
</style>
