<script setup>
	import { computed, onMounted, reactive, ref, watch } from 'vue'
	import { useRoute, useRouter } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { setLocale } from '@/i18n'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { useLocationOptions } from '@/composables/useLocationOptions'
	import { CATALOG_SCOPES, catalogGroupsForScope, catalogLabel, normalizeCatalogLocale } from '@/constants/catalogTopics'
	import { submitLeadsPage001 } from '@/services/api/businessLeads'
	import { apiErrorMessage } from '@/utils/apiErrors'

	const TRACKING_KEYS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'fbclid']
	const route = useRoute()
	const router = useRouter()
	const { locale, t } = useI18n()
	const formRef = ref(null)
	const submitting = ref(false)
	const optionsLoading = ref(false)
	const optionsError = ref('')
	const submitError = ref('')
	const consentError = ref('')
	const citySelectOptions = ref([])
	const selectedBusinessGroup = ref('')
	const serverErrors = reactive({})
	const { catalogGroups, loadCatalogTopics } = useCatalogTopics()
	const { cityOptions, loadLocationOptions, filterOptions } = useLocationOptions()
	const form = reactive({
		business_name: '',
		city: '',
		category_key: '',
		full_name: '',
		email: '',
		phone: '',
		consent: false,
		website: ''
	})

	const routeLocale = computed(() => String(route.params.locale || 'he'))
	const isRtl = computed(() => locale.value === 'he')
	const businessGroups = computed(() => catalogGroupsForScope(catalogGroups.value, CATALOG_SCOPES.BUSINESS_PAGES))
	const businessIndustryOptions = computed(() => businessGroups.value.map((group) => ({
		label: catalogLabel(group.labels, locale.value),
		value: group.key,
		color: group.color
	})))
	const businessTypeOptions = computed(() => {
		const group = businessGroups.value.find(({ key }) => key === selectedBusinessGroup.value)

		return (group?.topics || []).map((topic) => ({
			label: catalogLabel(topic.labels, locale.value),
			value: topic.key,
			color: topic.color || group.color
		}))
	})
	const requiredRule = (value) => String(value ?? '').trim().length > 0 || t('validation.required')
	const emailRule = (value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim()) || t('businessLead.invalidEmail')
	const phoneRule = (value) => /^\+?[0-9][0-9\s().-]{7,24}$/.test(String(value || '').trim()) || t('businessLead.invalidPhone')

	function firstQueryValue(key) {
		const value = route.query[key]
		return Array.isArray(value) ? String(value[0] || '') : String(value || '')
	}

	function trackingPayload() {
		return Object.fromEntries(TRACKING_KEYS
			.map((key) => [key, firstQueryValue(key).slice(0, key === 'fbclid' ? 500 : 255)])
			.filter(([, value]) => value))
	}

	function serverError(...keys) {
		for (const key of keys) {
			const messages = serverErrors[key]
			if (Array.isArray(messages) && messages[0]) {
				return messages[0]
			}
		}

		return ''
	}

	function clearServerError(...keys) {
		keys.forEach((key) => delete serverErrors[key])
		submitError.value = ''
	}

	function filterCityOptions(value, update) {
		update(() => {
			citySelectOptions.value = filterOptions(cityOptions.value, value)
		})
	}

	function selectBusinessGroup(value) {
		selectedBusinessGroup.value = value || ''
		form.category_key = ''
		clearServerError('category_key')
	}

	function trackLead(page) {
		window.gtag?.('event', 'generate_lead', {
			lead_source: 'leads_page_001',
			page_id: page.id
		})
		window.fbq?.('track', 'Lead', {
			content_name: 'Free business page',
			content_category: form.category_key
		})
	}

	async function submit() {
		submitError.value = ''
		consentError.value = form.consent ? '' : t('businessLead.consentRequired')

		const valid = await formRef.value?.validate()
		if (!valid || !form.consent || submitting.value) {
			return
		}

		submitting.value = true
		Object.keys(serverErrors).forEach((key) => delete serverErrors[key])

		try {
			const { data } = await submitLeadsPage001({
				...form,
				locale: routeLocale.value || locale.value,
				...trackingPayload()
			})
			const page = data.data?.page

			if (!page?.public_path) {
				throw new Error('Missing page path')
			}

			trackLead(page)
			await router.push(`/${normalizeCatalogLocale(routeLocale.value || locale.value)}${page.public_path}`)
		} catch (error) {
			const errors = error.response?.data?.errors || {}
			Object.assign(serverErrors, errors)
			submitError.value = apiErrorMessage(error, t('businessLead.submitFailed'))
		} finally {
			submitting.value = false
		}
	}

	async function initialize() {
		optionsLoading.value = true
		optionsError.value = ''

		try {
			if (routeLocale.value !== locale.value) {
				await setLocale(routeLocale.value)
			}

			await Promise.all([loadLocationOptions(), loadCatalogTopics()])
			citySelectOptions.value = cityOptions.value

			if (!cityOptions.value.length || !catalogGroups.value.length) {
				optionsError.value = t('businessLead.optionsFailed')
			}
		} catch {
			optionsError.value = t('businessLead.optionsFailed')
		} finally {
			optionsLoading.value = false
		}
	}

	watch(cityOptions, (options) => {
		citySelectOptions.value = options
	}, { immediate: true })

	watch(routeLocale, async(nextLocale) => {
		if (nextLocale !== locale.value) {
			await setLocale(nextLocale)
		}
	})

	onMounted(initialize)
</script>

<template>
	<q-page class="business-lead-page" :dir="isRtl ? 'rtl' : 'ltr'">
		<section class="business-lead-hero">
			<div class="business-lead-hero__content">
				<h1>{{ t('businessLead.heroTitle') }}</h1>
				<ul>
					<li><q-icon name="check_circle" aria-hidden="true" />{{ t('businessLead.benefitPrepared') }}</li>
					<li><q-icon name="check_circle" aria-hidden="true" />{{ t('businessLead.benefitIncluded') }}</li>
					<li><q-icon name="check_circle" aria-hidden="true" />{{ t('businessLead.benefitFree') }}</li>
				</ul>
			</div>
		</section>

		<section id="business-lead-form" class="business-lead-form-band">
			<div class="business-lead-form-wrap">
				<header class="business-lead-form-head">
					<span>{{ t('businessLead.formEyebrow') }}</span>
					<h2>{{ t('businessLead.formTitle') }}</h2>
					<p>{{ t('businessLead.formText') }}</p>
				</header>

				<q-banner v-if="optionsError" rounded class="business-lead-form__error">
					<template #avatar><q-icon name="error" /></template>
					{{ optionsError }}
				</q-banner>

				<q-form ref="formRef" class="business-lead-form" @submit.prevent="submit">
					<label class="business-lead-honeypot" aria-hidden="true">
						<input v-model="form.website" name="website" type="text" tabindex="-1" autocomplete="off" />
					</label>

					<q-input
						v-model="form.business_name"
						outlined
						class="business-lead-field--wide"
						name="organization"
						autocomplete="organization"
						maxlength="255"
						:label="`${t('businessLead.businessName')} *`"
						:rules="[requiredRule]"
						:error="Boolean(serverError('business_name')) || null"
						:error-message="serverError('business_name') || undefined"
						@update:model-value="clearServerError('business_name')"
					>
						<template #prepend><q-icon name="storefront" /></template>
					</q-input>

					<q-select
						v-model="form.city"
						outlined
						class="business-lead-field--wide"
						clearable
						emit-value
						map-options
						use-input
						hide-selected
						fill-input
						input-debounce="0"
						name="city"
						:options="citySelectOptions"
						:loading="optionsLoading"
						:disable="optionsLoading || Boolean(optionsError)"
						:label="`${t('businessLead.city')} *`"
						:rules="[requiredRule]"
						:error="Boolean(serverError('city', 'address.city')) || null"
						:error-message="serverError('city', 'address.city') || undefined"
						@filter="filterCityOptions"
						@update:model-value="clearServerError('city', 'address.city')"
					>
						<template #prepend><q-icon name="place" /></template>
					</q-select>

					<q-select
						:model-value="selectedBusinessGroup"
						outlined
						emit-value
						map-options
						name="business_group_key"
						:options="businessIndustryOptions"
						:loading="optionsLoading"
						:disable="optionsLoading || Boolean(optionsError)"
						:label="`${t('businessLead.industry')} *`"
						:rules="[requiredRule]"
						@update:model-value="selectBusinessGroup"
					>
						<template #prepend><q-icon name="category" /></template>
						<template #option="scope">
							<q-item v-bind="scope.itemProps">
								<q-item-section avatar class="business-lead-category-option__avatar">
									<span class="business-lead-category-option__swatch" :style="{ backgroundColor: scope.opt.color }" />
								</q-item-section>
								<q-item-section>{{ scope.opt.label }}</q-item-section>
							</q-item>
						</template>
					</q-select>

					<q-select
						v-model="form.category_key"
						outlined
						emit-value
						map-options
						name="category_key"
						:options="businessTypeOptions"
						:disable="optionsLoading || Boolean(optionsError) || !selectedBusinessGroup"
						:label="`${t('businessLead.businessType')} *`"
						:rules="[requiredRule]"
						:error="Boolean(serverError('category_key')) || null"
						:error-message="serverError('category_key') || undefined"
						@update:model-value="clearServerError('category_key')"
					>
						<template #prepend><q-icon name="tune" /></template>
						<template #option="scope">
							<q-item v-bind="scope.itemProps">
								<q-item-section avatar class="business-lead-category-option__avatar">
									<span class="business-lead-category-option__swatch" :style="{ backgroundColor: scope.opt.color }" />
								</q-item-section>
								<q-item-section>{{ scope.opt.label }}</q-item-section>
							</q-item>
						</template>
					</q-select>

					<q-input
						v-model="form.full_name"
						outlined
						class="business-lead-field--wide"
						name="name"
						autocomplete="name"
						maxlength="255"
						:label="`${t('businessLead.fullName')} *`"
						:rules="[requiredRule]"
						:error="Boolean(serverError('full_name')) || null"
						:error-message="serverError('full_name') || undefined"
						@update:model-value="clearServerError('full_name')"
					>
						<template #prepend><q-icon name="person" /></template>
					</q-input>

					<q-input
						v-model="form.email"
						outlined
						type="email"
						name="email"
						autocomplete="email"
						inputmode="email"
						dir="ltr"
						maxlength="255"
						:label="`${t('businessLead.email')} *`"
						:rules="[requiredRule, emailRule]"
						:error="Boolean(serverError('email')) || null"
						:error-message="serverError('email') || undefined"
						@update:model-value="clearServerError('email')"
					>
						<template #prepend><q-icon name="mail" /></template>
					</q-input>

					<q-input
						v-model="form.phone"
						outlined
						type="tel"
						name="tel"
						autocomplete="tel"
						inputmode="tel"
						dir="ltr"
						maxlength="40"
						:label="`${t('businessLead.phone')} *`"
						:rules="[requiredRule, phoneRule]"
						:error="Boolean(serverError('phone')) || null"
						:error-message="serverError('phone') || undefined"
						@update:model-value="clearServerError('phone')"
					>
						<template #prepend><q-icon name="phone" /></template>
					</q-input>

					<div class="business-lead-consent business-lead-field--wide" :class="{ 'business-lead-consent--error': consentError }">
						<q-checkbox v-model="form.consent" color="primary" @update:model-value="consentError = ''" />
						<p>
							{{ t('businessLead.consent') }}
							<router-link :to="{ name: 'privacy' }">{{ t('businessLead.privacyLink') }}</router-link>
						</p>
						<small v-if="consentError">{{ consentError }}</small>
					</div>

					<q-banner v-if="submitError" rounded class="business-lead-form__error business-lead-field--wide">
						<template #avatar><q-icon name="error" /></template>
						{{ submitError }}
					</q-banner>

					<q-btn
						class="business-lead-submit business-lead-field--wide"
						rounded
						unelevated
						no-caps
						type="submit"
						color="primary"
						icon="storefront"
						:loading="submitting"
						:disable="optionsLoading || Boolean(optionsError)"
						:label="t('businessLead.submit')"
					/>
				</q-form>
			</div>
		</section>
	</q-page>
</template>

<style scoped lang="scss">
.business-lead-page {
  width: 100%;
  min-width: 0;
  max-width: 100%;
  overflow-x: clip;
  background: #f8f2f7;
  color: #172238;
}

.business-lead-hero {
  display: grid;
  width: 100%;
  min-width: 0;
  max-width: 100%;
  place-items: center;
}

.business-lead-hero__content {
  box-sizing: border-box;
  width: min(1180px, 100%);
  min-width: 0;
  max-width: 100%;
  padding: 44px clamp(20px, 4vw, 48px) 38px;
}

.business-lead-hero h1 {
  width: min(720px, 100%);
  max-width: 100%;
  margin: 0;
  color: #2f1747;
  font-size: 2.65rem;
  line-height: 1.16;
  overflow-wrap: anywhere;
}

.business-lead-hero ul {
  display: grid;
  gap: 10px;
  width: min(680px, 100%);
  margin: 24px 0 0;
  padding: 0;
  list-style: none;
}

.business-lead-hero li {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  font-size: 1.05rem;
  font-weight: 700;
  line-height: 1.45;
}

.business-lead-hero li .q-icon {
  flex: 0 0 auto;
  margin-top: 3px;
  color: var(--soz-orange);
  font-size: 21px;
}

.business-lead-form-band {
  box-sizing: border-box;
  width: 100%;
  min-width: 0;
  max-width: 100%;
  padding: 48px 20px calc(58px + env(safe-area-inset-bottom));
}

.business-lead-form-wrap {
  box-sizing: border-box;
  width: min(760px, 100%);
  min-width: 0;
  max-width: 100%;
  margin: 0 auto;
  padding: 30px;
  border: 1px solid rgba(50, 28, 70, 0.1);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.92);
  box-shadow: 0 24px 62px rgba(49, 28, 70, 0.12);
}

.business-lead-form-head {
  margin-bottom: 24px;
}

.business-lead-form-head span {
  color: #d92d7a;
  font-size: 0.78rem;
  font-weight: 850;
}

.business-lead-form-head h2,
.business-lead-form-head p {
  margin: 0;
}

.business-lead-form-head h2 {
  margin-top: 5px;
  font-size: 1.72rem;
  line-height: 1.25;
}

.business-lead-form-head p {
  margin-top: 7px;
  color: rgba(23, 34, 56, 0.64);
  line-height: 1.55;
}

.business-lead-form {
  position: relative;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 4px 14px;
}

.business-lead-form > * {
  min-width: 0;
  max-width: 100%;
}

.business-lead-field--wide {
  grid-column: 1 / -1;
}

.business-lead-form :deep(.q-field__control) {
  min-height: 52px;
  border-radius: 14px;
  background: #fff;
}

.business-lead-form :deep(.q-field__prepend) {
  color: var(--soz-orange);
}

.business-lead-category-option__avatar {
  min-width: 30px;
}

.business-lead-category-option__swatch {
  display: block;
  width: 12px;
  height: 12px;
  border-radius: 50%;
  box-shadow: 0 0 0 4px rgba(245, 66, 145, 0.08);
}

.business-lead-consent {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 0 8px;
  align-items: start;
  margin: 4px 0 14px;
  padding: 10px 12px;
  border: 1px solid transparent;
  border-radius: 14px;
}

.business-lead-consent--error {
  border-color: #c10015;
  background: rgba(193, 0, 21, 0.04);
}

.business-lead-consent p {
  margin: 8px 0 0;
  color: rgba(23, 34, 56, 0.72);
  font-size: 0.84rem;
  line-height: 1.5;
}

.business-lead-consent a {
  margin-inline-start: 4px;
  color: #a62267;
  font-weight: 800;
}

.business-lead-consent small {
  grid-column: 2;
  color: #c10015;
}

.business-lead-form__error {
  margin-bottom: 14px;
  border: 1px solid rgba(193, 0, 21, 0.16);
  background: rgba(193, 0, 21, 0.06);
  color: #8d1020;
}

.business-lead-submit {
  min-height: 52px;
  font-size: 1rem;
  font-weight: 850;
  box-shadow: 0 15px 32px rgba(245, 66, 145, 0.24);
}

.business-lead-submit.q-btn.bg-primary {
  background: var(--soz-action-gradient) !important;
}

.business-lead-honeypot {
  position: absolute;
  width: 1px;
  height: 1px;
  margin: -1px;
  padding: 0;
  overflow: hidden;
  border: 0;
  clip: rect(0 0 0 0);
  clip-path: inset(50%);
  white-space: nowrap;
}

@media (max-width: 700px) {
  .business-lead-hero__content {
    width: 100%;
    padding: 26px 16px 28px;
  }

  .business-lead-hero h1 {
    font-size: 1.9rem;
    line-height: 1.2;
  }

  .business-lead-hero ul {
    gap: 8px;
    margin-top: 18px;
  }

  .business-lead-hero li {
    font-size: 0.94rem;
    line-height: 1.4;
  }

  .business-lead-form-band {
    padding: 18px 10px calc(34px + env(safe-area-inset-bottom));
  }

  .business-lead-form-wrap {
    padding: 20px 14px;
    border-radius: 8px;
  }

  .business-lead-form-head {
    margin-bottom: 18px;
  }

  .business-lead-form-head h2 {
    font-size: 1.42rem;
  }

  .business-lead-form {
    grid-template-columns: minmax(0, 1fr);
    gap: 2px;
  }

  .business-lead-field--wide {
    grid-column: auto;
  }

  .business-lead-consent {
    margin-bottom: 10px;
    padding-inline: 4px;
  }

  .business-lead-submit {
    width: 100%;
  }
}
</style>
