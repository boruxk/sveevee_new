<script setup>
	import { computed, watch } from 'vue'
	import { onBeforeRouteLeave, useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { setLocale } from '@/i18n'
	import { landingIconAvifSrcset, landingIconImage, landingIconWebpSrcset } from '@/constants/landingFeatureIcons'
	import { clearBusinessLeadDraft } from '@/utils/businessLeadDraft'

	const route = useRoute()
	const { locale, t } = useI18n()
	const routeLocale = computed(() => String(route.params.locale || 'he'))
	const isRtl = computed(() => locale.value === 'he')
	const formRoute = computed(() => ({
		name: 'leads-page-001',
		params: { locale: routeLocale.value },
		query: route.query,
		hash: '#business-lead-form'
	}))
	const features = computed(() => [
		{ icon: 'public', title: t('businessLead.infoFeatures.profileTitle'), text: t('businessLead.infoFeatures.profileText') },
		{ icon: 'forum', title: t('businessLead.infoFeatures.contactTitle'), text: t('businessLead.infoFeatures.contactText') },
		{ icon: 'storefront', title: t('businessLead.infoFeatures.storeTitle'), text: t('businessLead.infoFeatures.storeText') },
		{ icon: 'design_services', title: t('businessLead.infoFeatures.servicesTitle'), text: t('businessLead.infoFeatures.servicesText') },
		{ icon: 'receipt_long', title: t('businessLead.infoFeatures.priceListTitle'), text: t('businessLead.infoFeatures.priceListText') },
		{ icon: 'star', title: t('businessLead.infoFeatures.ratingsTitle'), text: t('businessLead.infoFeatures.ratingsText') }
	])

	watch(routeLocale, async(nextLocale) => {
		if (nextLocale !== locale.value) {
			await setLocale(nextLocale)
		}
	}, { immediate: true })

	onBeforeRouteLeave((to) => {
		if (to.name !== 'leads-page-001') {
			clearBusinessLeadDraft()
		}
	})
</script>

<template>
	<q-page class="business-lead-info-page" :dir="isRtl ? 'rtl' : 'ltr'">
		<article class="business-lead-info">
			<header>
				<h1>{{ t('businessLead.infoTitle') }}</h1>
				<p class="business-lead-info__intro">{{ t('businessLead.infoIntro') }}</p>
			</header>

			<dl class="business-lead-info__features">
				<div v-for="feature in features" :key="feature.title" class="business-lead-info__feature">
					<dt>
						<picture class="business-lead-info__image" aria-hidden="true">
							<source :srcset="landingIconAvifSrcset(feature.icon)" sizes="(max-width: 700px) 36px, 56px" type="image/avif" />
							<source :srcset="landingIconWebpSrcset(feature.icon)" sizes="(max-width: 700px) 36px, 56px" type="image/webp" />
							<img
								:src="landingIconImage(feature.icon)"
								alt=""
								width="56"
								height="56"
								loading="lazy"
								decoding="async"
							/>
						</picture>
						<span>{{ feature.title }}</span>
					</dt>
					<dd>{{ feature.text }}</dd>
				</div>
			</dl>

			<p class="business-lead-info__note">{{ t('businessLead.infoClaimNote') }}</p>

			<q-btn
				class="business-lead-info__back"
				rounded
				unelevated
				no-caps
				color="primary"
				:to="formRoute"
				:label="t('businessLead.backToForm')"
			/>
		</article>
	</q-page>
</template>

<style scoped lang="scss">
.business-lead-info-page {
  padding: 48px 20px calc(58px + env(safe-area-inset-bottom));
  background: #f8f2f7;
  color: #172238;
}

.business-lead-info {
  width: 100%;
  max-width: 760px;
  margin: 0 auto;
  padding: 30px;
  border: 1px solid rgba(50, 28, 70, 0.14);
  border-radius: 8px;
  background: #fff;
}

.business-lead-info h1 {
  margin: 0;
  font-size: 1.72rem;
  font-weight: 700;
  line-height: 1.25;
}

.business-lead-info__intro {
  margin: 12px 0 0;
  color: rgba(23, 34, 56, 0.7);
  line-height: 1.6;
}

.business-lead-info__features {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 24px 28px;
  margin: 28px 0;
}

.business-lead-info__feature dt {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 5px;
  font-size: 1rem;
  font-weight: 750;
  line-height: 1.4;
}

.business-lead-info__image {
  display: block;
  flex: 0 0 56px;
  width: 56px;
  height: 56px;
}

.business-lead-info__image img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.business-lead-info__feature dd {
  margin: 0;
  color: rgba(23, 34, 56, 0.7);
  line-height: 1.6;
}

.business-lead-info__note {
  margin: 0 0 24px;
  padding-top: 20px;
  border-top: 1px solid rgba(50, 28, 70, 0.14);
  color: rgba(23, 34, 56, 0.7);
  line-height: 1.6;
}

.business-lead-info__back {
  min-height: 44px;
  padding-inline: 24px;
  font-weight: 750;
}

.business-lead-info__back.q-btn.bg-primary {
  background: var(--soz-action-gradient) !important;
}

@media (max-width: 700px) {
  .business-lead-info-page {
    padding: 18px max(10px, env(safe-area-inset-right)) calc(34px + env(safe-area-inset-bottom)) max(10px, env(safe-area-inset-left));
  }

  .business-lead-info {
    padding: 24px 18px;
  }

  .business-lead-info h1 {
    font-size: 1.42rem;
  }

  .business-lead-info__features {
    grid-template-columns: minmax(0, 1fr);
    gap: 20px;
    margin: 24px 0;
  }

  .business-lead-info__back {
    width: 100%;
  }

  .business-lead-info__feature dt {
    gap: 8px;
  }

  .business-lead-info__image {
    flex-basis: 36px;
    width: 36px;
    height: 36px;
  }
}
</style>
