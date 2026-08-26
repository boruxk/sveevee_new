<script setup>
	import { computed, onMounted } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useAppStore } from '@/stores/app'
	import { useCatalogTopics } from '@/composables/useCatalogTopics'
	import { catalogLabel, catalogPath } from '@/constants/catalogTopics'
	import SocialShareButtons from '@/components/share/SocialShareButtons.vue'
	import {
		landingFeatureIconAvifSrcset,
		landingFeatureIconImage,
		landingFeatureIconWebpSrcset,
		landingIconAvifSrcset,
		landingIconImage,
		landingIconWebpSrcset,
		landingStepIconAvifSrcset,
		landingStepIconImage,
		landingStepIconWebpSrcset
	} from '@/constants/landingFeatureIcons'

	const { t, tm, locale } = useI18n()
	const appStore = useAppStore()
	const { catalogPopularTopics, loadCatalogTopics } = useCatalogTopics()
	const transparentPixel = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='
	const heroAvifSrcSet = '/assets/landing/hero-main-960.v1.avif 960w, /assets/landing/hero-main-1360.v1.avif 1360w'
	const heroWebpSrcSet = '/assets/landing/hero-main-960.v1.webp 960w, /assets/landing/hero-main-1360.v1.webp 1360w'
	const mobileHeroSizes = '(max-width: 520px) 100vw, 520px'
	const mobileHeroAvifSrcSet = '/assets/landing/hero-mobile-480.v1.avif 480w, /assets/landing/hero-mobile-640.v1.avif 640w, /assets/landing/hero-mobile-720.v1.avif 720w, /assets/landing/hero-mobile-800.v1.avif 800w'
	const mobileHeroWebpSrcSet = '/assets/landing/hero-mobile-480.v1.webp 480w, /assets/landing/hero-mobile-640.v1.webp 640w, /assets/landing/hero-mobile-720.v1.webp 720w, /assets/landing/hero-mobile-800.v1.webp 800w'
	const pricingBusinessSrc = '/assets/landing/pricing-business-280.v2.webp'
	const pricingPrivateSrc = '/assets/landing/pricing-private-220.v2.webp'
	const pricingBusinessAvifSrcSet = '/assets/landing/pricing-business-280.v2.avif 280w, /assets/landing/pricing-business-520.v2.avif 520w'
	const pricingBusinessWebpSrcSet = '/assets/landing/pricing-business-280.v2.webp 280w, /assets/landing/pricing-business-520.v2.webp 520w'
	const pricingPrivateAvifSrcSet = '/assets/landing/pricing-private-220.v2.avif 220w, /assets/landing/pricing-private-360.v2.avif 360w'
	const pricingPrivateWebpSrcSet = '/assets/landing/pricing-private-220.v2.webp 220w, /assets/landing/pricing-private-360.v2.webp 360w'
	const logoSrc = '/assets/landing/sveevee-logo-640.v1.webp'
	const logoAvifSrcSet = '/assets/landing/sveevee-logo-320.v1.avif 320w, /assets/landing/sveevee-logo-640.v1.avif 640w'
	const logoWebpSrcSet = '/assets/landing/sveevee-logo-320.v1.webp 320w, /assets/landing/sveevee-logo-640.v1.webp 640w'
	const workflowHouseSrc = '/assets/landing/workflow-house-420.v2.webp'
	const workflowHouseAvifSrcSet = '/assets/landing/workflow-house-420.v2.avif 420w, /assets/landing/workflow-house-720.v2.avif 720w'
	const workflowHouseWebpSrcSet = '/assets/landing/workflow-house-420.v2.webp 420w, /assets/landing/workflow-house-720.v2.webp 720w'
	const sveeveeShareUrl = 'https://sveevee.co.il'

	function listMessage(key) {
		const value = tm(key)
		return Array.isArray(value) ? value : []
	}

	const featureCards = computed(() => listMessage('landing.features'))
	const contentBlocks = computed(() => listMessage('landing.contentBlocks'))
	const businessBenefits = computed(() => listMessage('landing.businessBenefits'))
	const communityBenefits = computed(() => listMessage('landing.communityBenefits'))
	const purposeParagraphs = computed(() => listMessage('landing.purposeParagraphs'))
	const steps = computed(() => listMessage('landing.steps'))
	const plans = computed(() => listMessage('landing.plans'))
	const popularCatalogTopics = computed(() => catalogPopularTopics.value.slice(0, 12))
	const featureTitleParts = computed(() => {
		const title = t('landing.featureTitle')
		const words = title.split(' ')
		const accentWordCounts = {
			fr: 11
		}
		const accentWordCount = accentWordCounts[locale.value] ?? 5

		if (words.length <= accentWordCount) {
			return { lead: title, accent: '' }
		}

		return {
			lead: words.slice(0, -accentWordCount).join(' '),
			accent: words.slice(-accentWordCount).join(' ')
		}
	})
	const pricingTitleParts = computed(() => {
		const title = t('landing.pricingTitle')
		const words = title.split(' ')

		if (words.length < 2) {
			return { lead: title, accent: '' }
		}

		return {
			lead: words.slice(0, -1).join(' '),
			accent: words.at(-1)
		}
	})

	function planImage(plan) {
		return plan.featured ? pricingBusinessSrc : pricingPrivateSrc
	}

	function planImageAvifSrcSet(plan) {
		return plan.featured ? pricingBusinessAvifSrcSet : pricingPrivateAvifSrcSet
	}

	function planImageWebpSrcSet(plan) {
		return plan.featured ? pricingBusinessWebpSrcSet : pricingPrivateWebpSrcSet
	}

	function planImageSizes(plan) {
		return plan.featured ? '280px' : '220px'
	}

	function planTone(plan) {
		return plan.featured ? 'business' : 'private'
	}

	function topicName(topic) {
		return catalogLabel(topic?.labels, locale.value)
	}

	onMounted(loadCatalogTopics)
</script>

<template>
	<q-page class="landing-page" :class="{ 'landing-page--rtl': appStore.isRtl }">
		<section class="landing-hero">
			<div class="landing-hero__inner">
				<div class="landing-hero__copy">
					<h1 class="landing-hero__title">
						<picture>
							<source :srcset="logoAvifSrcSet" sizes="(max-width: 640px) calc(100vw - 48px), 560px" type="image/avif" />
							<source :srcset="logoWebpSrcSet" sizes="(max-width: 640px) calc(100vw - 48px), 560px" type="image/webp" />
							<img
								class="landing-hero__wordmark"
								:src="logoSrc"
								:alt="t('landing.title')"
								width="640"
								height="125"
								decoding="async"
							/>
						</picture>
					</h1>
					<p class="landing-hero__subtitle">
						<span class="landing-hero__subtitle-shape" aria-hidden="true"></span>
						{{ t('landing.subtitle') }}
					</p>

					<div class="landing-hero__mobile-visual">
						<picture>
							<source media="(max-width: 640px)" :srcset="mobileHeroAvifSrcSet" :sizes="mobileHeroSizes" type="image/avif" />
							<source media="(max-width: 640px)" :srcset="mobileHeroWebpSrcSet" :sizes="mobileHeroSizes" type="image/webp" />
							<img
								:src="transparentPixel"
								:alt="t('seo.landingTitle')"
								width="800"
								height="800"
								loading="eager"
								fetchpriority="high"
								decoding="async"
							/>
						</picture>
					</div>

					<router-link :to="{ name: 'register' }" class="landing-first-badge">
						<span class="landing-first-badge__copy">
							<strong>{{ t('landing.firstBadgeTitle') }}</strong>
							<span>{{ t('landing.firstBadgeBody') }}</span>
						</span>
					</router-link>

					<div class="landing-hero__actions">
						<q-btn color="primary"
							unelevated
							rounded
							icon="person_add"
							class="landing-register-cta"
							:label="t('nav.register')"
							:to="{ name: 'register' }"
						/>
						<q-btn unelevated
							rounded
							color="primary"
							class="landing-hero__search-btn"
							icon="search"
							:label="t('landing.searchWithoutRegistration')"
							:title="t('landing.searchWithoutRegistration')"
							:to="{ name: 'search' }"
						/>
					</div>
				</div>

				<div class="landing-hero__visual">
					<picture>
						<source media="(min-width: 641px)" :srcset="heroAvifSrcSet" sizes="90vw" type="image/avif" />
						<source media="(min-width: 641px)" :srcset="heroWebpSrcSet" sizes="90vw" type="image/webp" />
						<img
							:src="transparentPixel"
							:alt="t('seo.landingTitle')"
							width="1360"
							height="766"
							loading="eager"
							fetchpriority="high"
							decoding="async"
						/>
					</picture>
				</div>
			</div>
		</section>

		<section class="landing-share-section" aria-labelledby="landing-share-title">
			<div class="landing-share-section__inner">
				<div class="landing-share-section__copy">
					<h2 id="landing-share-title">{{ t('landing.shareTitle') }}</h2>
					<p>{{ t('landing.shareBody') }}</p>
				</div>
				<div class="landing-share-section__actions">
					<strong>{{ t('landing.shareCta') }}</strong>
					<SocialShareButtons :url="sveeveeShareUrl" title="Sveevee" />
				</div>
			</div>
		</section>

		<section class="landing-section landing-purpose-section" aria-labelledby="landing-purpose-title">
			<div class="landing-purpose">
				<div class="landing-purpose__copy">
					<div v-if="t('landing.purposeKicker')" class="section-kicker">{{ t('landing.purposeKicker') }}</div>
					<h2 id="landing-purpose-title">{{ t('landing.purposeTitle') }}</h2>
					<p v-for="paragraph in purposeParagraphs" :key="paragraph">{{ paragraph }}</p>
				</div>

			</div>
		</section>

		<section class="landing-section landing-section--features">
			<div class="landing-section__head">
				<div class="section-kicker">{{ t('landing.featureKicker') }}</div>
				<h2>
					{{ featureTitleParts.lead }}
					<span v-if="featureTitleParts.accent">{{ featureTitleParts.accent }}</span>
				</h2>
			</div>

			<div class="feature-grid">
				<article v-for="(item, index) in featureCards" :key="item.title" class="feature-card" :class="`feature-card--${index + 1}`">
					<span class="feature-card__icon" aria-hidden="true">
						<picture>
							<source :srcset="landingFeatureIconAvifSrcset(item, index)" sizes="(max-width: 640px) 68px, 90px" type="image/avif" />
							<source :srcset="landingFeatureIconWebpSrcset(item, index)" sizes="(max-width: 640px) 68px, 90px" type="image/webp" />
							<img
								class="landing-image-icon"
								:src="landingFeatureIconImage(item, index)"
								alt=""
								width="320"
								height="320"
								loading="lazy"
								decoding="async"
							/>
						</picture>
					</span>
					<h3>{{ item.title }}</h3>
					<p>{{ item.body }}</p>
				</article>
			</div>
		</section>

		<section class="landing-section content-section">
			<div class="content-section__head">
				<div class="section-kicker">{{ t('landing.contentKicker') }}</div>
				<h2>{{ t('landing.contentTitle') }}</h2>
			</div>

			<div class="content-grid">
				<article v-for="item in contentBlocks" :key="item.title" class="content-block">
					<h3>{{ item.title }}</h3>
					<p>{{ item.body }}</p>
				</article>
			</div>
		</section>

		<section class="landing-section audience-section">
			<article class="audience-panel audience-panel--business">
				<div class="audience-panel__copy">
					<div class="section-kicker">{{ t('landing.businessKicker') }}</div>
					<h2>{{ t('landing.businessTitle') }}</h2>
					<p>{{ t('landing.businessBody') }}</p>
					<p class="audience-panel__free-note">{{ t('landing.businessFreeNote') }}</p>
					<div class="audience-panel__actions">
						<q-btn
							unelevated
							rounded
							color="primary"
							class="audience-panel__link"
							:to="{ name: 'businesses-landing' }"
						>
							<span class="audience-panel__button-content">
								<svg class="audience-panel__button-svg audience-panel__button-svg--more" viewBox="0 0 24 24" aria-hidden="true">
									<path d="M5 12h12" />
									<path d="m13 7 5 5-5 5" />
								</svg>
								<span>{{ t('promoLanding.moreCta') }}</span>
							</span>
						</q-btn>
						<q-btn
							unelevated
							rounded
							color="primary"
							class="audience-panel__link audience-panel__link--secondary"
							icon="visibility"
							:label="t('promoLanding.examplePageCta')"
							:to="{ name: 'business-example-page' }"
						/>
					</div>
				</div>

				<div class="audience-benefit-grid">
					<article v-for="item in businessBenefits" :key="item.title" class="audience-benefit">
						<span class="audience-benefit__icon" aria-hidden="true">
							<picture>
								<source :srcset="landingIconAvifSrcset(item.icon)" sizes="(max-width: 640px) 48px, 66px" type="image/avif" />
								<source :srcset="landingIconWebpSrcset(item.icon)" sizes="(max-width: 640px) 48px, 66px" type="image/webp" />
								<img
									class="landing-image-icon"
									:src="landingIconImage(item.icon)"
									alt=""
									width="320"
									height="320"
									loading="lazy"
									decoding="async"
								/>
							</picture>
						</span>
						<div>
							<h3>{{ item.title }}</h3>
							<p>{{ item.body }}</p>
						</div>
					</article>
				</div>
			</article>

			<article class="audience-panel audience-panel--community">
				<div class="audience-panel__copy">
					<div class="section-kicker">{{ t('landing.communityKicker') }}</div>
					<h2>{{ t('landing.communityTitle') }}</h2>
					<p>{{ t('landing.communityBody') }}</p>
					<div class="audience-panel__actions">
						<q-btn
							unelevated
							rounded
							color="primary"
							class="audience-panel__link"
							:to="{ name: 'communities-landing' }"
						>
							<span class="audience-panel__button-content">
								<svg class="audience-panel__button-svg audience-panel__button-svg--more" viewBox="0 0 24 24" aria-hidden="true">
									<path d="M5 12h12" />
									<path d="m13 7 5 5-5 5" />
								</svg>
								<span>{{ t('promoLanding.moreCta') }}</span>
							</span>
						</q-btn>
						<q-btn
							unelevated
							rounded
							color="primary"
							class="audience-panel__link audience-panel__link--secondary"
							icon="visibility"
							:label="t('promoLanding.examplePageCta')"
							:to="{ name: 'community-example-page' }"
						/>
					</div>
				</div>

				<div class="audience-benefit-grid">
					<article v-for="item in communityBenefits" :key="item.title" class="audience-benefit">
						<span class="audience-benefit__icon" aria-hidden="true">
							<picture>
								<source :srcset="landingIconAvifSrcset(item.icon)" sizes="(max-width: 640px) 48px, 66px" type="image/avif" />
								<source :srcset="landingIconWebpSrcset(item.icon)" sizes="(max-width: 640px) 48px, 66px" type="image/webp" />
								<img
									class="landing-image-icon"
									:src="landingIconImage(item.icon)"
									alt=""
									width="320"
									height="320"
									loading="lazy"
									decoding="async"
								/>
							</picture>
						</span>
						<div>
							<h3>{{ item.title }}</h3>
							<p>{{ item.body }}</p>
						</div>
					</article>
				</div>
			</article>
		</section>

		<section class="landing-section workflow-section">
			<div class="workflow-copy">
				<div class="section-kicker">{{ t('landing.workflowKicker') }}</div>
				<h2>{{ t('landing.workflowTitle') }}</h2>
				<p>{{ t('landing.workflowBody') }}</p>
				<picture class="workflow-art">
					<source :srcset="workflowHouseAvifSrcSet" sizes="(max-width: 640px) calc(100vw - 32px), 420px" type="image/avif" />
					<source :srcset="workflowHouseWebpSrcSet" sizes="(max-width: 640px) calc(100vw - 32px), 420px" type="image/webp" />
					<img
						:src="workflowHouseSrc"
						:alt="t('landing.workflowTitle')"
						width="420"
						height="280"
						loading="lazy"
						decoding="async"
					/>
				</picture>
			</div>

			<div class="step-list">
				<article v-for="(item, index) in steps" :key="item.title" class="step-item" :class="`step-item--${index + 1}`">
					<span class="step-item__icon" aria-hidden="true">
						<picture>
							<source :srcset="landingStepIconAvifSrcset(index)" sizes="(max-width: 640px) 54px, 76px" type="image/avif" />
							<source :srcset="landingStepIconWebpSrcset(index)" sizes="(max-width: 640px) 54px, 76px" type="image/webp" />
							<img
								class="landing-image-icon"
								:src="landingStepIconImage(index)"
								alt=""
								width="320"
								height="320"
								loading="lazy"
								decoding="async"
							/>
						</picture>
					</span>
					<div class="step-item__copy">
						<h3>{{ item.title }}</h3>
						<p>{{ item.body }}</p>
					</div>
				</article>
			</div>
		</section>

		<section class="landing-section pricing-section">
			<div class="pricing-head">
				<div class="section-kicker">{{ t('landing.pricingKicker') }}</div>
				<h2>
					{{ pricingTitleParts.lead }}
					<span v-if="pricingTitleParts.accent">{{ pricingTitleParts.accent }}</span>
				</h2>
			</div>

			<div class="pricing-grid">
				<article v-for="plan in plans" :key="plan.title" class="pricing-card" :class="[`pricing-card--${planTone(plan)}`, { 'pricing-card--featured': plan.featured }]">
					<div class="pricing-card__top">
						<div class="pricing-card__intro">
							<div class="pricing-card__name">{{ plan.title }}</div>
							<p>{{ plan.subtitle }}</p>
						</div>
					</div>

					<div class="pricing-card__main">
						<div class="pricing-card__content">
							<div class="pricing-card__price">
								<strong>0</strong>
								<span class="pricing-card__currency">{{ t('landing.currency') }}</span>
								<span>{{ t('landing.month') }}</span>
							</div>

							<ul>
								<li v-for="feature in plan.features" :key="feature">
									<q-icon name="check_circle" size="20px" />
									<span>{{ feature }}</span>
								</li>
							</ul>
						</div>

						<picture class="pricing-card__art-wrap">
							<source :srcset="planImageAvifSrcSet(plan)" :sizes="planImageSizes(plan)" type="image/avif" />
							<source :srcset="planImageWebpSrcSet(plan)" :sizes="planImageSizes(plan)" type="image/webp" />
							<img
								class="pricing-card__art"
								:src="planImage(plan)"
								:alt="`${plan.title} ${t('landing.pricingKicker')}`"
								:width="plan.featured ? 280 : 220"
								:height="plan.featured ? 289 : 274"
								loading="lazy"
								decoding="async"
							/>
						</picture>
					</div>

					<q-btn
						class="pricing-card__button"
						unelevated
						rounded
						:label="t('nav.register')"
						:to="{ name: 'register' }"
					/>
				</article>
			</div>
		</section>

		<section v-if="popularCatalogTopics.length" class="landing-section landing-catalog-section">
			<div class="landing-section__head">
				<div class="section-kicker">{{ t('landing.catalogKicker') }}</div>
				<h2>{{ t('landing.catalogTitle') }}</h2>
			</div>
			<div class="landing-topic-grid">
				<router-link
					v-for="topic in popularCatalogTopics"
					:key="topic.key"
					class="landing-topic-chip"
					:to="catalogPath(topic)"
					:style="{ '--topic-color': topic.color }"
				>
					<span class="landing-topic-chip__dot" />
					<span>{{ topicName(topic) }}</span>
				</router-link>
			</div>
		</section>
	</q-page>
</template>

<style scoped lang="scss">
.landing-page {
  min-height: 100vh;
  padding-bottom: 72px;
  background: transparent;
}

.landing-hero {
  position: relative;
  overflow: hidden;
  background: transparent;
}

.landing-hero__inner {
  position: relative;
  display: grid;
  grid-template-columns: minmax(0, 0.88fr) minmax(420px, 1.12fr);
  gap: clamp(28px, 5vw, 72px);
  align-items: center;
  max-width: 1280px;
  min-height: clamp(540px, 52vw, 650px);
  margin: 0 auto;
  padding: clamp(42px, 7vw, 84px) 24px clamp(34px, 6vw, 72px);
}

.landing-hero__copy {
  position: relative;
  z-index: 2;
  grid-column: 1 / 2;
  max-width: 600px;
}

.landing-hero h1 {
  margin: 0;
  line-height: 0.95;
  letter-spacing: 0;
}

.landing-hero__wordmark {
  display: block;
  width: min(100%, 560px);
  height: auto;
}

.landing-hero p {
  max-width: 540px;
  margin: 24px 0 32px;
  color: rgba(21, 31, 59, 0.74);
  font-size: clamp(18px, 2vw, 22px);
  line-height: 1.65;
}

.landing-hero__subtitle-shape {
  display: none;
}

.landing-hero__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.landing-first-badge {
  display: grid;
  gap: 4px;
  width: min(100%, 500px);
  margin: 0 0 18px;
  padding: 13px 18px;
  border: 2px solid transparent;
  border-radius: 30px;
  background:
    linear-gradient(rgba(255, 255, 255, 0.72), rgba(255, 255, 255, 0.72)) padding-box,
    linear-gradient(115deg, #ff7426 0%, #f54291 24%, #7b3ff2 48%, #f54291 72%, #ff7426 100%) border-box;
  background-size: 100% 100%, 320% 320%;
  backdrop-filter: blur(14px);
  box-shadow: 0 10px 26px rgba(92, 47, 126, 0.09);
  color: inherit;
  text-decoration: none;
  cursor: pointer;
  transition: box-shadow 180ms ease, transform 180ms ease;
  animation: landingBadgeBorderWave 4.8s ease-in-out infinite;
}

.landing-first-badge:hover {
  box-shadow: 0 13px 30px rgba(92, 47, 126, 0.14);
  transform: translateY(-1px);
}

.landing-first-badge:focus-visible {
  outline: 3px solid rgba(112, 46, 230, 0.34);
  outline-offset: 3px;
}

.landing-first-badge__copy {
  display: grid;
  gap: 7px;
  min-width: 0;
}

.landing-first-badge strong {
  color: #702ee6;
  font-size: 20px;
  line-height: 1.25;
}

.landing-first-badge__copy > span {
  color: rgba(21, 31, 59, 0.72);
  font-size: 14px;
  line-height: 1.42;
}

@keyframes landingBadgeBorderWave {
  0%,
  100% {
    background-position: 0 0, 0% 50%;
  }

  50% {
    background-position: 0 0, 100% 50%;
  }
}

.landing-register-cta.q-btn.bg-primary {
  animation: landingCtaPulse 2.15s ease-in-out infinite;
  box-shadow: 0 16px 34px rgba(245, 66, 145, 0.28) !important;
  font-weight: 900;
}

@keyframes landingCtaPulse {
  0%,
  100% {
    transform: scale(1);
    filter: drop-shadow(0 0 0 rgba(245, 66, 145, 0));
  }

  50% {
    transform: scale(1.035);
    filter: drop-shadow(0 10px 18px rgba(245, 66, 145, 0.28));
  }
}

.landing-hero__search-btn.q-btn.bg-primary {
  background: var(--soz-menu-gradient) !important;
  box-shadow: 0 12px 24px rgba(123, 63, 242, 0.26) !important;
  color: #ffffff !important;
}

.landing-hero__visual {
  position: absolute;
  z-index: 1;
  top: 50%;
  right: 0;
  width: 90%;
  transform: translateY(-50%);
  pointer-events: none;
  user-select: none;
  -webkit-mask-image: linear-gradient(to right, transparent 0%, transparent 14%, rgba(0, 0, 0, 0.42) 34%, #000000 62%, #000000 100%);
  mask-image: linear-gradient(to right, transparent 0%, transparent 14%, rgba(0, 0, 0, 0.42) 34%, #000000 62%, #000000 100%);
}

.landing-hero__visual picture,
.landing-hero__visual img {
  display: block;
  width: 100%;
  height: auto;
}

.landing-hero__visual img {
  object-fit: contain;
  object-position: center;
}

.landing-page--rtl .landing-hero__visual {
  right: auto;
  left: 0;
  -webkit-mask-image: linear-gradient(to left, transparent 0%, transparent 14%, rgba(0, 0, 0, 0.42) 34%, #000000 62%, #000000 100%);
  mask-image: linear-gradient(to left, transparent 0%, transparent 14%, rgba(0, 0, 0, 0.42) 34%, #000000 62%, #000000 100%);
}

.landing-page--rtl .landing-hero__visual img {
  transform: scaleX(-1);
}

.landing-hero__mobile-visual {
  display: none;
}

.landing-hero__mobile-visual picture,
.landing-hero__mobile-visual img {
  display: block;
  width: 100%;
  height: auto;
}

.landing-section {
  max-width: 1280px;
  margin: 0 auto;
  padding: 54px 24px 0;
}

.landing-share-section {
  border: 0;
  background: transparent;
}

.landing-share-section__inner {
  display: grid;
  grid-template-columns: minmax(0, 1.25fr) minmax(320px, 0.75fr);
  gap: 38px;
  align-items: center;
  max-width: 1280px;
  margin: 0 auto;
  padding: 38px 24px;
}

.landing-share-section h2 {
  margin: 0;
  color: var(--soz-ink);
  font-size: clamp(28px, 3vw, 40px);
  line-height: 1.15;
}

.landing-share-section p {
  max-width: 760px;
  margin: 12px 0 0;
  color: rgba(21, 31, 59, 0.72);
  font-size: 17px;
  line-height: 1.68;
}

.landing-share-section__actions {
  display: grid;
  gap: 12px;
  justify-items: start;
}

.landing-share-section__actions > strong {
  color: #7f1239;
  font-size: 17px;
}

.landing-section--features {
  max-width: none;
  padding: 58px 24px 66px;
  background: transparent;
}

.landing-section__head {
  display: grid;
  gap: 8px;
  max-width: 780px;
  margin-bottom: 22px;
}

.landing-section--features .landing-section__head {
  justify-items: center;
  max-width: 820px;
  margin: 0 auto 30px;
  text-align: center;
}

.section-kicker {
  color: #7f1239;
  font-size: 13px;
  font-weight: 800;
  letter-spacing: 0;
  text-transform: uppercase;
}

.landing-section h2 {
  margin: 0;
  color: var(--soz-ink);
  font-size: clamp(30px, 4vw, 50px);
  line-height: 1.12;
}

.landing-purpose-section {
  padding-top: 48px;
}

.landing-purpose {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 24px;
  align-items: end;
  padding-bottom: 42px;
}

.landing-purpose h2 {
  font-size: clamp(28px, 3vw, 42px);
}

.landing-purpose p {
  margin: 16px 0 0;
  color: rgba(21, 31, 59, 0.72);
  font-size: 17px;
  line-height: 1.72;
}

.landing-catalog-section {
  display: grid;
  gap: 18px;
  padding-bottom: 30px;
}

.landing-topic-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 11px;
}

.landing-topic-chip {
  display: inline-flex;
  max-width: 100%;
  min-height: 42px;
  gap: 8px;
  align-items: center;
  padding: 9px 14px;
  border: 1px solid color-mix(in srgb, var(--topic-color, #f54291) 28%, rgba(17, 34, 45, 0.08));
  border-radius: 999px;
  background: color-mix(in srgb, var(--topic-color, #f54291) 10%, rgba(255, 255, 255, 0.86));
  color: #152033;
  font-weight: 780;
  text-decoration: none;
  box-shadow: 0 12px 28px color-mix(in srgb, var(--topic-color, #f54291) 12%, transparent);
}

.landing-topic-chip__dot {
  flex: 0 0 auto;
  width: 10px;
  height: 10px;
  border-radius: 999px;
  background: var(--topic-color, #f54291);
}

.landing-topic-chip span:last-child {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.landing-section--features h2 {
  max-width: 820px;
  color: #192443;
  font-size: clamp(34px, 4vw, 44px);
  font-weight: 900;
}

.landing-section--features h2 span {
  color: #7b3ff2;
  background: linear-gradient(90deg, #7b3ff2 0%, #e24d9a 48%, #ff7426 100%);
  background-clip: text;
  -webkit-text-fill-color: transparent;
}

.feature-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 28px;
  max-width: 1192px;
  margin: 0 auto;
}

.feature-card,
.step-item,
.pricing-card {
  border: 1px solid rgba(123, 63, 242, 0.12);
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 14px 34px rgba(64, 28, 145, 0.07);
}

.feature-card {
  display: grid;
  align-content: start;
  min-height: 318px;
  padding: 38px 34px 34px;
  border-color: rgba(64, 28, 145, 0.08);
  box-shadow: 0 22px 48px rgba(21, 31, 59, 0.1);
}

.feature-card__icon {
  display: block;
  width: 90px;
  height: 90px;
}

.feature-card__icon picture,
.audience-benefit__icon picture,
.step-item__icon picture {
  display: block;
  width: 100%;
  height: 100%;
}

.landing-image-icon {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
  filter: drop-shadow(0 12px 18px rgba(64, 28, 145, 0.16));
}

.feature-card h3,
.step-item h3 {
  margin: 24px 0 10px;
  color: var(--soz-ink);
  font-size: 22px;
  line-height: 1.22;
}

.feature-card h3 {
  max-width: 260px;
  font-weight: 850;
}

.feature-card p,
.step-item p,
.workflow-section p,
.pricing-card p {
  margin: 0;
  color: rgba(21, 31, 59, 0.68);
  line-height: 1.62;
}

.feature-card p {
  max-width: 320px;
  font-size: 15px;
}

.content-section {
  padding-top: 70px;
  padding-bottom: 62px;
}

.content-section__head {
  display: grid;
  gap: 10px;
  max-width: none;
  margin-bottom: 28px;
}

.content-section h2 {
  max-width: none;
  color: #24145d;
  font-size: clamp(34px, 4vw, 48px);
}

.content-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 28px 42px;
}

.content-block {
  padding: 0 0 26px;
}

.content-block h3 {
  margin: 0 0 12px;
  color: #192443;
  font-size: 24px;
  font-weight: 850;
  line-height: 1.24;
}

.content-block p {
  max-width: 560px;
  margin: 0;
  color: rgba(21, 31, 59, 0.72);
  font-size: 17px;
  line-height: 1.76;
}

.audience-section {
  display: grid;
  gap: 10px;
  padding-top: 64px;
  padding-bottom: 18px;
}

.audience-panel {
  display: grid;
  grid-template-columns: minmax(0, 0.78fr) minmax(0, 1.22fr);
  gap: clamp(28px, 5vw, 64px);
  align-items: start;
  padding: 34px 0;
}

.audience-panel__copy {
  display: grid;
  gap: 12px;
  max-width: 430px;
}

.audience-panel__copy h2 {
  color: #24145d;
  font-size: clamp(32px, 3.5vw, 46px);
}

.audience-panel__copy p {
  margin: 0;
  color: rgba(21, 31, 59, 0.72);
  font-size: 17px;
  line-height: 1.72;
}

.audience-panel__copy .audience-panel__free-note {
  padding-inline-start: 13px;
  border-inline-start: 3px solid #f54291;
  color: #5f2ac7;
  font-weight: 800;
  line-height: 1.55;
}

.audience-panel__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  margin-top: 4px;
}

.audience-panel__link.q-btn.bg-primary {
  background: var(--soz-menu-gradient) !important;
  box-shadow: 0 12px 24px rgba(123, 63, 242, 0.22) !important;
  color: #ffffff !important;
  font-weight: 850;
}

.audience-panel__button-content {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  justify-content: center;
}

.audience-panel__button-svg {
  width: 20px;
  height: 20px;
  fill: none;
  stroke: currentColor;
  stroke-linecap: round;
  stroke-linejoin: round;
  stroke-width: 2.2;
}

.landing-page--rtl .audience-panel__button-svg--more {
  transform: scaleX(-1);
}

.audience-panel__link--secondary.q-btn.bg-primary {
  background: rgba(255, 255, 255, 0.76) !important;
  color: #5b2fd6 !important;
  box-shadow: 0 10px 22px rgba(64, 28, 145, 0.1) !important;
}

.audience-benefit-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.audience-benefit {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 14px;
  align-items: start;
  min-height: 142px;
  padding: 18px;
  border: 1px solid rgba(64, 28, 145, 0.08);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.56);
  box-shadow: 0 16px 34px rgba(21, 31, 59, 0.06);
}

.audience-benefit__icon {
  display: block;
  width: 66px;
  height: 66px;
}

.audience-benefit h3 {
  margin: 0 0 8px;
  color: #192443;
  font-size: 19px;
  font-weight: 850;
  line-height: 1.25;
}

.audience-benefit p {
  margin: 0;
  color: rgba(21, 31, 59, 0.68);
  font-size: 14px;
  line-height: 1.58;
}

.workflow-section {
  position: relative;
  display: grid;
  grid-template-columns: minmax(0, 0.82fr) minmax(520px, 1.18fr);
  gap: clamp(36px, 6vw, 86px);
  align-items: center;
  padding-top: 72px;
  padding-bottom: 66px;
  overflow: hidden;
}

.workflow-copy {
  display: grid;
  align-content: center;
  gap: 14px;
  min-height: 390px;
}

.workflow-copy p {
  max-width: 360px;
  font-size: 18px;
  font-weight: 600;
}

.workflow-copy h2 {
  max-width: 380px;
  color: #2a176b;
  font-size: clamp(40px, 4vw, 50px);
  font-weight: 900;
}

.workflow-art {
  display: block;
  width: min(100%, 420px);
  aspect-ratio: 16 / 9;
  margin-top: 18px;
  border-radius: 8px;
  filter: saturate(1.04);
}

.workflow-art img {
  width: 100%;
  height: auto;
  object-fit: contain;
  object-position: center;
}

.step-list {
  display: grid;
  gap: 18px;
}

.step-item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 22px;
  align-items: center;
  min-height: 124px;
  padding: 22px 28px;
  border-color: rgba(64, 28, 145, 0.08);
  box-shadow: 0 18px 40px rgba(21, 31, 59, 0.09);
}

.step-item__icon {
  display: block;
  width: 76px;
  height: 76px;
}

.step-item__copy h3 {
  margin: 0 0 6px;
  color: #2a176b;
  font-size: 22px;
  font-weight: 850;
}

.step-item__copy p {
  max-width: 470px;
  font-size: 15px;
  line-height: 1.45;
}

.pricing-head {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
  margin-bottom: 28px;
  text-align: center;
}

.pricing-head .section-kicker {
  display: inline-flex;
  gap: 8px;
  align-items: center;
}

.pricing-head h2 {
  position: relative;
  font-size: 38px;
}

.pricing-head h2 span {
  position: relative;
  color: #ff4f5f;
}

.pricing-head h2 span::after {
  position: absolute;
  right: 0;
  bottom: -10px;
  left: 0;
  height: 12px;
  background: url("data:image/svg+xml,%3Csvg width='178' height='21' viewBox='0 0 178 21' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M3 13C38 4 116 1 175 18' stroke='%23E846A2' stroke-width='4' stroke-linecap='round'/%3E%3Cpath d='M74 16C91 11 107 10 125 12' stroke='%23E846A2' stroke-width='3' stroke-linecap='round'/%3E%3C/svg%3E") center / contain no-repeat;
  content: "";
}

.pricing-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 28px;
}

.pricing-card {
  position: relative;
  display: grid;
  grid-template-rows: auto 1fr auto;
  gap: 16px;
  min-height: 390px;
  overflow: hidden;
  padding: 28px 32px 26px;
  box-shadow: 0 18px 42px rgba(64, 28, 145, 0.1);
}

.pricing-card--private {
  border-color: rgba(123, 63, 242, 0.2);
  background:
    radial-gradient(circle at 76% 44%, rgba(123, 63, 242, 0.08), transparent 28%),
    #ffffff;
}

.pricing-card--featured {
  border-color: rgba(255, 116, 38, 0.24);
  background:
    radial-gradient(circle at 86% 12%, rgba(255, 79, 152, 0.08), transparent 30%),
    #ffffff;
  box-shadow: 0 24px 56px rgba(245, 66, 145, 0.14);
}

.pricing-card__top {
  position: relative;
  z-index: 1;
  display: block;
}

.pricing-card__intro {
  max-width: 340px;
}

.pricing-card__name {
  color: #24145d;
  font-size: 22px;
  font-weight: 800;
  line-height: 1.2;
}

.pricing-card__main {
  position: relative;
  z-index: 1;
  display: flex;
  gap: 18px;
  align-items: center;
  min-height: 220px;
  padding-top: 8px;
  direction: ltr;
}

.pricing-card__content {
  order: 1;
  display: grid;
  flex: 1 1 56%;
  align-content: start;
  gap: 18px;
  min-width: 0;
  direction: ltr;
}

.landing-page--rtl .pricing-card__content {
  order: 2;
  direction: rtl;
  text-align: right;
}

.pricing-card__price {
  display: flex;
  align-items: baseline;
  direction: ltr;
  gap: 8px;
}

.landing-page--rtl .pricing-card__price {
  justify-content: flex-end;
}

.landing-page--rtl .pricing-card__currency {
  order: -1;
}

.pricing-card__currency {
  color: #24304f;
  font-size: 40px;
  font-weight: 800;
}

.pricing-card__price strong {
  color: #1c2443;
  font-size: 66px;
  line-height: 0.95;
}

.pricing-card__price span:last-child {
  color: #24304f;
  font-size: 14px;
  font-weight: 700;
}

.pricing-card ul {
  display: grid;
  gap: 10px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.pricing-card li {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 10px;
  align-items: start;
  min-height: 24px;
  color: #24304f;
  font-size: 14px;
  font-weight: 700;
}

.pricing-card--private li .q-icon {
  color: #7b3ff2;
}

.pricing-card--business li .q-icon {
  color: #ff7426;
}

.pricing-card__art-wrap {
  order: 2;
  flex: 0 1 44%;
  justify-self: center;
  width: min(100%, 220px);
  max-height: 230px;
  pointer-events: none;
  user-select: none;
}

.pricing-card__art {
  width: 100%;
  height: auto;
  object-fit: contain;
}

.landing-page--rtl .pricing-card__art-wrap {
  order: 1;
}

.pricing-card--business .pricing-card__art-wrap {
  align-self: flex-end;
  width: min(100%, 280px);
  max-height: 260px;
  transform: translateY(20px);
}

.pricing-card__button {
  position: relative;
  z-index: 1;
  width: 100%;
  min-height: 54px;
  margin-top: 4px;
  font-size: 16px;
  font-weight: 800;
  text-transform: none;
}

.pricing-card--private .pricing-card__button {
  color: #ffffff;
  background: linear-gradient(135deg, #7b3ff2 0%, #8d4dff 100%);
}

.pricing-card--business .pricing-card__button {
  color: #ffffff;
  background: linear-gradient(135deg, #ff7426 0%, #f54291 100%);
}

@media (max-width: 980px) {
  .landing-hero__inner,
  .workflow-section,
  .audience-panel,
  .content-grid,
  .pricing-grid {
    grid-template-columns: 1fr;
  }

  .landing-hero__copy {
    max-width: 760px;
  }

  .feature-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    max-width: 780px;
  }

  .workflow-section {
    gap: 30px;
  }

  .audience-panel__copy {
    max-width: 720px;
  }

  .landing-purpose {
    grid-template-columns: 1fr;
    align-items: start;
  }

  .landing-share-section__inner {
    grid-template-columns: 1fr;
  }

  .workflow-copy {
    min-height: auto;
  }
}

@media (max-width: 640px) {
  .landing-hero__inner {
    --landing-mobile-gutter: clamp(24px, 7vw, 30px);

    min-height: auto;
    padding: 24px 0 30px;
    overflow: hidden;
  }

  .landing-hero__copy {
    max-width: none;
  }

  .landing-hero h1 {
    width: auto;
    max-width: 390px;
    margin-inline: var(--landing-mobile-gutter);
  }

  .landing-hero p {
    max-width: calc(100% - (var(--landing-mobile-gutter) * 2));
    margin: 24px var(--landing-mobile-gutter) 0;
    font-size: 17px;
    line-height: 1.68;
  }

  .landing-hero__subtitle {
    overflow: hidden;
  }

  .landing-hero__subtitle-shape {
    display: block;
    float: right;
    width: min(38%, 150px);
    height: 136px;
    margin-top: 74px;
    margin-left: 12px;
    shape-margin: 8px;
    shape-outside: polygon(100% 0, 100% 100%, 8% 100%, 38% 72%, 68% 42%, 88% 16%);
  }

  .landing-hero__visual {
    display: none;
  }

  .landing-hero__mobile-visual {
    position: relative;
    z-index: 1;
    display: block;
    width: 100%;
    max-width: 520px;
    margin: -186px 0 0;
    pointer-events: none;
    user-select: none;
  }

  .landing-page--rtl .landing-hero__mobile-visual {
    margin-right: 0;
    margin-left: 0;
  }

  .landing-section {
    padding: 38px 16px 0;
  }

  .landing-catalog-section {
    padding-bottom: 30px;
  }

  .landing-purpose-section {
    padding-top: 36px;
  }

  .landing-purpose {
    gap: 20px;
    padding-bottom: 34px;
  }

  .landing-purpose h2 {
    font-size: 28px;
  }

  .landing-purpose p {
    font-size: 16px;
    line-height: 1.68;
  }

  .landing-section--features {
    padding: 42px 16px 50px;
  }

  .landing-section--features h2 {
    font-size: 32px;
  }

  .feature-grid {
    grid-template-columns: 1fr;
    max-width: 520px;
  }

  .feature-card,
  .step-item,
  .pricing-card {
    padding: 20px;
  }

  .feature-card {
    min-height: 248px;
    padding: 28px 24px;
  }

  .feature-card__icon {
    width: 68px;
    height: 68px;
  }

  .workflow-section {
    padding-top: 46px;
    padding-bottom: 46px;
  }

  .audience-section {
    padding-top: 44px;
  }

  .audience-panel {
    gap: 22px;
    padding: 28px 0;
  }

  .audience-panel__copy h2 {
    font-size: 30px;
  }

  .audience-panel__copy p {
    font-size: 16px;
    line-height: 1.66;
  }

  .audience-benefit-grid {
    grid-template-columns: 1fr;
  }

  .audience-benefit {
    min-height: 0;
    padding: 16px;
  }

  .audience-benefit__icon {
    width: 48px;
    height: 48px;
  }

  .workflow-art {
    width: 100%;
  }

  .step-item {
    grid-template-columns: auto minmax(0, 1fr);
    gap: 14px 16px;
  }

  .step-item__icon {
    grid-column: 1;
    grid-row: 1;
    width: 54px;
    height: 54px;
  }

  .step-item__copy {
    grid-column: 2;
    grid-row: 1;
  }

  .pricing-card__content {
    width: 100%;
  }

  .pricing-card__art-wrap,
  .pricing-card--business .pricing-card__art-wrap {
    display: none;
  }

  .pricing-card__price strong {
    font-size: 54px;
  }

  .landing-hero__actions {
    align-items: stretch;
    position: relative;
    z-index: 2;
    gap: 14px;
    margin: 0 var(--landing-mobile-gutter);
    padding: 0;
  }

  .landing-first-badge {
    width: auto;
    gap: 3px;
    margin: -20px var(--landing-mobile-gutter) 16px;
    padding: 11px 15px;
    border-radius: 24px;
  }

  .landing-first-badge strong {
    font-size: 18px;
  }

  .landing-first-badge__copy > span {
    font-size: 13px;
  }

  .landing-share-section__inner {
    gap: 24px;
    padding: 32px 16px;
  }

  .landing-share-section h2 {
    font-size: 28px;
  }

  .landing-share-section p {
    font-size: 16px;
  }

  .landing-hero__actions .q-btn {
    width: 100%;
    min-width: 0;
    min-height: 58px;
    font-size: 14px;
  }

  .landing-hero__actions .q-btn :deep(.q-btn__content) {
    min-width: 0;
  }

  .landing-hero__actions .q-btn :deep(.block) {
    min-width: 0;
    white-space: normal;
  }
}
</style>
