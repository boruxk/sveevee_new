<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'
	import heroSrc from '@/assets/hero-neighborhood.png'

	const { t, tm } = useI18n()

	function listMessage(key) {
		const value = tm(key)
		return Array.isArray(value) ? value : []
	}

	const featureCards = computed(() => listMessage('landing.features'))
	const steps = computed(() => listMessage('landing.steps'))
	const plans = computed(() => listMessage('landing.plans'))
</script>

<template>
	<q-page class="landing-page">
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
				<h2>{{ t('landing.pricingTitle') }}</h2>
			</div>

			<div class="pricing-grid">
				<article v-for="plan in plans" :key="plan.title" class="pricing-card" :class="{ 'pricing-card--featured': plan.featured }">
					<div class="pricing-card__top">
						<div>
							<div class="pricing-card__name">{{ plan.title }}</div>
							<p>{{ plan.subtitle }}</p>
						</div>
						<q-chip v-if="plan.featured" dense color="primary" text-color="white">{{ t('landing.popular') }}</q-chip>
					</div>

					<div class="pricing-card__price">
						<span class="pricing-card__currency">{{ t('landing.currency') }}</span>
						<strong>0</strong>
						<span>{{ t('landing.month') }}</span>
					</div>

					<ul>
						<li v-for="feature in plan.features" :key="feature">
							<q-icon name="check_circle" color="positive" size="20px" />
							<span>{{ feature }}</span>
						</li>
					</ul>

					<q-btn
						class="pricing-card__button"
						:color="plan.featured ? 'primary' : 'dark'"
						:outline="!plan.featured"
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
  gap: 8px;
  margin-bottom: 24px;
  text-align: center;
}

.pricing-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 22px;
}

.pricing-card {
  display: grid;
  align-content: start;
  gap: 24px;
  padding: 30px;
}

.pricing-card--featured {
  border-color: rgba(245, 66, 145, 0.38);
  box-shadow: 0 24px 54px rgba(245, 66, 145, 0.14);
}

.pricing-card__top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 14px;
}

.pricing-card__name {
  font-size: 24px;
  font-weight: 800;
  line-height: 1.2;
}

.pricing-card__price {
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.pricing-card__currency {
  font-size: 30px;
  font-weight: 700;
}

.pricing-card__price strong {
  font-size: 64px;
  line-height: 0.95;
}

.pricing-card ul {
  display: grid;
  gap: 12px;
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
}

.pricing-card__button {
  width: 100%;
  margin-top: 6px;
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
}
</style>
