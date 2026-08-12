<script setup>
	import { computed } from 'vue'
	import { useI18n } from 'vue-i18n'
	import { getPrivacyPolicy } from '@/constants/privacyPolicy'

	const { locale } = useI18n()
	const policy = computed(() => getPrivacyPolicy(locale.value))
</script>

<template>
	<q-page padding class="privacy-page">
		<div class="privacy-shell">
			<section class="soz-section-card privacy-panel">
				<header class="privacy-head">
					<h1 class="soz-page-title">{{ policy.title }}</h1>
					<p class="privacy-updated">{{ policy.updated }}</p>
					<p class="privacy-intro">{{ policy.intro }}</p>
				</header>

				<div class="privacy-sections">
					<section v-for="section in policy.sections" :key="section.title" class="privacy-section">
						<h2>{{ section.title }}</h2>
						<p v-for="paragraph in section.body" :key="paragraph">{{ paragraph }}</p>
					</section>
				</div>
			</section>
		</div>
	</q-page>
</template>

<style scoped lang="scss">
.privacy-page {
  padding: 0 20px 36px;
}

.privacy-shell {
  max-width: 1280px;
  margin: 0 auto;
}

.privacy-panel {
  padding: clamp(24px, 4vw, 42px);
}

.privacy-head {
  display: grid;
  gap: 14px;
}

.privacy-head h1,
.privacy-head p {
  margin: 0;
}

.privacy-updated {
  color: var(--soz-primary-deep);
  font-weight: 800;
}

.privacy-intro {
  max-width: 760px;
  color: rgba(17, 34, 45, 0.72);
  font-size: 1.08rem;
  line-height: 1.7;
}

.privacy-sections {
  display: grid;
  gap: 28px;
  margin-top: 34px;
}

.privacy-section {
  display: grid;
  gap: 10px;
}

.privacy-section h2 {
  margin: 0;
  font-size: clamp(1.35rem, 2vw, 1.8rem);
  line-height: 1.22;
}

.privacy-section p {
  margin: 0;
  color: rgba(17, 34, 45, 0.74);
  font-size: 1rem;
  line-height: 1.75;
}

@media (max-width: 700px) {
  .privacy-page {
    padding-inline: 10px;
  }

  .privacy-panel {
    padding: 22px;
  }

  .privacy-sections {
    gap: 24px;
    margin-top: 28px;
  }
}
</style>
