<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { useAppStore } from '@/stores/app'
	import heroSrc from '@/assets/hero-neighborhood.png'
	import pricingBusinessSrc from '@/assets/pricing-business.png'
	import pricingPrivateSrc from '@/assets/pricing-private.png'

	const { t, tm } = useI18n()
	const appStore = useAppStore()

	function listMessage(key) {
		const value = tm(key)
		return Array.isArray(value) ? value : []
	}

	const featureCards = computed(() => listMessage('landing.features'))
	const steps = computed(() => listMessage('landing.steps'))
	const plans = computed(() => listMessage('landing.plans'))
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

	function planTone(plan) {
		return plan.featured ? 'business' : 'private'
	}

	function planIcon(plan) {
		return plan.featured ? 'storefront' : 'person'
	}
</script>

<template>
	<q-page class="landing-page" :class="{ 'landing-page--rtl': appStore.isRtl }">
		<section class="landing-hero">
			<div class="landing-hero__inner">
				<div class="landing-hero__copy">
					<q-chip dense color="white" text-color="primary" class="landing-kicker-chip">
						{{ t('landing.eyebrow') }}
					</q-chip>

					<h1>{{ t('landing.title') }}</h1>
					<p>{{ t('landing.subtitle') }}</p>

					<div class="landing-hero__actions">
						<q-btn color="primary"
							unelevated
							rounded
							icon="person_add"
							:label="t('nav.register')"
							:to="{ name: 'register' }"
						/>
						<q-btn outline
							rounded
							color="dark"
							icon="search"
							:label="t('nav.search')"
							:to="{ name: 'search' }"
						/>
					</div>
				</div>

				<div class="landing-hero__visual">
					<img :src="heroSrc" alt="" />
				</div>
			</div>
		</section>

		<section class="landing-section landing-section--features">
			<div class="landing-section__head">
				<div class="section-kicker">{{ t('landing.featureKicker') }}</div>
				<h2>{{ t('landing.featureTitle') }}</h2>
			</div>

			<div class="feature-grid">
				<article v-for="item in featureCards" :key="item.title" class="feature-card">
					<span class="feature-card__icon">
						<q-icon :name="item.icon" size="28px" color="primary" />
					</span>
					<h3>{{ item.title }}</h3>
					<p>{{ item.body }}</p>
				</article>
			</div>
		</section>

		<section class="landing-section workflow-section">
			<div class="workflow-copy">
				<div class="section-kicker">{{ t('landing.workflowKicker') }}</div>
				<h2>{{ t('landing.workflowTitle') }}</h2>
				<p>{{ t('landing.workflowBody') }}</p>
			</div>

			<div class="step-list">
				<article v-for="(item, index) in steps" :key="item.title" class="step-item">
					<span class="step-item__number">{{ index + 1 }}</span>
					<div>
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
						<span class="pricing-card__icon">
							<q-icon :name="planIcon(plan)" size="28px" />
						</span>
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

						<img class="pricing-card__art" :src="planImage(plan)" alt="" />
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
	</q-page>
</template>

<style scoped lang="scss">
.landing-page {
  min-height: 100vh;
  padding-bottom: 72px;
  background: #fff8fb;
}

.landing-hero {
  background:
    radial-gradient(circle at 86% 10%, rgba(123, 63, 242, 0.12), transparent 26%),
    linear-gradient(180deg, #ffffff 0%, #fff8fb 100%);
  border-bottom: 1px solid rgba(123, 63, 242, 0.08);
}

.landing-hero__inner {
  display: grid;
  grid-template-columns: minmax(0, 0.88fr) minmax(420px, 1.12fr);
  gap: clamp(28px, 5vw, 72px);
  align-items: center;
  max-width: 1240px;
  margin: 0 auto;
  padding: clamp(42px, 7vw, 84px) 24px clamp(34px, 6vw, 72px);
}

.landing-hero__copy {
  max-width: 600px;
}

.landing-kicker-chip {
  margin-bottom: 20px;
  font-weight: 800;
}

.landing-hero h1 {
  margin: 0;
  color: var(--soz-primary);
  background: var(--soz-gradient);
  background-clip: text;
  -webkit-text-fill-color: transparent;
  font-size: clamp(54px, 8vw, 104px);
  line-height: 0.95;
  letter-spacing: 0;
}

.landing-hero p {
  max-width: 540px;
  margin: 24px 0 32px;
  color: rgba(21, 31, 59, 0.74);
  font-size: clamp(18px, 2vw, 22px);
  line-height: 1.65;
}

.landing-hero__actions {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
}

.landing-hero__visual {
  overflow: hidden;
  border-radius: 8px;
  background: #ffffff;
  box-shadow: 0 26px 64px rgba(64, 28, 145, 0.16);
}

.landing-hero__visual img {
  display: block;
  width: 100%;
  aspect-ratio: 16 / 11;
  object-fit: cover;
  object-position: center;
}

.landing-section {
  max-width: 1240px;
  margin: 0 auto;
  padding: 54px 24px 0;
}

.landing-section__head {
  display: grid;
  gap: 8px;
  max-width: 780px;
  margin-bottom: 22px;
}

.section-kicker {
  color: var(--soz-primary);
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

.feature-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 16px;
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
  min-height: 220px;
  padding: 24px;
}

.feature-card__icon {
  display: grid;
  place-items: center;
  width: 52px;
  height: 52px;
  border-radius: 8px;
  background: var(--soz-primary-tint);
}

.feature-card h3,
.step-item h3 {
  margin: 18px 0 8px;
  color: var(--soz-ink);
  font-size: 22px;
  line-height: 1.22;
}

.feature-card p,
.step-item p,
.workflow-section p,
.pricing-card p {
  margin: 0;
  color: rgba(21, 31, 59, 0.68);
  line-height: 1.62;
}

.workflow-section {
  display: grid;
  grid-template-columns: minmax(0, 0.92fr) minmax(420px, 1.08fr);
  gap: clamp(24px, 4vw, 56px);
  align-items: center;
}

.workflow-copy {
  display: grid;
  gap: 14px;
}

.workflow-copy p {
  max-width: 600px;
  font-size: 18px;
}

.step-list {
  display: grid;
  gap: 14px;
}

.step-item {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 16px;
  align-items: start;
  padding: 20px;
}

.step-item__number {
  display: grid;
  place-items: center;
  width: 40px;
  height: 40px;
  border-radius: 999px;
  background: var(--soz-primary-tint);
  color: var(--soz-primary);
  font-weight: 800;
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
  color: #f54291;
}

.pricing-head .section-kicker::before,
.pricing-head .section-kicker::after {
  content: "...";
  color: #d96bff;
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
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

.pricing-card__icon {
  display: grid;
  place-items: center;
  width: 64px;
  height: 64px;
  border-radius: 8px;
  box-shadow: 0 10px 26px rgba(64, 28, 145, 0.14);
}

.pricing-card--private .pricing-card__icon {
  color: #7b3ff2;
  background: linear-gradient(180deg, #f4edff 0%, #ffffff 100%);
}

.pricing-card--business .pricing-card__icon {
  color: #ff7426;
  background: linear-gradient(180deg, #fff0e7 0%, #ffffff 100%);
}

.pricing-card__intro {
  max-width: 285px;
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

.pricing-card__art {
  order: 2;
  flex: 0 1 44%;
  justify-self: center;
  width: min(100%, 220px);
  max-height: 230px;
  object-fit: contain;
  pointer-events: none;
  user-select: none;
}

.landing-page--rtl .pricing-card__art {
  order: 1;
}

.pricing-card--business .pricing-card__art {
  width: min(100%, 280px);
  max-height: 260px;
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
  border: 2px solid #7b3ff2;
  color: #7b3ff2;
  background: #ffffff;
}

.pricing-card--business .pricing-card__button {
  color: #ffffff;
  background: linear-gradient(135deg, #ff7426 0%, #f54291 100%);
}

@media (max-width: 980px) {
  .landing-hero__inner,
  .workflow-section,
  .feature-grid,
  .pricing-grid {
    grid-template-columns: 1fr;
  }

  .landing-hero__copy {
    max-width: 760px;
  }
}

@media (max-width: 640px) {
  .landing-hero__inner {
    padding: 34px 16px 42px;
  }

  .landing-hero h1 {
    font-size: 48px;
  }

  .landing-section {
    padding: 38px 16px 0;
  }

  .feature-card,
  .step-item,
  .pricing-card {
    padding: 20px;
  }

  .pricing-card__price strong {
    font-size: 54px;
  }

  .pricing-card__content {
    width: 100%;
  }

  .pricing-card__art,
  .pricing-card--business .pricing-card__art {
    justify-self: center;
    width: min(72%, 230px);
    max-height: 220px;
    margin: -6px 0 0;
  }

  .pricing-card__top {
    grid-template-columns: auto minmax(0, 1fr);
  }

}
</style>
