<script setup>
	import { onMounted, reactive, ref, toRef, watch } from 'vue'
	import { useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useQuasar } from 'quasar'
	import { useAuthStore } from '@/stores/auth'
	import { useAppStore } from '@/stores/app'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { useRequiredFields } from '@/composables/useRequiredFields'
	import PasswordInput from '@/components/PasswordInput.vue'

	const { t } = useI18n()
	const $q = useQuasar()
	const router = useRouter()
	const authStore = useAuthStore()
	const appStore = useAppStore()
	const formRef = ref(null)
	const citySelectOptions = ref([])
	const neighborhoodSelectOptions = ref([])
	const form = reactive({
		email: '',
		password: '',
		password_confirmation: '',
		given_name: '',
		family_name: '',
		phone: '',
		city: '',
		neighborhood: ''
	})
	const {
		cityOptions,
		neighborhoodOptions,
		loadLocationOptions,
		rememberLocation,
		addOption,
		filterOptions,
		hasOptionValue
	} = useLocationOptions(toRef(form, 'city'))
	const { requiredLabel, requiredRule, validateRequiredForm } = useRequiredFields(t, $q)

	async function submit() {
		if (!(await validateRequiredForm(formRef))) {
			return
		}

		try {
			await authStore.register({ ...form, locale: appStore.locale })
			rememberLocation(form.city, form.neighborhood)
			router.push({ name: 'home' })
		} catch (error) {
			$q.notify({ type: 'negative', message: error.response?.data?.message || t('auth.registerFailed') })
		}
	}

	function filterCityOptions(value, update) {
		update(() => {
			citySelectOptions.value = filterOptions(cityOptions.value, value)
		})
	}

	function filterNeighborhoodOptions(value, update) {
		update(() => {
			neighborhoodSelectOptions.value = filterOptions(neighborhoodOptions.value, value)
		})
	}

	watch(cityOptions, (options) => {
		citySelectOptions.value = options
	}, { immediate: true })

	watch(neighborhoodOptions, (options) => {
		neighborhoodSelectOptions.value = options
	}, { immediate: true })

	watch(() => form.city, () => {
		if (!form.city) {
			form.neighborhood = ''
			return
		}

		if (form.neighborhood && !hasOptionValue(neighborhoodOptions.value, form.neighborhood)) {
			form.neighborhood = ''
		}
	})

	onMounted(async() => {
		await loadLocationOptions()
		citySelectOptions.value = cityOptions.value
		neighborhoodSelectOptions.value = neighborhoodOptions.value
	})
</script>

<template>
	<q-page padding class="auth-page">
		<div class="auth-shell">
			<section class="soz-section-card auth-panel">
				<div class="auth-panel__inner">
					<h1 class="soz-page-title">{{ t('auth.registerTitle') }}</h1>
					<q-form ref="formRef" greedy class="register-form" @submit.prevent="submit()">
						<div class="register-form__row">
							<q-input class="col-12 col-md-4"
								v-model="form.email"
								outlined
								type="email"
								autocomplete="email"
								:label="requiredLabel('auth.email')"
								:rules="[requiredRule]"
							/>
							<q-input class="col-12 col-md-4"
								v-model="form.given_name"
								outlined
								:label="requiredLabel('auth.givenName')"
								:rules="[requiredRule]"
							/>
							<q-input class="col-12 col-md-4"
								v-model="form.family_name"
								outlined
								:label="requiredLabel('auth.familyName')"
								:rules="[requiredRule]"
							/>
						</div>
						<div class="register-form__row">
							<PasswordInput class="col-12 col-md-6"
								v-model="form.password"
								autocomplete="new-password"
								:label="requiredLabel('auth.password')"
								:rules="[requiredRule]"
							/>
							<PasswordInput class="col-12 col-md-6"
								v-model="form.password_confirmation"
								autocomplete="new-password"
								:label="requiredLabel('auth.passwordConfirmation')"
								:rules="[requiredRule]"
							/>
						</div>
						<div class="register-form__row">
							<q-input class="col-12 col-md-4" v-model="form.phone" outlined :label="t('auth.phone')" />
							<q-select class="col-12 col-md-4"
								v-model="form.city"
								outlined
								clearable
								emit-value
								map-options
								use-input
								hide-selected
								fill-input
								input-debounce="0"
								new-value-mode="add-unique"
								:options="citySelectOptions"
								:label="requiredLabel('auth.city')"
								:rules="[requiredRule]"
								@filter="filterCityOptions"
								@new-value="addOption"
							/>
							<q-select class="col-12 col-md-4"
								v-model="form.neighborhood"
								outlined
								clearable
								emit-value
								map-options
								use-input
								hide-selected
								fill-input
								input-debounce="0"
								new-value-mode="add-unique"
								:options="neighborhoodSelectOptions"
								:label="t('auth.neighborhood')"
								:disable="!form.city"
								@filter="filterNeighborhoodOptions"
								@new-value="addOption"
							/>
						</div>
						<q-btn class="form-submit"
							color="primary"
							unelevated
							rounded
							type="submit"
							icon="person_add"
							:loading="authStore.loading"
							:label="t('nav.register')"
						/>
					</q-form>
				</div>
			</section>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.auth-page {
  display: block;
}

.auth-shell {
  display: grid;
  place-items: start center;
  max-width: 1280px;
  width: 100%;
  margin: 0 auto;
}

.auth-panel {
  width: 100%;
  padding: 28px;
}

.auth-panel__inner {
  width: min(1040px, 100%);
  margin: 0 auto;
}

.auth-panel__inner h1 {
  margin-bottom: 24px;
}

.register-form {
  display: grid;
  gap: 16px;
  width: 100%;
  min-width: 0;
}

.register-form__row {
  display: grid;
  grid-template-columns: repeat(12, minmax(0, 1fr));
  gap: 16px;
  width: 100%;
  min-width: 0;
}

.register-form__row > :deep(.col-12) {
  grid-column: 1 / -1;
  min-width: 0;
}

.register-form__row > :deep(.col-md-4) {
  grid-column: span 4;
}

.register-form__row > :deep(.col-md-6) {
  grid-column: span 6;
}

.form-submit {
  width: min(220px, 100%);
  margin: 0 auto !important;
}

@media (max-width: 700px) {
  .auth-page {
    padding-inline: 10px;
  }

  .auth-panel {
    padding: 20px;
  }

  .auth-shell,
  .auth-panel,
  .auth-panel__inner,
  .register-form {
    width: 100%;
    min-width: 0;
    max-width: 100%;
  }

  .auth-panel {
    overflow: hidden;
  }

  .register-form__row {
    grid-template-columns: 1fr;
    gap: 12px;
    row-gap: 12px;
  }

  .register-form__row > * {
    grid-column: 1 / -1 !important;
    width: 100%;
    min-width: 0;
    max-width: 100%;
  }

  .auth-panel__inner :deep(.q-field),
  .auth-panel__inner :deep(.q-field__inner),
  .auth-panel__inner :deep(.q-field__control) {
    width: 100%;
    min-width: 0;
    max-width: 100%;
  }
}
</style>
