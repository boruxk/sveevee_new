<script setup>
	import { computed } from 'vue'
	import { useRoute } from 'vue-router'
	import { useI18n } from 'vue-i18n'
	import { useAppStore } from '@/stores/app'
	import { landingIconAvifSrcset, landingIconImage, landingIconWebpSrcset } from '@/constants/landingFeatureIcons'

	const route = useRoute()
	const { t, tm } = useI18n()
	const appStore = useAppStore()

	const promoType = computed(() => (route.meta.promoType === 'community' ? 'community' : 'business'))
	const copyBase = computed(() => `promoLanding.${promoType.value}`)
	const heroImages = {
		business: {
			base: 'promo-business-hero'
		},
		community: {
			base: 'promo-community-hero'
		}
	}
	const heroBase = computed(() => heroImages[promoType.value].base)
	const heroRtlSuffix = computed(() => (appStore.isRtl ? '-rtl' : ''))
	const transparentPixel = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='
	const heroAvifSrcSet = computed(() => [
		`/assets/landing/${heroBase.value}-960${heroRtlSuffix.value}.v3.avif 960w`,
		`/assets/landing/${heroBase.value}-1360${heroRtlSuffix.value}.v3.avif 1360w`
	].join(', '))
	const heroWebpSrcSet = computed(() => [
		`/assets/landing/${heroBase.value}-960${heroRtlSuffix.value}.v3.webp 960w`,
		`/assets/landing/${heroBase.value}-1360${heroRtlSuffix.value}.v3.webp 1360w`
	].join(', '))
	const mobileHeroSizes = '(max-width: 520px) 100vw, 520px'
	const mobileHeroAvifSrcSet = computed(() => [
		`/assets/landing/${heroBase.value}-mobile-480${heroRtlSuffix.value}.v3.avif 480w`,
		`/assets/landing/${heroBase.value}-mobile-640${heroRtlSuffix.value}.v3.avif 640w`,
		`/assets/landing/${heroBase.value}-mobile-720${heroRtlSuffix.value}.v3.avif 720w`,
		`/assets/landing/${heroBase.value}-mobile-800${heroRtlSuffix.value}.v3.avif 800w`
	].join(', '))
	const mobileHeroWebpSrcSet = computed(() => [
		`/assets/landing/${heroBase.value}-mobile-480${heroRtlSuffix.value}.v3.webp 480w`,
		`/assets/landing/${heroBase.value}-mobile-640${heroRtlSuffix.value}.v3.webp 640w`,
		`/assets/landing/${heroBase.value}-mobile-720${heroRtlSuffix.value}.v3.webp 720w`,
		`/assets/landing/${heroBase.value}-mobile-800${heroRtlSuffix.value}.v3.webp 800w`
	].join(', '))
	const primaryRoute = computed(() => ({ name: 'register' }))
	const secondaryRoute = computed(() => ({
		name: promoType.value === 'community' ? 'community-example-page' : 'business-example-page'
	}))

	function copyKey(key) {
		return `${copyBase.value}.${key}`
	}

	function listMessage(key) {
		const value = tm(copyKey(key))
		return Array.isArray(value) ? value : []
	}

	const functions = computed(() => listMessage('functions'))
	const benefits = computed(() => listMessage('benefits'))
</script>

<template>
	<q-page class="promo-page" :class="[`promo-page--${promoType}`, { 'promo-page--rtl': appStore.isRtl }]">
		<section class="promo-hero">
			<div class="promo-hero__inner">
				<div class="promo-hero__copy">
					<q-chip dense color="white" text-color="primary" class="promo-hero__chip">
						{{ t('promoLanding.freeBadge') }}
					</q-chip>
					<p class="promo-hero__eyebrow">{{ t(copyKey('eyebrow')) }}</p>
					<h1>{{ t(copyKey('title')) }}</h1>
					<p class="promo-hero__subtitle">
						<span class="promo-hero__subtitle-shape" aria-hidden="true"></span>
						{{ t(copyKey('subtitle')) }}
					</p>

					<div class="promo-hero__mobile-visual">
						<picture>
							<source media="(max-width: 640px)" :srcset="mobileHeroAvifSrcSet" :sizes="mobileHeroSizes" type="image/avif" />
							<source media="(max-width: 640px)" :srcset="mobileHeroWebpSrcSet" :sizes="mobileHeroSizes" type="image/webp" />
							<img
								:src="transparentPixel"
								:alt="t(copyKey('title'))"
								width="800"
								height="800"
								loading="eager"
								fetchpriority="high"
								decoding="async"
							/>
						</picture>
					</div>
					<div class="promo-hero__actions">
						<q-btn
							color="primary"
							unelevated
							rounded
							icon="person_add"
							class="promo-register-cta"
							:label="t('promoLanding.registerToStart')"
							:to="primaryRoute"
						/>
						<q-btn
							unelevated
							rounded
							color="primary"
							class="promo-hero__secondary"
							icon="visibility"
							:label="t('promoLanding.examplePageCta')"
							:to="secondaryRoute"
						/>
					</div>
				</div>

				<picture class="promo-hero__visual">
					<source media="(min-width: 641px)" :srcset="heroAvifSrcSet" sizes="90vw" type="image/avif" />
					<source media="(min-width: 641px)" :srcset="heroWebpSrcSet" sizes="90vw" type="image/webp" />
					<img
						:src="transparentPixel"
						:alt="t(copyKey('title'))"
						width="1360"
						height="765"
						loading="eager"
						fetchpriority="high"
						decoding="async"
					/>
				</picture>
			</div>
		</section>

		<section class="promo-section promo-description">
			<div class="promo-description__copy">
				<div class="section-kicker">{{ t(copyKey('descriptionKicker')) }}</div>
				<h2>{{ t(copyKey('descriptionTitle')) }}</h2>
				<p>{{ t(copyKey('descriptionBody')) }}</p>
			</div>
			<div class="promo-description__free">
				<strong>{{ t('promoLanding.freeTitle') }}</strong>
				<span>{{ t(copyKey('freeBody')) }}</span>
			</div>
		</section>

		<section class="promo-section promo-benefits">
			<div class="promo-section__head">
				<div class="section-kicker">{{ t('promoLanding.benefitsKicker') }}</div>
				<h2>{{ t(copyKey('benefitsTitle')) }}</h2>
			</div>

			<div class="promo-benefit-list">
				<article v-for="item in benefits" :key="item.title" class="promo-benefit">
					<q-icon name="task_alt" size="24px" />
					<div>
						<h3>{{ item.title }}</h3>
						<p>{{ item.body }}</p>
					</div>
				</article>
			</div>
		</section>

		<section class="promo-section">
			<div class="promo-section__head">
				<div class="section-kicker">{{ t('promoLanding.functionsKicker') }}</div>
				<h2>{{ t(copyKey('functionsTitle')) }}</h2>
			</div>

			<div class="promo-function-grid">
				<article v-for="(item, index) in functions" :key="item.title" class="promo-function">
					<span class="promo-function__marker">{{ String(index + 1).padStart(2, '0') }}</span>
					<header class="promo-function__head">
						<span class="promo-function__icon" aria-hidden="true">
							<picture>
								<source :srcset="landingIconAvifSrcset(item.icon)" sizes="72px" type="image/avif" />
								<source :srcset="landingIconWebpSrcset(item.icon)" sizes="72px" type="image/webp" />
								<img
									class="promo-function__image"
									:src="landingIconImage(item.icon)"
									alt=""
									width="320"
									height="320"
									loading="lazy"
									decoding="async"
								/>
							</picture>
						</span>
						<h3>{{ item.title }}</h3>
					</header>
					<p v-if="item.body">{{ item.body }}</p>
					<ul v-if="item.items?.length">
						<li v-for="point in item.items" :key="point">
							<q-icon name="check_circle" size="18px" />
							<span>{{ point }}</span>
						</li>
					</ul>
				</article>
			</div>
		</section>

		<section class="promo-section promo-cta">
			<div>
				<div class="section-kicker">{{ t('promoLanding.freeBadge') }}</div>
				<h2>{{ t(copyKey('ctaTitle')) }}</h2>
				<p>{{ t(copyKey('ctaBody')) }}</p>
			</div>
			<q-btn
				color="primary"
				unelevated
				rounded
				icon="person_add"
				class="promo-register-cta"
				:label="t('promoLanding.registerToStart')"
				:to="primaryRoute"
			/>
		</section>
	</q-page>
</template>

<style scoped lang="scss">
.promo-page {
  min-height: 100vh;
  padding-bottom: 72px;
  background: transparent;
}

.promo-hero {
  position: relative;
  overflow: hidden;
  background: transparent;
}

.promo-hero__inner {
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

.promo-hero__copy {
  position: relative;
  z-index: 2;
  display: grid;
  gap: 16px;
  grid-column: 1 / 2;
  max-width: 620px;
}

.promo-hero__chip {
  width: max-content;
  padding-inline: 0;
  background: transparent !important;
  box-shadow: none !important;
  font-weight: 850;
}

.promo-hero__eyebrow,
.section-kicker {
  margin: 0;
  color: #7f1239;
  font-size: 13px;
  font-weight: 850;
  letter-spacing: 0;
  text-transform: uppercase;
}

.promo-hero h1 {
  margin: 0;
  color: var(--soz-ink);
  font-size: clamp(40px, 5.5vw, 72px);
  font-weight: 920;
  line-height: 1.03;
  letter-spacing: 0;
}

.promo-hero__subtitle {
  max-width: 620px;
  margin: 0;
  color: rgba(21, 31, 59, 0.76);
  font-size: clamp(18px, 2vw, 22px);
  font-weight: 650;
  line-height: 1.65;
}

.promo-hero__subtitle-shape {
  display: none;
}

.promo-hero__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin-top: 8px;
}

.promo-hero__secondary.q-btn.bg-primary {
  background: var(--soz-menu-gradient) !important;
  box-shadow: 0 12px 24px rgba(123, 63, 242, 0.24) !important;
  color: #ffffff !important;
}

.promo-register-cta.q-btn.bg-primary {
  min-height: 60px;
  padding-inline: 24px;
  font-size: 1.04rem;
  font-weight: 900;
  animation: promoCtaPulse 2.15s ease-in-out infinite;
  box-shadow: 0 16px 34px rgba(245, 66, 145, 0.28) !important;
}

@keyframes promoCtaPulse {
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

.promo-hero__visual {
  position: absolute;
  z-index: 1;
  top: 50%;
  right: 0;
  display: block;
  width: 90%;
  transform: translateY(-50%);
  pointer-events: none;
  user-select: none;
  -webkit-mask-image: linear-gradient(to right, transparent 0%, transparent 14%, rgba(0, 0, 0, 0.42) 34%, #000000 62%, #000000 100%);
  mask-image: linear-gradient(to right, transparent 0%, transparent 14%, rgba(0, 0, 0, 0.42) 34%, #000000 62%, #000000 100%);
}

.promo-hero__visual,
.promo-hero__visual img {
  display: block;
}

.promo-hero__visual img {
  width: 100%;
  height: auto;
  object-fit: contain;
  object-position: center;
}

.promo-hero__mobile-visual {
  display: none;
}

.promo-hero__mobile-visual picture,
.promo-hero__mobile-visual img {
  display: block;
  width: 100%;
  height: auto;
}

.promo-page--rtl .promo-hero__visual {
  right: auto;
  left: 0;
  -webkit-mask-image: linear-gradient(to left, transparent 0%, transparent 14%, rgba(0, 0, 0, 0.42) 34%, #000000 62%, #000000 100%);
  mask-image: linear-gradient(to left, transparent 0%, transparent 14%, rgba(0, 0, 0, 0.42) 34%, #000000 62%, #000000 100%);
}

.promo-section {
  max-width: 1280px;
  margin: 0 auto;
  padding: 66px 24px 0;
}

.promo-section__head {
  display: grid;
  gap: 10px;
  max-width: 780px;
  margin-bottom: 26px;
}

.promo-section h2 {
  margin: 0;
  color: #24145d;
  font-size: clamp(31px, 4vw, 50px);
  font-weight: 900;
  line-height: 1.12;
}

.promo-description {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(280px, 0.38fr);
  gap: clamp(28px, 5vw, 64px);
  align-items: center;
  padding-top: 54px;
  padding-bottom: 48px;
  border-bottom: 1px solid rgba(123, 63, 242, 0.12);
}

.promo-description__copy {
  display: grid;
  gap: 12px;
}

.promo-description__copy p,
.promo-cta p {
  max-width: 800px;
  margin: 0;
  color: rgba(21, 31, 59, 0.72);
  font-size: 17px;
  line-height: 1.74;
}

.promo-description__free {
  display: grid;
  gap: 8px;
  padding: 24px;
  border: 1px solid rgba(255, 116, 38, 0.18);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.62);
  box-shadow: 0 18px 38px rgba(255, 116, 38, 0.08);
}

.promo-description__free strong {
  color: #7f1239;
  font-size: 24px;
  line-height: 1.16;
}

.promo-description__free span {
  color: rgba(21, 31, 59, 0.72);
  font-size: 15px;
  line-height: 1.6;
}

.promo-function-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 18px;
  counter-reset: promoFunctions;
}

.promo-function {
  position: relative;
  display: grid;
  gap: 14px;
  align-content: start;
  min-height: 286px;
  padding: 26px 24px 24px;
  border: 1px solid rgba(64, 28, 145, 0.08);
  border-radius: 8px;
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.7)),
    radial-gradient(circle at 100% 0%, rgba(255, 116, 38, 0.13), transparent 38%);
  box-shadow: 0 18px 42px rgba(21, 31, 59, 0.075);
}

.promo-page--community .promo-function {
  background:
    linear-gradient(180deg, rgba(255, 255, 255, 0.9), rgba(255, 255, 255, 0.7)),
    radial-gradient(circle at 100% 0%, rgba(40, 199, 183, 0.13), transparent 38%);
}

.promo-function__marker {
  position: absolute;
  top: 18px;
  right: 20px;
  color: rgba(36, 20, 93, 0.14);
  font-size: 34px;
  font-weight: 900;
  line-height: 1;
}

.promo-page--rtl .promo-function__marker {
  right: auto;
  left: 20px;
}

.promo-function__head {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 14px;
  align-items: center;
  padding-inline-end: 44px;
}

.promo-page--rtl .promo-function__head {
  padding-inline: 44px 0;
}

.promo-function__icon {
  display: block;
  width: 72px;
  height: 72px;
}

.promo-function__icon picture {
  display: block;
  width: 100%;
  height: 100%;
}

.promo-function__image {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: contain;
  filter: drop-shadow(0 12px 18px rgba(64, 28, 145, 0.15));
}

.promo-function h3,
.promo-benefit h3 {
  margin: 0;
  color: #192443;
  font-size: 20px;
  font-weight: 850;
  line-height: 1.25;
}

.promo-function p,
.promo-benefit p {
  margin: 0;
  color: rgba(21, 31, 59, 0.68);
  font-size: 15px;
  line-height: 1.62;
}

.promo-function ul {
  display: grid;
  gap: 8px;
  margin: 2px 0 0;
  padding: 0;
  list-style: none;
}

.promo-function li {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 8px;
  align-items: start;
  color: rgba(21, 31, 59, 0.78);
  font-size: 14px;
  font-weight: 720;
  line-height: 1.45;
}

.promo-function li .q-icon {
  margin-top: 1px;
  color: #ff7426;
}

.promo-page--community .promo-function li .q-icon {
  color: #7b3ff2;
}

.promo-benefits {
  padding-bottom: 24px;
}

.promo-benefit-list {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px 24px;
}

.promo-benefit {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 12px;
  align-items: start;
  padding: 0 0 18px;
  border-bottom: 1px solid rgba(123, 63, 242, 0.13);
}

.promo-benefit .q-icon {
  margin-top: 2px;
  color: #7b3ff2;
}

.promo-page--business .promo-benefit .q-icon {
  color: #ff7426;
}

.promo-cta {
  display: flex;
  gap: 24px;
  align-items: center;
  justify-content: space-between;
  margin-top: 40px;
  padding-top: 42px;
  padding-bottom: 42px;
  border-top: 1px solid rgba(123, 63, 242, 0.12);
  border-bottom: 1px solid rgba(123, 63, 242, 0.12);
}

.promo-cta > div {
  display: grid;
  gap: 10px;
}

.promo-cta .q-btn {
  flex: 0 0 auto;
  min-width: 220px;
  min-height: 54px;
  font-weight: 850;
}

@media (max-width: 980px) {
  .promo-hero__inner {
    grid-template-columns: 1fr;
    gap: 24px;
    min-height: 0;
  }

  .promo-hero__copy {
    max-width: 100%;
  }

  .promo-description,
  .promo-benefit-list {
    grid-template-columns: 1fr;
  }

  .promo-function-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .promo-function {
    min-height: 0;
  }

  .promo-cta {
    align-items: stretch;
    flex-direction: column;
  }

  .promo-cta .q-btn {
    width: 100%;
  }
}

@media (max-width: 640px) {
  .promo-page {
    padding-bottom: 48px;
  }

  .promo-hero__inner {
    --promo-mobile-gutter: clamp(24px, 7vw, 30px);

    min-height: auto;
    padding: 24px 0 30px;
    overflow: hidden;
  }

  .promo-hero__copy {
    max-width: none;
  }

  .promo-hero__chip,
  .promo-hero__eyebrow {
    margin-inline: var(--promo-mobile-gutter);
  }

  .promo-hero h1 {
    max-width: 390px;
    margin-inline: var(--promo-mobile-gutter);
    font-size: 38px;
  }

  .promo-hero__subtitle {
    max-width: calc(100% - (var(--promo-mobile-gutter) * 2));
    margin: 0 var(--promo-mobile-gutter);
    font-size: 17px;
    line-height: 1.62;
    overflow: hidden;
  }

  .promo-hero__subtitle-shape {
    display: block;
    float: right;
    width: min(38%, 150px);
    height: 136px;
    margin-top: 74px;
    margin-left: 12px;
    shape-margin: 8px;
    shape-outside: polygon(100% 0, 100% 100%, 8% 100%, 38% 72%, 68% 42%, 88% 16%);
  }

  .promo-hero__visual {
    display: none;
  }

  .promo-hero__mobile-visual {
    position: relative;
    z-index: 1;
    display: block;
    width: 100%;
    max-width: 520px;
    margin: -176px 0 0;
    pointer-events: none;
    user-select: none;
  }

  .promo-hero__actions {
    align-items: stretch;
    position: relative;
    z-index: 2;
    flex-direction: column;
    gap: 14px;
    margin: -14px var(--promo-mobile-gutter) 0;
    padding: 0;
  }

  .promo-hero__actions .q-btn {
    width: 100%;
    min-height: 56px;
  }

  .promo-section {
    padding: 44px 16px 0;
  }

  .promo-function-grid {
    grid-template-columns: 1fr;
  }

  .promo-description {
    padding-top: 38px;
    padding-bottom: 38px;
  }

  .promo-description__free,
  .promo-function {
    padding: 20px;
  }

  .promo-section h2 {
    font-size: 30px;
  }

  .promo-cta {
    margin-top: 28px;
    padding-top: 34px;
    padding-bottom: 34px;
  }
}
</style>
