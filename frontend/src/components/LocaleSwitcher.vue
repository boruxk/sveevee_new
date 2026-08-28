<script setup>
	import { computed, ref } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAppStore } from '@/stores/app'
	import { useAuthStore } from '@/stores/auth'
	import { setLocale } from '@/i18n'
	import { updateProfileLocale } from '@/services/api/profile'
	import { apiErrorMessage } from '@/utils/apiErrors'
	import LocaleFlag from '@/components/icons/LocaleFlag.vue'

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
	const allLocaleOptions = computed(() => [
		{ label: t('languages.he'), value: 'he' },
		{ label: t('languages.en'), value: 'en' },
		{ label: t('languages.ru'), value: 'ru' },
		{ label: t('languages.fr'), value: 'fr' }
	])
	const localeOptions = computed(() => allLocaleOptions.value
		.filter((option) => option.value !== 'ru')
		.map((option) => ({
			label: option.label,
			value: option.value
		})))
	const selectedLocale = computed(() => allLocaleOptions.value.find(({ value }) => value === appStore.locale) || {
		label: appStore.locale,
		value: appStore.locale
	})

	async function selectLocale(locale) {
		const previousLocale = appStore.locale

		changing.value = true

		try {
			await setLocale(locale)
			emit('update:modelValue', locale)

			if (props.guestStorage && !authStore.isAuthenticated) {
				appStore.setGuestLocale(locale)
			}

			if (!props.persist || !authStore.isAuthenticated) {
				return
			}

			const { data } = await updateProfileLocale(locale)

			if (data.data?.user) {
				authStore.user = data.data.user
			}
		} catch (error) {
			await setLocale(previousLocale)
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
	>
		<template #selected-item>
			<div class="locale-switcher__selection">
				<LocaleFlag :locale="selectedLocale.value" :label="selectedLocale.label" />
			</div>
		</template>
		<template #option="scope">
			<q-item v-bind="scope.itemProps" class="locale-switcher__option">
				<q-item-section avatar>
					<LocaleFlag :locale="scope.opt.value" :label="scope.opt.label" />
				</q-item-section>
			</q-item>
		</template>
	</q-select>
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

.locale-switcher__selection {
  display: inline-flex;
  align-items: center;
  gap: 9px;
  min-width: 0;
}

.locale-switcher__option :deep(.q-item__section--avatar) {
  align-items: center;
  min-width: 28px;
}
</style>
